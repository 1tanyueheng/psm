<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Models\Milestone;
use App\Models\MilestoneSchedule;
use App\Models\MilestoneTemplate;
use App\Models\Project;
use App\Models\SubmissionEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — The milestone state machine.
 *
 * All status transitions go through `transitionTo()`, which validates against
 * MilestoneStatus::allowedTransitions(). This is what makes the audit trail
 * (Module 7) trustworthy: there is exactly one code path that can change a
 * milestone's status, and it records why.
 */
class MilestoneService
{
    public function __construct(
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // Instantiation
    // -----------------------------------------------------------------

    /**
     * Create the milestone chain for a project from its category template.
     * Called once, at project registration.
     *
     * Dates come from the term's published calendar when there is one, and from
     * the template's offsets otherwise. That order matters: the faculty sets
     * Chapter 1 for 15 November for the whole batch, so a student registering in
     * October and one registering in November must both be held to 15 November.
     * Deriving from each project's own start date instead — which is what this
     * did — gave two students in the same batch deadlines a fortnight apart and
     * left nothing able to state the real date.
     *
     * The template offsets remain the fallback for a term with no calendar, and
     * they are what the calendar itself is seeded from.
     *
     * @return array<int, Milestone>
     */
    public function instantiateFor(Project $project, ?Carbon $startDate = null): array
    {
        $template = MilestoneTemplate::resolveFor($project->category, $project->psm_part);

        if ($template === null) {
            throw new InvalidArgumentException(
                "No active milestone template for category [{$project->category->value}] "
                ."and part [{$project->psm_part}]."
            );
        }

        $start = $startDate ?? now();

        // The term's calendar, if the coordinator has published one. Keyed by
        // code so each milestone can find its own date in one lookup.
        $schedule = MilestoneSchedule::query()
            ->forSemesterPart($project->academic_semester_id, $project->psm_part)
            ->get()
            ->keyBy('code');

        return DB::transaction(function () use ($project, $template, $start, $schedule) {
            $created = [];

            /**
             * Lampiran A and B are created **retroactively**, ahead of the chain.
             *
             * Both were filed before this project existed: A is the supervisor
             * agreement, B is the title proposal that created the project and its
             * milestone chain (see RegistrationService). They cannot be "tasks" —
             * the student has already done them — so they are written as
             * `approved` records at sequence 1 and 2, and every template item
             * shifts down by two to make room.
             *
             * The point is the chain a reader sees: A -> B -> proposal -> ...,
             * rather than a chain that begins at the proposal and leaves the two
             * documents that justified it on a separate screen.
             *
             * The agreement is carried on each so the digital form can render what
             * was actually filed — the proposed titles, the agreed title, the
             * signature dates — instead of an empty shell.
             *
             * PSM 2 has no registration documents: a student reaches it by
             * progression, not by a second Lampiran A, so the shift is zero.
             */
            $shift = $project->psm_part === 'PSM1' ? 2 : 0;

            if ($shift > 0) {
                $created[] = $this->recordRegistrationDocument(
                    $project,
                    Milestone::CODE_LAMPIRAN_A,
                    'Lampiran A — Supervisor Agreement',
                    'Filed at registration: the supervisor agreement and the three proposed titles.',
                    1,
                );

                $created[] = $this->recordRegistrationDocument(
                    $project,
                    Milestone::CODE_LAMPIRAN_B,
                    'Lampiran B — Title Proposal',
                    'Filed at registration: the agreed title and the project details that opened this chain.',
                    2,
                );
            }

            foreach ($template->items as $item) {
                $scheduled = $schedule->get($item->code);

                if ($scheduled !== null) {
                    // A published date is the faculty's, and it is fixed: it does
                    // not move when the chain runs late, which is what
                    // `deadline_is_fixed` tells `repairedDueAt()`.
                    $opensAt = $scheduled->opens_at?->copy() ?? $start->copy();
                    $dueAt = $scheduled->due_at->copy();
                } else {
                    $opensAt = $start->copy()->addDays($item->offset_days);
                    $dueAt = $opensAt->copy()->addDays($item->duration_days);
                }

                // The first *task* milestone opens immediately; the rest open
                // when their predecessor is approved (see activateNext()).
                //
                // `$item->sequence` is the template's own numbering, which starts
                // at 1 and is unaffected by the two registration records ahead of
                // it — so this test still identifies the first real task, not
                // Lampiran A.
                $status = $item->sequence === 1
                    ? MilestoneStatus::Open
                    : MilestoneStatus::Pending;

                /**
                 * `opens_at` records when the milestone *actually* opened, not
                 * when the calendar planned to open it — that is the fact a
                 * student's lateness is measured against. The first milestone
                 * opens now, so that is what it carries; a later one is stamped
                 * by `activateNext()` when its predecessor clears.
                 *
                 * The planned date is not lost: it stays on the term's calendar,
                 * which is what the coordinator edits.
                 */
                if ($status === MilestoneStatus::Open) {
                    $opensAt = now();
                }

                $milestone = Milestone::create([
                    'project_id'                    => $project->id,
                    'milestone_template_item_id'    => $item->id,
                    'code'                          => $item->code,
                    'title'                         => $item->title,
                    'description'                   => $item->description,
                    // Shifted by the two registration records ahead of the chain.
                    'sequence'                      => $item->sequence + $shift,
                    'weight_percent'                => $item->weight_percent,
                    'status'                        => $status,
                    'opens_at'                      => $opensAt,
                    'due_at'                        => $dueAt,
                    'deadline_is_fixed'             => $scheduled !== null,
                    'allowed_file_types'            => $item->allowed_file_types,
                    'max_files'                     => $item->max_files,
                    'requires_supervisor_approval'  => $item->requires_supervisor_approval,
                ]);

                $this->recordEvent(
                    $milestone,
                    SubmissionEvent::EVENT_OPENED,
                    null,
                    "Milestone created from template [{$template->name} v{$template->version}]",
                    actor: null,
                );

                $created[] = $milestone;
            }

            return $created;
        });
    }

    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    /**
     * Move a milestone to a new status, validating the transition and
     * recording it. Throws if the transition is illegal.
     *
     * `$narrativeEvent` overrides the event type written to the timeline.
     * The default is derived from the target status, which is right whenever
     * the status change *is* the event. It is wrong when something else caused
     * the change: a student withdrawing their submission drives the milestone
     * to `rejected`, and the default would record "… requested a revision",
     * which inverts who did what. Callers in that position name the event
     * themselves so the timeline has one accurate entry rather than a
     * misleading one plus a duplicate.
     */
    public function transitionTo(
        Milestone $milestone,
        MilestoneStatus $target,
        ?User $actor = null,
        ?string $comment = null,
        array $extra = [],
        ?string $narrativeEvent = null,
    ): Milestone {
        $current = $milestone->status;

        if ($current === $target) {
            return $milestone;
        }

        if (! $current->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "Cannot move milestone [{$milestone->code}] from "
                ."'{$current->value}' to '{$target->value}'."
            );
        }

        return DB::transaction(function () use ($milestone, $current, $target, $actor, $comment, $extra, $narrativeEvent) {
            $before = $milestone->getAttributes();

            $updates = ['status' => $target];

            // Stamp the timestamps that belong to each state
            $updates += match ($target) {
                MilestoneStatus::Submitted => ['submitted_at' => now()],
                MilestoneStatus::Reviewed  => ['reviewed_at' => now(), 'reviewed_by' => $actor?->id],
                MilestoneStatus::Approved  => ['approved_at' => now(), 'reviewed_by' => $actor?->id],
                MilestoneStatus::Rejected  => [
                    'reviewed_at'    => now(),
                    'reviewed_by'    => $actor?->id,
                    // Nudge the revision counter so the submission UI can label it
                    'revision_count' => ($milestone->revision_count ?? 0) + 1,
                ],
                MilestoneStatus::Open      => ['opens_at' => $milestone->opens_at ?? now()],
                default                    => [],
            };

            if ($comment !== null) {
                $updates['review_comment'] = $comment;
            }

            $milestone->update($updates + $extra);

            $this->recordEvent(
                $milestone,
                $narrativeEvent ?? $this->eventForTransition($target),
                $current,
                $comment,
                $actor,
                ['to_status' => $target->value],
            );

            $this->audit->log(
                action: $this->auditActionForTransition($target),
                description: "{$milestone->title} → {$target->label()}",
                subject: $milestone,
                before: $before,
                after: $milestone->getAttributes(),
                actor: $actor,
            );

            // Approving a milestone unlocks the next one in the chain
            if ($target === MilestoneStatus::Approved) {
                $this->activateNext($milestone->project, $milestone->sequence);
            }

            return $milestone->fresh();
        });
    }

