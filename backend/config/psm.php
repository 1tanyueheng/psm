<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PSM domain constants
    |--------------------------------------------------------------------------
    */

    // Module 1 — Roles
    //
    // No `examiner`: sitting on a panel is a seating, not a role, and it is
    // modelled by `examiner_assignments`. Academic staff supervise their own
    // students and may additionally examine someone else's.
    'roles' => [
        'student',
        'supervisor',
        'coordinator',
        'admin',
    ],

    // Module 3 — Project categories drive milestone + rubric templates
    'categories' => [
        'system'   => 'System Development',
        'research' => 'Research',
    ],

    // Module 3 — Milestone lifecycle
    'milestone_statuses' => [
        'pending'   => 'Not started',
        'open'      => 'Open for submission',
        'submitted' => 'Submitted',
        'reviewed'  => 'Reviewed',
        'approved'  => 'Approved',
        'rejected'  => 'Revision required',
        'overdue'   => 'Overdue',
    ],

    // Module 1 — Account lifecycle
    'account_statuses' => [
        'active'    => 'Active',
        'inactive'  => 'Inactive',
        'suspended' => 'Suspended',
        'pending'   => 'Pending verification',
    ],

    /**
     * Module 1/2 — the password a newly created account starts with.
     *
     * Accounts are created by an admin who only has the person's institutional
     * email address; there is nothing to send them yet, and no mail transport to
     * send it with on a fresh deploy. So an account starts on a known default and
     * the holder resets it themselves — `POST /api/auth/forgot-password` already
     * covers that path, and the password reset emails route through the normal
     * mailer once one is configured.
     *
     * `must_change_password` is deliberately NOT set for these accounts: forcing
     * a change would lock the person out of everything except the change form
     * (see ForcePasswordChange), which is the opposite of "log in and look
     * around". Flip it on in `UserController::store()` if the faculty wants
     * first-login rotation instead.
     *
     * Override per deployment with PSM_DEFAULT_PASSWORD. Changing it does not
     * touch existing accounts.
     */
    'default_user_password' => env('PSM_DEFAULT_PASSWORD', 'password'),

    // Module 3 — PSM parts
    'psm_parts' => [
        'PSM1',
        'PSM2',
    ],

    // Module 4 — Assessor types contributing to the aggregate
    'assessor_types' => [
        'supervisor' => 'Supervisor',
        'examiner'   => 'Examiner',
        'coordinator'=> 'Coordinator',
    ],

    // Module 4 — Weighted component kinds inside a rubric
    'component_kinds' => [
        'report',
        'presentation',
        'demo',
        'code',
        'logbook',
        'proposal',
        'other',
    ],

    // Module 2 — Default limits enforced unless coordinator overrides
    'supervisor_max_capacity' => (int) env('SUPERVISOR_MAX_CAPACITY', 8),
    'student_default_max_supervisors' => 2,

    /**
     * Module 2 — supervision capacity, counted PER PSM PART rather than globally.
     *
     * FSKTM runs PSM 1 and PSM 2 concurrently in the same semester from the same
     * pool of supervisors. A single global cap made that impossible to staff: a
     * supervisor carrying 4 PSM 1 students showed as "4/8" but was still one
     * short of the flat limit, and one carrying 5 + 3 read as "8/8 — full" and
     * was refused any further allocation even though each batch individually had
     * room. The two parts are separate pieces of work with separate milestone
     * chains, so they get separate caps.
     *
     * `BOTH` pairings (a supervisor attached to a student across both parts)
     * count against both caps — that is the point of a BOTH assignment.
     *
     * `supervisor_max_capacity` above remains the aggregate ceiling across both
     * parts; these two values normally sum to it. A per-supervisor override on
     * `supervisor_profiles.max_supervisees_psm1/psm2` takes precedence.
     */
    'supervisor_capacity' => [
        'PSM1' => (int) env('PSM_SUPERVISOR_CAPACITY_PSM1', 5),
        'PSM2' => (int) env('PSM_SUPERVISOR_CAPACITY_PSM2', 5),
    ],

    /**
     * Module 2/4 — how many students one examiner pair may carry per part.
     *
     * A pair is a standing allocation, not a per-project choice, so it needs a
     * ceiling: without one a single panel silently ends up examining an entire
     * cohort while the other panels sit idle.
     */
    'examiner_capacity' => [
        'PSM1' => (int) env('PSM_EXAMINER_CAPACITY_PSM1', 10),
        'PSM2' => (int) env('PSM_EXAMINER_CAPACITY_PSM2', 10),
    ],

    /**
     * Module 3 — how many terms PSM 1 → PSM 2 progression skips.
     *
     * PSM 1 and PSM 2 are one project across **two continuous semesters**, so
     * the default is 0: PSM 2 runs in the term immediately after PSM 1. A value
     * of 1 leaves one term between the parts, for a faculty that does not run
     * them back to back.
     *
     * Counted over the recorded semesters in chronological order, so "the next
     * term" is the next row — Semester II of the same session where the faculty
     * runs two terms a year, or the following session where it does not.
     */
    'progression_term_gap' => (int) env('PSM_PROGRESSION_TERM_GAP', 0),

    /**
     * Module 4 — how many examiners sit on a project's panel.
     *
     * The PSM requirement fixes this at two: "Two (2) Examiners are assigned
     * per student", and both grade the end-of-semester seminar, with the
     * system averaging the two marks. It is configuration rather than a
     * constant because the faculty may change the panel size between
     * cohorts, and because the evaluation arithmetic already averages
     * whatever number of examiners it is given.
     */
    'examiner_panel_size' => (int) env('EXAMINER_PANEL_SIZE', 2),

    /**
     * Module 4/5 — the pass mark, and why there is now one.
     *
     * This used to read "no grade bands": the released total was not on a
     * 0-100 scale, because the forms the system scored carried only part of the
     * assessment and the remainder was marked outside it. Mapping a 0-65 figure
     * onto A/B/C would indeed have invented a grade.
     *
     * That premise no longer holds. PSM 1 and PSM 2 both total exactly 100 —
     * coursework 35 + Lampiran E 35 + Lampiran I 30, and G 50 + H1 5 + H2 5 +
     * J 40 — so a released mark *is* a percentage, and asking whether it clears
     * the faculty's pass mark is a meaningful question rather than a banding.
     *
     * A pass/fail split is not a letter grade. It names one boundary the faculty
     * already applies, and reports need it: a cohort's pass rate is the first
     * number anyone asks for, and it could not be computed at all while the
     * system refused to have an opinion about 40.
     *
     * Configurable because it is a faculty regulation, not a constant of the
     * code — if the boundary moves, this is the one place to change.
     */
    'pass_mark' => (float) env('PSM_PASS_MARK', 40),

    // Module 6 — Reminder schedule
    'reminder_days_before' => array_map(
        'intval',
        explode(',', (string) env('REMINDER_DAYS_BEFORE', '7,3,1'))
    ),
    'reminder_send_hour' => (int) env('REMINDER_SEND_HOUR', 8),

    // Module 3 — Submission constraints
    'submission' => [
        'max_mb'             => (int) env('SUBMISSION_MAX_MB', 25),
        // Used when a milestone row records no `max_files` of its own.
        'default_max_files'  => (int) env('SUBMISSION_DEFAULT_MAX_FILES', 3),
        'allowed_extensions' => explode(',', (string) env(
            'SUBMISSION_ALLOWED_EXTENSIONS',
            'pdf,doc,docx,zip,txt,md,py,java,js,sql,pptx,xlsx'
        )),
        'late_penalty_percent_per_day' => (float) env('SUBMISSION_LATE_PENALTY', 0),
        'late_grace_hours'             => (int) env('SUBMISSION_LATE_GRACE_HOURS', 0),
        'allow_resubmission_when'      => ['rejected'], // statuses that reopen a milestone
    ],

    /**
     * Module 4 — Assessment weights, by official form (Lampiran code).
     *
     * Both parts now total 100. PSM 1 was assembled from the two Lampiran alone
     * and so reached only 65, on the understanding that the remainder was marked
     * outside the system — which is exactly the gap the coursework component
     * fills. It is no longer outside the system, so PSM 1 is whole:
     *
     *   PSM 1   Coursework 35 (the section lecturer: quiz + 3 assignments)
     *           + Lampiran E 35 (supervisor)
     *           + Lampiran I 30 (examiners)                            = 100
     *   PSM 2   Lampiran G 50 + Lampiran H1 5 + Lampiran H2 5
     *           + Lampiran J 40 (examiners)                            = 100
     *
     * Coursework is a form code rather than an assessor type so the aggregate can
     * weight it like any other component. It carries no rubric and no evaluation
     * row — see `LecturerMark` — and `EvaluationService::computeFinalGrade()`
     * injects it into the per-form totals.
     *
     * Keyed by FORM, not by assessor role. G, H1 and H2 are all supervisor
     * forms carrying different shares, so a per-role scheme would collapse them
     * into a single bucket and lose the split.
     *
     * H1 and H2 are the two PSM 2 progress reports — Laporan Kemajuan 1 and
     * Laporan Kemajuan 2. They are separate *form codes* rather than two
     * instances of one form, because the aggregate groups by form code: sharing
     * a code made the two reports average into a single 5% component instead of
     * counting 5% each, which is not what the faculty awards.
     *
     * The coordinator appears nowhere: they moderate the process, they do not
     * award marks.
     */
    'assessment_weights' => [
        'PSM1' => ['E' => 35.0, 'I' => 30.0, 'CW' => 35.0],
        'PSM2' => ['G' => 50.0, 'H1' => 5.0, 'H2' => 5.0, 'J' => 40.0],
    ],

    /**
     * Module 4 — the coursework component (`CW`).
     *
     * A lecturer marks four items for one section of a service course that runs
     * alongside PSM 1. They are not Lampiran: there is no rubric behind them,
     * no assessor type, and no evaluation row — the lecturer keys a number per
     * item and it is stored on `lecturer_marks`. The four sum to exactly the
     * `CW` weight above, and `LecturerMark::COMPONENTS` is the single
     * declaration of both the names and the maxima so the form, the validation
     * and the aggregate cannot disagree.
     *
     * **These are fixed by faculty decision, not configurable.** The split is
     * what the course coordinator publishes, so it is a constant rather than
     * settings a term could drift from.
     */
    'coursework' => [
        'form_code' => 'CW',
        'label'     => 'Coursework',
        'components' => [
            'quiz'          => ['label' => 'Quiz',         'max' => 5.0],
            'assignment_c'  => ['label' => 'Assignment-C', 'max' => 12.0],
            'assignment_p'  => ['label' => 'Assignment-P', 'max' => 9.0],
            'assignment_a'  => ['label' => 'Assignment-A', 'max' => 9.0],
        ],
    ],

    /**
     * Module 4 — How much the assessment aggregate is blended with milestone
     * completion.
     *
     * Now 0. It used to be 20 so that a student who never submitted could not
     * score 100. That job is done by the gate instead: Lampiran E cannot be
     * completed until every milestone is approved, so an unsubmitted project
     * has no supervisor mark at all. Blending progress into the mark on top of
     * the explicit form weights would make the released mark disagree with the
     * published weighting.
     */
    'milestone_blend_percent' => (float) env('PSM_MILESTONE_BLEND_PERCENT', 0),

    // Module 8 — Public recognition / leaderboard
    'leaderboard' => [
        // How many projects appear on the podium
        'top_n' => (int) env('LEADERBOARD_TOP_N', 3),

        // A result may only be shown publicly once this many assessors have
        // reported on it — a single marker is not enough evidence to rank.
        'min_assessors' => (int) env('LEADERBOARD_MIN_ASSESSORS', 2),

        // Where the public page lives. Used to build absolute links in
        // notifications and emails pointing at the no-login board.
        'base_path' => env('LEADERBOARD_BASE_PATH', '/leaderboard'),
    ],

    // Module 7 — Audit retention
    'audit' => [
        'retain_years'    => (int) env('AUDIT_RETAIN_YEARS', 7),
        'log_reads'       => (bool) env('AUDIT_LOG_READS', false),
        'excluded_fields' => ['password', 'password_confirmation', 'remember_token', 'two_factor_secret'],
    ],

];
