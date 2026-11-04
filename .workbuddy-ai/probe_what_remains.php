<?php

/**
 * What is actually still in the schema after the user's clean-up?
 *
 * The user says they removed the leaderboard tables and kept only milestone,
 * milestone_template and milestone_review. Read the truth rather than the
 * migrations, which still describe tables that may no longer exist.
 */

use Illuminate\Support\Facades\DB;

$wanted = [
    // leaderboard family — the user says these are gone
    'leaderboard_settings', 'leaderboards', 'leaderboard_entries',
    // milestone family — the user says these stay, but which?
    'milestones', 'milestone_templates', 'milestone_template_items',
    'milestone_reviews', 'milestone_schedules',
    // neighbouring, to see what else moved
    'submission_files', 'submission_events', 'reminder_dispatches',
    'grading_scales', 'grade_schemes', 'rubrics',
];

$existing = DB::table('information_schema.tables')
    ->where('table_schema', 'public')
    ->where('table_type', 'BASE TABLE')
    ->pluck('table_name')
    ->all();

foreach ($wanted as $t) {
    if (in_array($t, $existing, true)) {
        $n = DB::table($t)->count();
        echo "PRESENT  {$t}  ({$n} rows)\n";
    } else {
        echo "GONE     {$t}\n";
    }
}

echo "\n--- every table still present ---\n";
$skip = '/cache|jobs|failed_jobs|migrations|sessions|password_reset|personal_access/i';
foreach ($existing as $t) {
    if (preg_match($skip, $t)) {
        continue;
    }
    echo "   {$t}\n";
}
