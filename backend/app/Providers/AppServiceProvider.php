<?php

namespace App\Providers;

use App\Models\AcademicSemester;
use App\Models\ArchivedProject;
use App\Models\Evaluation;
use App\Models\FinalGrade;
use App\Models\Leaderboard;
use App\Models\AssessmentWindow;
use App\Models\MarkSubmission;
use App\Models\LecturerMark;
use App\Models\LampiranBForm;
use App\Models\Section;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SupervisionAssignment;
use App\Models\User;
use App\Policies\ArchivePolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\FinalGradePolicy;
use App\Policies\LeaderboardPolicy;
use App\Policies\AssessmentWindowPolicy;
use App\Policies\MarkSubmissionPolicy;
use App\Policies\LecturerMarkPolicy;
use App\Policies\LampiranBFormPolicy;
use App\Policies\SectionPolicy;
use App\Policies\MilestonePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\SemesterPolicy;
use App\Policies\SupervisionPolicy;
use App\Policies\SupervisorAgreementPolicy;
use App\Policies\UserPolicy;
use App\Services\ArchiveService;
use App\Services\AssignmentService;
use App\Services\AuditLogger;
use App\Services\EvaluationService;
use App\Services\ExaminerPairingService;
use App\Services\LeaderboardService;
use App\Services\AssessmentWindowService;
use App\Services\MarkSubmissionService;
use App\Services\MarkVisibilityService;
use App\Services\MilestoneService;
use App\Services\NotificationDispatcher;
use App\Services\ProposalReviewService;
use App\Services\ReportingService;
use App\Services\SemesterService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Module 1 — the policy map that backs every $this->authorize() call.
     */
    protected array $policies = [
        User::class                  => UserPolicy::class,
        Project::class               => ProjectPolicy::class,
        Milestone::class             => MilestonePolicy::class,
        Evaluation::class            => EvaluationPolicy::class,
        FinalGrade::class            => FinalGradePolicy::class,
        SupervisionAssignment::class => SupervisionPolicy::class,
        AcademicSemester::class      => SemesterPolicy::class,
        \App\Models\SupervisorAgreement::class => \App\Policies\SupervisorAgreementPolicy::class,
        Leaderboard::class           => LeaderboardPolicy::class,
        ArchivedProject::class       => ArchivePolicy::class,
        MarkSubmission::class        => MarkSubmissionPolicy::class,
        AssessmentWindow::class      => AssessmentWindowPolicy::class,
        LecturerMark::class          => LecturerMarkPolicy::class,
        Section::class               => SectionPolicy::class,

        /**
         * Lampiran B filings.
         *
         * Registered here rather than relying on discovery: `LampiranBFormPolicy`
         * would need to sit at `App\Policies\LampiranBFormPolicy` for Laravel to
         * guess it, and the model is `App\Models\LampiranBForm` — a match, but
         * only by coincidence of naming, and this map is how every other policy
         * in the system is wired. Registering it explicitly means the failure is
         * a missing line, not a guess that stopped working.
         */
        LampiranBForm::class         => LampiranBFormPolicy::class,
    ];

    public function register(): void
    {
        // The audit logger is used by nearly every service; a singleton keeps
        // per-request state (already-logged flags) consistent.
        $this->app->singleton(AuditLogger::class);

        $this->app->singleton(NotificationDispatcher::class);

        // Domain services are cheap to construct but hold no state, so they
        // are registered as singletons for consistency within a request.
        foreach ([
            MilestoneService::class,
            EvaluationService::class,
            MarkSubmissionService::class,
            MarkVisibilityService::class,
            AssignmentService::class,
            ReportingService::class,
            LeaderboardService::class,
            ArchiveService::class,
            ProposalReviewService::class,
            AssessmentWindowService::class,
            ExaminerPairingService::class,
            SemesterService::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // -----------------------------------------------------------------
        // Module 8 — a super-admin bypass for local seeding & maintenance.
        // -----------------------------------------------------------------
        Gate::before(function (User $user, string $ability) {
            // Admins are not automatically omnipotent: the audit trail and
            // grade integrity rules must still bind them. Only these bypass.
            if ($user->isAdmin() && in_array($ability, [
                'viewAuditLog',
                'manageSettings',
                'exportData',
                'pruneAuditLog',
            ], true)) {
                return true;
            }

            return null;
        });

        // -----------------------------------------------------------------
        // Force HTTPS in production so generated URLs (password reset,
        // leaderboard links) are correct behind Render's proxy.
        // -----------------------------------------------------------------
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
