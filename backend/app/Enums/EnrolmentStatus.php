<?php

namespace App\Enums;

/**
 * The lifecycle of one student's attempt at one PSM part in one semester.
 *
 * This is deliberately **not** the project's status. A project moves with its
 * marks — `in_progress`, `completed`, `failed` — and it only exists once the
 * student has registered a title. An enrolment exists *before* that, which is
 * the whole point of it: the admin registers a student into a PSM session per
 * semester, and the project is attached to that enrolment once registration
 * produces one.
 *
 * Keeping the two apart answers a question the project could not. "How many
 * times has this student attempted PSM 2?" used to require walking a chain of
 * `repeated_from` links and inferring, because only *repeats* carried a repeat
 * number and the first attempt carried nothing. Here it is a count of rows.
 */
enum EnrolmentStatus: string
{
    /**
     * Registered for a part in a term, but no project attached yet.
     *
     * This is the state a PSM 1 student sits in between enrolling and Lampiran
     * A being registered, and the state a repeat lands in when the student has
     * to re-register. Nothing is in progress, but the seat is taken.
     */
    case Enrolling = 'enrolling';

    /** A project is attached and the attempt is live. */
    case Active = 'active';

    /** The attempt finished and passed. Terminal. */
    case Completed = 'completed';

    /** The attempt finished and did not pass. The student may repeat. */
    case Failed = 'failed';

    /**
     * The student left before a verdict — deferral, transfer, withdrawal.
     *
     * Distinct from `failed` on purpose: a withdrawal is not an academic
     * failure and must not read as one on a transcript or in the analytics.
     * It does not block a later enrolment either.
     */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Enrolling => 'Enrolling',
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Badge tone for the API, matching the frontend's BADGE_TONES keys. */
    public function tone(): string
    {
        return match ($this) {
            self::Enrolling => 'amber',
            self::Active    => 'blue',
            self::Completed => 'emerald',
            self::Failed    => 'rose',
            self::Withdrawn => 'slate',
        };
    }

    /**
     * Whether this attempt occupies a place in the term.
     *
     * A seat is held from enrolment until the attempt is settled — so a student
     * who is enrolling but has not registered yet still counts against the
     * cohort, and a failed attempt stops counting the moment it is ruled on.
     * That is what lets a repeat take a seat in the *next* term rather than
     * stacking on top of the old one.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Enrolling, self::Active], true);
    }

    /** Whether the attempt is over, whichever way it went. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Withdrawn], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
