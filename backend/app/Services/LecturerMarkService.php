<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicSemester;
use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 1/4 — coursework marks, keyed by section lecturers.
 *
 * This is deliberately the smallest possible service: a lecturer owns a section,
 * reads its roster, and writes four numbers per student. There is no window, no
 * submission lifecycle, no rubric and no moderation — the requirement is "just
 * key in mark only", and every one of those other things would be a workflow the
 * faculty has not asked for and would then have to be maintained.
 *
 * The one rule it does enforce is ownership: a lecturer writes only their own
 * section. That check lives here as well as in the policy because the aggregate
 * reads these marks, so a wrong section would put a wrong number into a
 * student's final result.
 */
class LecturerMarkService
{
    /** The four component keys, in config order. */
    public static function componentKeys(): array
    {
        return array_keys(LecturerMark::components());
    }

    /**
     * The roster a lecturer keys marks against.
     *
     * Returns the students in the lecturer's section for the term, each with
     * their existing marks row (or null). Students with no section are not
     * anyone's list — an unplaced student is a data gap for the admin, not a
     * queue for a lecturer.
     *
     * @return Collection<int, array{student: StudentProfile, marks: ?LecturerMark, complete: bool}>
     */
    public function rosterForSection(Section $section, ?int $semesterId = null): Collection
    {
        $students = StudentProfile::query()
            ->with('user')
            ->where('section_id', $section->id)
            ->when($semesterId !== null, fn ($q) => $q->where('academic_semester_id', $semesterId))
            ->orderBy('student_id')
            ->get();

        $marks = LecturerMark::query()
            ->whereIn('student_profile_id', $students->pluck('id'))
            ->when($semesterId !== null,
                fn ($q) => $q->where('academic_semester_id', $semesterId),
                fn ($q) => $q->whereNull('academic_semester_id'))
            ->get()
            ->keyBy('student_profile_id');

        return $students->map(function (StudentProfile $student) use ($marks) {
            $mark = $marks->get($student->id);

            return [
                'student'  => $student,
                'marks'    => $mark,
                'complete' => $mark?->isComplete() ?? false,
            ];
        });
    }

    /**
     * Write a student's coursework marks.
     *
     * Every component is validated against its own maximum from config, so the
     * scale is declared once. A mark above the maximum is refused rather than
     * clamped: silently turning 15/12 into 12/12 hides a typo that would
     * otherwise push the student's overall mark up, and the lecturer is the only
     * person who can tell which number they meant.
     *
     * A partial set is accepted and stored. A lecturer who has marked the
     * assignments but not yet the quiz should be able to save their work;
     * `isComplete()` and `percent()` then keep it out of the aggregate until the
     * last component lands, so a half-keyed student is never marked as having
     * scored zero on what is missing.
     *
     * @param  array<string, float|int|string|null>  $values
     *
     * @throws InvalidArgumentException on an unknown component or an out-of-range mark
     */
    public function save(
        StudentProfile $student,
        Section $section,
        array $values,
        User $actor,
        ?int $semesterId = null,
    ): LecturerMark {
        // The student must actually be in this section. Without this a lecturer
        // could key marks for any student id they guessed, and the section on the
        // row would disagree with the student's own placement.
        if ((int) $student->section_id !== (int) $section->id) {
            throw new InvalidArgumentException(
                "{$student->student_id} is not in {$section->code}. Marks may only be keyed for "
                .'students in your own section.'
            );
        }

        $components = LecturerMark::components();
        $clean = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $components)) {
                throw new InvalidArgumentException("Unknown coursework component [{$key}].");
            }

            // Blank means "not entered", which is stored as null rather than 0.
            if ($value === null || $value === '') {
                $clean[$key] = null;

                continue;
            }

            if (! is_numeric($value)) {
                throw new InvalidArgumentException(
                    "{$components[$key]['label']} must be a number."
                );
            }

            $number = (float) $value;

            if ($number < 0) {
                throw new InvalidArgumentException(
                    "{$components[$key]['label']} cannot be negative."
                );
            }

            if ($number > $components[$key]['max']) {
                throw new InvalidArgumentException(sprintf(
                    '%s is out of %s, so %s is not a mark that can be awarded.',
                    $components[$key]['label'],
                    rtrim(rtrim(number_format($components[$key]['max'], 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.'),
                ));
            }

            $clean[$key] = $number;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('No coursework marks were given.');
        }

        $semesterId ??= $student->academic_semester_id
            ?? AcademicSemester::current()?->id;

        return DB::transaction(function () use ($student, $section, $clean, $actor, $semesterId) {
            $mark = LecturerMark::firstOrNew([
                'student_profile_id'   => $student->id,
                'academic_semester_id' => $semesterId,
            ]);

            $before = $mark->exists ? $mark->only(static::componentKeys()) : null;

            $mark->fill($clean);
            $mark->section_id  = $section->id;
            $mark->entered_by  = $actor->id;
            $mark->entered_at  = now();
            $mark->save();

            $this->audit->log(
                action: AuditAction::LecturerMarksRecorded,
                description: $before === null
                    ? "Coursework marks entered for {$student->student_id} in {$section->code}"
                    : "Coursework marks updated for {$student->student_id} in {$section->code}",
                subject: $mark,
                // Both sides of the change: a mark correction is an appeal
                // waiting to happen, and "what did it say before" is the first
                // question asked.
                before: $before ?? [],
                after: $mark->only(static::componentKeys()),
                actor: $actor,
            );

            return $mark->fresh(['section', 'enteredBy']);
        });
    }

    public function __construct(
        protected AuditLogger $audit,
    ) {
    }
}
