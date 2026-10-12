<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\AcademicSemester;
use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LecturerMarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 1/4 — the lecturer's coursework screen, and the admin's section setup.
 *
 * Two audiences share this controller because they are two halves of one
 * feature: an admin places a student in a section and appoints its lecturer, and
 * the lecturer keys that student's marks. Splitting them would put the section
 * concept in two files and let the halves drift.
 *
 * A lecturer's reach is deliberately tiny — their own section, its roster, four
 * numbers per student, and nothing else in the system. Every method here is
 * written so that a lecturer cannot widen it: the section is always resolved
 * from the authenticated user rather than from a request parameter, so there is
 * no id to tamper with.
 */
class LecturerMarkController extends ApiController
{
    public function __construct(
        protected LecturerMarkService $marks,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * The lecturer's own section, or null.
     *
     * Resolved from the session, never from a query parameter — the lecturer has
     * exactly one section and asking which one would only create a way to ask
     * for somebody else's.
     */
    public function mySection(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LecturerMark::class);

        $section = $request->user()->lecturerSection();

        // A lecturer with no section is a normal state, not an error: the admin
        // has not appointed them yet. The screen says so rather than showing an
        // empty roster that reads as "no students".
        return $this->ok($section ? $this->sectionPayload($section) : null);
    }

    /**
     * The roster for the lecturer's section, with each student's marks.
     *
     * Returns the students in the lecturer's own section only, so this endpoint
     * cannot be used to enumerate a colleague's roster — the section is not a
     * parameter.
     */
    public function roster(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LecturerMark::class);

        $section = $request->user()->lecturerSection();

        if ($section === null) {
            return $this->ok(['section' => null, 'students' => []]);
        }

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $rows = $this->marks->rosterForSection($section, $semesterId)
            ->map(fn (array $row) => $this->studentRow($row))
            ->values();

