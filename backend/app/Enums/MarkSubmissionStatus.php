<?php

namespace App\Enums;

/**
 * Module 4 — the coordinator's mark submission, one per student per project
 * per PSM part.
 *
 * open     : the coordinator allocated the forms; assessors are filling them
 * locked   : every expected form is in and the coordinator attested the result
 * released : published to the student (delegated to FinalGrade's own release)
 *
 * There is deliberately no 'ready' case. "Every form is in" is a fact about the
 * evaluation rows, not a state this record owns, so it is derived on read.
 * Storing it would let a stale flag gate a lock the marks no longer support —
 * for instance if a panel member were excused after the flag was written.
 */
enum MarkSubmissionStatus: string
{
    case Open     = 'open';
    case Locked   = 'locked';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Open     => 'Awaiting marks',
            self::Locked   => 'Locked',
            self::Released => 'Released',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open     => 'amber',
            self::Locked   => 'emerald',
            self::Released => 'emerald',
        };
    }

    /**
     * A locked submission is frozen. Releasing is still allowed, because
     * publishing is a separate decision from attesting and happens later; but
     * nothing about the mark itself may change.
     */
    public function isLocked(): bool
    {
        return $this === self::Locked || $this === self::Released;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}