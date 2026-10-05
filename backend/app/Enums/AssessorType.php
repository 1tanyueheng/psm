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

    /*
     * `defaultWeight()` used to live here, returning supervisor 60 / examiner
     * 40 / coordinator 0. It is gone because the weighting is no longer per
     * role: it is per official form (see `psm.assessment_weights`), so that
     * Lampiran G 50 and Lampiran H 5 can be told apart even though both are
     * supervisor forms. A role-level weight would have merged them.
     *
     * The coordinator appears in no weighting at all — they moderate the
     * process, they do not award marks.
     */

    /**
     * The Role that normally holds this assessor type.
     *
     * `Examiner` maps to `Role::Supervisor` because being an examiner is a
     * seating, not a role — a panel is drawn from the academic staff who
     * supervise. Which *form* someone fills is decided by the assessor type on
     * the evaluation, not by who they are: the same person fills the supervisor's
     * form for their own student and the examiner's form for a student whose
     * panel they sit on.
     */
    public function role(): Role
    {
        return match ($this) {
            self::Supervisor  => Role::Supervisor,
            self::Examiner    => Role::Supervisor,
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
