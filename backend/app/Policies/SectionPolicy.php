<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

/**
 * Who may see and manage teaching sections.
 *
 * Sections are administrative furniture: an admin creates the four and appoints
 * a lecturer to each. A lecturer may read their own section and its roster —
 * that is the list they key marks against — but may not create, rename,
 * reassign or delete one. A section's lecturer is a faculty appointment, not
 * something the lecturer can change about themselves.
 *
 * "Lecturer" here means whoever holds the appointment, not whoever has the
 * role: a supervisor who teaches a section reads that section and no other.
 * See `User::lecturerSection()` and `User::lecturesAnySection()` — the first is
 * per term, the second is what decides whether the person may see a section list
 * at all, so a past teacher can still find what they taught.
 */
class SectionPolicy
{
    /**
     * May this person see a section list?
     *
     * A coordinator is included because they need the roster to answer "who
     * teaches this student" and to place students; they cannot appoint
     * lecturers, which is what `manage()` gates. A plain supervisor is *not*
     * included — sections are not part of supervision — but one who teaches a
     * section is, so the appointed case is checked on its own.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'lecturer')
            || $actor->lecturesAnySection();
    }

    public function view(User $actor, Section $section): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        // A lecturer may read only the section they own.
        return $this->owns($actor, $section);
    }

    /**
     * May this person key coursework marks into this section?
     *
     * This ability lives here, not on `LecturerMarkPolicy`, because the object
     * being authorised is a *section* and Laravel resolves a policy from the
     * class of the argument it is handed: `authorize('key', $section)` reaches
     * this class and no other. A `key()` written next to the marks is therefore
     * never called — which fails closed, so it presented as "nobody can save,
     * admins included" rather than as an open door, but a coursework screen with
     * no working save is not a feature either.
     *
     * `lecturer_id` is nullable, and a section with no lecturer must be
     * unkeyable rather than open to every lecturer: the null means "not yet
     * appointed", not "anyone".
     */
    public function key(User $actor, Section $section): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        return $this->owns($actor, $section);
    }

    /**
     * Does this person hold the appointment on this row?
     *
     * Asked of the row, because the row is the appointment: it carries both its
     * term and its `lecturer_id`, so owning it means owning it in whichever term
     * it belongs to. Resolving "their section for the current term" instead would
     * refuse a teacher a look at what they keyed last term, and — worse — would
     * answer about a different row whenever the same code is reused.
     */
    private function owns(User $actor, Section $section): bool
    {
        return $section->lecturer_id !== null
            && $section->lecturer_id === $actor->id;
    }

    /** Creating, renaming, reassigning and deleting are admin-only. */
    public function manage(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    /**
     * May this person place a student into a section?
     *
     * Admins and coordinators, per the requirement that an admin sets it. The
     * lecturer cannot: a student's section is decided by enrolment, and letting
     * a lecturer add one to their own roster would let them mark a student the
     * faculty never placed with them.
     */
    public function assignStudents(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }
}
