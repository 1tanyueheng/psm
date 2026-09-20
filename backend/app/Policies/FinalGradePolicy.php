<?php

namespace App\Policies;

use App\Models\FinalGrade;
use App\Models\User;

/**
 * Module 4 / 5 / 8 — Grade visibility and release.
 *
 * A student may see their own grade only once it has been released. This is
 * the single most important confidentiality rule in the system.
 */
class FinalGradePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function view(User $actor, FinalGrade $grade): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        // A supervisor may see the result of a project they supervise
        if ($actor->isSupervisor()) {
            $supervisorId = $actor->supervisorProfile?->id;

            if ($supervisorId === null) {
                return false;
            }

            return $grade->project->members()
                ->whereHas('studentProfile.activeSupervisions', function ($q) use ($supervisorId) {
                    $q->where('supervisor_profile_id', $supervisorId);
                })
                ->exists();
        }

        // A student sees their own grade, and only after release
        if ($actor->isStudent()) {
            $studentId = $actor->studentProfile?->id;

            return $studentId !== null
                && $grade->student_profile_id === $studentId
                && $grade->isReleased();
        }

        // An examiner sees the outcomes of projects they assessed
        if ($actor->isExaminer()) {
            return $grade->project->examinerAssignments()
                ->where('examiner_id', $actor->id)
                ->exists();
        }

        return false;
    }

    /** Recompute an aggregate. */
    public function recompute(User $actor, FinalGrade $grade): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Release a grade to the student. */
    public function release(User $actor, FinalGrade $grade): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && ! $grade->isReleased();
    }

    /** Withhold a result (e.g. pending an integrity investigation). */
    public function withhold(User $actor, FinalGrade $grade): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Module 8 — public display. */
    public function publish(User $actor, FinalGrade $grade): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Module 5 — export grade reports. */
    public function export(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
