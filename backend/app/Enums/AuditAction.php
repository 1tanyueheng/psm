<?php

namespace App\Enums;

/**
 * Module 7 — Actions recorded in the audit trail.
 *
 * Using an enum rather than free text keeps the trail queryable and lets the
 * UI render a human sentence without a lookup table.
 */
enum AuditAction: string
{
    // Auth
    case Login            = 'auth.login';
    case LoginFailed      = 'auth.login_failed';
    case Logout           = 'auth.logout';
    case PasswordChanged  = 'auth.password_changed';
    case PasswordReset    = 'auth.password_reset';

    // Users & profiles (Module 2)
    case UserCreated      = 'user.created';
    case UserUpdated      = 'user.updated';
    case UserDeactivated  = 'user.deactivated';
    case UserReactivated  = 'user.reactivated';
    case RoleChanged      = 'user.role_changed';
    case ProfileUpdated   = 'profile.updated';

    // Supervision assignments (Module 2)
    case SupervisorAssigned   = 'assignment.supervisor_assigned';
    case SupervisorRemoved    = 'assignment.supervisor_removed';
    case CapacityChanged      = 'assignment.capacity_changed';

    // Projects & milestones (Module 3)
    case ProjectCreated    = 'project.created';
    case ProjectUpdated    = 'project.updated';
    case ProjectSubmitted  = 'project.submitted';
    case ProjectApproved   = 'project.approved';
    case ProjectRejected   = 'project.rejected';
    case ProjectArchived   = 'project.archived';
    case MilestoneOpened   = 'milestone.opened';
    case MilestoneSubmitted= 'milestone.submitted';
    case MilestoneReviewed = 'milestone.reviewed';
    case MilestoneApproved = 'milestone.approved';
    case MilestoneRejected = 'milestone.rejected';
    case DeadlineChanged   = 'milestone.deadline_changed';
    case FileUploaded      = 'milestone.file_uploaded';
    case FileDownloaded    = 'milestone.file_downloaded';

    // Evaluation (Module 4)
    case EvaluationCreated = 'evaluation.created';
    case EvaluationUpdated = 'evaluation.updated';
    case EvaluationSubmitted = 'evaluation.submitted';
    case EvaluationModerated = 'evaluation.moderated';
    case GradeReleased     = 'grade.released';
    case GradeRecalculated = 'grade.recalculated';

    // Reporting (Module 5)
    case ReportExported    = 'report.exported';
    case ReportViewed      = 'report.viewed';

    // Leaderboard (Module 8)
    case LeaderboardPublished = 'leaderboard.published';
    case LeaderboardUnpublished = 'leaderboard.unpublished';
    case LeaderboardConfigChanged = 'leaderboard.config_changed';

    // Archive (Module 7)
    case ArchiveExported   = 'archive.exported';
    case ArchiveRestored   = 'archive.restored';

    public function label(): string
    {
        return match ($this) {
            self::Login                 => 'Signed in',
            self::LoginFailed           => 'Failed sign-in attempt',
            self::Logout                => 'Signed out',
            self::PasswordChanged       => 'Changed password',
            self::PasswordReset         => 'Reset password',
            self::UserCreated           => 'Created account',
            self::UserUpdated           => 'Updated account',
            self::UserDeactivated       => 'Deactivated account',
            self::UserReactivated       => 'Reactivated account',
            self::RoleChanged           => 'Changed role',
            self::ProfileUpdated        => 'Updated profile',
            self::SupervisorAssigned    => 'Assigned supervisor',
            self::SupervisorRemoved     => 'Removed supervisor',
            self::CapacityChanged       => 'Changed supervision capacity',
            self::ProjectCreated        => 'Registered project',
            self::ProjectUpdated        => 'Updated project',
            self::ProjectSubmitted      => 'Submitted project',
            self::ProjectApproved       => 'Approved project',
            self::ProjectRejected       => 'Rejected project',
            self::ProjectArchived       => 'Archived project',
            self::MilestoneOpened       => 'Opened milestone',
            self::MilestoneSubmitted    => 'Submitted milestone',
            self::MilestoneReviewed     => 'Reviewed milestone',
            self::MilestoneApproved     => 'Approved milestone',
            self::MilestoneRejected     => 'Requested revision',
            self::DeadlineChanged       => 'Changed milestone deadline',
            self::FileUploaded          => 'Uploaded file',
            self::FileDownloaded        => 'Downloaded file',
            self::EvaluationCreated     => 'Started evaluation',
            self::EvaluationUpdated     => 'Updated evaluation',
            self::EvaluationSubmitted   => 'Submitted marks',
            self::EvaluationModerated   => 'Moderated marks',
            self::GradeReleased         => 'Released grade',
            self::GradeRecalculated     => 'Recalculated grade',
            self::ReportExported        => 'Exported report',
            self::ReportViewed          => 'Viewed report',
            self::LeaderboardPublished  => 'Published leaderboard',
            self::LeaderboardUnpublished=> 'Unpublished leaderboard',
            self::LeaderboardConfigChanged => 'Changed leaderboard settings',
            self::ArchiveExported       => 'Exported archive',
            self::ArchiveRestored       => 'Restored from archive',
        };
    }

    /** Coarse grouping used to filter the audit log UI. */
    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'auth.')        => 'Authentication',
            str_starts_with($this->value, 'user.'),
            str_starts_with($this->value, 'profile.'),
            str_starts_with($this->value, 'assignment.')  => 'Accounts & Profiles',
            str_starts_with($this->value, 'project.'),
            str_starts_with($this->value, 'milestone.')   => 'Projects & Milestones',
            str_starts_with($this->value, 'evaluation.'),
            str_starts_with($this->value, 'grade.')       => 'Evaluation & Grading',
            str_starts_with($this->value, 'report.')      => 'Reporting',
            str_starts_with($this->value, 'leaderboard.') => 'Recognitions',
            str_starts_with($this->value, 'archive.')     => 'Archive',
            default => 'Other',
        };
    }

    /** Severity drives the badge colour and the "security events" filter. */
    public function severity(): string
    {
        return match ($this) {
            self::LoginFailed,
            self::UserDeactivated,
            self::RoleChanged,
            self::EvaluationModerated,
            self::ProjectRejected,
            self::MilestoneRejected,
            self::LeaderboardConfigChanged => 'warning',

            self::PasswordReset,
            self::PasswordChanged,
            self::GradeReleased => 'critical',

            default => 'info',
        };
    }

    /** Actions that always warrant an audit entry even for read-only routes. */
    public function isSecurityRelevant(): bool
    {
        return in_array($this->category(), ['Authentication'], true)
            || in_array($this->severity(), ['warning', 'critical'], true);
    }

    /** Only these may be triggered by a GET request (read-tracking). */
    public function isReadAction(): bool
    {
        return in_array($this, [
            self::FileDownloaded,
            self::ReportViewed,
        ], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $a) => [$a->value => $a->category().' — '.$a->label()])
            ->all();
    }
}