    /**
     * Approve with the standard "work accepted" semantics.
     *
     * Refused for the proposal milestone: its verdict is the panel's and it
     * settles the title, so it goes through
     * `ProposalReviewService::recordDecision()`. Approving it here would fix the
     * milestone without ever recording a title decision, which is the state this
     * design exists to prevent.
     */
    public function approve(Milestone $milestone, User $actor, ?string $comment = null): Milestone
    {
        $this->assertNotProposal($milestone, 'approved');

        return $this->transitionTo($milestone, MilestoneStatus::Approved, $actor, $comment);
    }

    /** Request a revision — reopens the milestone for a re-upload. */
    public function requestRevision(Milestone $milestone, User $actor, string $comment): Milestone
    {
        $this->assertNotProposal($milestone, 'sent back for revision');

        $milestone = $this->transitionTo($milestone, MilestoneStatus::Rejected, $actor, $comment);

        $this->notifications->notify(
            $milestone->project->students->pluck('user')->filter(),
            NotificationType::RevisionRequested,
            [
                'title'      => 'Revision required',
                'body'       => "{$milestone->title} needs changes: {$comment}",
                'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
                'milestone'  => $milestone->title,
            ],
            $milestone,
        );

        return $milestone;
    }

    /**
     * Open the milestone that follows the one just approved.
     *
     * Selects the immediate next milestone that can still be opened — `pending`,
     * or `overdue`/`rejected` because it was already flagged or returned before
     * its predecessor cleared. Selecting only `pending` was a stall waiting to
     * happen: the proposal milestone now gates the whole chain, so a chapter
     * whose deadline passed while the proposal was still being decided was
     * flipped to `overdue` by the nightly job and then skipped forever — the
     * chain jumped over it, and its work could never be opened in order.
     */
    public function activateNext(Project $project, int $afterSequence): ?Milestone
    {
        $next = $project->milestones()
            ->where('sequence', '>', $afterSequence)
            ->whereIn('status', [
                MilestoneStatus::Pending->value,
                MilestoneStatus::Overdue->value,
                MilestoneStatus::Rejected->value,
            ])
            ->orderBy('sequence')
            ->first();

        if ($next === null) {
            // Nothing left: the project itself is finished
            $remaining = $project->milestones()
                ->where('status', '!=', MilestoneStatus::Approved->value)
                ->count();

            if ($remaining === 0) {
                $project->update(['status' => 'completed']);
            }

            return null;
        }

        /**
         * Stamp the moment it actually opened, and move its deadline with it —
         * but only when the deadline is relative.
         *
         * `due_at` was computed at registration as "planned open + duration".
         * The chain is sequential, so a milestone opens when its predecessor is
         * approved — which is routinely later than planned. Stamping only
         * `opens_at` left the milestone holding a deadline that had already
         * passed the moment it opened: 49 of 134 were in that state, and the
         * nightly job then flagged them overdue on their first day.
         *
         * When the term has a published calendar, though, the date is the
         * faculty's and `repairedDueAt()` leaves it alone. A chapter that opens
         * late against a fixed date is *genuinely* overdue — that is information
         * the coordinator needs, not a bug to smooth over.
         */
        $next->opens_at = now();
        $next->due_at = $next->repairedDueAt() ?? $next->due_at;
        $next->save();

        $next = $this->transitionTo(
            $next,
            MilestoneStatus::Open,
            null,
            'Opened after previous milestone was approved',
        );

        $this->notifications->notify(
            $next->project->students->pluck('user')->filter(),
            NotificationType::MilestoneOpened,
            [
                'title'      => 'New milestone open',
                'body'       => "{$next->title} is now open".($next->due_at ? ', due '.$next->due_at->format('d M Y') : ''),
                'action_url' => "/projects/{$next->project_id}/milestones/{$next->id}",
                'milestone'  => $next->title,
            ],
            $next,
        );

        return $next;
    }

