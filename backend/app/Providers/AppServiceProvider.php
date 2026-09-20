<?php

namespace App\Providers;

use App\Models\ArchivedProject;
use App\Models\Evaluation;
use App\Models\FinalGrade;
use App\Models\Leaderboard;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SupervisionAssignment;
use App\Models\User;
use App\Policies\ArchivePolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\FinalGradePolicy;
use App\Policies\LeaderboardPolicy;
use App\Policies\MilestonePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\SupervisionPolicy;
use App\Policies\UserPolicy;
use App\Services\ArchiveService;
use App\Services\AssignmentService;
use App\Services\AuditLogger;
use App\Services\EvaluationService;
use App\Services\LeaderboardService;
use App\Services\MilestoneService;
use App\Services\NotificationDispatcher;
use App\Services\ReportingService;
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
        Leaderboard::class           => LeaderboardPolicy::class,
        ArchivedProject::class       => ArchivePolicy::class,
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
            AssignmentService::class,
            ReportingService::class,
            LeaderboardService::class,
            ArchiveService::class,
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
