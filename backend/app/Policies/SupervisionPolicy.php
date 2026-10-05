<?php

namespace App\Policies;

use App\Models\SupervisionAssignment;
use App\Models\User;

/**
 * Module 2 — Supervisor↔student pairing rights.
 *
 * Pairing is a Coordinator function. Supervisors may view their own pairings;
 * students may view theirs; neither may create or end one.
 */
class SupervisionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'supervisor');
    }

    public function view(User $actor, SupervisionAssignment $assignment): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        if ($actor->isSupervisor()) {
            return $assignment->supervisorProfile?->user_id === $actor->id;
        }

        if ($actor->isStudent()) {
            return $assignment->studentProfile?->user_id === $actor->id;
        }

        return false;
    }

    /** Create a pairing — coordinator/admin only. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function update(User $actor, SupervisionAssignment $assignment): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Ending a pairing. */
    public function delete(User $actor, SupervisionAssignment $assignment): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * A supervisor may set their own availability and capacity ceiling,
     * but not another supervisor's.
     */
    public function manageOwnProfile(User $actor, int $supervisorUserId): bool
    {
        return $actor->hasRole('admin', 'coordinator') || $actor->id === $supervisorUserId;
    }

    /*
     * `allocateExaminer` used to live here, and it never ran.
     *
     * The examiner-allocation endpoints authorise with
     * `$this->authorize('allocateExaminer', User::class)`. Gate resolves the
     * policy from the *class* it is handed — `User` — which maps to
     * UserPolicy, not to this one (this policy is registered for
     * SupervisionAssignment). So this method was unreachable, UserPolicy had
     * no such ability, and every examiner allocation answered 403.
     *
     * The ability now lives on UserPolicy, where the authorisation actually
     * resolves. Kept as a note rather than deleted silently so the next person
     * looking for it here finds out where it went.
     */
}
