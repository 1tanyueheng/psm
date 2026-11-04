<?php

/**
 * What would it cost to stop drawing every user_id?
 *
 * The diagram is messy because `users` is the hub of 22 foreign keys. The obvious
 * relief is to keep `users` for identity only and split the per-role data into
 * the profiles. That is only worth doing if the profiles already exist and are
 * already loaded on every screen — so read the truth rather than assume it.
 */

use Illuminate\Support\Facades\DB;

echo "users table:\n";
foreach (DB::table('information_schema.columns')
    ->where('table_name', 'users')->where('table_schema', 'public')
    ->orderBy('ordinal_position')->get(['column_name', 'data_type', 'is_nullable']) as $c) {
    printf("  %-28s %-22s null=%s\n", $c->column_name, $c->data_type, $c->is_nullable);
}

echo "\nrows per table:\n";
foreach ([
    'users', 'student_profiles', 'supervisor_profiles',
    'coordinator_scopes', 'user_profiles',
] as $t) {
    $exists = DB::table('information_schema.tables')
        ->where('table_schema', 'public')->where('table_name', $t)->exists();
    echo "  {$t}: ".($exists ? DB::table($t)->count().' rows' : 'TABLE DOES NOT EXIST')."\n";
}

echo "\nusers by role:\n";
foreach (DB::table('users')->select('role', DB::raw('count(*) as n'))
    ->groupBy('role')->orderBy('role')->get() as $r) {
    printf("  %-12s %d\n", $r->role, $r->n);
}

echo "\nstaff fields living on users (the ones that are not identity):\n";
$staffCols = ['department', 'faculty', 'staff_id', 'phone', 'avatar_url'];
foreach (DB::table('information_schema.columns')
    ->where('table_name', 'users')->where('table_schema', 'public')
    ->whereIn('column_name', $staffCols)->pluck('column_name') as $c) {
    $filled = DB::table('users')->whereNotNull($c)->where($c, '!=', '')->count();
    echo "  {$c}: {$filled} filled\n";
}
