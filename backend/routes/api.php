<?php

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\PublicApi\LeaderboardController as PublicLeaderboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PSM Management System — API routes
|--------------------------------------------------------------------------
|
| Structure mirrors the eight modules. Reading order:
|   1. Public, unauthenticated routes (Module 8)
|   2. Authentication (Module 1)
|   3. Authenticated routes, grouped by module
|
| Authorisation is enforced in two layers:
|   - `role:` middleware for coarse route groups
|   - policies inside controllers for per-resource rules
| The policies are authoritative; the middleware exists to fail fast and to
| keep the route file self-documenting.
|
*/

// =====================================================================
// Module 8 — Public recognition (NO authentication)
// =====================================================================
// Deliberately registered before any auth middleware. Rate-limited because
// these endpoints are internet-facing and have no user to throttle against.
Route::prefix('public')->name('public.')->middleware('throttle:60,1')->group(function () {
    Route::get('leaderboard', [PublicLeaderboardController::class, 'current'])->name('leaderboard.current');
    Route::get('leaderboard/status', [PublicLeaderboardController::class, 'status'])->name('leaderboard.status');
    Route::get('leaderboard/archive', [PublicLeaderboardController::class, 'archive'])->name('leaderboard.archive');
    // `slug` after the static routes above so they are not shadowed
    Route::get('leaderboard/{slug}', [PublicLeaderboardController::class, 'show'])->name('leaderboard.show');
    Route::get('leaderboard/{slug}/entry/{rank}', [PublicLeaderboardController::class, 'entry'])->name('leaderboard.entry');
});

// =====================================================================
// Module 1 — Authentication
// =====================================================================
Route::prefix('auth')->name('auth.')->group(function () {
    // Tight throttle on login to blunt credential stuffing
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');

    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,1')
        ->name('forgot-password');

    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('reset-password');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
    });
});

