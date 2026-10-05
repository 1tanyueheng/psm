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

        /**
         * Academic staff see a project they supervise *or* one they are
         * allocated to as a panel examiner.
         *
         * Both halves matter. Being on a panel is a seating, not a role, so a
         * supervisor examining someone else's student must reach that project —
         * otherwise they cannot read, or decide the proposal milestone of, the
         * work they were appointed to examine.
         */
        if ($actor->isSupervisor()) {
            return $this->supervises($actor, $project) || $this->examines($actor, $project);
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

    /**
     * Move a PSM 1 student into PSM 2.
     *
     * Same audience as approving a project, plus a part restriction: the action
     * archives the project it is called on and creates the PSM 2 successor, so
     * it must not be reachable against a PSM 2 or BOTH project.
     */
    public function progress(User $actor, Project $project): bool
    {
        return $this->approve($actor, $project)
            && $project->psm_part === 'PSM1';
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

    /**
     * Is this user seated as an examiner on the project?
     *
     * Read from `examiner_assignments` — anchored on the student while the panel
     * is allocated, and stamped with `project_id` once Lampiran B creates the
     * project. Role is deliberately not checked: the panel is drawn from
     * supervisors as well as examiners.
     */
    protected function examines(User $actor, Project $project): bool
    {
        return $project->examinerAssignments()
            ->where('examiner_id', $actor->id)
            ->where('is_active', true)
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
