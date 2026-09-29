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
            self::Login                 => 'تسجيل دخول',
            self::LoginFailed           => 'محاولة تسجيل دخول فاشلة',
            self::Logout                => 'تسجيل خروج',
            self::PasswordChanged       => 'تغيير كلمة المرور',
            self::PasswordReset         => 'إعادة تعيين كلمة المرور',
            self::UserCreated           => 'إنشاء حساب',
            self::UserUpdated           => 'تحديث حساب',
            self::UserDeactivated       => 'إيقاف حساب',
            self::UserReactivated       => 'إعادة تفعيل حساب',
            self::RoleChanged           => 'تغيير الدور',
            self::ProfileUpdated        => 'تحديث الملف الشخصي',
            self::SupervisorAssigned    => 'تعيين مشرف',
            self::SupervisorRemoved     => 'إزالة مشرف',
            self::CapacityChanged       => 'تغيير سعة الإشراف',
            self::ProjectCreated        => 'تسجيل مشروع',
            self::ProjectUpdated        => 'تحديث مشروع',
            self::ProjectSubmitted      => 'تسليم مشروع',
            self::ProjectApproved       => 'اعتماد مشروع',
            self::ProjectRejected       => 'رفض مشروع',
            self::ProjectArchived       => 'أرشفة مشروع',
            self::MilestoneOpened       => 'فتح معلم',
            self::MilestoneSubmitted    => 'تسليم معلم',
            self::MilestoneReviewed     => 'مراجعة معلم',
            self::MilestoneApproved     => 'اعتماد معلم',
            self::MilestoneRejected     => 'طلب تعديل',
            self::DeadlineChanged       => 'تغيير موعد المعلم',
            self::FileUploaded          => 'رفع ملف',
            self::FileDownloaded        => 'تنزيل ملف',
            self::EvaluationCreated     => 'بدء تقييم',
            self::EvaluationUpdated     => 'تحديث تقييم',
            self::EvaluationSubmitted   => 'تسليم الدرجات',
            self::EvaluationModerated   => 'تحكيم الدرجات',
            self::GradeReleased         => 'الإفراج عن الدرجة',
            self::GradeRecalculated     => 'إعادة حساب الدرجة',
            self::ReportExported        => 'تصدير تقرير',
            self::ReportViewed          => 'عرض تقرير',
            self::LeaderboardPublished  => 'نشر لوح الجوائز',
            self::LeaderboardUnpublished=> 'إلغاء نشر لوح الجوائز',
            self::LeaderboardConfigChanged => 'تغيير إعدادات لوح الجوائز',
            self::ArchiveExported       => 'تصدير الأرشيف',
            self::ArchiveRestored       => 'استعادة من الأرشيف',
        };
    }

    /** Coarse grouping used to filter the audit log UI. */
    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'auth.')        => 'المصادقة',
            str_starts_with($this->value, 'user.'),
            str_starts_with($this->value, 'profile.'),
            str_starts_with($this->value, 'assignment.')  => 'الحسابات والملفات الشخصية',
            str_starts_with($this->value, 'project.'),
            str_starts_with($this->value, 'milestone.')   => 'المشاريع والمعالم',
            str_starts_with($this->value, 'evaluation.'),
            str_starts_with($this->value, 'grade.')       => 'التقييم والدرجات',
            str_starts_with($this->value, 'report.')      => 'التقارير',
            str_starts_with($this->value, 'leaderboard.') => 'التكريم',
            str_starts_with($this->value, 'archive.')     => 'الأرشيف',
            default => 'أخرى',
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
        return in_array($this->category(), ['المصادقة'], true)
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