// =====================================================================
// Everything below requires an authenticated, active account
// =====================================================================
Route::middleware(['auth:sanctum', 'active', 'first.login', 'audit'])->group(function () {

    // -----------------------------------------------------------------
    // Module 1 — Current user
    // -----------------------------------------------------------------
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // -----------------------------------------------------------------
    // Module 6 — Notifications (every role)
    // -----------------------------------------------------------------
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('summary', [NotificationController::class, 'summary'])->name('summary');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::delete('{id}', [NotificationController::class, 'destroy'])->name('destroy');

        Route::get('preferences', [NotificationController::class, 'preferences'])->name('preferences');
        Route::put('preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
    });

    // -----------------------------------------------------------------
    // Module 2 — Own profile (every role)
    // -----------------------------------------------------------------
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('availability', [ProfileController::class, 'setAvailability'])->name('availability');
        Route::put('expertise', [ProfileController::class, 'updateExpertise'])->name('expertise');
        Route::get('workload', [ProfileController::class, 'workload'])->name('workload');
    });

    // -----------------------------------------------------------------
    // Module 2 — User & profile administration
    // -----------------------------------------------------------------
    Route::middleware('role:admin,coordinator')->group(function () {
        // Dropdown data must be reachable by coordinators, not just admins
        Route::get('users/options', [UserController::class, 'options'])->name('users.options');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Read + self-update: policy decides who may touch whom
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/reset-password', [UserController::class, 'sendPasswordReset'])->name('users.reset-password');

    // -----------------------------------------------------------------
    // Module 2 — Assignments (supervisor pairing, examiner allocation)
    // -----------------------------------------------------------------
    Route::prefix('assignments')->name('assignments.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('supervisions', [AssignmentController::class, 'indexSupervisions'])->name('supervisions.index');
        Route::post('supervisions', [AssignmentController::class, 'storeSupervision'])->name('supervisions.store');
        Route::delete('supervisions/{assignment}', [AssignmentController::class, 'destroySupervision'])->name('supervisions.destroy');

        Route::post('supervisors/{supervisor}/capacity', [AssignmentController::class, 'setCapacity'])->name('supervisors.capacity');
        Route::get('suggest-supervisors/{student}', [AssignmentController::class, 'suggestSupervisors'])->name('suggest-supervisors');

        Route::get('examiners', [AssignmentController::class, 'indexExaminers'])->name('examiners.index');
        Route::post('examiners', [AssignmentController::class, 'storeExaminer'])->name('examiners.store');
        Route::delete('examiners/{assignment}', [AssignmentController::class, 'destroyExaminer'])->name('examiners.destroy');

        Route::get('students/unassigned', [AssignmentController::class, 'unassignedStudents'])->name('students.unassigned');
    });

    // Reference data, readable by anyone who assigns or marks
    Route::get('assignments/expertise-areas', [AssignmentController::class, 'expertiseAreas'])
        ->middleware('role:admin,coordinator,supervisor')
        ->name('assignments.expertise-areas');

    // -----------------------------------------------------------------
    // Module 3 — Projects
    // -----------------------------------------------------------------
    Route::prefix('projects')->name('projects.')->group(function () {
        // Scoped by Project::visibleTo() inside the controller
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('options', [ProjectController::class, 'options'])->name('options');
        Route::get('summary', [ProjectController::class, 'summary'])->name('summary');

        Route::post('/', [ProjectController::class, 'store'])
            ->middleware('role:student')
            ->name('store');

        Route::get('{project}', [ProjectController::class, 'show'])->name('show');
        Route::patch('{project}', [ProjectController::class, 'update'])->name('update');
        Route::post('{project}/submit', [ProjectController::class, 'submit'])->name('submit');
        Route::post('{project}/leaderboard-consent', [ProjectController::class, 'toggleLeaderboardConsent'])->name('leaderboard-consent');

        // Coordinator/admin acts
        Route::middleware('role:admin,coordinator')->group(function () {
            Route::post('{project}/approve', [ProjectController::class, 'approve'])->name('approve');
            Route::post('{project}/reject', [ProjectController::class, 'reject'])->name('reject');
            Route::post('{project}/archive', [ProjectController::class, 'archiveProject'])->name('archive');
        });

        // Milestones live under their project for a natural URL shape
        Route::get('{project}/milestones', [MilestoneController::class, 'index'])->name('milestones.index');
    });

    // -----------------------------------------------------------------
    // Module 3 — Milestones
    // -----------------------------------------------------------------
    Route::prefix('milestones')->name('milestones.')->group(function () {
        // The cross-project worklist behind the Milestones screen: every
        // milestone the signed-in user is allowed to see, across all their
        // projects. Registered before the {milestone} wildcard.
        Route::get('/', [MilestoneController::class, 'all'])->name('index');

        Route::get('{milestone}', [MilestoneController::class, 'show'])->name('show');
        Route::post('{milestone}/submit', [MilestoneController::class, 'submit'])->name('submit');
        Route::post('{milestone}/comment', [MilestoneController::class, 'comment'])->name('comment');

        // Review — the student's supervisor or a coordinator
        Route::post('{milestone}/approve', [MilestoneController::class, 'approve'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('approve');

        Route::post('{milestone}/request-revision', [MilestoneController::class, 'requestRevision'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('request-revision');

        Route::post('{milestone}/deadline', [MilestoneController::class, 'changeDeadline'])
            ->middleware('role:admin,coordinator')
            ->name('deadline');
    });

    // -----------------------------------------------------------------
    // Module 3 — Submission files
    // -----------------------------------------------------------------
    Route::get('submissions/{file}/download', [MilestoneController::class, 'download'])->name('submissions.download');
    Route::delete('submissions/{file}', [MilestoneController::class, 'destroyFile'])->name('submissions.destroy');

    // -----------------------------------------------------------------
    // Module 4 — Evaluations & grading
    // -----------------------------------------------------------------
    Route::prefix('evaluations')->name('evaluations.')->group(function () {
        Route::get('/', [EvaluationController::class, 'index'])->name('index');

        // Only a coordinator allocates evaluation forms
        Route::post('/', [EvaluationController::class, 'store'])
            ->middleware('role:admin,coordinator')
            ->name('store');

        Route::get('{evaluation}', [EvaluationController::class, 'show'])->name('show');
        Route::put('{evaluation}/marks', [EvaluationController::class, 'saveMarks'])->name('marks');
        Route::post('{evaluation}/submit', [EvaluationController::class, 'submit'])->name('submit');
        Route::post('{evaluation}/moderate', [EvaluationController::class, 'moderate'])
            ->middleware('role:admin,coordinator')
            ->name('moderate');
        Route::post('{evaluation}/declare-conflict', [EvaluationController::class, 'declareConflict'])->name('declare-conflict');
    });

    Route::get('rubrics', [EvaluationController::class, 'rubrics'])->name('rubrics.index');

    Route::prefix('grades')->name('grades.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('/', [EvaluationController::class, 'grades'])->name('index');
        Route::post('{grade}/recompute', [EvaluationController::class, 'recompute'])->name('recompute');
        Route::post('{grade}/release', [EvaluationController::class, 'releaseGrade'])->name('release');
    });

    Route::get('projects/{project}/grades', [EvaluationController::class, 'projectGrades'])->name('projects.grades');
    Route::post('projects/{project}/grades/release-all', [EvaluationController::class, 'releaseAll'])
        ->middleware('role:admin,coordinator')
        ->name('projects.grades.release-all');
    Route::put('projects/{project}/grade-scheme', [EvaluationController::class, 'updateGradeScheme'])
        ->middleware('role:admin,coordinator')
        ->name('projects.grade-scheme');

    // -----------------------------------------------------------------
    // Module 5 — Reporting & analytics
    // -----------------------------------------------------------------
    Route::prefix('reports')->name('reports.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
        Route::get('cohort-progress', [ReportController::class, 'cohortProgress'])->name('cohort-progress');
        Route::get('at-risk', [ReportController::class, 'atRisk'])->name('at-risk');
        Route::get('supervisor-workload', [ReportController::class, 'supervisorWorkload'])->name('supervisor-workload');
        Route::get('examiner-workload', [ReportController::class, 'examinerWorkload'])->name('examiner-workload');
        Route::get('grade-distribution', [ReportController::class, 'gradeDistribution'])->name('grade-distribution');
        Route::get('milestone-breakdown', [ReportController::class, 'milestoneBreakdown'])->name('milestone-breakdown');

        Route::get('export/grades.csv', [ReportController::class, 'exportGrades'])->name('export.grades');
        Route::get('export/projects.csv', [ReportController::class, 'exportProjects'])->name('export.projects');
    });

    // -----------------------------------------------------------------
    // Module 7 — Archive & audit
    // -----------------------------------------------------------------
    Route::prefix('archive')->name('archive.')->group(function () {
        Route::get('/', [ArchiveController::class, 'index'])->name('index');
        Route::get('filters', [ArchiveController::class, 'filters'])->name('filters');
        Route::get('export.csv', [ArchiveController::class, 'export'])->name('export');
        Route::get('{archived}', [ArchiveController::class, 'show'])->name('show');

        Route::post('{archived}/restore', [ArchiveController::class, 'restore'])
            ->middleware('role:admin')
            ->name('restore');
    });

    Route::prefix('audit-logs')->name('audit-logs.')->group(function () {
        // A user's own history is always available to them
        Route::get('me', [ArchiveController::class, 'myAuditLog'])->name('me');

        Route::middleware('role:admin,coordinator')->group(function () {
            Route::get('/', [ArchiveController::class, 'auditLogs'])->name('index');
            Route::get('filters', [ArchiveController::class, 'auditFilters'])->name('filters');
            Route::get('for/{type}/{id}', [ArchiveController::class, 'auditForSubject'])->name('subject');
        });
    });

    // -----------------------------------------------------------------
    // Module 8 — Leaderboard management (staff side)
    // -----------------------------------------------------------------
    Route::prefix('leaderboards')->name('leaderboards.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('/', [LeaderboardController::class, 'index'])->name('index');
        Route::get('eligible', [LeaderboardController::class, 'eligible'])->name('eligible');

        Route::get('settings', [LeaderboardController::class, 'settings'])->name('settings');
        Route::put('settings', [LeaderboardController::class, 'updateSettings'])
            ->middleware('role:admin')
            ->name('settings.update');

        Route::get('preview/{slug}', [LeaderboardController::class, 'preview'])->name('preview');

        Route::post('/', [LeaderboardController::class, 'store'])->name('store');
        Route::get('{leaderboard}', [LeaderboardController::class, 'show'])->name('show');
        Route::patch('{leaderboard}', [LeaderboardController::class, 'update'])->name('update');
        Route::delete('{leaderboard}', [LeaderboardController::class, 'destroy'])->name('destroy');

        Route::post('{leaderboard}/build', [LeaderboardController::class, 'build'])->name('build');
        Route::post('{leaderboard}/publish', [LeaderboardController::class, 'publish'])->name('publish');
        Route::post('{leaderboard}/unpublish', [LeaderboardController::class, 'unpublish'])->name('unpublish');

        Route::patch('{leaderboard}/entries/{entry}', [LeaderboardController::class, 'updateEntry'])->name('entries.update');
    });
});
