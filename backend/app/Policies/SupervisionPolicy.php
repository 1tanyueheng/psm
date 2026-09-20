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

    /** Module 2 — allocating examiners to a project. */
    public function allocateExaminer(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