    // -----------------------------------------------------------------
    // Deadline administration
    // -----------------------------------------------------------------

    /**
     * Override a deadline. Restricted to coordinators/admins by the policy;
     * the reason is mandatory because this is the single most contested
     * administrative action in the system.
     */
    public function overrideDeadline(
        Milestone $milestone,
        Carbon $newDueAt,
        User $actor,
        string $reason,
    ): Milestone {
        $before = $milestone->getAttributes();
        $old = $milestone->due_at?->format('d M Y') ?? 'none';

        $milestone->update([
            'due_at'                    => $newDueAt,
            'extended_until'            => $newDueAt->copy()->endOfDay(),
            'deadline_overridden_by'    => $actor->id,
            'deadline_override_reason'  => $reason,
        ]);

        $this->recordEvent(
            $milestone,
            SubmissionEvent::EVENT_DEADLINE_CHANGED,
            $milestone->status,
            "Deadline moved from {$old} to {$newDueAt->format('d M Y')}: {$reason}",
            $actor,
            ['old_due_at' => $old, 'new_due_at' => $newDueAt->toDateString()],
        );

        $this->audit->log(
            action: AuditAction::DeadlineChanged,
            description: "{$milestone->title}: {$old} → {$newDueAt->format('d M Y')}",
            subject: $milestone,
            before: $before,
            after: $milestone->getAttributes(),
            actor: $actor,
        );

        $this->notifications->notify(
            $milestone->project->students->pluck('user')->filter(),
            NotificationType::DeadlineOverridden,
            [
                'title'      => 'Deadline changed',
                'body'       => "{$milestone->title} is now due {$newDueAt->format('d M Y')}.",
                'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
            ],
            $milestone,
        );

        return $milestone->fresh();
    }

