<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AcademicSemester;
use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\LecturerMarkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The four teaching sections, their lecturers, and the coursework marks.
 *
 * Coursework is 35% of a PSM 1 result — Quiz 5, Assignment-C 12, Assignment-P 9,
 * Assignment-A 9 — keyed by the lecturer who owns the student's section. This
 * seeder creates the sections, appoints a lecturer to each, places the PSM 1
 * cohort across them, and keys marks so the demo shows the component in the
 * aggregate rather than an empty column.
 *
 * **Sections are created in the active term only, and that is the point.** A
 * section row *is* a term's appointment, so the next term starts with no sections
 * at all and gets them by copying this term's — which is what the admin screen's
 * "Copy last term's sections" button does, and what proves the codes repeat
 * across terms (`sections.code` is unique only within a term). Seeding a second
 * term's sections by hand would hide exactly the workflow worth demonstrating.
 *
 * **Only PSM 1 students are placed.** Coursework is weighted into PSM 1 alone
 * (`config/psm.php`), so putting a PSM 2 student in a section would create a
 * roster the lecturer can key marks against while those marks could never count.
 *
 * Marks are keyed through `LecturerMarkService`, not written directly, so the
 * seeded state is one the running application could produce — the same rule the
 * evaluation and registration seeders hold. The grades are then recomputed, in
 * the same order a real term does it.
 */
class LecturerMarkSeeder extends Seeder
{
    /**
     * The four sections the faculty teaches, and who teaches them this term.
     *
     * The staff accounts are created here rather than in `UserSeeder` because
     * they are the demo's four dedicated lecturers; the *real* case is a
     * supervisor who teaches a section, and any of the seeded supervisors can be
     * appointed to one from the admin's Sections screen without losing their
     * supervision.
     */
    private const SECTIONS = [
        ['code' => 'S1', 'name' => 'Section 1', 'name_of' => 'Dr. Farah binti Idris',      'staff' => 'LEC-001'],
        ['code' => 'S2', 'name' => 'Section 2', 'name_of' => 'Mr. Ramesh a/l Subramaniam', 'staff' => 'LEC-002'],
        ['code' => 'S3', 'name' => 'Section 3', 'name_of' => 'Dr. Norhayati binti Salleh', 'staff' => 'LEC-003'],
        ['code' => 'S4', 'name' => 'Section 4', 'name_of' => 'Ms. Tan Li Wen',            'staff' => 'LEC-004'],
    ];

    public function run(): void
    {
        $term = AcademicSemester::current() ?? AcademicSemester::query()->first();

        if ($term === null) {
            $this->command?->warn('  No academic term exists; skipping sections and coursework.');

            return;
        }

        $sections = $this->sections($term);

        $placed = $this->placeStudents($sections, $term);
        $keyed = $this->keyMarks($sections, $term);

        $this->command?->line(sprintf(
            '  Coursework: %d section(s), %d PSM 1 student(s) placed, %d mark set(s) keyed.',
            count($sections),
            $placed,
            $keyed,
        ));
    }

    /**
     * Create or update the four sections and their lecturer accounts.
     *
     * Keyed on **the term and the code together**, because that pair is what a
     * section is: re-running must refresh this term's rows rather than collide
     * with them, and the same code in another term is a different section
     * altogether. (`code` alone used to be the key, back when it was unique
     * globally and a term could only ever have one `S1`.)
     *
     * @return array<int, Section>
     */
    private function sections(AcademicSemester $term): array
    {
        $sections = [];

        foreach (self::SECTIONS as $spec) {
            $lecturer = User::updateOrCreate(
                ['staff_id' => $spec['staff']],
                [
                    'email'      => strtolower($spec['staff']).'@psm.test',
                    'name'       => $spec['name_of'],
                    'password'   => Hash::make('password'),
                    'role'       => Role::Lecturer,
                    'status'     => 'active',
                    'department' => 'Faculty of Computing',
                ]
            );

            $sections[] = Section::updateOrCreate(
                [
                    'academic_semester_id' => $term->id,
                    'code'                 => $spec['code'],
                ],
                [
                    'name'        => $spec['name'],
                    'lecturer_id' => $lecturer->id,
                ]
            );
        }

        return $sections;
    }

    /**
     * Spread the PSM 1 cohort across the sections.
     *
     * Round-robin by student id order, which is what an admin working down an
     * enrolment list would produce. Only students with a PSM 1 project are
     * placed, for the reason in the class docblock.
     *
     * @param  array<int, Section>  $sections
     */
    private function placeStudents(array $sections, AcademicSemester $term): int
    {
        $students = StudentProfile::query()
            ->whereHas('projects', fn ($q) => $q->where('psm_part', 'PSM1'))
            ->orderBy('student_id')
            ->get();

        $placed = 0;

        foreach ($students as $index => $student) {
            $section = $sections[$index % count($sections)];

            $student->forceFill(['section_id' => $section->id])->save();
            $placed++;
        }

        return $placed;
    }

    /**
     * Key coursework for every placed student.
     *
     * The marks are derived from the student's id so a re-seed is stable and two
     * runs cannot disagree, and they are deliberately *varied* — a fixed 80% for
     * everyone would make the component look like decoration rather than
     * something that moves a mark.
     *
     * @param  array<int, Section>  $sections
     */
    private function keyMarks(array $sections, AcademicSemester $term): int
    {
        $service = app(LecturerMarkService::class);
        $evaluations = app(EvaluationService::class);
        $keyed = 0;

        foreach ($sections as $section) {
            $lecturer = $section->lecturer;

            if ($lecturer === null) {
                continue;
            }

            foreach ($section->students()->orderBy('student_id')->get() as $student) {
                // A deterministic spread of roughly 55-95% per component, so the
                // cohort shows a range without any randomness between runs.
                $seed = $student->id * 7919;

                $values = [];

                foreach (LecturerMark::components() as $key => $spec) {
                    $max = (float) $spec['max'];

                    // 55% .. 95% in five-point steps.
                    $fraction = (55 + (($seed + crc32($key)) % 9) * 5) / 100;

                    $values[$key] = round($max * $fraction, 2);
                }

                $service->save($student, $section, $values, $lecturer, $term->id);
                $keyed++;

                /**
                 * Recompute the student's PSM 1 mark now the component exists.
                 *
                 * Done here rather than in a later pass so the seeded grade always
                 * agrees with the seeded marks. `computeFinalGrade()` writes the
                 * aggregate but deliberately does not decide visibility — that is
                 * `MarkVisibilityService`, which the evaluation seeder already
                 * ran — so a grade it locked keeps its status and the recomputed
                 * number lands underneath it.
                 */
                foreach ($student->projects()->where('psm_part', 'PSM1')->get() as $project) {
                    $evaluations->computeFinalGrade($project, $student->id);
                }
            }
        }

        return $keyed;
    }
}
