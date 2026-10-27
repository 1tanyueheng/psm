<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Enums\PanelDecision;
use App\Models\ExaminerAssignment;
use App\Models\LampiranBForm;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — the panel's review of the proposal, and the title it settles.
 *
 * The title is decided at the **proposal milestone** — the first milestone of
 * every chain. The student files Lampiran A, the supervisor acknowledges, and
 * Lampiran B creates the project; from there the panel's verdict on the proposal
 * milestone is what gates the rest of the chain:
 *
 *   Approved            the milestone is approved, which opens the remaining
 *                       chapters (MilestoneService::activateNext)
 *   ConditionalApprove  the title stands subject to corrections: the student
 *                       files Lampiran C, and accepting it approves the milestone
 *   Rejected            the title itself is refused. The student changes it,
 *                       which rewrites the project title and reopens the
 *                       milestone for a fresh decision
 *
 * There is no coordinator-run sitting and no separate defence event. This
 * replaced both — the defence asked the same two examiners to judge the same
 * candidate titles, and the coordinator's step only relayed a decision that was
 * not the coordinator's to make.
 */
class ProposalReviewService
{
    public function __construct(
        protected MilestoneService $milestones,
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // The decision
    // -----------------------------------------------------------------

    /**
     * Record the panel's verdict on the proposal milestone.
     *
     * One decision for the whole panel, not one per examiner: the panel reads
     * the proposal together and the student is held to a single verdict.
     */
    public function recordDecision(Milestone $milestone, array $data, User $actor): Milestone
    {
        $this->assertIsProposal($milestone);
        $this->assertAwaitingDecision($milestone);

        $decision = PanelDecision::from($data['decision']);

        $reason = trim((string) ($data['panel_reason'] ?? ''));

        if ($decision->requiresReason() && $reason === '') {
            throw new InvalidArgumentException(
                "A reason is required for a '{$decision->label()}' decision — "
                .'the student needs to know what to change before resubmitting.'
            );
        }

        $target = match ($decision) {
            PanelDecision::Approved           => MilestoneStatus::Approved,
            PanelDecision::ConditionalApprove => MilestoneStatus::Conditional,
            PanelDecision::Rejected           => MilestoneStatus::Rejected,
        };

        $milestone = $this->milestones->transitionTo(
            $milestone,
            $target,
            $actor,
            $reason !== '' ? $reason : null,
        );

        /**
         * A conditional approval creates a **Lampiran C milestone**.
         *
         * The panel accepted the title subject to corrections, so the student owes
         * a document: previous title, new title, and the corrections taken against
         * each examiner comment. Until now that obligation lived as four columns
         * on the proposal and was invisible in the chain — a reader saw "Proposal
         * — conditional" with no sign that anything was outstanding.
         *
         * The other two verdicts create nothing:
         *   Approved  — no corrections, nothing owed.
         *   Rejected  — the title itself is refused; the student changes it via
         *               `changeTitle()` and the proposal reopens. There is no
         *               corrections document for a title that was never accepted.
         */
        if ($decision === PanelDecision::ConditionalApprove) {
            $milestone->forceFill(['requires_lampiran_c' => $reason])->save();
            $this->openLampiranCMilestone($milestone->fresh(), $reason, $actor);
        }

        /**
         * A rejected title is **re-presented**, not abandoned (spec §3.2).
         *
         * The faculty's rule is that a refused title goes back for a fresh
         * Lampiran B and a fresh defence, in the same semester, as many times as
         * it takes. So a rejection opens the next round here.
         *
         * **Nothing is overwritten.** This proposal milestone stays `rejected`
         * with the panel's reason — it is the record of what was refused — and
         * the refused Lampiran B stays `rejected` too. The student's next attempt
         * is new rows alongside them, which is what lets the panel at the second
         * defence see what changed.
         */
        if ($decision === PanelDecision::Rejected) {
            $this->openRefileRound($milestone->fresh(), $reason, $actor);
        }

        $this->notifyStudent($milestone, $decision, $reason);

        return $milestone->fresh();
    }

    /**
     * Open the next re-presentation: a new Lampiran B and a new proposal.
     *
     * Called only from a panel rejection. The new Lampiran B is a **draft** —
     * the student edits the title, the supervisor signs it, and only then does
     * the new proposal milestone open. Approving it automatically here would
     * defeat the point of requiring a signature on the new title.
     *
     * Inserted at `proposal.sequence + 1`, pushing everything after it down, so
     * the chain reads as it happened:
     *
     *   Proposal #1 (rejected) → Lampiran B #2 → Proposal #2 → chapters…
     */
    protected function openRefileRound(Milestone $proposal, string $reason, User $actor): LampiranBForm
    {
        return DB::transaction(function () use ($proposal, $reason, $actor) {
            $project = $proposal->project;

            $previous = LampiranBForm::forProject($project->id)
                ->orderByDesc('revision_no')
                ->first();

            /**
             * Record the refusal on the form the panel was looking at.
             *
             * Found by project rather than by milestone: the first filing
             * predates every milestone, so it carries no `milestone_id`. The most
             * recent form is the one in play.
             */
            if ($previous !== null && ! $previous->isRejected()) {
                $previous->forceFill([
                    'status'          => LampiranBForm::STATUS_REJECTED,
                    'rejected_reason' => $reason !== '' ? $reason : null,
                    'rejected_at'     => now(),
                    'rejected_by'     => $actor->id,
                ])->save();
            }

            /**
             * The next filing, pre-filled from the refused one.
             *
             * Everything but the title is carried over: the supervisor cannot
             * change (Lampiran A fixed the pairing), and the project type, field
             * of study and requirements rarely move with a retitle. Pre-filling
             * means the student edits rather than retypes, and cannot drop a
             * requirement by starting from a blank form.
             */
            $form = LampiranBForm::create([
                'project_id'   => $project->id,
                'revision_no'  => $previous === null ? 1 : $previous->revision_no + 1,
                'title'        => $previous?->title ?? $project->title,
                'project_type' => $previous?->project_type
                    ?? ($project->category?->value === 'research' ? 'research' : 'system'),
                'field_study'  => $previous?->field_study,
                'req_software' => $previous?->req_software,
                'req_hardware' => $previous?->req_hardware,
                'req_technology' => $previous?->req_technology,
                'origin'       => $previous?->origin,
                'supervisor_profile_id' => $previous?->supervisor_profile_id,
                'status'       => LampiranBForm::STATUS_DRAFT,
                'supersedes_id' => $previous?->id,
            ]);

            if ($previous !== null) {
                $previous->forceFill(['superseded_by_id' => $form->id])->save();
            }

            // A new proposal milestone, after the refused one.
            $sequence = ((int) $proposal->sequence) + 1;

            $project->milestones()
                ->where('sequence', '>=', $sequence)
                ->increment('sequence');

            $next = Milestone::create([
                'project_id'   => $project->id,
                'code'         => Milestone::CODE_PROPOSAL,
                /**
                 * The next attempt at this step.
                 *
                 * `(project_id, code)` is unique per *attempt*, not per lifetime:
                 * this is the second proposal this project has had, so it is
                 * attempt 2. Taken from the highest attempt rather than counted,
                 * so deleting a milestone cannot make two rows claim the same
                 * number.
                 */
                'attempt'      => (int) $project->milestones()
                    ->where('code', Milestone::CODE_PROPOSAL)
                    ->max('attempt') + 1,
                'title'        => 'Proposal (re-presented)',
                'description'  => sprintf(
                    'The previous title was refused. Revise it, have your supervisor sign the new '
                    .'Lampiran B, then upload the proposal for this title. Refused because: %s',
                    $reason !== '' ? $reason : 'no reason recorded',
                ),
                'sequence'     => $sequence,
                'weight_percent' => $proposal->weight_percent,
                // Held closed until the supervisor signs the new Lampiran B.
                'status'       => MilestoneStatus::Pending,
                'allow_late_submission' => true,
                'allowed_file_types'    => $proposal->allowed_file_types,
                'max_files'             => $proposal->max_files,
                'requires_supervisor_approval' => true,
            ]);

            $form->forceFill(['milestone_id' => $next->id])->save();

            $this->audit->log(
                action: AuditAction::MilestoneOpened,
                description: "Lampiran B #{$form->revision_no} opened for {$project->code} — "
                    .'title refused by the panel',
                subject: $next,
                actor: $actor,
            );

            return $form->fresh();
        });
    }

    /**
     * Create (or reopen) the Lampiran C milestone for a conditionally approved
     * proposal.
     *
     * Inserted at `proposal.sequence + 1` and everything after it shifted down,
     * so the chain reads: Proposal → Lampiran C → Chapters 1-4 → OSHE → Final
     * Report. The corrections step belongs immediately after the decision that
     * demanded it, not at the end of the term.
     *
     * Idempotent: if a C milestone already exists for this project it is reopened
     * rather than duplicated, because a proposal can be sent back and
     * conditionally approved again.
     */
    protected function openLampiranCMilestone(Milestone $proposal, string $reason, User $actor): Milestone
    {
        $project = $proposal->project;

        $existing = $project->milestones()
            ->where('code', Milestone::CODE_LAMPIRAN_C)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'status'     => MilestoneStatus::Open,
                'requires_lampiran_c' => $reason,
                'opens_at'   => now(),
            ])->save();

