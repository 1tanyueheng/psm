<?php

/**
 * Is there a table for the reports UC-05 generates?
 *
 * Read-only. Looks for anything that stores a generated report, and lists the
 * tables the reporting service reads from, so the answer is the schema's.
 */

use Illuminate\Support\Facades\DB;

$tables = DB::table('information_schema.tables')
    ->where('table_schema', 'public')
    ->where('table_type', 'BASE TABLE')
    ->orderBy('table_name')
    ->pluck('table_name');

echo "tables mentioning report/export/leaderboard/grade:\n";
foreach ($tables as $t) {
    if (preg_match('/report|export|leaderboard|grade|mark_submission/i', $t)) {
        $n = DB::table($t)->count();
        echo "   {$t}  ({$n} rows)\n";
    }
}

echo "\nall tables (" . count($tables) . "):\n";
foreach ($tables as $t) {
    if (preg_match('/cache|jobs|failed|migrations|sessions|password_reset|personal_access/i', $t)) {
        continue;
    }
    echo "   {$t}\n";
}
