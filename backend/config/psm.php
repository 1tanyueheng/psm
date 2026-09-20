<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PSM domain constants
    |--------------------------------------------------------------------------
    */

    // Module 1 — Roles
    'roles' => [
        'student',
        'supervisor',
        'coordinator',
        'examiner',
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

    // Module 4 — Grade banding
    'grade_bands' => [
        ['min' => 80, 'grade' => 'A',  'point' => 4.00, 'label' => 'Excellent'],
        ['min' => 75, 'grade' => 'A-', 'point' => 3.67, 'label' => 'Very good'],
        ['min' => 70, 'grade' => 'B+', 'point' => 3.33, 'label' => 'Good'],
        ['min' => 65, 'grade' => 'B',  'point' => 3.00, 'label' => 'Satisfactory'],
        ['min' => 60, 'grade' => 'B-', 'point' => 2.67, 'label' => 'Acceptable'],
        ['min' => 55, 'grade' => 'C+', 'point' => 2.33, 'label' => 'Weak'],
        ['min' => 50, 'grade' => 'C',  'point' => 2.00, 'label' => 'Pass'],
        ['min' => 47, 'grade' => 'C-', 'point' => 1.67, 'label' => 'Marginal fail'],
        ['min' => 40, 'grade' => 'D',  'point' => 1.00, 'label' => 'Fail'],
        ['min' => 0,  'grade' => 'F',  'point' => 0.00, 'label' => 'Fail'],
    ],

    // Module 6 — Reminder schedule
    'reminder_days_before' => array_map(
        'intval',
        explode(',', (string) env('REMINDER_DAYS_BEFORE', '7,3,1'))
    ),
    'reminder_send_hour' => (int) env('REMINDER_SEND_HOUR', 8),

    // Module 3 — Submission constraints
    'submission' => [
        'max_mb'             => (int) env('SUBMISSION_MAX_MB', 25),
        'allowed_extensions' => explode(',', (string) env(
            'SUBMISSION_ALLOWED_EXTENSIONS',
            'pdf,doc,docx,zip,txt,md,py,java,js,sql,pptx,xlsx'
        )),
        'late_penalty_percent_per_day' => (float) env('SUBMISSION_LATE_PENALTY', 0),
        'late_grace_hours'             => (int) env('SUBMISSION_LATE_GRACE_HOURS', 0),
        'allow_resubmission_when'      => ['rejected'], // statuses that reopen a milestone
    ],

    // Module 4 — How much the assessment aggregate is blended with milestone
    // completion. The aggregate carries the bulk of the mark; milestones act
    // as a gate, so a student who never submitted cannot score 100 overall.
    // Set to 0 to grade on assessor marks alone.
    'milestone_blend_percent' => (float) env('PSM_MILESTONE_BLEND_PERCENT', 20),

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