            return $existing;
        }

        return DB::transaction(function () use ($project, $proposal, $reason, $actor) {
            $sequence = ((int) $proposal->sequence) + 1;

            // Make room for the new step.
            $project->milestones()
                ->where('sequence', '>=', $sequence)
                ->increment('sequence');

            $milestone = Milestone::create([
                'project_id'            => $project->id,
                'code'                  => Milestone::CODE_LAMPIRAN_C,
                'title'                 => 'Lampiran C',
                'description'           => 'Corrections required by the panel\'s conditional approval. '
                    .'Fill in the previous and new title, record the corrections taken, then '
                    .'your supervisor signs it.',
                'sequence'              => $sequence,
                // A corrections form is not an assessed deliverable.
                'weight_percent'        => 0,
                'status'                => MilestoneStatus::Open,
                'opens_at'              => now(),
                'due_at'                => now()->addDays(14),
                'allow_late_submission' => true,
                'late_window_days'      => 7,
                'allowed_file_types'    => ['pdf'],
                'max_files'             => 3,
                'requires_supervisor_approval' => true,
                'requires_lampiran_c'   => $reason,
            ]);

            $this->audit->log(
                action: AuditAction::MilestoneOpened,
                description: "Lampiran C opened — conditional approval on {$project->code}",
                subject: $milestone,
            );

            return $milestone;
        });
    }

    /**
     * Accept Lampiran C — the corrections a conditional approval required.
     *
     * Filed by the student. Accepting it is what makes the conditional approval
     * final, and the corrected title is what the project then carries.
     */
    public function fileLampiranC(Milestone $milestone, array $data, User $actor): Milestone
    {
        $this->assertIsProposal($milestone);

        if ($milestone->status !== MilestoneStatus::Conditional) {
            throw new InvalidArgumentException(
                'Lampiran C only applies to a proposal the panel approved conditionally. '
                ."This milestone is '{$milestone->status->value}'."
            );
        }

        $title = trim((string) ($data['corrections_title'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException(
                'Lampiran C must state the corrected title — it is the title the project will carry.'
            );
        }

        $actions = $data['corrections_actions'] ?? null;

        if (is_array($actions)) {
            $actions = array_values(array_filter(
                $actions,
                fn ($row) => is_array($row)
                    && (filled($row['comment'] ?? null) || filled($row['action'] ?? null))
            ));
        }

        return DB::transaction(function () use ($milestone, $title, $actions, $actor) {
            $this->applyTitle($milestone, $title, $actor, 'Lampiran C');

            // Clearing the condition approves the milestone, which opens the
            // remaining chapters. `transitionTo` writes the Lampiran C record
            // and the timeline entry in the same transaction.
            return $this->milestones->transitionTo(
                $milestone,
                MilestoneStatus::Approved,
                $actor,
                "Lampiran C accepted — title confirmed as \"{$title}\"",
                [
                    'lampiran_c_title'   => $title,
                    'lampiran_c_actions' => $actions ?: null,
                    'lampiran_c_at'      => now(),
                    'lampiran_c_by'      => $actor->id,
                ],
            );
        });
    }

    /**
     * The examiners seated on the project's student, with each member's role.
     *
     * Read from the allocations rather than typed in, so the screen names the
     * people the system actually appointed. The panel is anchored on the
     * *student*, because it is seated from Lampiran A onward — before the project
     * exists — and the project is stamped onto the allocation by Lampiran B.
     *
     * @return array<int, array{user_id:int, name:string, role:?string, role_label:?string}>
     */
    public function panel(Project $project): array
    {
        /**
         * Reuse the loaded allocations when the caller has them.
         *
         * The project list renders every milestone, and each proposal milestone
         * asked for its panel — so a cohort of 26 projects issued 26 separate
         * `examiner_assignments` queries, plus the `projects` lookup that
         * `leader()` used to do. Both are now read from relations the
         * controller already eager-loaded, which collapses them to nothing on
         * that screen. Falls back to querying for callers that have not.
         */
        $assignments = $project->relationLoaded('examinerAssignments')
            ? $project->examinerAssignments
                ->where('is_active', true)
                ->sortBy('id')
                ->values()
            : $this->loadPanelAssignments($project);

        return $assignments
            ->map(function (ExaminerAssignment $assignment) {
                $name = $assignment->examiner?->displayName();

                if ($name === null) {
                    return null;
                }

                return [
                    'user_id'    => (int) $assignment->examiner_id,
                    'name'       => $name,
                    'role'       => $assignment->panel_role,
                    'role_label' => match ($assignment->panel_role) {
                        'chair'   => 'Chair',
                        'member'  => 'Member',
                        'reserve' => 'Reserve',
                        default   => null,
                    },
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Fetch a project's active panel allocations, for callers that have not
     * eager-loaded them.
     *
     * `examiner.supervisorProfile` is eager-loaded because
     * `User::displayName()` reads the academic title from it. Without that the
     * panel cost one query per examiner for a value that was already reachable
     * — and on a hosted database each one is a network round trip, so a
     * two-person panel paid two.
     *
     * @return \Illuminate\Support\Collection<int, ExaminerAssignment>
     */
    protected function loadPanelAssignments(Project $project): Collection
    {
        $studentId = $project->leader()?->id;

        if ($studentId === null) {
            return collect();
        }

        return ExaminerAssignment::query()
            ->forStudent($studentId)
            ->where('is_active', true)
            ->with('examiner.supervisorProfile')
            ->orderBy('id')
            ->get();
    }

    /**
     * Write a title to the project.
     *
     * The project title *is* the confirmed title — Lampiran B registers it, and a
     * decision that settles a different one must not leave the two disagreeing.
     * The audit entry names the cause so the change is traceable to the decision
     * that produced it.
     */
    protected function applyTitle(Milestone $milestone, string $title, User $actor, string $cause): void
    {
        $project = $milestone->project;

        if ($project === null) {
            throw new InvalidArgumentException('This milestone has no project, so its title cannot be changed.');
        }

        $before = trim((string) $project->title);

        if ($before === $title) {
            return;
        }

        $project->update(['title' => $title]);

        $this->audit->log(
            action: AuditAction::ProjectUpdated,
            description: "Project title changed by {$cause} — \"{$before}\" → \"{$title}\"",
            subject: $project,
            before: ['title' => $before],
            after: ['title' => $title],
            actor: $actor,
        );
    }

    protected function assertIsProposal(Milestone $milestone): void
    {
        if (! $milestone->isProposal()) {
            throw new InvalidArgumentException(
                'Only the proposal milestone settles the title. '
                ."Milestone '{$milestone->code}' is reviewed through the normal milestone flow."
            );
        }
    }

    /**
     * The panel may only rule on a proposal its supervisor has endorsed.
     *
     * `Reviewed` is the supervisor's signature — the faculty's sequence is that
     * the supervisor reads the proposal **before** the title defence, and only
     * then does the panel sit. Accepting `Submitted` as well used to let the
     * panel decide on a document nobody had read, and because the title decision
     * is written here and nowhere else, nothing downstream could tell which way
     * it had happened.
     *
     * The panel remains the higher authority: its verdict on `Reviewed` is
     * final as before, and it can still reject. What it can no longer do is act
     * as a shortcut around the supervisor.
     */
    protected function assertAwaitingDecision(Milestone $milestone): void
    {
        if ($milestone->status === MilestoneStatus::Reviewed) {
            return;
        }

        throw new InvalidArgumentException(
            match ($milestone->status) {
                MilestoneStatus::Submitted =>
                    'The proposal has been submitted but not yet endorsed by the supervisor. '
                    .'Your supervisor reads it before the title defence, and their endorsement '
                    .'is what opens it to the panel.',
                default =>
                    'There is nothing to decide yet — the proposal has not been submitted. '
                    ."This milestone is '{$milestone->status->value}'.",
            }
        );
    }

    /** Tell the student what the panel decided, when it needs an action. */
    protected function notifyStudent(Milestone $milestone, PanelDecision $decision, string $reason): void
    {
        if ($decision === PanelDecision::Approved) {
            return;
        }

        $milestone->loadMissing('project.students.user');

        $students = $milestone->project?->students->pluck('user')->filter() ?? collect();

        if ($students->isEmpty()) {
            return;
        }

        $conditional = $decision === PanelDecision::ConditionalApprove;

        $this->notifications->notify(
            $students->all(),
            $conditional ? NotificationType::MilestoneReviewed : NotificationType::RevisionRequested,
            [
                'title'      => $conditional ? 'Proposal approved with corrections' : 'Proposal rejected',
                'body'       => $conditional
                    ? "File Lampiran C for your proposal: {$reason}"
                    : "Change your title and resubmit the proposal: {$reason}",
                'action_url' => "/milestones/{$milestone->id}",
                'milestone'  => $milestone->title,
                'urgent'     => ! $conditional,
            ],
            $milestone,
        );
    }
}
