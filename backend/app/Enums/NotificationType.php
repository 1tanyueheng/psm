<?php

namespace App\Enums;

/**
 * Module 6 — Notification catalogue.
 *
 * Each case knows its own default channels and whether it is urgent enough to
 * bypass the user's "digest only" preference.
 */
enum NotificationType: string
{
    // --- Module 3: milestones -------------------------------------------------
    case MilestoneOpened        = 'milestone.opened';
    case MilestoneSubmitted     = 'milestone.submitted';
    case MilestoneReviewed      = 'milestone.reviewed';
    case MilestoneApproved      = 'milestone.approved';
    case RevisionRequested      = 'milestone.revision_requested';

    // --- Module 6: reminders --------------------------------------------------
    case DeadlineReminder       = 'deadline.reminder';
    case DeadlineMissed         = 'deadline.missed';
    case DeadlineOverridden     = 'deadline.overridden';

    // --- Module 4: grading ----------------------------------------------------
    case EvaluationAssigned     = 'evaluation.assigned';
    case EvaluationSubmitted    = 'evaluation.submitted';
    case GradeReleased          = 'grade.released';
    case GradeModerated         = 'grade.moderated';

    // --- Module 2: accounts & supervision -------------------------------------
    case AccountCreated         = 'account.created';
    case SupervisorAssigned     = 'supervisor.assigned';
    case SupervisorReassigned   = 'supervisor.reassigned';
    case CapacityWarning        = 'supervisor.capacity_warning';

    // --- Module 8: recognition -------------------------------------------------
    case LeaderboardPublished   = 'leaderboard.published';

    public function label(): string
    {
        return match ($this) {
            self::MilestoneOpened      => 'Milestone opened',
            self::MilestoneSubmitted   => 'New submission received',
            self::MilestoneReviewed    => 'Submission reviewed',
            self::MilestoneApproved    => 'Milestone approved',
            self::RevisionRequested    => 'Revision required',
            self::DeadlineReminder     => 'Deadline approaching',
            self::DeadlineMissed       => 'Deadline missed',
            self::DeadlineOverridden   => 'Deadline changed',
            self::EvaluationAssigned   => 'Evaluation assigned to you',
            self::EvaluationSubmitted  => 'Evaluation submitted',
            self::GradeReleased        => 'Grade released',
            self::GradeModerated       => 'Grade moderated',
            self::AccountCreated       => 'Welcome — account created',
            self::SupervisorAssigned   => 'Supervisor assigned',
            self::SupervisorReassigned => 'Supervision changed',
            self::CapacityWarning      => 'Supervision capacity warning',
            self::LeaderboardPublished => 'Results published',
        };
    }

    /** Channels this notification fans out to by default. */
    public function defaultChannels(): array
    {
        return match ($this) {
            // Account creation is email-only: the user has no in-app session yet.
            self::AccountCreated => ['mail'],

            // Time-critical: always both, and never batched.
            self::DeadlineReminder,
            self::DeadlineMissed,
            self::RevisionRequested,
            self::GradeReleased  => ['mail', 'database'],

            default => ['database', 'mail'],
        };
    }

    /** Urgent notifications ignore the user's digest preference. */
    public function isUrgent(): bool
    {
        return in_array($this, [
            self::DeadlineReminder,
            self::DeadlineMissed,
            self::RevisionRequested,
        ], true);
    }

    /**
     * Icon name consumed by the React notification centre, so the frontend
     * never has to keep its own copy of this mapping.
     */
    public function icon(): string
    {
        return match ($this) {
            self::MilestoneOpened      => 'play-circle',
            self::MilestoneSubmitted,
            self::EvaluationSubmitted  => 'upload',
            self::MilestoneReviewed    => 'eye',
            self::MilestoneApproved    => 'check-circle',
            self::RevisionRequested,
            self::DeadlineMissed       => 'alert-triangle',
            self::DeadlineReminder     => 'clock',
            self::DeadlineOverridden   => 'calendar',
            self::EvaluationAssigned   => 'clipboard-list',
            self::GradeReleased,
            self::GradeModerated       => 'award',
            self::AccountCreated,
            self::SupervisorAssigned,
            self::SupervisorReassigned => 'user-check',
            self::CapacityWarning      => 'users',
            self::LeaderboardPublished => 'trophy',
        };
    }

    /** All types, grouped for a preferences screen. */
    public static function grouped(): array
    {
        return [
            'Milestones' => [
                self::MilestoneOpened->value,
                self::MilestoneSubmitted->value,
                self::MilestoneReviewed->value,
                self::MilestoneApproved->value,
                self::RevisionRequested->value,
            ],
            'Deadlines' => [
                self::DeadlineReminder->value,
                self::DeadlineMissed->value,
                self::DeadlineOverridden->value,
            ],
            'Grading' => [
                self::EvaluationAssigned->value,
                self::EvaluationSubmitted->value,
                self::GradeReleased->value,
                self::GradeModerated->value,
            ],
            'Account' => [
                self::AccountCreated->value,
                self::SupervisorAssigned->value,
                self::SupervisorReassigned->value,
                self::CapacityWarning->value,
            ],
            'Recognition' => [
                self::LeaderboardPublished->value,
            ],
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])
            ->all();
    }
}
