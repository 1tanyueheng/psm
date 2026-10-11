<?php

namespace App\Policies;

use App\Models\User;

/**
 * Module 3 — who may read and set the term's milestone calendar.
 *
 * Setting it is a coordinator decision: the dates apply to every student in a
 * batch, so moving one is a faculty-level act rather than a per-project edit.
 * Supervisors and students may read the calendar — it is the timetable they are
 * working to, and hiding it would leave them guessing.
 */
class MilestoneSchedulePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'supervisor', 'student');
    }

    public function update(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
