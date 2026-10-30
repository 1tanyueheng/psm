<?php

namespace App\Policies;

use App\Models\StudentEnrolment;
use App\Models\User;

/**
 * Who may set up and change a student's PSM enrolment.
 *
 * Enrolling a student into a term is an administrative act with academic
 * consequences — it decides who the faculty will teach and assess — so it is
 * reserved for the coordinator and the admin, the same pair who rule on
 * verdicts. A supervisor cannot enrol their own student, and a student cannot
 * enrol themselves.
 *
 * Reading is wider than writing. A student must be able to see their own
 * enrolment history (it is their transcript in miniature), and staff with a
 * legitimate interest in a cohort can read it. The list endpoint is scoped by
 * the controller; `view` here answers the per-record question.
 */
class StudentEnrolmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;   // scoped by the controller's query, never unrestricted
    }

    public function view(User $actor, StudentEnrolment $enrolment): bool
    {
        if ($actor->hasRole('admin') || $actor->isCoordinator()) {
            return true;
        }

        // A student sees their own attempts, all of them.
        if ($actor->isStudent()) {
            return $actor->studentProfile?->id === $enrolment->student_profile_id;
        }

        // A supervisor sees the enrolments of students they supervise, which is
        // how their own load screen explains where their students came from.
        if ($actor->isSupervisor() || $actor->isLecturer()) {
            $supervisorId = $actor->supervisorProfile?->id;

            return $supervisorId !== null
                && $enrolment->studentProfile?->activeSupervisions()
                    ->where('supervisor_profile_id', $supervisorId)
                    ->exists();
        }

        return false;
    }

    /** Creating an enrolment for a term. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Placing a repeat, withdrawing an attempt. */
    public function update(User $actor, StudentEnrolment $enrolment): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * Deleting is a misoperation undo, not a way to edit history.
     *
     * Offered to the same people who may enrol, because they are the ones who
     * make the mistake. The rules that decide *whether a row may go* live in
     * `EnrolmentService::remove()` — a policy cannot see the attempt's history,
     * and a half-check here would either block a legitimate undo or let through
     * a delete that strands a repeat.
     */
    public function delete(User $actor, StudentEnrolment $enrolment): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
