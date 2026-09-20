<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Models\Milestone;
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

        return DB::transaction(function () use ($project, $template, $start) {
            $created = [];

            foreach ($template->items as $item) {
                $opensAt = $start->copy()->addDays($item->offset_days);
                $dueAt   = $opensAt->copy()->addDays($item->duration_days);

                // The first milestone opens immediately; the rest open when
                // their predecessor is approved (see activateNext()).
                $status = $item->sequence === 1
                    ? MilestoneStatus::Open
                    : MilestoneStatus::Pending;

                $milestone = Milestone::create([
                    'project_id'                    => $project->id,
                    'milestone_template_item_id'    => $item->id,
                    'code'                          => $item->code,
                    'title'                         => $item->title,
                    'description'                   => $item->description,
                    'sequence'                      => $item->sequence,
                    'weight_percent'                => $item->weight_percent,
                    'status'                        => $status,
                    'opens_at'                      => $opensAt,
                    'due_at'                        => $dueAt,
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
     */
    public function transitionTo(
        Milestone $milestone,
        MilestoneStatus $target,
        ?User $actor = null,
        ?string $comment = null,
        array $extra = [],
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

        return DB::transaction(function () use ($milestone, $current, $target, $actor, $comment, $extra) {
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
                $this->eventForTransition($target),
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

    /** Approve with the standard "work accepted" semantics. */
    public function approve(Milestone $milestone, User $actor, ?string $comment = null): Milestone
    {
        return $this->transitionTo($milestone, MilestoneStatus::Approved, $actor, $comment);
    }

    /** Request a revision — reopens the milestone for a re-upload. */
    public function requestRevision(Milestone $milestone, User $actor, string $comment): Milestone
    {
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

    /** Open the milestone that follows the one just approved. */
    public function activateNext(Project $project, int $afterSequence): ?Milestone
    {
        $next = $project->milestones()
            ->where('sequence', '>', $afterSequence)
            ->where('status', MilestoneStatus::Pending->value)
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

        $next->update(['status' => MilestoneStatus::Open, 'opens_at' => now()]);

        $this->recordEvent(
            $next,
            SubmissionEvent::EVENT_OPENED,
            MilestoneStatus::Pending,
            'Opened after previous milestone was approved',
            actor: null,
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
