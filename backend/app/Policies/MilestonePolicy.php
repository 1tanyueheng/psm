<?php

namespace App\Policies;

use App\Models\Milestone;
use App\Models\User;

/**
 * Module 3 — Milestone visibility, submission and review rights.
 */
class MilestonePolicy
{
    public function view(User $actor, Milestone $milestone): bool
    {
        return app(ProjectPolicy::class)->view($actor, $milestone->project);
    }

    /**
     * Upload: the owning student only, and only while the milestone actually
     * accepts a submission (see Milestone::acceptsSubmission()).
     */
    public function submit(User $actor, Milestone $milestone): bool
    {
        if (! $actor->isStudent()) {
            return false;
        }

        $studentId = $actor->studentProfile?->id;

        if ($studentId === null) {
            return false;
        }

        $isMember = $milestone->project->members()
            ->where('student_profile_id', $studentId)
            ->exists();

        if (! $isMember) {
            return false;
        }

        // Whether the milestone is currently accepting work (open, rejected,
        // or inside a granted late window) is decided by the model.
        return $milestone->acceptsSubmission();
    }

    /**
     * Review (approve / request revision): the student's own supervisor, or a
     * coordinator. An examiner does not approve milestones — they assess the
     * finished work through Module 4.
     */
    public function review(User $actor, Milestone $milestone): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->isCoordinator()) {
            return app(ProjectPolicy::class)->view($actor, $milestone->project);
        }

        $supervisorId = $actor->supervisorProfile?->id;

        if ($supervisorId === null) {
            return false;
        }

        return $milestone->project->members()
            ->whereHas('studentProfile.activeSupervisions', function ($q) use ($supervisorId) {
                $q->where('supervisor_profile_id', $supervisorId);
            })
            ->exists();
    }

    /** Downloading an attached file follows the same rule as viewing. */
    public function download(User $actor, Milestone $milestone): bool
    {
        return $this->view($actor, $milestone);
    }

    /**
     * Changing a deadline is the most contested administrative action, so it
     * is restricted to coordinators/admins even though supervisors can review.
     */
    public function changeDeadline(User $actor, Milestone $milestone): bool
    {
        if (! $actor->hasRole('admin', 'coordinator')) {
            return false;
        }

        return $actor->hasRole('admin')
            || app(ProjectPolicy::class)->view($actor, $milestone->project);
    }

    /** Adding an ad-hoc milestone to a project. */
    public function create(User $actor, Milestone $milestone): bool
    {
        return $this->changeDeadline($actor, $milestone);
    }

    public function delete(User $actor, Milestone $milestone): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && ! $milestone->submitted_at;
    }
}
