<?php

namespace App\Policies;

use App\Models\SupervisorAgreement;
use App\Models\User;

/**
 * Registration flow policy for Lampiran A/B.
 *
 * Gates the agreement endpoints that previously had no authorization at all
 * (e.g. showAgreement was callable by any authenticated user). The rules mirror
 * how the registration UI exposes actions: only the parties to an agreement may
 * see it, the supervisor may acknowledge, and coordinators/admins decide.
 */
class SupervisorAgreementPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'supervisor', 'student');
    }

    public function view(User $actor, SupervisorAgreement $agreement): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        if ($actor->isSupervisor()) {
            return $agreement->supervisorProfile?->user_id === $actor->id;
        }

        if ($actor->isStudent()) {
            return $agreement->studentProfile?->user_id === $actor->id;
        }

        return false;
    }

    /** Creating a new agreement is the student's job. */
    public function create(User $actor): bool
    {
        return $actor->isStudent();
    }

    /** Only the named supervisor may acknowledge Part C. */
    public function acknowledge(User $actor, SupervisorAgreement $agreement): bool
    {
        if (! $actor->isSupervisor()) {
            return false;
        }

        return $agreement->supervisorProfile?->user_id === $actor->id;
    }

    /** Coordinator/admin act on Part D. */
    public function decide(User $actor, SupervisorAgreement $agreement): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Student submits Lampiran B against an approved agreement they own. */
    public function submitTitleProposal(User $actor, SupervisorAgreement $agreement): bool
    {
        if (! $actor->isStudent()) {
            return false;
        }

        return $agreement->studentProfile?->user_id === $actor->id;
    }
}
