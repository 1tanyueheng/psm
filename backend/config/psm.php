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

    // Module 4 — no grade bands.
    //
    // The system releases a mark, not a grade. The forms it scores carry only
    // part of the assessment (the remainder is marked outside the system), so
    // the released total is not on a 0-100 scale and mapping it onto A/B/C
    // bands would invent a grade the faculty never awarded.

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
     * These are the shares the system is responsible for. The remainder of the
     * official weighting is marked outside it — by the lecturer in class — so
     * the totals are deliberately below 100:
     *
     *   PSM 1   Lampiran E 35 (supervisor) + Lampiran I 30 (examiners)  = 65
     *   PSM 2   Lampiran G 50 + Lampiran H 5 (supervisor)
     *           + Lampiran J 40 (examiners)                            = 95
     *
     * Keyed by FORM, not by assessor role. G and H are both supervisor forms
     * carrying different shares, so a per-role scheme would collapse them into
     * a single bucket and lose the 50/5 split.
     *
     * The coordinator appears nowhere: they moderate the process, they do not
     * award marks.
     */
    'assessment_weights' => [
        'PSM1' => ['E' => 35.0, 'I' => 30.0],
        'PSM2' => ['G' => 50.0, 'H' => 5.0,  'J' => 40.0],
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