    /**
     * Flag every milestone whose deadline has passed without a submission.
     * Run nightly by the scheduler; returns the number flagged.
     */
    public function flagOverdue(): int
    {
        $count = 0;

        Milestone::query()
            ->shouldBeOverdue()
            ->with('project.students.user')
            ->chunkById(200, function ($milestones) use (&$count) {
                foreach ($milestones as $milestone) {
                    $this->transitionTo($milestone, MilestoneStatus::Overdue);

                    $this->notifications->notify(
                        $milestone->project->students->pluck('user')->filter(),
                        NotificationType::DeadlineMissed,
                        [
                            'title'      => 'Deadline missed',
                            'body'       => "{$milestone->title} was due {$milestone->due_at?->format('d M Y')} and has not been submitted.",
                            'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
                            'urgent'     => true,
                        ],
                        $milestone,
                    );

                    $count++;
                }
            });

        return $count;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Refuse the generic review actions on the proposal milestone.
     *
     * Its verdict is the panel's, and it carries the title decision — so it is
     * decided through ProposalReviewService, which knows about the conditional
     * and rejected branches. Pointing the caller there is more useful than a
     * generic "not allowed".
     */
    protected function assertNotProposal(Milestone $milestone, string $action): void
    {
        if ($milestone->isProposal()) {
            throw new InvalidArgumentException(
                "The proposal milestone cannot be {$action} from here — its title is decided by the "
                .'panel. Record the panel\'s decision instead.'
            );
        }
    }

    /**
     * Write one of the two registration documents as a completed milestone.
     *
     * Lampiran A and B are not tasks — they were filed before the project
     * existed — so this writes them straight to `approved`, with the timestamps a
     * reader expects to see: `submitted_at` and `approved_at` both taken from the
     * agreement, because the document was filed and accepted in one act at
     * registration.
     *
     * `agreement_id` is stamped into the milestone's metadata so the digital form
     * can find and render the agreement it records.
     */
    protected function recordRegistrationDocument(
        Project $project,
        string $code,
        string $title,
        string $description,
        int $sequence,
    ): Milestone {
        $agreement = $project->agreement;
        $filedAt = $agreement?->supervisor_acknowledged_at
            ?? $agreement?->student_signed_at
            ?? $project->submitted_at
            ?? now();

        $milestone = Milestone::create([
            'project_id'                   => $project->id,
            'code'                         => $code,
            'title'                        => $title,
            'description'                  => $description,
            'sequence'                     => $sequence,
            // Neither document is assessed: the proposal milestone carries the
            // title's weight.
            'weight_percent'               => 0,
            'status'                       => MilestoneStatus::Approved,
            'opens_at'                     => $agreement?->student_signed_at ?? $filedAt,
            'due_at'                       => $filedAt,
            'submitted_at'                 => $filedAt,
            'approved_at'                  => $filedAt,
            'allowed_file_types'           => ['pdf'],
            'max_files'                    => 3,
            'requires_supervisor_approval' => false,
        ]);

        $this->recordEvent(
            $milestone,
            SubmissionEvent::EVENT_APPROVED,
            null,
            "Filed at registration under agreement #{$agreement?->id}",
            actor: null,
        );

        return $milestone;
    }

    protected function recordEvent(
        Milestone $milestone,
        string $event,
        ?MilestoneStatus $from = null,
        ?string $comment = null,
        ?User $actor = null,
        array $payload = [],
    ): SubmissionEvent {
        return SubmissionEvent::create([
            'milestone_id' => $milestone->id,
            'actor_id'     => $actor?->id,
            'event'        => $event,
            'from_status'  => $from?->value,
            'to_status'    => $milestone->status->value,
            'comment'      => $comment,
            'payload'      => $payload ?: null,
        ]);
    }

    protected function eventForTransition(MilestoneStatus $target): string
    {
        return match ($target) {
            MilestoneStatus::Submitted => SubmissionEvent::EVENT_UPLOADED,
            MilestoneStatus::Reviewed  => SubmissionEvent::EVENT_REVIEWED,
            MilestoneStatus::Approved  => SubmissionEvent::EVENT_APPROVED,
            MilestoneStatus::Rejected  => SubmissionEvent::EVENT_REJECTED,
            MilestoneStatus::Open      => SubmissionEvent::EVENT_OPENED,
            default                    => SubmissionEvent::EVENT_COMMENTED,
        };
    }

    protected function auditActionForTransition(MilestoneStatus $target): AuditAction
    {
        return match ($target) {
            MilestoneStatus::Submitted => AuditAction::MilestoneSubmitted,
            MilestoneStatus::Reviewed  => AuditAction::MilestoneReviewed,
            MilestoneStatus::Approved  => AuditAction::MilestoneApproved,
            MilestoneStatus::Rejected  => AuditAction::MilestoneRejected,
            MilestoneStatus::Open      => AuditAction::MilestoneOpened,
            default                    => AuditAction::MilestoneReviewed,
        };
    }
}
