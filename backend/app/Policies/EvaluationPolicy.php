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
    /**
     * May this actor ask for a *list* of evaluation forms at all?
     *
     * Students are included, and the filter below is what protects them. They
     * were excluded here, which made `/api/evaluations` answer 403 to every
     * student — so the Assessments tab on their own project page was always
     * empty, and the empty list read as "nothing has been marked" rather than
     * "you are not allowed to look".
     *
     * Listing is not the same as reading: `view()` still decides per row, and a
     * student only passes that for a form that has been *released* to them. So
     * the list they receive is their own released forms and nothing else. The
     * controller narrows further — a student's query is scoped to their own
     * projects and never exposes another student's row.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->canAssess()
            || $actor->isStudent()
            || $actor->hasRole('admin', 'coordinator');
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
