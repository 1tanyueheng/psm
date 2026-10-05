<?php

namespace App\Enums;

/**
 * Module 3 — which half of the Final Year Project a record belongs to.
 *
 * PSM 1 and PSM 2 run concurrently in the same semester from the same pool of
 * staff, which makes `psm_part` a *scoping* column rather than a label: it
 * decides which milestone template applies, which marking forms count toward
 * the aggregate, which examiner pairs may be allocated, and — critically — how
 * many students a supervisor may carry.
 *
 * The three cases are not interchangeable:
 *
 *   Psm1   Chapters 1–4. The title is proposed and reviewed before Lampiran B.
 *          Assessed by Lampiran E (supervisor) + I (examiners).
 *   Psm2   Chapters 5–7 plus the final report. Lampiran G + H (supervisor) and
 *          J (examiners).
 *   Both   A pairing that covers both parts of one student's project — it counts
 *          against the supervisor's capacity in *each* part.
 *
 * Existing columns store the raw string ('PSM1'), and older rows still do, so
 * the helpers below accept either the enum or a loose string. Nothing here
 * casts a column automatically: `psm_part` is not uniformly constrained across
 * the ten tables that carry it (projects accept PSM1/PSM2, pairings also accept
 * BOTH), and forcing a cast would turn a legacy or malformed value into a 500
 * on an unrelated screen.
 */
enum PsmPart: string
{
    case Psm1 = 'PSM1';
    case Psm2 = 'PSM2';
    case Both = 'BOTH';

    /**
     * The two parts a *project* may belong to.
     *
     * A project is one or the other, never both — "BOTH" only describes a
     * relationship (a supervision covering both parts of one student's work),
     * not a deliverable. Filters that split a cohort therefore iterate this,
     * never `cases()`.
     *
     * @return array<int, self>
     */
    public static function deliverables(): array
    {
        return [self::Psm1, self::Psm2];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** The two project parts only — for `in:PSM1,PSM2` validation rules. */
    public static function deliverableValues(): array
    {
        return [self::Psm1->value, self::Psm2->value];
    }

    /**
     * Resolve from a loose value without throwing.
     *
     * Call sites that must tolerate legacy rows (`'BOTH'` on a project, mixed
     * case from a query string, a null column) use this; a hard failure on a
     * display screen because of one bad row is worse than an unknown part.
     */
    public static function tryParse(mixed $value, ?self $fallback = null): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return $fallback;
        }

        return self::tryFrom(strtoupper(trim((string) $value))) ?? $fallback;
    }

    /** Parse or fail — for validated input, where an unknown part is a 422. */
    public static function parseOrFail(?string $value, self $default = self::Psm2): self
    {
        return self::tryParse($value) ?? $default;
    }

    public function label(): string
    {
        return match ($this) {
            self::Psm1  => 'PSM 1',
            self::Psm2  => 'PSM 2',
            self::Both  => 'PSM 1 & 2',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Psm1  => 'PSM1',
            self::Psm2  => 'PSM2',
            self::Both  => 'Both',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Psm1 => 'Chapters 1–4, with the title proposed and reviewed before registration.',
            self::Psm2 => 'Chapters 5–7 and the final report, assessed at the end of the semester.',
            self::Both => 'A supervision covering both parts of one student\'s project.',
        };
    }

    /**
     * Does an assignment stored under `$other` count against this part's cap?
     *
     * A `BOTH` pairing consumes capacity in both parts; a `PSM1` pairing
     * consumes none of PSM 2's. This is the single definition of that rule —
     * the capacity check, the per-part load count and the workload report all
     * defer to it rather than re-deriving it.
     *
     * The relationship is symmetric in the `BOTH` case on purpose:
     * `Both->covers(Psm1)` and `Psm1->covers(Both)` are both true. An earlier
     * version returned true only in the first direction, which quietly meant a
     * `BOTH` pairing was free in each individual part's cap — so a supervisor
     * could be reported as having room in PSM 1 while their gate disagreed,
     * and the two figures were both "correct" depending on which one was asked.
     *
     * An unrecognised or missing value counts rather than hides: a malformed
     * legacy row must not silently hand out extra capacity.
     */
    public function covers(string|PsmPart|null $other): bool
    {
        // `BOTH` is covered by every part, in both directions.
        if ($this === self::Both) {
            return true;
        }

        $resolved = self::tryParse($other);

        if ($resolved === null) {
            return true;
        }

        return $resolved === self::Both || $resolved === $this;
    }

    /** The other part — used when promoting PSM 1 to PSM 2. */
    public function counterpart(): self
    {
        return match ($this) {
            self::Psm1  => self::Psm2,
            self::Psm2, self::Both => self::Psm1,
        };
    }

    public function isDeliverable(): bool
    {
        return $this !== self::Both;
    }
}
