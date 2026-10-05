<?php

namespace App\Policies;

use App\Models\AcademicSemester;
use App\Models\User;

/**
 * Module 3 — who may administer a semester.
 *
 * A term is a faculty-wide administrative object, not a student-owned one, so
 * there is no per-instance check beyond the role: every coordinator manages
 * every term. That is deliberate and matches requirement §7.3, which lets a
 * coordinator manage several terms.
 *
 * What the role does *not* grant is visible here rather than left implicit:
 *
 *   viewAny   — every authenticated user. The filter dropdowns on the project
 *               list, the reports and the registration screen all need the term
 *               list to label their current selection, and a student who cannot
 *               read it sees "Semester I" with no way to tell which one.
 *   view      — as above. A term contains no student data; its stats do, and
 *               that is gated on `viewStats` instead.
 *   viewStats — coordinator and admin only. `stats()` counts students with
 *               supervisors, students with examiners and approved titles across
 *               a whole cohort, which is not a student's or a single
 *               supervisor's business even where they would be permitted to see
 *               their own project.
 */
class SemesterPolicy
{
    /** Read the term list — used by every filter dropdown in the app. */
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, AcademicSemester $semester): bool
    {
        return true;
    }

    /** Cohort-wide figures for a term. */
    public function viewStats(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * Create a term, or change which term is active.
     *
     * Activating a term deactivates the previous one, so this is the ability
     * that actually decides which cohort every default in the app points at.
     */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Dates, name, metadata. */
    public function update(User $actor, AcademicSemester $semester): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Opening and closing the Lampiran A window. */
    public function manageRegistration(User $actor, AcademicSemester $semester): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * Publishing or withholding a whole term's results — REMOVED.
     *
     * There is no such ability any more. A mark is published by the submission
     * that completes it, and no coordinator action can publish or retract a
     * term. The ability used to exist to keep an incidental `PATCH` from
     * publishing a cohort; with no term-level release to authorise, leaving it
     * in place would only be a hook nobody remembers is dead.
     */

    public function close(User $actor, AcademicSemester $semester): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /**
     * Reopen a closed term.
     *
     * Same audience as `close`: whoever can shut a term must be able to undo it,
     * or an accidental close is unrecoverable without touching the database.
     */
    public function reopen(User $actor, AcademicSemester $semester): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function delete(User $actor, AcademicSemester $semester): bool
    {
        // A term that has held projects cannot be deleted — only closed. The
        // records a term carries (registrations, marks, assessment sheets) are
        // the faculty's assessment archive.
        return $actor->isAdmin() && $semester->projects()->doesntExist();
    }
}