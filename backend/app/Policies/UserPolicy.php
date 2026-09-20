<?php

namespace App\Policies;

use App\Models\User;

/**
 * Module 1 / 2 — Account and role administration.
 *
 * Deliberately strict: only an Admin may create, delete, or re-role accounts.
 * A Coordinator may manage student/supervisor *profiles* and their pairings
 * (see ProjectPolicy / SupervisionPolicy) but never grants privileges.
 */
class UserPolicy
{
    /** Admin only: the account list. */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        // Users may always read their own record
        if ($actor->id === $target->id) {
            return true;
        }

        // A coordinator sees staff and students in their own scope
        if ($actor->isCoordinator()) {
            return true;
        }

        // A supervisor sees their own supervisees
        if ($actor->isSupervisor()) {
            $superviseeIds = $actor->supervisorProfile?->students->pluck('user_id') ?? collect();

            return $superviseeIds->contains($target->id);
        }

        return false;
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        // Anyone may update their own contact details (but not their role —
        // that is guarded in the form request, not here).
        return $actor->id === $target->id;
    }

    /** Role changes are the most sensitive operation in the system. */
    public function changeRole(User $actor, User $target): bool
    {
        if (! $actor->hasRole('admin')) {
            return false;
        }

        // Nobody may change their own role — prevents privilege self-escalation
        // and stops an admin accidentally locking themselves out.
        return $actor->id !== $target->id;
    }

    /** Admin only, and never on yourself. */
    public function delete(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') && $actor->id !== $target->id;
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') && $actor->id !== $target->id;
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $actor->hasRole('admin');
    }

    /** Unlock an account locked by failed sign-in attempts. */
    public function unlock(User $actor, User $target): bool
    {
        return $actor->hasRole('admin');
    }

    /** Module 2 — capacity and availability of supervisors. */
    public function setCapacity(User $actor, User $target): bool
    {
        return $actor->hasRole('admin', 'coordinator') && $target->isSupervisor();
    }

    /** Module 5 — cohort analytics and Module 7 exports. */
    public function viewAnalytics(User $actor): bool
    {
        return $actor->canViewCohortAnalytics();
    }

    public function exportData(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
