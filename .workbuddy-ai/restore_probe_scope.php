<?php

/** Put coordinator@psm.test back to the head scope the seeder gives it. */

use App\Models\CoordinatorScope;
use App\Models\User;

$user = User::query()->where('email', 'coordinator@psm.test')->firstOrFail();

CoordinatorScope::query()->where('user_id', $user->id)->delete();

CoordinatorScope::create([
    'user_id'    => $user->id,
    'program'    => null,
    'is_primary' => true,
]);

echo "coordinator@psm.test restored to head (all programmes)\n";
