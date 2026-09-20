<?php

namespace App\Policies;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\User;

/**
 * Module 4 — Who may create, edit, submit and moderate an evaluation.
 *
 * The rule that matters most for academic integrity: an assessor may only
 * ever touch their *own* evaluation, and only while it is a draft. Once
 * submitted, marks are locked and only a coordinator can adjust them, with a
 * recorded reason.
 */
class EvaluationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAssess() || $actor->hasRole('admin', 'coordinator');
    }

    public function view(User $actor, Evaluation $evaluation): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        // The assessor who owns the form
        if ($evaluation->assessor_id === $actor->id) {
            return true;
        }

        // A coordinator overseeing the cohort
        if ($actor->isCoordinator()) {
            return app(ProjectPolicy::class)->view($actor, $evaluation->project);
        }

        // The student being assessed may see it once it has been released
        if ($actor->isStudent() && $evaluation->status === EvaluationStatus::Released) {
            $studentId = $actor->studentProfile?->id;

            return $studentId !== null
                && $evaluation->project->members()->where('student_profile_id', $studentId)->exists();
        }

        return false;
    }

    /** A coordinator (or the system) creates forms; assessors do not self-allocate. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * Editing marks: the owning assessor while still a draft.
     */
    public function update(User $actor, Evaluation $evaluation): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($evaluation->assessor_id !== $actor->id) {
            return false;
        }

        return $evaluation->status->isEditable();
    }

    /** Submit a completed draft. */
    public function submit(User $actor, Evaluation $evaluation): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        return $evaluation->assessor_id === $actor->id
            && $evaluation->status->isEditable();
    }

    /**
     * Moderation: change a locked mark. Coordinator/admin only, never the
     * assessor who wrote it — that is the point of moderation.
     */
    public function moderate(User $actor, Evaluation $evaluation): bool
    {
        if (! $actor->hasRole('admin', 'coordinator')) {
            return false;
        }

        // An assessor cannot moderate their own submission, even if they also
        // hold a coordinator role.
        if ($evaluation->assessor_id === $actor->id) {
            return false;
        }

        if (! $evaluation->status->isLocked()) {
            return false;
        }

        return $actor->hasRole('admin')
            || app(ProjectPolicy::class)->view($actor, $evaluation->project);
    }

    /** Declare a conflict of interest and step back from assessing. */
    public function declareConflict(User $actor, Evaluation $evaluation): bool
    {
        return $evaluation->assessor_id === $actor->id
            && ! $evaluation->status->isLocked();
    }

    /** Delete a draft form (only before any marks are meaningful). */
    public function delete(User $actor, Evaluation $evaluation): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && $evaluation->status === EvaluationStatus::Draft;
    }

    /**
     * Module 8 — publishing results is separate from marking, and is a
     * coordinator act.
     */
    public function publishResults(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
