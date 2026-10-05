<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Enums\PanelDecision;
use App\Models\ExaminerAssignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
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

        $this->notifyStudent($milestone, $decision, $reason);

        return $milestone;
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
     * Change the title after a rejection.
     *
     * The title is the *project's* — so the change is written to
     * `projects.title`, not held on the milestone. The milestone then reopens so
     * the student can refile the proposal under the new title and the panel
     * decides again.
     */
    public function changeTitle(Milestone $milestone, string $title, User $actor): Milestone
    {
        $this->assertIsProposal($milestone);

        if (! in_array($milestone->status, [MilestoneStatus::Rejected, MilestoneStatus::Conditional], true)) {
            throw new InvalidArgumentException(
                'The title may only be changed while the proposal is rejected or conditionally '
                ."approved. This milestone is '{$milestone->status->value}'."
            );
        }

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('Enter the new title.');
        }

        if ($title === trim((string) $milestone->project?->title)) {
            throw new InvalidArgumentException(
                'That is already the project title — enter the title the panel should consider instead.'
            );
        }

        return DB::transaction(function () use ($milestone, $title, $actor) {
            $this->applyTitle($milestone, $title, $actor, 'title change');

            // Reopen on a rejection: the proposal document now carries a new
            // title, so the student refiles it and the panel decides again.
            if ($milestone->status === MilestoneStatus::Rejected) {
                return $this->milestones->transitionTo(
                    $milestone,
                    MilestoneStatus::Open,
                    $actor,
                    "Title changed to \"{$title}\" — resubmit the proposal for a fresh decision.",
                );
            }

            return $milestone->fresh();
        });
    }

    // -----------------------------------------------------------------
    // The panel
    // -----------------------------------------------------------------

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
        $studentId = $project->leader()?->id;

        if ($studentId === null) {
            return [];
        }

        return ExaminerAssignment::query()
            ->forStudent($studentId)
            ->where('is_active', true)
            ->with('examiner')
            ->orderBy('id')
            ->get()
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

    protected function assertAwaitingDecision(Milestone $milestone): void
    {
        if (! in_array($milestone->status, [MilestoneStatus::Submitted, MilestoneStatus::Reviewed], true)) {
            throw new InvalidArgumentException(
                'There is nothing to decide yet — the proposal has not been submitted. '
                ."This milestone is '{$milestone->status->value}'."
            );
        }
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
