<?php

namespace App\Policies;

use App\Models\MarkSubmission;
use App\Models\User;

/**
 * Module 4 — Who may open, view, lock, unlock a mark submission.
 *
 * All actions are coordinator-only. The submission is the coordinator's
 * instrument; assessors submit their own forms independently.
 */
class MarkSubmissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function view(User $actor, MarkSubmission $submission): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        // A coordinator may view submissions in their cohort
        if ($actor->isCoordinator()) {
            return app(ProjectPolicy::class)->view($actor, $submission->project);
        }

        return false;
    }

    /** Only a coordinator may open a mark submission (allocates forms). */
    public function open(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Only a coordinator may lock a submission (attests all forms are in). */
    public function lock(User $actor, MarkSubmission $submission): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && app(ProjectPolicy::class)->view($actor, $submission->project);
    }

    /** Only a coordinator may unlock a submission (requires a reason). */
    public function unlock(User $actor, MarkSubmission $submission): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && app(ProjectPolicy::class)->view($actor, $submission->project);
    }
}