        return $this->ok([
            'section'  => $this->sectionPayload($section),
            'students' => $rows,
        ]);
    }

    /**
     * Key one student's coursework marks.
     *
     * The student is checked against the lecturer's own section inside the
     * service, so passing another section's student id is refused rather than
     * silently writing a mark into a roster the lecturer does not own.
     */
    public function saveMarks(Request $request, int $studentProfileId): JsonResponse
    {
        $this->authorize('create', LecturerMark::class);

        $student = StudentProfile::with('user')->findOrFail($studentProfileId);

        $actor = $request->user();

        /**
         * Which section to write into: the lecturer's own, or — for an admin or
         * coordinator correcting a mark — the student's.
         *
         * Staff are not restricted to one section, so for them the student's own
         * placement is authoritative. Using the lecturer's section for everyone
         * would make an admin's correction land in the wrong section whenever
         * the student had been moved.
         */
        $section = $actor->isLecturer()
            ? $actor->lecturerSection()
            : $student->section;

        if ($section === null) {
            return $this->fail(
                $actor->isLecturer()
                    ? 'You are not assigned to a section yet, so there are no marks you can key.'
                    : "{$student->student_id} is not in a section yet. Place them in one first.",
                422
            );
        }

        $this->authorize('key', $section);

        $validated = $request->validate($this->markRules());

        $semesterId = $request->filled('semester_id')
            ? $request->integer('semester_id')
            : null;

        try {
            $mark = $this->marks->save($student, $section, $validated, $actor, $semesterId);
        } catch (InvalidArgumentException $e) {
            // Out-of-range and unknown-component refusals are the lecturer's to
            // fix, so they come back as a validation error rather than a 500.
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok([
            'marks'   => $this->marksPayload($mark),
            'total'   => $mark->total(),
            'percent' => $mark->percent(),
            'complete' => $mark->isComplete(),
        ], 'Coursework marks saved.');
    }

    // -----------------------------------------------------------------
    // Section administration (admin / coordinator)
    // -----------------------------------------------------------------

    /** Every section, with its lecturer and roster size. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Section::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $sections = Section::query()
            ->with('lecturer')
            ->forSemester($semesterId)
            ->orderBy('code')
            ->get()
            ->map(fn (Section $s) => $this->sectionPayload($s) + [
                'student_count' => $s->students()->count(),
            ]);

        return $this->ok($sections);
    }

    /**
     * Rename a section or appoint its lecturer.
     *
     * Appointing is admin-only, per the requirement that a section's lecturer is
     * a faculty decision. A coordinator may read the list but not change who
     * owns a section.
     */
    public function update(Request $request, Section $section): JsonResponse
    {
        $this->authorize('manage', Section::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'lecturer_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                // The account must actually be a lecturer, or the section would
                // be owned by someone whose role cannot open the screen it
                // grants access to.
                Rule::exists('users', 'id')->where('role', 'lecturer'),
            ],
            'academic_semester_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_semesters,id'],
        ]);

        /**
         * One section per lecturer.
         *
         * `User::lecturerSection()` returns a single section, so two rows
         * pointing at the same lecturer would make which one they can key
         * depend on row order. Refused rather than silently allowed.
         */
        if (array_key_exists('lecturer_id', $validated) && $validated['lecturer_id'] !== null) {
            $taken = Section::query()
                ->where('lecturer_id', $validated['lecturer_id'])
                ->whereKeyNot($section->id)
                ->first();

            if ($taken !== null) {
                $name = User::find($validated['lecturer_id'])?->name ?? 'That lecturer';

                return $this->fail(
                    "{$name} already owns {$taken->code}. A lecturer may own only one section.",
                    422
                );
            }
        }

        $before = $section->only(['name', 'lecturer_id', 'academic_semester_id']);

        $section->fill($validated)->save();

        $this->audit->log(
            action: \App\Enums\AuditAction::SectionChanged,
            description: "Section {$section->code} updated",
            subject: $section,
            before: $before,
            after: $section->only(['name', 'lecturer_id', 'academic_semester_id']),
            actor: $request->user(),
        );

        return $this->ok(
            $this->sectionPayload($section->fresh('lecturer')) + [
                'student_count' => $section->students()->count(),
            ],
            'Section updated.'
        );
    }

    /**
     * Place a student into a section, or remove them from one.
     *
     * Admin or coordinator, per the requirement that an admin sets it. A
     * lecturer cannot: letting them add a student to their own roster would let
     * them mark someone the faculty never placed with them.
     */
    public function assignStudent(Request $request, int $studentProfileId): JsonResponse
    {
        $this->authorize('assignStudents', Section::class);

        $validated = $request->validate([
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $student = StudentProfile::with('user')->findOrFail($studentProfileId);

        $before = $student->section_id;

        $student->forceFill(['section_id' => $validated['section_id'] ?? null])->save();

        $this->audit->log(
            action: \App\Enums\AuditAction::StudentSectionAssigned,
            description: $validated['section_id'] ?? null
                ? "{$student->student_id} placed in section "
                    .(Section::find($validated['section_id'])?->code ?? '?')
                : "{$student->student_id} removed from their section",
            subject: $student,
            before: ['section_id' => $before],
            after: ['section_id' => $student->section_id],
            actor: $request->user(),
        );

        return $this->ok([
            'id'         => $student->id,
            'student_id' => $student->student_id,
            'section_id' => $student->section_id,
        ], 'Section placement updated.');
    }

    // -----------------------------------------------------------------
    // Shape
    // -----------------------------------------------------------------

    /** The validation rules for a coursework entry, one per configured component. */
    protected function markRules(): array
    {
        $rules = [];

        foreach (LecturerMark::components() as $key => $spec) {
            // Each component is bounded by its own maximum, so the scale lives
            // in one place (`config/psm.php`) and the rule cannot drift from it.
            $rules[$key] = ['nullable', 'numeric', 'min:0', 'max:'.$spec['max']];
        }

        $rules['semester_id'] = ['nullable', 'integer', 'exists:academic_semesters,id'];

        return $rules;
    }

    protected function sectionPayload(Section $section): array
    {
        return [
            'id'                   => $section->id,
            'code'                 => $section->code,
            'name'                 => $section->name,
            'academic_semester_id' => $section->academic_semester_id,
            'lecturer'             => $section->lecturer ? [
                'id'    => $section->lecturer->id,
                'name'  => $section->lecturer->name,
                'email' => $section->lecturer->email,
            ] : null,
        ];
    }

    protected function marksPayload(LecturerMark $mark): array
    {
        return [
            'id'           => $mark->id,
            'quiz'         => $mark->quiz,
            'assignment_c' => $mark->assignment_c,
            'assignment_p' => $mark->assignment_p,
            'assignment_a' => $mark->assignment_a,
            'entered_at'   => $mark->entered_at?->toIso8601String(),
            'entered_by_name' => $mark->enteredBy?->name,
        ];
    }

    /** One roster row, in the shape the marking table renders. */
    protected function studentRow(array $row): array
    {
        /** @var StudentProfile $student */
        $student = $row['student'];
        /** @var LecturerMark|null $mark */
        $mark = $row['marks'];

        return [
            'id'           => $student->id,
            'student_id'   => $student->student_id,
            'name'         => $student->user?->name,
            'email'        => $student->user?->email,
            'program_code' => $student->program_code,
            'marks'        => $mark ? $this->marksPayload($mark) : null,
            'total'        => $mark?->total(),
            'percent'      => $mark?->percent(),
            'complete'     => $mark?->isComplete() ?? false,
            // Sent so the screen can say "out of 35" without hardcoding it, and
            // so a future change to the split needs no frontend release.
            'components'   => $mark?->breakdown() ?? collect(LecturerMark::components())
                ->map(fn ($spec, $key) => [
                    'key'   => $key,
                    'label' => $spec['label'],
                    'marks' => null,
                    'max'   => (float) $spec['max'],
                ])->values()->all(),
        ];
    }
}
