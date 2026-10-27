<?php

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AssessmentWindowController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\LecturerMarkController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\LampiranCController;
use App\Http\Controllers\Api\MilestoneReviewController;
use App\Http\Controllers\Api\OsheController;
use App\Http\Controllers\Api\MilestoneScheduleController;
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

            // Who the admin's intake actually enrolled. Registered inside this
            // group with the other staff-only reads rather than with the open
            // `{semester}` wildcard below, because it returns student records.
            Route::get('{semester}/students', [SemesterController::class, 'students'])
                ->name('students');
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

    /**
     * The five programmes FSKTM runs PSM for.
     *
     * Served from the `Programme` enum rather than hardcoded in two places, so a
     * form offering a programme and the rule that compares programmes cannot
     * drift apart. This matters more here than for ordinary reference data: the
     * supervision rule matches on these exact codes, so a form that let someone
     * type a code the enum does not recognise would create a pair the rule
     * silently permits rather than refuses.
     */
    Route::get('programmes', fn () => response()->json([
        'success' => true,
        'data'    => collect(\App\Enums\Programme::cases())->map(fn ($p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'short' => $p->shortLabel(),
        ])->all(),
    ]))->middleware('role:admin,coordinator,supervisor,student,lecturer')->name('programmes.index');

    // -----------------------------------------------------------------
    // Module 1/4 — Teaching sections and coursework marks
    // -----------------------------------------------------------------
    /**
     * The lecturer's own screen.
     *
     * A lecturer is deliberately outside PSM: they mark coursework for one
     * section of a service course and nothing else. These three routes are the
     * whole of their access — the section is resolved from the session rather
     * than from a parameter, so there is no id a lecturer could change to reach
     * a colleague's roster.
     *
     * Admins and coordinators reach the same endpoints to correct a mark, which
     * is why the role list is not `lecturer` alone. A supervisor is in the list
     * for the same reason as the lecturer: the staff who teach a section usually
     * supervise PSM projects as well, and `users.role` holds one value, so the
     * seating rather than the role decides — `LecturerMarkPolicy` requires an
     * appointment and refuses a supervisor who has none, so widening the
     * middleware to `supervisor` opens nothing on its own.
     */
    Route::middleware('role:admin,coordinator,lecturer,supervisor')->group(function () {
        Route::get('lecturer/section', [LecturerMarkController::class, 'mySection'])
            ->name('lecturer.section');
        Route::get('lecturer/roster', [LecturerMarkController::class, 'roster'])
            ->name('lecturer.roster');
        Route::put('lecturer/students/{studentProfileId}/marks', [LecturerMarkController::class, 'saveMarks'])
            ->name('lecturer.marks.save');

        /**
         * Section administration.
         *
         * The list is readable by a lecturer too — they need to know their own
         * section's name — but the policy narrows `view()` to the section they
         * own, and `manage()` / `assignStudents()` are admin and coordinator
         * only. Migration of a student between sections is an admin decision.
         */
        Route::get('sections', [LecturerMarkController::class, 'index'])->name('sections.index');
        Route::put('students/{studentProfileId}/section', [LecturerMarkController::class, 'assignStudent'])
            ->name('students.section.assign');
    });

    /**
     * Appointing a section's lecturer, and placing students in bulk.
     *
     * The middleware list mirrors the policy rather than widening it: an admin
     * appoints (`SectionPolicy::manage()`), and an admin or coordinator places
     * (`assignStudents()`). Declared before the `sections/{section}` route below
     * so that `sections/lecturers` is matched as the options list rather than as
     * a section whose id happens to be the string "lecturers" — the same reason
     * `sections/copy` and `sections/import` are declared above it too.
     */
    Route::get('sections/lecturers', [LecturerMarkController::class, 'lecturerOptions'])
        ->middleware('role:admin')
        ->name('sections.lecturer_options');

    Route::post('sections/import', [LecturerMarkController::class, 'importStudents'])
        ->middleware('role:admin,coordinator')
        ->name('sections.import');

    // Provisioning a term: one section by hand (`store`) or the previous term's
    // set in one click (`copy`). Both are admin-only, like appointing, because
    // they settle which sections exist — the same faculty decision.
    Route::post('sections/copy', [LecturerMarkController::class, 'copy'])
        ->middleware('role:admin')
        ->name('sections.copy');

    Route::post('sections', [LecturerMarkController::class, 'store'])
        ->middleware('role:admin')
        ->name('sections.store');

    // Appointing a section's lecturer is admin-only, per `SectionPolicy::manage()`.
    Route::put('sections/{section}', [LecturerMarkController::class, 'update'])
        ->middleware('role:admin')
        ->name('sections.update');

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

        /**
         * Withdraw a Lampiran A before it becomes a project.
         *
         * Open to a student and to a coordinator/admin, but **not** to a
         * supervisor — see `SupervisorAgreementPolicy::withdraw()`. The state
         * half of the rule (no project registered yet) is enforced in
         * `RegistrationService::withdrawAgreement()`.
         */
        Route::post('agreements/{agreement}/withdraw', [RegistrationController::class, 'withdraw'])
            ->middleware('role:student,coordinator,admin')
            ->name('agreements.withdraw');
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

        // The Lampiran that can be opened for each batch, so the create form
        // offers real forms rather than a hardcoded list that drifts from the
        // seeded rubrics.
        Route::get('forms', [AssessmentWindowController::class, 'forms'])
            ->middleware('role:admin,coordinator')
            ->name('forms');

        Route::get('/', [AssessmentWindowController::class, 'index'])
            ->middleware('role:admin,coordinator')
            ->name('index');
        Route::post('/', [AssessmentWindowController::class, 'store'])
            ->middleware('role:admin,coordinator')
            ->name('store');

        Route::get('{window}', [AssessmentWindowController::class, 'show'])->name('show');

        // The form's published marking period. Separate from open/close, so a
        // coordinator can state when a Lampiran closes before opening it.
        Route::patch('{window}', [AssessmentWindowController::class, 'update'])
            ->middleware('role:admin,coordinator')
            ->name('update');

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

        /**
         * The batch PSM 1 → PSM 2 rollover.
         *
         * Registered **before** the `{project}` wildcard below, or "rollover"
         * would be read as a project id — the same ordering rule `/semesters/current`
         * depends on. `/projects/rollover` is a literal segment and Laravel
         * matches routes in registration order, not by specificity.
         */
        Route::middleware('role:admin,coordinator')->group(function () {
            Route::get('rollover', [ProjectController::class, 'rolloverCandidates'])
                ->name('rollover.index');
            Route::post('rollover', [ProjectController::class, 'rollover'])
                ->name('rollover.store');

            /**
             * The verdict worklist.
             *
             * Also a literal segment, and also before the `{project}` wildcard
             * for the same reason `rollover` is: "verdicts" would otherwise be
             * read as a project id.
             */
            Route::get('verdicts', [ProjectController::class, 'verdictCandidates'])
                ->name('verdicts.index');
        });

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

            /**
             * The committee's verdict, and the repeat that follows a fail.
             *
             * `resolve` is the step the lifecycle was missing between "the marks
             * are in" and "this part is over": it records pass or fail and closes
             * the supervisor and panel attached to the project. `repeat` starts
             * the next attempt — which for PSM 1 means handing the student back
             * to registration rather than creating a project, because a PSM 1
             * project only exists once Lampiran B registers the title.
             */
            Route::post('{project}/resolve', [ProjectController::class, 'resolveProject'])
                ->name('resolve');
            Route::post('{project}/repeat', [ProjectController::class, 'repeatProject'])
                ->name('repeat');
        });

        // Milestones live under their project for a natural URL shape
        Route::get('{project}/milestones', [MilestoneController::class, 'index'])->name('milestones.index');
    });

    /**
     * Enrolments — the admin's start-of-term placement.
     *
     * A student's term used to live only on their project, so the cohort could
     * not be set up before anyone had registered a title, and a repeat had to
     * guess which term it belonged to. These routes are that missing verb: enrol
     * a student into a PSM part for a semester, and place a repeat into a real
     * term once one exists.
     *
     * Top level, **not** under `projects` — an enrolment exists before a project
     * does, so nesting it there would say the opposite of what the model means.
     *
     * `eligible` is a literal segment and is registered before any `{enrolment}`
     * wildcard, for the same reason `rollover` and `verdicts` are.
     */
    Route::prefix('enrolments')->name('enrolments.')->group(function () {
        Route::get('/', [EnrolmentController::class, 'index'])->name('index');

        Route::middleware('role:admin,coordinator')->group(function () {
            Route::get('eligible', [EnrolmentController::class, 'eligible'])->name('eligible');
            Route::post('/', [EnrolmentController::class, 'store'])->name('store');
            Route::post('{enrolment}/place', [EnrolmentController::class, 'place'])->name('place');
            Route::post('{enrolment}/withdraw', [EnrolmentController::class, 'withdraw'])->name('withdraw');
        });
    });

    // -----------------------------------------------------------------
    // Module 3 — The term's milestone calendar
    // -----------------------------------------------------------------
    // The faculty publishes one set of deadlines per batch per term, and every
    // student follows it. Read by anyone who works to the timetable; set only by
    // a coordinator, because a change moves the date for a whole cohort.
    Route::prefix('milestone-schedule')->name('milestone-schedule.')->group(function () {
        Route::get('/', [MilestoneScheduleController::class, 'index'])->name('index');

        Route::put('/', [MilestoneScheduleController::class, 'update'])
            ->middleware('role:admin,coordinator')
            ->name('update');

        // Re-seed from the template's own offsets. The escape hatch for a term
        // whose dates were set wrongly, and how a new term gets a calendar.
        Route::post('reset', [MilestoneScheduleController::class, 'reset'])
            ->middleware('role:admin,coordinator')
            ->name('reset');
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

        /**
         * The supervisor's endorsement of a proposal — the gate before the
         * title defence.
         *
         * `Submitted -> Reviewed`, and nothing else. The supervisor reads the
         * uploaded proposal and confirms it is ready to be examined; the panel
         * then sits and settles the title. Until this endpoint existed there was
         * no way to record that reading, so the panel could act on a document
         * its supervisor had never opened.
         */
        Route::post('{milestone}/endorse', [MilestoneController::class, 'endorse'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('endorse');

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

        // --- Lampiran R1: the supervisor's response to a submission --------
        // Not a milestone of its own and not a document the student files: the
        // R1 *is* the review. Its two checkboxes ('received' / 'reviewed for
        // improvement') decide whether the milestone settles or goes back, and
        // its scores are evidence only — they never reach a grade.
        //
        // No date middleware and no window check on purpose: chapter milestones
        // stay open, so a student may resubmit at any time and nothing closes at
        // the deadline. The due date still drives the at-risk report.
        //
        // Reading the history is open to anyone who can see the milestone (a
        // student needs to read why they were sent back); writing is narrowed by
        // MilestonePolicy::reviewR1 to the supervisor, coordinator or admin.
        Route::get('{milestone}/reviews', [MilestoneReviewController::class, 'index'])
            ->name('reviews.index');

        Route::post('{milestone}/reviews', [MilestoneReviewController::class, 'store'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('reviews.store');

        Route::post('{milestone}/reviews/{review}/signature', [MilestoneReviewController::class, 'storeSignature'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('reviews.signature.store');

        Route::get('{milestone}/reviews/{review}/signature', [MilestoneReviewController::class, 'showSignature'])
            ->name('reviews.signature');

        /**
         * The OSHE form's supervisor signature.
         *
         * The one milestone the supervisor *completes* rather than reviews: the
         * student files the filled form, the supervisor signs it and uploads the
         * signed copy back, which settles the milestone. Restricted by
         * MilestonePolicy::review() — the owning supervisor, a coordinator or an
         * administrator.
         */
        Route::post('{milestone}/oshe/sign', [OsheController::class, 'sign'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('oshe.sign');

        /**
         * Lampiran C — the corrections a conditional approval obliges, as a
         * two-party digital form: the student files it, the supervisor signs it.
         */
        Route::post('{milestone}/lampiran-c/form', [LampiranCController::class, 'file'])
            ->middleware('role:student')
            ->name('lampiran-c.file');

        Route::post('{milestone}/lampiran-c/sign', [LampiranCController::class, 'sign'])
            ->middleware('role:admin,coordinator,supervisor')
            ->name('lampiran-c.sign');
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
