<?php

namespace App\Policies;

use App\Models\AssessmentWindow;
use App\Models\ExaminerAssignment;
use App\Models\SupervisionAssignment;
use App\Models\User;

/**
 * Module 4 — who may open and close the assessment window.
 *
 * Opening and closing is a coordinator action: it is the coordinator's
 * declaration that marking may begin. Assessors may read the window (they need
 * to know whether they can file) but never change it.
 */
class AssessmentWindowPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function view(User $actor, AssessmentWindow $window): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        // An assessor may read the window for a batch they have work in — that
        // is how their marking page knows whether it is open.
        return $this->hasWorkIn($actor, $window);
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function open(User $actor, AssessmentWindow $window): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function close(User $actor, AssessmentWindow $window): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function update(User $actor, AssessmentWindow $window): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function delete(User $actor, AssessmentWindow $window): bool
    {
        return $actor->isAdmin();
    }

    /** Does this user supervise or examine anyone in the window's batch? */
    protected function hasWorkIn(User $actor, AssessmentWindow $window): bool
    {
        $supervises = SupervisionAssignment::query()
            ->where('is_active', true)
            ->where('psm_part', $window->psm_part)
            ->whereHas('supervisorProfile', fn ($q) => $q->where('user_id', $actor->id))
            ->exists();

        if ($supervises) {
            return true;
        }

        return ExaminerAssignment::query()
            ->where('is_active', true)
            ->where('examiner_id', $actor->id)
            ->where('psm_part', $window->psm_part)
            ->exists();
    }
}
