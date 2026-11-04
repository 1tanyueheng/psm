<?php

/**
 * Flip the real coordinator to a BIP-only scope, so the HTTP probe can prove the
 * limit works end to end. `restore_probe_scope.php` puts it back.
 *
 * Written for real rather than inside a transaction: a tinker transaction is
 * invisible to a separate `curl` call to the same application (PROGRESS.md §4 —
 * the lesson that cost an hour on the verdict work).
 */

use App\Models\CoordinatorScope;
use App\Models\User;

$user = User::query()->where('email', 'coordinator@psm.test')->firstOrFail();

CoordinatorScope::query()->where('user_id', $user->id)->delete();

CoordinatorScope::create([
    'user_id'    => $user->id,
    'program'    => 'BIP',
    'is_primary' => false,
]);

echo "coordinator@psm.test is now scoped to BIP only (head flag cleared)\n";
echo "visible programmes: ".implode(', ',
    CoordinatorScope::visibleProgrammes($user->fresh('coordinatorScopes')) ?? ['ALL'])."\n";
