<?php

namespace App\Enums;

/**
 * Module 4 — Who is assessing, and how much their mark counts.
 *
 * The weight is the *default* share of the aggregate when a project has more
 * than one assessor of a given kind. A coordinator may override per project
 * via the project's grading scheme (see GradeScheme / EvaluationService).
 */
enum AssessorType: string
{
    case Supervisor  = 'supervisor';
    case Examiner    = 'examiner';
    case Coordinator = 'coordinator';

    public function label(): string
    {
        return match ($this) {
            self::Supervisor  => 'Supervisor',
            self::Examiner    => 'Examiner',
            self::Coordinator => 'Coordinator',
        };
    }

    /** Default contribution to the final aggregate, in percent. */
    public function defaultWeight(): float
    {
        return match ($this) {
            self::Supervisor  => 60.0,
            self::Examiner    => 40.0,
            self::Coordinator => 0.0,   // moderating/moderating-only by default
        };
    }

    /** The Role that normally holds this assessor type. */
    public function role(): Role
    {
        return match ($this) {
            self::Supervisor  => Role::Supervisor,
            self::Examiner    => Role::Examiner,
            self::Coordinator => Role::Coordinator,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
