<?php

/**
 * Prove the new scope rule, inside a transaction that is rolled back.
 *
 * The three states, and the one old behaviour that must be gone:
 *
 *   head (is_primary)      -> every project
 *   program = BIP          -> only BIP students' projects
 *   no rows                -> every project (the documented fail-open)
 *   old batch-only scope   -> MUST NOT limit anything any more
 */

use App\Models\CoordinatorScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

$n = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $n, $fail;
    $n++;
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}".($detail !== '' ? "  -- {$detail}" : '')."\n";
    }
}

$coordinator = User::query()->where('role', 'coordinator')->firstOrFail();
$total = Project::query()->count();

echo "coordinator: {$coordinator->email}\n";
echo "projects in total: {$total}\n\n";

// ---- 1. head coordinator -------------------------------------------------
DB::table('coordinator_scopes')->where('user_id', $coordinator->id)->delete();
CoordinatorScope::create(['user_id' => $coordinator->id, 'program' => null, 'is_primary' => true]);

$visible = Project::query()->visibleTo($coordinator->fresh('coordinatorScopes'))->count();
check('a head coordinator sees every project', $visible === $total, "{$visible} of {$total}");

// ---- 2. limited to one programme -----------------------------------------
DB::table('coordinator_scopes')->where('user_id', $coordinator->id)->delete();
CoordinatorScope::create(['user_id' => $coordinator->id, 'program' => 'BIP', 'is_primary' => false]);

$bipVisible = Project::query()->visibleTo($coordinator->fresh('coordinatorScopes'))->count();
$bipAll = Project::query()->whereHas('members.studentProfile',
    fn ($q) => $q->where('program_code', 'BIP'))->count();

check('a BIP coordinator sees only BIP projects',
    $bipVisible === $bipAll && $bipVisible < $total,
    "saw {$bipVisible}, expected {$bipAll}, total {$total}");

// ---- 3. no scope rows ----------------------------------------------------
DB::table('coordinator_scopes')->where('user_id', $coordinator->id)->delete();

$open = Project::query()->visibleTo($coordinator->fresh('coordinatorScopes'))->count();
check('no scope rows still means the whole faculty (documented fail-open)',
    $open === $total, "{$open} of {$total}");

// ---- 4. the old batch behaviour is GONE ----------------------------------
// A scope naming only a programme is not narrowed by any intake year: this was
// the bug — a coordinator scoped to 2026 could not see a 2025 project at all.
DB::table('coordinator_scopes')->where('user_id', $coordinator->id)->delete();
CoordinatorScope::create(['user_id' => $coordinator->id, 'program' => 'BIP', 'is_primary' => false]);

$batches = Project::query()->visibleTo($coordinator->fresh('coordinatorScopes'))
    ->distinct()->pluck('batch')->filter()->values()->all();
check('a BIP coordinator is not narrowed by batch', count($batches) >= 1,
    'batches visible: '.implode(', ', $batches));

// ---- 5. the scope table no longer has a batch column --------------------
$hasBatch = DB::table('information_schema.columns')
    ->where('table_name', 'coordinator_scopes')
    ->where('column_name', 'batch')
    ->exists();
check('coordinator_scopes.batch is gone', ! $hasBatch);

// ---- 6. is_primary wins over a programme on the same row ---------------
DB::table('coordinator_scopes')->where('user_id', $coordinator->id)->delete();
CoordinatorScope::create(['user_id' => $coordinator->id, 'program' => 'BIT', 'is_primary' => true]);

$head = Project::query()->visibleTo($coordinator->fresh('coordinatorScopes'))->count();
check('is_primary overrides the programme on the same row', $head === $total, "{$head} of {$total}");

DB::rollBack();
echo "\nrolled back - nothing written\n";
echo "\n" . ($n - $fail) . " passed, {$fail} failed\n";

if ($fail > 0) {
    exit(1);
}
