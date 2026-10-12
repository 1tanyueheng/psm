<?php

namespace App\Policies;

use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\User;

/**
 * Module 1/4 — who may read and key coursework marks.
 *
 * The rule is narrow on purpose. A lecturer may only touch the marks of the
 * section they own: they key four numbers for students the faculty has placed
 * with them, and nothing else in the system is theirs. They cannot see another
 * section's students at all — not read-only, not for comparison — because a
 * lecturer comparing their section's marks against a colleague's before
 * moderation is a conversation the coordinator convenes, not a screen.
 *
 * Admins and coordinators may do everything, which is what makes a correction
 * possible: a lecturer leaves, a mark is wrong, and somebody has to be able to
 * fix it without editing the database.
 */
class LecturerMarkPolicy
{
    /** May this person see a coursework screen at all? */
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            || ($actor->hasRole('lecturer') && $actor->lecturerSection() !== null);
    }

    /**
     * May this person read these marks?
     *
     * A student may read their own — the coursework is 35% of their PSM 1
     * result, and a component of a mark they are shown must be explainable to
     * them. Staff who can see the student's project may read them too, since
     * coursework is part of that project's mark.
     */
    public function view(User $actor, LecturerMark $mark): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        if ($actor->hasRole('lecturer')) {
            return $this->ownsSection($actor, $mark->section_id);
        }

        // The student themselves.
        if ($actor->isStudent()) {
            return $actor->studentProfile?->id === $mark->student_profile_id;
        }

        return false;
    }

    /**
     * May this person key marks into this section?
     *
     * `lecturer_id` is nullable on the section, and a section with no lecturer
     * must be unkeyable rather than open to every lecturer — the null case means
     * "not yet appointed", not "anyone".
     */
    public function key(User $actor, Section $section): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        return $this->ownsSection($actor, $section->id);
    }

    /** Only staff key marks; a student never writes their own. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'lecturer');
    }

    public function update(User $actor, LecturerMark $mark): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        return $actor->hasRole('lecturer')
            && $this->ownsSection($actor, $mark->section_id);
    }

    /** A mark is corrected, never deleted: the audit trail expects a reason. */
    public function delete(User $actor, LecturerMark $mark): bool
    {
        return $actor->hasRole('admin');
    }

    private function ownsSection(User $actor, ?int $sectionId): bool
    {
        return $sectionId !== null
            && $actor->lecturerSection()?->id === $sectionId;
    }
}
