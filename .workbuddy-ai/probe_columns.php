<?php

/**
 * Columns for the classes the widened class diagram needs, so its members are
 * the schema's and not somebody's memory of it.
 */

use Illuminate\Support\Facades\DB;

$wanted = [
    'supervisor_agreements', 'examiner_pairs', 'examiner_assignments',
    'assessment_windows', 'mark_submissions', 'submission_files',
    'submission_events', 'milestone_reviews', 'milestone_schedules',
    'rubric_templates', 'rubric_components', 'rubric_criteria',
    'lecturer_marks', 'sections', 'student_enrolments',
];

foreach ($wanted as $table) {
    $cols = DB::table('information_schema.columns')
        ->where('table_name', $table)
        ->where('table_schema', 'public')
        ->orderBy('ordinal_position')
        ->get(['column_name', 'data_type', 'character_maximum_length', 'is_nullable']);

    echo "=== {$table} ===\n";

    foreach ($cols as $c) {
        if (in_array($c->column_name, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        $type = $c->data_type;
        if ($c->character_maximum_length) {
            $type .= '('.$c->character_maximum_length.')';
        }

        printf("    %-28s %-18s %s\n", $c->column_name, $type,
            $c->is_nullable === 'YES' ? 'nullable' : '');
    }

    echo "\n";
}
