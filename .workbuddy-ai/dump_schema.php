"""Dump the real column definitions for the Chapter 4 data dictionary.

Reads `information_schema.columns` so the type and size in Table 4.x are what
PostgreSQL actually holds, not what a migration meant. Writes JSON that
`build_chapter4.py` consumes.

Run:  docker cp to psm-app, `php artisan tinker dump_schema.php`, read the JSON.
"""
<?php

$tables = [
    'users', 'student_profiles', 'supervisor_profiles', 'projects',
    'supervisor_agreements', 'lampiran_b_forms', 'milestones',
    'submission_files', 'evaluations', 'evaluation_scores', 'mark_submissions',
    'final_grades', 'student_enrolments', 'sections', 'lecturer_marks',
    'examiner_assignments', 'notifications', 'audit_logs',
];

$out = [];

foreach ($tables as $table) {
    $cols = DB::select("
        select column_name, data_type, character_maximum_length, numeric_precision,
               is_nullable, column_default
        from information_schema.columns
        where table_schema = 'public' and table_name = ?
        order by ordinal_position
    ", [$table]);

    if (! $cols) {
        $out[$table] = null;
        continue;
    }

    $out[$table] = [];
    foreach ($cols as $c) {
        $type = strtoupper($c->data_type);
        $size = '';
        if ($c->character_maximum_length) {
            $size = (string) $c->character_maximum_length;
        } elseif ($c->numeric_precision && in_array($type, ['INTEGER', 'BIGINT', 'SMALLINT'], true)) {
            $size = '11';
        }

        $out[$table][] = [
            'name'    => $c->column_name,
            'type'    => $type,
            'size'    => $size,
            'null'    => $c->is_nullable === 'YES',
            'default' => $c->column_default,
        ];
    }
}

// Keys, so the dictionary can say Primary / Foreign rather than guess.
$pk = [];
foreach ($tables as $table) {
    $rows = DB::select("
        select a.attname
        from pg_index i
        join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
        where i.indrelid = ?::regclass and i.indisprimary
    ", [$table]);
    $pk[$table] = array_map(fn ($r) => $r->attname, $rows);
}

$fk = [];
$rows = DB::select("
    select tc.table_name, kcu.column_name, ccu.table_name as foreign_table
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu
      on kcu.constraint_name = tc.constraint_name
    join information_schema.constraint_column_usage ccu
      on ccu.constraint_name = tc.constraint_name
    where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public'
");
foreach ($rows as $r) {
    $fk[$r->table_name][$r->column_name] = $r->foreign_table;
}

file_put_contents('/var/www/html/schema.json', json_encode([
    'columns' => $out,
    'primary' => $pk,
    'foreign' => $fk,
], JSON_PRETTY_PRINT));

echo "wrote schema.json\n";
