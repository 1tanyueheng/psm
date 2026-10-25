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
    // A student moving from PSM 1 to PSM 2: creates the PSM 2 project from the
    // PSM 1 title, moves the enrolment, and archives PSM 1. Distinct from
    // `ProjectCreated` because it is a lifecycle event spanning two projects,
    // and the trail needs to be able to answer "who progressed whom, and when".
    case ProjectProgressed = 'project.progressed';

    /**
     * The committee's pass/fail verdict on a project.
     *
     * Separate from approving and progressing because it is the one action that
     * ends a student's part of the programme. A fail entitles them to repeat, so
     * "who decided this, when, and on what grounds" is the first thing an appeal
     * asks — which is why the reason travels with the audit record and not only
     * on the project row.
     */
    case ProjectResolved   = 'project.resolved';

    /**
     * A student placed into (or taken out of) a PSM part for a semester.
     *
     * Separate from the project events because an enrolment exists before a
     * project does — the admin can register a cohort for a term that has not
     * started, and that act has no project to hang an audit entry from.
     */
    case StudentEnrolled   = 'enrolment.created';
    case StudentWithdrawn  = 'enrolment.withdrawn';

    // The panel's verdict on a Lampiran A proposal, and the student's response
    // to a rejection. Registration events rather than project ones: they decide
    // the title before any project exists.
    case ProposalReviewed    = 'proposal.reviewed';
    case ProposalResubmitted = 'proposal.resubmitted';
    case MilestoneOpened   = 'milestone.opened';
    case MilestoneSubmitted= 'milestone.submitted';
    case MilestoneReviewed = 'milestone.reviewed';
    case MilestoneApproved = 'milestone.approved';
    case MilestoneRejected = 'milestone.rejected';
    case DeadlineChanged   = 'milestone.deadline_changed';
    case FileUploaded      = 'milestone.file_uploaded';
    case FileDownloaded    = 'milestone.file_downloaded';
    case FileWithdrawn     = 'milestone.file_withdrawn';

    // Evaluation (Module 4)
    case EvaluationCreated = 'evaluation.created';
    case EvaluationUpdated = 'evaluation.updated';
    case EvaluationSubmitted = 'evaluation.submitted';
    case EvaluationModerated = 'evaluation.moderated';
    case GradeReleased     = 'grade.released';
    case GradeRecalculated = 'grade.recalculated';
    // A mark that goes back down. Distinct from `GradeReleased` because it is
    // the alarming direction: a student who has already read a number needs to
    // be able to find out why it disappeared.
    case GradeWithheld     = 'grade.withheld';
    // The coordinator's attestation that every expected form came back.
    case MarkSubmissionLocked       = 'mark_submission.locked';
    // The same attestation, made by the system the moment the last form landed.
    // Kept apart from the manual lock so the trail can still answer "did a human
    // sign this off, or did it close itself?".
    case MarkSubmissionAutoLocked   = 'mark_submission.auto_locked';
    case MarkSubmissionUnlocked     = 'mark_submission.unlocked';

    /**
     * Coursework marks keyed by a section lecturer.
     *
     * Audited with both sides of the change because these four numbers are 35%
     * of a PSM 1 result. A correction months later is an appeal, and the trail
     * has to answer "what did it say before, who changed it, and when" without
     * anyone reconstructing it from memory.
     */
    case LecturerMarksRecorded = 'lecturer_marks.recorded';
    // A section's lecturer, or a student's placement in one.
    case SectionChanged        = 'section.changed';
    case StudentSectionAssigned = 'section.student_assigned';

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

    // Academic semesters (Module 3)
    case SemesterCreated        = 'semester.created';
    case SemesterUpdated        = 'semester.updated';
    case SemesterClosed         = 'semester.closed';
    case SemesterReopened       = 'semester.reopened';
    case RegistrationWindowSet  = 'semester.registration_window';
    case SemesterGradesReleased = 'semester.grades_released';

    /**
     * Human phrasing for one action: "Keyed coursework marks".
     *
     * The match is deliberately **exhaustive and has no `default` arm**. A case
     * added without an arm is not a cosmetic gap — it is an `UnhandledMatchError`
     * thrown the moment anything renders that action, and the two places that
     * reach here are both read paths with no error handling of their own:
     * `AuditLog::sentence()` and `AuditLogResource`, which between them feed the
     * audit log, the admin overview's recent activity, and the filter panel.
     *
     * That is exactly what happened: `project.progressed`, `proposal.reviewed`,
     * `proposal.resubmitted`, `lecturer_marks.recorded`, `section.changed` and
     * `section.student_assigned` were added to this enum without arms, and the
     * first audit row they wrote turned the admin overview into a 500. The
     * filter panel was worse — `AuditAction::options()` labels *every* case, so
     * it failed regardless of what the trail contained.
     *
     * A `default` would have hidden all of that behind 'Other'. Failing loudly
     * here is the cheaper trade, because it points at the missing arm instead of
     * quietly shipping a blank label to a screen an auditor reads. So: **add a
     * case to this enum, add its arm here in the same commit.**
     *
     * Wording follows the enum's own convention — past tense, no actor and no
     * subject ("Changed role", not "Admin changed the role"), because
     * `sentence()` prefixes the actor and `description` supplies the specifics:
     * "Dr. Farah binti Idris — Keyed coursework marks: AI240009 S1".
     */
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
            self::ProjectProgressed     => 'Progressed to PSM 2',
            self::ProjectResolved       => 'Resolved project (pass/fail)',
            self::StudentEnrolled       => 'Enrolled student in a PSM session',
            self::StudentWithdrawn      => 'Withdrew a student enrolment',
            self::ProposalReviewed      => 'Reviewed proposal',
            self::ProposalResubmitted   => 'Resubmitted proposal',
            self::MilestoneOpened       => 'Opened milestone',
            self::MilestoneSubmitted    => 'Submitted milestone',
            self::MilestoneReviewed     => 'Reviewed milestone',
            self::MilestoneApproved     => 'Approved milestone',
            self::MilestoneRejected     => 'Requested revision',
            self::DeadlineChanged       => 'Changed milestone deadline',
            self::FileUploaded          => 'Uploaded file',
            self::FileDownloaded        => 'Downloaded file',
            self::FileWithdrawn         => 'Withdrew file',
            self::EvaluationCreated     => 'Started evaluation',
            self::EvaluationUpdated     => 'Updated evaluation',
            self::EvaluationSubmitted   => 'Submitted marks',
            self::EvaluationModerated   => 'Moderated marks',
            self::GradeReleased         => 'Released mark',
            self::GradeRecalculated     => 'Recalculated mark',
            self::GradeWithheld         => 'Withheld mark',
            self::MarkSubmissionLocked     => 'Locked mark submission',
            self::MarkSubmissionAutoLocked => 'Auto-locked mark submission',
            self::MarkSubmissionUnlocked   => 'Unlocked mark submission',
            // "Changed a student's section" rather than "Assigned student",
            // because the one audit case covers both directions — the same
            // entry is written when a placement is undone, and the description
            // says which ("removed from their section").
            self::LecturerMarksRecorded    => 'Keyed coursework marks',
            self::SectionChanged           => 'Changed section',
            self::StudentSectionAssigned   => "Changed a student's section",
            self::ReportExported        => 'Exported report',
            self::ReportViewed          => 'Viewed report',
            self::LeaderboardPublished  => 'Published leaderboard',
            self::LeaderboardUnpublished=> 'Unpublished leaderboard',
            self::LeaderboardConfigChanged => 'Changed leaderboard settings',
            self::ArchiveExported       => 'Exported archive',
            self::ArchiveRestored       => 'Restored from archive',
            self::SemesterCreated      => 'Created semester',
            self::SemesterUpdated      => 'Updated semester',
            self::SemesterClosed       => 'Closed semester',
            self::SemesterReopened     => 'Reopened semester',
            self::RegistrationWindowSet=> 'Changed registration window',
            self::SemesterGradesReleased => 'Released marks for semester',
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
            str_starts_with($this->value, 'grade.'),
            str_starts_with($this->value, 'lecturer_marks.'),
            str_starts_with($this->value, 'mark_submission.') => 'Evaluation & Grading',
            str_starts_with($this->value, 'section.')     => 'Accounts & Profiles',
            str_starts_with($this->value, 'report.')      => 'Reporting',
            str_starts_with($this->value, 'leaderboard.') => 'Recognitions',
            str_starts_with($this->value, 'semester.')    => 'Semesters',
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
            self::GradeReleased,
            self::GradeWithheld,
            self::SemesterGradesReleased => 'critical',

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
