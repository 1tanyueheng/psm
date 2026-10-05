<?php

namespace Database\Seeders;

use App\Models\AcademicSemester;
use Illuminate\Database\Seeder;

/**
 * Module 3 — academic semesters.
 *
 * The requirement this system exists to satisfy is that PSM 1 and PSM 2 run
 * *concurrently in the same term*. That is not demonstrable without a term to
 * demonstrate it in: every semester-scoped screen (the coordinator's segmented
 * overview, the title-defence roster, the reports, the grade release toggle)
 * resolves a null `semester_id` to the active term and shows nothing when there
 * is no active term.
 *
 * So this seeder creates the three terms a working faculty needs, rather than a
 * single one:
 *
 *   - 2025/2026 Semester I  — closed and archived: the finished term that
 *     backfills and reports-over-previous-terms are tested against.
 *   - 2025/2026 Semester II — the **active** term, registration open. This is
 *     where PSM 1 and PSM 2 coexist, which is the point of the feature.
 *   - 2026/2027 Semester I  — planned, not yet active, registration closed.
 *
 * Deliberately NOT set on the active term: `is_marks_released`. Results stay
 * withheld until a coordinator opens them, so the release gate
 * (`EvaluationService::assertTermAllowsRelease`) can be exercised in both states
 * without having to hand-edit rows.
 *
 * Idempotent: keyed on the (academic_session, semester_number) unique pair, so
 * re-running refreshes flags rather than duplicating terms or fighting the
 * constraint.
 */
class AcademicSemesterSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            [
                'name'             => '2025/2026 Semester I',
                'academic_session' => '2025/2026',
                'semester_number'  => 1,
                'starts_at'        => '2025-08-18',
                'ends_at'          => '2025-12-19',
                // Finished: not active, registration long shut, results out.
                'is_active'             => false,
                'is_registration_open'  => false,
                'is_marks_released'    => true,
                'marks_released_at'    => '2026-01-14 10:00:00',
                'registration_opened_at'=> '2025-07-01 09:00:00',
                'closed_at'             => '2025-09-05 17:00:00',
            ],
            [
                'name'             => '2025/2026 Semester II',
                'academic_session' => '2025/2026',
                'semester_number'  => 2,
                'starts_at'        => '2026-01-19',
                'ends_at'          => '2026-05-22',
                // The live term, and the only one taking registrations.
                'is_active'             => true,
                'is_registration_open'  => true,
                // Withheld on purpose — see the class docblock.
                'is_marks_released'    => false,
                'marks_released_at'    => null,
                'registration_opened_at'=> '2026-01-05 09:00:00',
                'closed_at'             => null,
            ],
            [
                'name'             => '2026/2027 Semester I',
                'academic_session' => '2026/2027',
                'semester_number'  => 1,
                'starts_at'        => '2026-08-17',
                'ends_at'          => '2026-12-18',
                // Planned: visible in the term list, not yet taking registrations.
                'is_active'             => false,
                'is_registration_open'  => false,
                'is_marks_released'    => false,
                'marks_released_at'    => null,
                'registration_opened_at'=> null,
                'closed_at'             => null,
            ],
        ];

        foreach ($terms as $term) {
            $semester = AcademicSemester::updateOrCreate(
                [
                    'academic_session' => $term['academic_session'],
                    'semester_number'  => $term['semester_number'],
                ],
                $term,
            );

            $this->command?->line(sprintf(
                '  <info>%-24s</info> %s',
                $semester->name,
                $semester->is_active
                    ? 'active, registration ' . ($semester->is_registration_open ? 'open' : 'closed')
                    : ($semester->is_marks_released ? 'archived, results released' : 'planned'),
            ));
        }
    }
}