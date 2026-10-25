<?php

namespace App\Policies;

use App\Models\LecturerMark;
use App\Models\User;

/**
 * Module 1/4 — who may read and key coursework marks.
 *
 * Unless stated otherwise, "a lecturer" below means *whoever has been named as
 * a section's lecturer* — in practice usually a supervisor who also teaches a
 * section, sometimes a dedicated `lecturer` account. Anything that asked
 * `hasRole('lecturer')` asked the wrong question: the role is a job title, the
 * appointment is the entitlement (`User::lecturesAnySection()`).
 *
 * The appointment lasts one term, and the two questions that fact splits into
 * are answered in different places on purpose. "May you be here at all" is
 * `lecturesAnySection()` and is *not* term-scoped, so a past teacher can still
 * read what they keyed. "May you write into this section" is asked of the
 * section row itself — `SectionPolicy::key()` — which carries its own term: the
 * same `S1` in two terms is two rows, and only one of them is theirs. That
 * question is asked of a `Section`, so `authorize()` resolves it to
 * `SectionPolicy`; a `key()` declared here would never be called.
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
            // An empty screen is a useful answer for a lecturer who has not
            // been appointed yet — it says so — but a supervisor who is not a
            // lecturer has no business on the screen at all.
            || $actor->hasRole('lecturer')
            // Any term, not this one: a teacher who lectured last term and not
            // this one must still be able to read the marks they keyed, and the
            // screen's own term picker is what shows them.
            || $actor->lecturesAnySection();
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

        // Only the section's own lecturer, whatever their role.
        if ($actor->lecturesAnySection()) {
            return $this->ownsSection($actor, $mark->section_id);
        }

        // The student themselves.
        if ($actor->isStudent()) {
            return $actor->studentProfile?->id === $mark->student_profile_id;
        }

        return false;
    }

    /** Only staff key marks; a student never writes their own. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator', 'lecturer')
            || $actor->lecturesAnySection();
    }

    public function update(User $actor, LecturerMark $mark): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        return $this->ownsSection($actor, $mark->section_id);
    }

    /** A mark is corrected, never deleted: the audit trail expects a reason. */
    public function delete(User $actor, LecturerMark $mark): bool
    {
        return $actor->hasRole('admin');
    }

    /**
     * Does this person lecture *this* section row?
     *
     * Asked of the row, because the row is the appointment: it carries both its
     * term and its `lecturer_id`, so owning it means owning it in whichever term
     * it belongs to. Resolving "their section for the current term" here instead
     * would refuse a teacher a look at what they keyed last term, and — worse —
     * would answer about a different row whenever the same code is reused.
     */
    private function ownsSection(User $actor, ?int $sectionId): bool
    {
        return $sectionId !== null
            && $actor->sectionsLectured()->whereKey($sectionId)->exists();
    }
}
