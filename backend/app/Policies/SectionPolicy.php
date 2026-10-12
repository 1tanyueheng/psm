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
 */
class SectionPolicy
{
    /**
     * May this person see a section list?
     *
     * A coordinator is included because they need the roster to answer "who
     * teaches this student" and to place students; they cannot appoint
     * lecturers, which is what `manage()` gates.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'lecturer');
    }

    public function view(User $actor, Section $section): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        // A lecturer may read only the section they own.
        return $actor->hasRole('lecturer')
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
