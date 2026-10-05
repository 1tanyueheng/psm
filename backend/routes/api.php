<?php

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AssessmentWindowController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SemesterController;
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
    // Module 3 — Academic semesters
    //
    // The scoping unit for everything below: a session holds two terms, and a
    // term holds the PSM 1 and PSM 2 batches that run concurrently in it.
    //
    // Reads are open to every authenticated user because the term list feeds the
    // filter dropdowns on the project list, the reports and the registration
    // screen. Writes are behind coordinator roles, and are NOT expressed here as
    // route middleware for grade release — SemesterController gates that behind
    // SemesterPolicy::releaseMarks so a coordinator editing a term's dates cannot
    // publish its results as a side effect.
    // -----------------------------------------------------------------
    Route::prefix('semesters')->name('semesters.')->group(function () {
        // Before the {semester} wildcard, or "current" is read as an id.
        Route::get('current', [SemesterController::class, 'current'])->name('current');

        Route::get('/', [SemesterController::class, 'index'])->name('index');

        Route::middleware('role:admin,coordinator')->group(function () {
            Route::post('/', [SemesterController::class, 'store'])->name('store');

            Route::patch('{semester}', [SemesterController::class, 'update'])->name('update');
            Route::post('{semester}/close', [SemesterController::class, 'close'])->name('close');
            // The counterpart to close: without it a closed term could never be
            // brought back, through the API or the screen.
            Route::post('{semester}/reopen', [SemesterController::class, 'reopen'])->name('reopen');

            Route::post('{semester}/registration', [SemesterController::class, 'registration'])
                ->name('registration');
            // There is no `release-marks` route. A mark is published by the
            // submission that completes it (MarkVisibilityService), so a
            // coordinator has nothing to release. See SemesterController.
        });

        Route::get('{semester}', [SemesterController::class, 'show'])->name('show');
    });

    // -----------------------------------------------------------------
    // Module 2 — Assignments (supervisor pairing, examiner allocation)
    // -----------------------------------------------------------------
    Route::prefix('assignments')->name('assignments.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('supervisions', [AssignmentController::class, 'indexSupervisions'])->name('supervisions.index');
        Route::post('supervisions', [AssignmentController::class, 'storeSupervision'])->name('supervisions.store');
        Route::delete('supervisions/{assignment}', [AssignmentController::class, 'destroySupervision'])->name('supervisions.destroy');

        Route::get('supervisors', [AssignmentController::class, 'indexSupervisors'])->name('supervisors.index');
        Route::post('supervisors/{supervisor}/capacity', [AssignmentController::class, 'setCapacity'])->name('supervisors.capacity');
        Route::get('suggest-supervisors/{student}', [AssignmentController::class, 'suggestSupervisors'])->name('suggest-supervisors');

        Route::get('examiners', [AssignmentController::class, 'indexExaminers'])->name('examiners.index');
        Route::post('examiners', [AssignmentController::class, 'storeExaminer'])->name('examiners.store');
        Route::delete('examiners/{assignment}', [AssignmentController::class, 'destroyExaminer'])->name('examiners.destroy');

        // One student's panel, seated as a **pair**.
        //
        // The routes above seat a single examiner against a project. A panel is
        // two people and belongs to a student — the same pair decides the
        // proposal and the final mark, and the proposal is reviewed before the
        // project exists. `showPanel` returns the candidates with the student's
        // own supervisor already excluded.
        Route::get('students/{student}/panel', [AssignmentController::class, 'showPanel'])->name('students.panel.show');
        Route::post('students/{student}/panel', [AssignmentController::class, 'storePanel'])->name('students.panel.store');

        // The cohort view of the same question: every student beside every
        // examiner who could examine them.
        Route::get('panel-matching', [AssignmentController::class, 'panelMatching'])->name('panel-matching');

        // Fixed examiner pairs — the faculty's model: examiners are grouped
        // into standing pairs and batches of students are assigned to a pair.
        Route::get('examiner-pairs', [AssignmentController::class, 'indexExaminerPairs'])->name('examiner-pairs.index');
        Route::post('examiner-pairs/auto-assign', [AssignmentController::class, 'autoAssignExaminerPairs'])
            ->name('examiner-pairs.auto-assign');

        Route::get('students/unassigned', [AssignmentController::class, 'unassignedStudents'])->name('students.unassigned');
    });

    // Reference data, readable by anyone who assigns or marks
    Route::get('assignments/expertise-areas', [AssignmentController::class, 'expertiseAreas'])
        ->middleware('role:admin,coordinator,supervisor')
        ->name('assignments.expertise-areas');

    // -----------------------------------------------------------------
    // Module 2/3 — Registration flow (Lampiran A & B)
    // -----------------------------------------------------------------
    // Lampiran A drives the supervisor<->student pairing and fixes the agreed
    // title; Lampiran B registers that title as a project. There is no approval
    // step between them — the supervisor's acknowledgement is the gate, and the
    // title is judged afterwards by the panel at the project's proposal
    // milestone (see the milestone routes below).
    Route::prefix('registrations')->name('registrations.')->group(function () {
        Route::get('agreements', [RegistrationController::class, 'indexAgreements'])
            ->name('agreements.index');

        Route::post('agreements', [RegistrationController::class, 'storeAgreement'])
            ->middleware('role:student')
            ->name('agreements.store');

        Route::get('agreements/{agreement}', [RegistrationController::class, 'showAgreement'])
            ->name('agreements.show');

        Route::post('agreements/{agreement}/acknowledge', [RegistrationController::class, 'acknowledge'])
            ->middleware('role:supervisor')
            ->name('agreements.acknowledge');

        Route::post('agreements/{agreement}/title-proposal', [RegistrationController::class, 'storeTitleProposal'])
            ->middleware('role:student')
            ->name('agreements.title-proposal');
    });

    // -----------------------------------------------------------------
    // Module 4 — the assessment window
    // -----------------------------------------------------------------
    // The coordinator opens it, which allocates every Lampiran the batch needs
    // and starts accepting marks; assessors then pick a student and file their
    // form. Closing stops new marks but keeps what was filed.
    // -----------------------------------------------------------------
    Route::prefix('assessment-windows')->name('assessment-windows.')->group(function () {
        // Registered before the {window} wildcard, or "current" reads as an id.
        Route::get('current', [AssessmentWindowController::class, 'current'])->name('current');

        Route::get('/', [AssessmentWindowController::class, 'index'])
            ->middleware('role:admin,coordinator')
            ->name('index');
        Route::post('/', [AssessmentWindowController::class, 'store'])
            ->middleware('role:admin,coordinator')
            ->name('store');

        Route::get('{window}', [AssessmentWindowController::class, 'show'])->name('show');

        Route::post('{window}/open', [AssessmentWindowController::class, 'open'])
            ->middleware('role:admin,coordinator')
            ->name('open');
        Route::post('{window}/close', [AssessmentWindowController::class, 'close'])
            ->middleware('role:admin,coordinator')
            ->name('close');
    });

    // -----------------------------------------------------------------
    // Module 3 — Projects
    // -----------------------------------------------------------------
    Route::prefix('projects')->name('projects.')->group(function () {
        // Scoped by Project::visibleTo() inside the controller
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('options', [ProjectController::class, 'options'])->name('options');
        Route::get('summary', [ProjectController::class, 'summary'])->name('summary');
        Route::get('registration-meta', [ProjectController::class, 'registrationMeta'])->name('registration-meta');

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

            // PSM 1 → PSM 2. One title across two continuous terms, so PSM 2 is
            // reached by progressing the student, not by a second Lampiran A.
            Route::post('{project}/progress-to-psm2', [ProjectController::class, 'progressToPsm2'])
                ->name('progress-to-psm2');
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

        // --- The proposal milestone: the title decision -------------------
        // The panel's verdict on the proposal, which settles the title and
        // gates the rest of the chain. MilestoneService refuses the generic
        // approve/request-revision routes for the proposal, so this is the only
        // way its verdict is recorded. The policy narrows it to the seated
        // examiners (or a coordinator recording it on their behalf).
        //
        // `supervisor`, not `examiner` — there is no examiner role, and leaving
        // the old name here locked the seated panel out of the very decision the
        // screen exists to record. The middleware denies anyone whose role is not
        // listed, so the failure was a flat 403 rather than anything that pointed
        // at the cause.
        Route::post('{milestone}/title-decision', [MilestoneController::class, 'titleDecision'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('title-decision');

        // Lampiran C — the corrections a conditional approval required.
        Route::post('{milestone}/lampiran-c', [MilestoneController::class, 'fileLampiranC'])
            ->name('lampiran-c');

        // Change the title after a rejection; the milestone reopens for a
        // fresh decision.
        Route::post('{milestone}/change-title', [MilestoneController::class, 'changeTitle'])
            ->name('change-title');

        Route::post('{milestone}/deadline', [MilestoneController::class, 'changeDeadline'])
            ->middleware('role:admin,coordinator')
            ->name('deadline');
    });

    /**
     * The panel member's own proposals to decide.
     *
     * A panel is a *seating*, so the audience is the academic-staff role rather
     * than a separate one — and the policy narrows it further to the people
     * actually seated on each project. Without this the milestone screen had the
     * form but nothing pointed a panel member at it.
     */
    Route::get('panel/proposals', [MilestoneController::class, 'panelProposals'])
        ->middleware('role:admin,coordinator,supervisor')
        ->name('panel.proposals');

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

        // Lampiran H — the supervisor's PSM 2 progress report, taken twice.
        Route::post('progress-report', [EvaluationController::class, 'storeProgressReport'])
            ->middleware('role:admin,coordinator')
            ->name('progress-report');

        Route::get('{evaluation}', [EvaluationController::class, 'show'])->name('show');
        Route::put('{evaluation}/marks', [EvaluationController::class, 'saveMarks'])->name('marks');
        Route::post('{evaluation}/submit', [EvaluationController::class, 'submit'])->name('submit');
        Route::post('{evaluation}/declare-conflict', [EvaluationController::class, 'declareConflict'])->name('declare-conflict');
    });

    // -----------------------------------------------------------------
    // Module 4 — Mark submission lifecycle (coordinator)
    // -----------------------------------------------------------------
    Route::prefix('projects/{project}/students/{student}/mark-submission')
        ->middleware('role:admin,coordinator')
        ->name('mark-submission.')
        ->group(function () {
            Route::post('open', [EvaluationController::class, 'openMarkSubmission'])->name('open');
            Route::get('/', [EvaluationController::class, 'showMarkSubmission'])->name('show');
            Route::post('lock', [EvaluationController::class, 'lockMarkSubmission'])->name('lock');
            Route::post('unlock', [EvaluationController::class, 'unlockMarkSubmission'])->name('unlock');
        });

    Route::get('rubrics', [EvaluationController::class, 'rubrics'])->name('rubrics.index');

    Route::prefix('grades')->name('grades.')->middleware('role:admin,coordinator')->group(function () {
        Route::get('/', [EvaluationController::class, 'grades'])->name('index');
        Route::post('{grade}/recompute', [EvaluationController::class, 'recompute'])->name('recompute');
        // No `{grade}/release`. Marks publish themselves; see
        // MarkVisibilityService. The coordinator's grade list is read-only.
    });

    Route::get('projects/{project}/grades', [EvaluationController::class, 'projectGrades'])->name('projects.grades');

    // Deliberately not coordinator-only: a student reads their own breakdown.
    // The controller scopes a student to their own roster entry.
    Route::get('projects/{project}/students/{student}/mark-breakdown', [EvaluationController::class, 'markBreakdown'])
        ->name('projects.students.mark-breakdown');
    // No `grades/release-all` either — same reason as `{grade}/release`.
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
        Route::get('mark-distribution', [ReportController::class, 'markDistribution'])->name('mark-distribution');
        Route::get('milestone-breakdown', [ReportController::class, 'milestoneBreakdown'])->name('milestone-breakdown');

        Route::get('export/marks.csv', [ReportController::class, 'exportMarks'])->name('export.marks');
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
