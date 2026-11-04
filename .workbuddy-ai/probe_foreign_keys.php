<?php

/**
 * Every foreign key in the live schema, which is what a class diagram's
 * associations should be derived from.
 *
 * The diagram has been drawn from memory twice. A class diagram whose arrows do
 * not match the constraints is worse than a sparse one, because a reader
 * assumes it is the schema.
 */

use Illuminate\Support\Facades\DB;

$rows = DB::select(<<<'SQL'
    select
        tc.table_name        as child,
        kcu.column_name      as child_column,
        ccu.table_name       as parent,
        ccu.column_name      as parent_column,
        rc.delete_rule       as on_delete,
        (select count(*) from information_schema.table_constraints x
         where x.constraint_name = tc.constraint_name
           and x.constraint_type = 'UNIQUE') as is_unique_col
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu
      on kcu.constraint_name = tc.constraint_name
     and kcu.table_schema = tc.table_schema
    join information_schema.constraint_column_usage ccu
      on ccu.constraint_name = tc.constraint_name
     and ccu.table_schema = tc.table_schema
    join information_schema.referential_constraints rc
      on rc.constraint_name = tc.constraint_name
     and rc.constraint_schema = tc.table_schema
    where tc.constraint_type = 'FOREIGN KEY'
      and tc.table_schema = 'public'
    order by tc.table_name, kcu.column_name
SQL);

$byParent = [];
foreach ($rows as $r) {
    $byParent[$r->parent][] = $r;
}

echo 'total foreign keys: '.count($rows)."\n\n";
foreach ($byParent as $parent => $list) {
    echo "{$parent}\n";
    foreach ($list as $r) {
        printf("    <- %-28s %-26s %s\n", $r->child, $r->child_column, $r->on_delete ?? '');
    }
}

echo "\n--- tables with no incoming FK (roots / lookups) ---\n";
$tables = DB::table('information_schema.tables')
    ->where('table_schema', 'public')
    ->where('table_type', 'BASE TABLE')
    ->pluck('table_name');
foreach ($tables as $t) {
    if (! isset($byParent[$t])) {
        echo "    {$t}\n";
    }
}
