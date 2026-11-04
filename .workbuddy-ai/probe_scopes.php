<?php

/** The live coordinator scopes, after `batch` was dropped. */

use App\Models\CoordinatorScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "rows: ".DB::table('coordinator_scopes')->count()."\n";
echo "columns: ".implode(', ', DB::table('information_schema.columns')
    ->where('table_name', 'coordinator_scopes')
    ->where('table_schema', 'public')
    ->orderBy('ordinal_position')
    ->pluck('column_name')->all())."\n\n";

foreach (CoordinatorScope::query()->with('user')->get() as $s) {
    printf("user %-3s %-26s program=%-8s primary=%-3s -> %s\n",
        $s->user_id,
        $s->user?->email ?? '?',
        $s->program ?? 'NULL',
        $s->is_primary ? 'yes' : 'no',
        $s->describe()
    );
}

echo "\nwhat each coordinator may see:\n";
foreach (User::query()->where('role', 'coordinator')->get() as $u) {
    $programmes = CoordinatorScope::visibleProgrammes($u->coordinatorScopes);
    printf("  %-26s %s\n", $u->email,
        $programmes === null ? 'ALL programmes' : implode(', ', $programmes));
}
