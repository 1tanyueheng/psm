<?php

/** Columns for the tables the class diagram disagreed with. */

use Illuminate\Support\Facades\DB;

foreach (['evaluations', 'supervision_assignments', 'notifications', 'final_grades'] as $t) {
    echo "=== {$t} ===\n";
    foreach (DB::table('information_schema.columns')
        ->where('table_name', $t)->where('table_schema', 'public')
        ->orderBy('ordinal_position')->get(['column_name', 'data_type']) as $c) {
        printf("    %-28s %s\n", $c->column_name, $c->data_type);
    }
    echo "\n";
}

echo "=== self-referencing FKs that exist ===\n";
foreach (DB::select("select tc.table_name as t, kcu.column_name as c
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
    join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
    where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public'
      and ccu.table_name = tc.table_name") as $r) {
    echo "    {$r->t}.{$r->c}\n";
}
