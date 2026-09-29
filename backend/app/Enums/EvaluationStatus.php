<?php

namespace App\Enums;

/**
 * Module 4 — Evaluation lifecycle.
 *
 * draft    : assessor is filling the form, invisible to everyone else
 * submitted: marks locked, waiting on the aggregate / moderation
 * moderated: a coordinator adjusted the mark, an audit note is required
 * released : visible to the student
 */
enum EvaluationStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Moderated = 'moderated';
    case Released  = 'released';
    case Recused   = 'recused';   // assessor declared a conflict of interest

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Submitted',
            self::Moderated => 'Moderated',
            self::Released  => 'Released',
            self::Recused   => 'Recused',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft     => 'slate',
            self::Submitted => 'amber',
            self::Moderated => 'violet',
            self::Released  => 'emerald',
            self::Recused   => 'rose',
        };
    }

    /** Only a draft may be edited by its author. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Marks are frozen from this point on. */
    public function isLocked(): bool
    {
        return in_array($this, [self::Submitted, self::Moderated, self::Released], true);
    }

    /** Counts toward the computed aggregate (Module 4) and leaderboard (Module 8). */
    public function countsTowardAggregate(): bool
    {
        return in_array($this, [self::Submitted, self::Moderated, self::Released], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
