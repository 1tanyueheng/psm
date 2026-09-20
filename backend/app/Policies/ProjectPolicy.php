<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Module 3 — Who may see and change a project.
 *
 * The visibility rule mirrors Project::scopeVisibleTo() so a list query and a
 * single-record check can never disagree.
 */
class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;   // always scoped by Project::visibleTo()
    }

    public function view(User $actor, Project $project): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->isCoordinator()) {
            return $this->coordinatorCovers($actor, $project);
        }

        if ($actor->isStudent()) {
            return $this->isMember($actor, $project);
        }

        // A supervisor sees projects they supervise
        if ($actor->isSupervisor()) {
            return $this->supervises($actor, $project);
        }

        // An examiner sees only projects they are allocated to
        if ($actor->isExaminer()) {
            return $project->examinerAssignments()
                ->where('examiner_id', $actor->id)
                ->where('is_active', true)
                ->exists();
        }

        return false;
    }

    /** Students register their own project. */
    public function create(User $actor): bool
    {
        return $actor->isStudent();
    }

    /**
     * Editing the registration: the owning student while still a draft, and
     * the coordinator at any time (they administer the record).
     */
    public function update(User $actor, Project $project): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->isCoordinator()) {
            return $this->coordinatorCovers($actor, $project);
        }

        if ($actor->isStudent()) {
            return $this->isMember($actor, $project)
                && in_array($project->status, ['draft', 'rejected'], true);
        }

        return false;
    }

    /** Submit a draft for approval. */
    public function submit(User $actor, Project $project): bool
    {
        return $this->isMember($actor, $project)
            && in_array($project->status, ['draft', 'rejected'], true);
    }

    /** Approving a registration is a coordinator/admin act. */
    public function approve(User $actor, Project $project): bool
    {
        if (! $actor->hasRole('admin', 'coordinator')) {
            return false;
        }

        return $actor->hasRole('admin') || $this->coordinatorCovers($actor, $project);
    }

    public function reject(User $actor, Project $project): bool
    {
        return $this->approve($actor, $project);
    }

    /** Allocate examiners — coordinator territory. */
    public function assignExaminers(User $actor, Project $project): bool
    {
        return $this->approve($actor, $project);
    }

    /** Change the grade weighting scheme for a project. */
    public function manageGradeScheme(User $actor, Project $project): bool
    {
        // Once grades are released the scheme is frozen, to protect results
        if ($project->finalGrades()->where('status', 'released')->exists()) {
            return false;
        }

        return $this->approve($actor, $project);
    }

    /** Archiving (Module 7) is coordinator/admin only. */
    public function archive(User $actor, Project $project): bool
    {
        return $this->approve($actor, $project);
    }

    /** Hard deletion is admin-only and rare; archiving is the normal path. */
    public function delete(User $actor, Project $project): bool
    {
        return $actor->hasRole('admin');
    }

    /** Whether the student consents to public display (Module 8). */
    public function toggleLeaderboardConsent(User $actor, Project $project): bool
    {
        return $this->isMember($actor, $project) || $actor->hasRole('admin');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    protected function isMember(User $actor, Project $project): bool
    {
        $studentId = $actor->studentProfile?->id;

        if ($studentId === null) {
            return false;
        }

        return $project->members()->where('student_profile_id', $studentId)->exists();
    }

    protected function supervises(User $actor, Project $project): bool
    {
        $supervisorId = $actor->supervisorProfile?->id;

        if ($supervisorId === null) {
            return false;
        }

        return $project->members()
            ->whereHas('studentProfile.activeSupervisions', function ($q) use ($supervisorId) {
                $q->where('supervisor_profile_id', $supervisorId);
            })
            ->exists();
    }

    protected function coordinatorCovers(User $actor, Project $project): bool
    {
        $scopes = $actor->coordinatorScopes;

        if ($scopes->isEmpty()) {
            return true;   // no explicit scope = whole faculty
        }

        return $scopes->contains(fn ($scope) => $scope->batch === $project->batch
            || ($scope->program !== null && $scope->program === $project->program));
    }
}
