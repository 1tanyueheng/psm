<?php

namespace App\Policies;

use App\Models\ArchivedProject;
use App\Models\User;

/**
 * Module 7 — Archive and audit access.
 *
 * Archive reads are extended to Faculty (supervisors) so past projects can be
 * used as reference material, which is one of the module's stated purposes.
 * Writing to the archive stays with coordinators and admins.
 */
class ArchivePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAccessArchive() || $actor->isSupervisor();
    }

    public function view(User $actor, ArchivedProject $record): bool
    {
        if ($actor->canAccessArchive()) {
            return true;
        }

        // Supervisors may consult past work as reference
        if ($actor->isSupervisor()) {
            return true;
        }

        // A student may see their own archived project
        if ($actor->isStudent()) {
            $actorId = $actor->id;

            return collect($record->students ?? [])->contains(
                fn (array $s) => ($s['user_id'] ?? null) === $actorId
            );
        }

        return false;
    }

    public function archive(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function restore(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function export(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function delete(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    /**
     * Module 7 — the audit trail.
     * Read is restricted to those who hold accountability for the cohort;
     * nobody may write to it through the API.
     */
    public function viewAuditLog(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** A user may always see their own action history. */
    public function viewOwnAuditLog(User $actor, User $target): bool
    {
        return $actor->hasRole('admin', 'coordinator') || $actor->id === $target->id;
    }

    /** Pruning by retention policy is an admin-only, scheduled operation. */
    public function pruneAuditLog(User $actor): bool
    {
        return $actor->hasRole('admin');
    }
}
