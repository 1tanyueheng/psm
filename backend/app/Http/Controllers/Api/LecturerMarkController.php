<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\Role;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     * The lecturer's own section for a term, or null.
     *
     * Resolved from the session, never from the caller — the lecturer has one
     * section per term, and asking *which section* would only create a way to ask
     * for somebody else's.
     *
     * The **term** is a parameter, which is not the same thing: the term selects
     * among the sections this person already lectures, so the worst a tampered
     * value can do is show them their own roster from another term. That is a
     * feature — it is how a teacher reads the marks they keyed last term — and
     * the answer is null for a term they did not teach in, because the section is
     * still resolved by `lecturer_id`.
     */
    public function mySection(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LecturerMark::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $section = $request->user()->lecturerSection($semesterId);

        // A lecturer with no section in this term is a normal state, not an
        // error: either the admin has not provisioned this term's sections yet or
        // somebody else teaches in it. The screen says so rather than showing an
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

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $section = $request->user()->lecturerSection($semesterId);

        if ($section === null) {
            return $this->ok(['section' => null, 'students' => []]);
        }

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
         * Which term the marks belong to.
         *
         * Resolved here rather than left to the service because the *section* to
         * write into is a different row in every term: the same person lectures
         * this term's S1 and last term's S2, and the students of one are not in
         * the other. The precedence is the one the service uses — an explicit
         * request, else the student's own term, else the live term — so a
         * correction to an old student's marks lands where the student is.
         */
        $requested = $request->input('semester_id');

        $semesterId = ($requested === null || $requested === '' || $requested === 'current')
            ? ($student->academic_semester_id ?? AcademicSemester::current()?->id)
            : AcademicSemester::resolveFilterId($requested);

        /**
         * Which section to write into: the lecturer's own for that term, or — for
         * an admin or coordinator correcting a mark — the student's.
         *
         * Staff are not restricted to one section, so for them the student's own
         * placement is authoritative. Using the lecturer's section for everyone
         * would make an admin's correction land in the wrong section whenever
         * the student had been moved.
         */
        $corrects = $actor->hasRole('admin', 'coordinator');
        $ownSection = $actor->lecturerSection($semesterId);

        /**
         * Refuse before the section is even resolved.
         *
         * The person keying is often a supervisor who teaches a section: they
         * hold no lecturer role, but the section they were appointed to is the
         * only one they may write into. Someone with neither the role nor an
         * appointment in this term — a supervisor with no section, a student, or
         * a teacher whose section was this term's and not that one — may not key
         * at all, and answering "the student has no section" would send them to
         * fix the wrong thing.
         */
        if (! $corrects && $ownSection === null) {
            return $this->fail(
                $actor->isLecturer()
                    ? 'You are not assigned to a section in this term, so there are no marks you can key.'
                    : 'You are not the lecturer for a section in this term, so there are no marks you can key.',
                403
            );
        }

        $section = $corrects ? $student->section : $ownSection;

        if ($section === null) {
            return $this->fail(
                "{$student->student_id} is not in a section yet. Place them in one first.",
                422
            );
        }

        /**
         * Authorised against the section row (see `SectionPolicy::key()`), so
         * that a teacher appointed to *this term's* S1 cannot write into last
         * term's S1 — two different rows that only look alike.
         */
        $this->authorize('key', $section);

        $validated = $request->validate($this->markRules());

        /**
         * `semester_id` is a placement, not a mark: it names which term's row to
         * write into, and the service below refuses any key it does not
         * recognise as a component. Handing it the whole validated payload
         * therefore refused every save that named a term — with "Unknown
         * coursework component [semester_id]" — which is every save the marking
         * screen makes, since the screen always knows its term.
         *
         * Filtering against the components keeps the split honest in the other
         * direction too: a rule added here later for something that is not a
         * mark travels no further than this line.
         */
        $values = array_intersect_key($validated, LecturerMark::components());

        try {
            $mark = $this->marks->save($student, $section, $values, $actor, $semesterId);
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
            ->with(['lecturer', 'academicSemester'])
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
            'course_code' => ['sometimes', 'string', Rule::in(array_keys(Section::courses()))],
            'lecturer_id' => $this->lecturerRules(),
            'academic_semester_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_semesters,id'],
        ]);

        /**
         * One section per teacher **per course per term**.
         *
         * The rule is about a person, and a person teaches one class of a course
         * at a time: two rows naming the same lecturer for the same course in
         * one term would make which one they can key depend on row order. Across
         * terms it is expected — the seating rotates, and last term's rows keep
         * the person who taught them — so the check is narrowed to the term and
         * course being written, taken from the request when the row is being
         * moved and from the row otherwise.
         *
         * Since 2026-11-02 the course is part of the rule: the term runs both
         * BIC31602 and BIT34002, and teaching one section of each is not the
         * fault this check exists to catch.
         */
        if (array_key_exists('lecturer_id', $validated) && $validated['lecturer_id'] !== null) {
            $termId = $validated['academic_semester_id'] ?? $section->academic_semester_id;
            $courseCode = $validated['course_code'] ?? $section->course_code;

            $taken = $this->sectionTakenBy((int) $validated['lecturer_id'], $termId, $section->id, $courseCode);

            if ($taken !== null) {
                $name = User::find($validated['lecturer_id'])?->name ?? 'That lecturer';

                return $this->fail(
                    "{$name} already teaches {$taken->code} for {$courseCode} this term. "
                    .'A teacher may teach only one section of a course per term.',
                    422
                );
            }
        }

        $before = $section->only(['name', 'lecturer_id', 'academic_semester_id']);

        $section->fill($validated)->save();

        $this->audit->log(
            action: AuditAction::SectionChanged,
            description: "Section {$section->code} updated",
            subject: $section,
            before: $before,
            after: $section->only(['name', 'lecturer_id', 'academic_semester_id']),
            actor: $request->user(),
        );

        return $this->ok(
            $this->sectionPayload($section->fresh(['lecturer', 'academicSemester'])) + [
                'student_count' => $section->students()->count(),
            ],
            'Section updated.'
        );
    }

    /**
     * Add a section to a term.
     *
     * Sections are provisioned per term, and a term's list is not always the last
     * one's: a cohort can be split into a fifth class, or a code retired, so the
     * list has to be editable rather than fixed at four. `copy()` covers the
     * ordinary case in one click; this is for the difference.
     *
     * No lecturer is required. A section with nobody appointed is a real state —
     * it is where students wait until the faculty decides who teaches them — and
     * the screen flags it. It is also the only state in which *nobody* may key
     * that section's marks, which is why the flag is a warning rather than a
     * cosmetic detail.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage', Section::class);

        /**
         * The term is resolved before validation so the uniqueness rule can be
         * scoped to it.
         *
         * `code` is unique *within a term*, never globally: every term teaches an
         * S1. A plain `unique:sections,code` would therefore refuse the second
         * term's first section, which is exactly the work this screen exists to
         * do.
         */
        $semesterId = AcademicSemester::resolveFilterId($request->input('academic_semester_id'));

        if ($semesterId === null) {
            return $this->fail(
                'There is no academic term to add a section to. Create or activate a term first.',
                422
            );
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:16',
            ],
            // The offering the class belongs to. `BIC31602` serves BIP/BIW/BIS/BIM
            // and `BIT34002` serves BIT; they are different courses with their
            // own sections and their own teachers.
            'course_code' => [
                'sometimes',
                'string',
                Rule::in(array_keys(Section::courses())),
            ],
            'name'        => ['sometimes', 'nullable', 'string', 'max:128'],
            'lecturer_id' => $this->lecturerRules(),
        ]);

        $code = strtoupper(trim($validated['code']));
        $courseCode = strtoupper(trim((string) ($validated['course_code'] ?? Section::COURSE_BIC)));

        /**
         * `code` is unique within a **term and a course**, never globally.
         *
         * This used to be a validation rule; it is now an explicit check so the
         * refusal can name the course. `S1` exists once per course per term by
         * design — every term teaches an S1 in each offering — so a plain
         * `unique:sections,code` would refuse the second term's first section,
         * and a rule scoped to the term alone would refuse the BIT group's S1
         * because the BIC group already has one.
         */
        $duplicate = Section::query()
            ->where('academic_semester_id', $semesterId)
            ->where('course_code', $courseCode)
            ->where('code', $code)
            ->exists();

        if ($duplicate) {
            return $this->fail(
                "{$courseCode} already has a section {$code} this term. "
                .'A code names one class per course per term.',
                422
            );
        }

        $lecturerId = $validated['lecturer_id'] ?? null;

        if ($lecturerId !== null) {
            $taken = $this->sectionTakenBy((int) $lecturerId, $semesterId, null, $courseCode);

            if ($taken !== null) {
                $name = User::find($lecturerId)?->name ?? 'That lecturer';

                return $this->fail(
                    "{$name} already teaches {$taken->code} for {$courseCode} this term. "
                    .'A teacher may teach only one section of a course per term.',
                    422
                );
            }
        }

        /**
         * The name is derived when it is left out.
         *
         * The schema requires one, and the faculty refers to these as "Section 1"
         * anyway, so asking for both fields would be busywork that invites two
         * spellings of the same class. A code with no digits in it ("LAB-A") keeps
         * its own text as the name rather than becoming "Section ".
         */
        $digits = trim((string) preg_replace('/\D+/', '', $code));

        $section = Section::create([
            'code'                 => $code,
            'name'                 => $validated['name'] ?? ($digits !== '' ? "Section {$digits}" : $code),
            'course_code'          => $courseCode,
            'lecturer_id'          => $lecturerId,
            'academic_semester_id' => $semesterId,
        ]);

        $this->audit->log(
            action: AuditAction::SectionChanged,
            description: "Section {$section->code} created in ".($section->academicSemester?->name ?? 'a term'),
            subject: $section,
            before: [],
            after: $section->only(['code', 'name', 'lecturer_id', 'academic_semester_id']),
            actor: $request->user(),
        );

        return $this->ok(
            $this->sectionPayload($section->fresh(['lecturer', 'academicSemester'])) + [
                'student_count' => 0,
            ],
            "Section {$section->code} added."
        );
    }

    /**
     * Copy a term's sections from the term before it.
     *
     * The faculty teaches the same codes every term, so opening a term means
     * re-creating the same four rows, and — usually — appointing the same four
     * people. Re-typing either is where mistakes come from, and the previous
     * term's rows are the authoritative record of both, so both are copied from
     * there rather than retyped.
     *
     * **It only ever adds.** A code that already exists in the target term is
     * skipped, not duplicated and not refused, so the button can be pressed
     * twice, or pressed after somebody added a section by hand, without making a
     * mess or an error.
     *
     * **Students are never copied.** A class list is a fact about one term; the
     * new term's students arrive as a fresh list (`importStudents`).
     *
     * `include_lecturers` is a separate flag because the two halves are decided
     * at different moments: the codes when the term is opened, the teachers once
     * the faculty has met. A copied appointment is dropped when it would put
     * somebody in two sections of the *target* term, since one section per teacher
     * per term is the rule the term is supposed to start out respecting.
     */
    public function copy(Request $request): JsonResponse
    {
        $this->authorize('manage', Section::class);

        $validated = $request->validate([
            'semester_id'       => ['sometimes', 'nullable', 'integer', 'exists:academic_semesters,id'],
            'from_semester_id'  => ['sometimes', 'nullable', 'integer', 'exists:academic_semesters,id'],
            'include_lecturers' => ['sometimes', 'boolean'],
        ]);

        // `resolve()` rather than `resolveFilterId()`: "the term being worked in"
        // is the sensible default, but a caller who names a term means that one,
        // and the target of a copy must be a term that exists.
        $target = AcademicSemester::resolve($validated['semester_id'] ?? null)
            ?? AcademicSemester::current();

        if ($target === null) {
            return $this->fail(
                'There is no academic term to copy sections into. Create or activate a term first.',
                422
            );
        }

        $source = isset($validated['from_semester_id'])
            ? AcademicSemester::find($validated['from_semester_id'])
            : AcademicSemester::previousOf($target);

        if ($source === null) {
            return $this->fail('There is no earlier term to copy sections from.', 422);
        }

        if ($source->id === $target->id) {
            return $this->fail('A term cannot be copied from itself.', 422);
        }

        $includeLecturers = (bool) ($validated['include_lecturers'] ?? false);

        $sourceSections = Section::query()->forSemester($source->id)
            ->orderBy('course_code')->orderBy('code')->get();

        if ($sourceSections->isEmpty()) {
            return $this->fail("{$source->name} has no sections to copy.", 422);
        }

        // Codes are compared case-insensitively because the admins type them by
        // hand; `S1` and `s1` are the same class and must not both be created.
        //
        // Keyed on course AND code since 2026-11-02: a term runs two offerings of
        // the service course, each with its own S1, so a code alone no longer
        // identifies a class — copying would otherwise skip the BIT group's S1
        // as though the BIC group's one already covered it.
        $existing = Section::query()
            ->forSemester($target->id)
            ->get(['course_code', 'code'])
            ->map(fn ($s) => strtoupper(trim((string) $s->course_code))
                .'|'.strtoupper(trim((string) $s->code)))
            ->all();

        [$created, $skipped, $withoutLecturer] = DB::transaction(function () use (
            $sourceSections,
            $existing,
            $target,
            $includeLecturers,
            $request
        ) {
            $created = [];
            $skipped = 0;
            $withoutLecturer = 0;

            foreach ($sourceSections as $from) {
                $code = strtoupper(trim((string) $from->code));
                $courseCode = strtoupper(trim((string) $from->course_code));

                if (in_array($courseCode.'|'.$code, $existing, true)) {
                    $skipped++;

                    continue;
                }

                $lecturerId = $includeLecturers ? $from->lecturer_id : null;

                if ($lecturerId !== null && $this->sectionTakenBy((int) $lecturerId, $target->id, null, $courseCode) !== null) {
                    $lecturerId = null;
                    $withoutLecturer++;
                }

                $section = Section::create([
                    'code'                 => $code,
                    'name'                 => $from->name,
                    'course_code'          => $courseCode,
                    'lecturer_id'          => $lecturerId,
                    'academic_semester_id' => $target->id,
                ]);

                $this->audit->log(
                    action: AuditAction::SectionChanged,
                    description: "Section {$section->code} copied into {$target->name} from {$from->academicSemester?->name}",
                    subject: $section,
                    before: [],
                    after: $section->only(['code', 'name', 'course_code', 'lecturer_id', 'academic_semester_id']),
                    actor: $request->user(),
                );

                $created[] = ['id' => $section->id, 'code' => $section->code, 'course_code' => $section->course_code];
            }

            return [$created, $skipped, $withoutLecturer];
        });

        $payload = [
            'term'             => ['id' => $target->id, 'name' => $target->name],
            'source'           => ['id' => $source->id, 'name' => $source->name],
            'created'          => $created,
            'skipped'          => $skipped,
            'without_lecturer' => $withoutLecturer,
        ];

        if ($created === []) {
            return $this->ok(
                $payload,
                "{$target->name} already has every section from {$source->name}."
            );
        }

        $count = count($created);
        $message = "Copied {$count} section" . ($count === 1 ? '' : 's') . " into {$target->name} from {$source->name}.";

        if ($includeLecturers) {
            $message .= $withoutLecturer > 0
                ? " {$withoutLecturer} kept no teacher, because that person already teaches another section of this term."
                : ' Their teachers were copied too.';
        }

        return $this->ok($payload, $message);
    }

    /**
     * Candidates for a section's lecturer, for the admin's picker.
     *
     * Lecturers *and* supervisors, because the staff who teach a section are
     * usually supervisors. Each row says which section the person already teaches
     * **in the term being edited**, so the picker can show "already teaches S2"
     * rather than offering somebody the one-section rule will refuse a moment
     * later — and so the same person reads as free in a term where they teach
     * nothing.
     */
    public function lecturerOptions(Request $request): JsonResponse
    {
        $this->authorize('manage', Section::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $users = User::query()
            ->active()
            ->whereIn('role', self::appointableRoles())
            /**
             * Eager-loaded, and it matters: the section each candidate teaches is
             * read once per row, so without this a staff list of thirty costs
             * thirty queries to draw one dropdown. `lecturerSection()` prefers the
             * loaded relation, and filters it by term itself — which is why the
             * eager load is not narrowed to the term here: one candidate can be
             * teaching this term's S1 and last term's S2, and both rows are needed
             * to answer for either term.
             */
            ->with('sectionsLectured:id,code,lecturer_id,academic_semester_id')
            ->orderBy('name')
            ->get();

        return $this->ok($users->map(function (User $u) use ($semesterId) {
            $section = $u->lecturerSection($semesterId);

            return [
                'value' => $u->id,
                'label' => $u->displayName(),
                'meta'  => [
                    'role'       => $u->role->value,
                    'role_label' => $u->role->label(),
                    'email'      => $u->email,
                    'staff_id'   => $u->staff_id,
                    'section'    => $section ? [
                        'id'   => $section->id,
                        'code' => $section->code,
                    ] : null,
                ],
            ];
        }));
    }

    /**
     * Place a student into a section, or remove them from one.
     *
     * Admin or coordinator, per the requirement that an admin sets it. A
     * lecturer cannot: letting them add a student to their own roster would let
     * them mark someone the faculty never placed with them.
     *
     * A student holds **one** section pointer, not one per term, so this is a
     * move rather than an addition: placing somebody in a section replaces
     * whatever placement they had. The section named carries its own term, which
     * is how the roster screen knows which class — and which term's marks — the
     * student now belongs to. The endpoint does not take a term of its own for
     * that reason: the answer would be the section's, and two ways to say it
     * could disagree.
     */
    public function assignStudent(Request $request, int $studentProfileId): JsonResponse
    {
        $this->authorize('assignStudents', Section::class);

        $validated = $request->validate([
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $student = StudentProfile::with('user')->findOrFail($studentProfileId);

        /**
         * The student must be placed in their own programme's course.
         *
         * A term runs two offerings of the service course, each with its own
         * sections and its own S1, so `section_id` alone can name the wrong one.
         * The check is here as well as in the CSV import because this endpoint
         * is what the roster panel calls, and a panel that offers only what the
         * save would accept is the only way the admin never meets a refusal they
         * cannot act on.
         */
        if (($validated['section_id'] ?? null) !== null) {
            $target = Section::find($validated['section_id']);
            $wanted = Section::courseForProgramme($student->program_code);

            if ($target !== null && $target->course_code !== $wanted) {
                return $this->fail(
                    "Section {$target->code} is {$target->course_code}, but a student on "
                    .($student->program_code ?: 'that programme')." takes {$wanted}.",
                    422
                );
            }
        }

        $before = $student->section_id;

        $student->forceFill(['section_id' => $validated['section_id'] ?? null])->save();

        $this->audit->log(
            action: \App\Enums\AuditAction::StudentSectionAssigned,
            description: $validated['section_id'] ?? null
                ? "{$student->student_id} placed in section "
                    .(Section::find($validated['section_id'])?->qualifiedLabel() ?? '?')
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

    /**
     * Place students into sections from a class list (CSV).
     *
     * The list names a section **code**; since 2026-11-02 that is no longer
     * enough to identify the class, because a term runs two offerings and each
     * has its own S1. The student's own programme resolves the course
     * (`Section::courseForProgramme()`), so a BIT student lands in the BIT
     * group without the upload having to say so and without the admin being able
     * to put them in the wrong class by accident.
     *
     * A student whose programme has no section under that code is reported, not
     * silently placed: "no such class" and "wrong class for this student" are
     * different facts and only the first is the uploader's typo.
     *
     * The faculty already knows who is in which section — it arrives from the
     * enrolment office as a spreadsheet — so the sheet is accepted as it is
     * rather than asking an admin to make the same decision sixty times through
     * the roster panel.
     *
     * **Every row is resolved on its own and reported.** One unrecognised matric
     * number costs that row and not the upload; nothing is written for a row
     * that cannot be resolved, and the response says which rows those were. A
     * bulk action that fails half way through without a report leaves an admin
     * comparing two lists by eye to find out which half.
     *
     * The sheet carries two things: the matric number and the section code.
     * Columns are matched by header name where the file has a header ("Matric
     * No", "Section") and by position — matric first, section second — where it
     * does not. Extra columns are ignored, because the name column of a class
     * list is not ours to overwrite and a student's name comes from their
     * account.
     *
     * The term comes from the section being named, not from the file: section
     * codes are resolved among the sections of the term in view, so an upload
     * while looking at 2026/2027 cannot place a student using last year's S1.
     */
    public function importStudents(Request $request): JsonResponse
    {
        $this->authorize('assignStudents', Section::class);

        $validated = $request->validate([
            /**
             * A file or pasted text, not both. The paste path exists because a
             * class list often arrives in an email body, and because it makes
             * the endpoint usable without multipart form encoding.
             */
            'file' => ['required_without:csv', 'file', 'mimes:csv,txt', 'max:2048'],
            'csv'  => ['required_without:file', 'string', 'max:1048576'],
        ]);

        $text = $request->hasFile('file')
            ? (string) $request->file('file')->get()
            : (string) $validated['csv'];

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        /**
         * An upload must name the term it is placing into.
         *
         * Codes repeat across terms now, so "which S1?" has no answer without
         * one: `forSemester(null)` resolves the code against every term at once,
         * and the `keyBy` below would silently keep whichever row it read last —
         * placing students in a term nobody chose. Falling back to the live term
         * keeps the pasted-text path usable, but a database with no active term
         * is refused rather than guessed at.
         */
        $semesterId ??= AcademicSemester::current()?->id;

        if ($semesterId === null) {
            return $this->fail(
                'There is no academic term to place students into. Create or activate a term first.',
                422
            );
        }

        // Resolved once for the whole upload: a class list is sixty rows and a
        // query per row would be sixty round trips to find data that fits in
        // memory several times over.
        //
        // The map is keyed on COURSE|CODE, because a code alone is ambiguous
        // since a term runs two offerings of the service course. A one-code
        // lookup would hand every student whichever S1 happened to be loaded
        // last.
        $sections = Section::query()
            ->forSemester($semesterId)
            ->get()
            ->keyBy(fn (Section $s) => strtoupper(trim((string) $s->course_code))
                .'|'.strtoupper(trim((string) $s->code)));

        $students = StudentProfile::query()
            ->get()
            ->keyBy(fn (StudentProfile $p) => strtoupper(trim((string) $p->student_id)));

        [$matricCol, $sectionCol, $rows] = $this->readClassList($text);

        $report  = [];
        $placed  = 0;
        $already = 0;
        $failed  = 0;

        foreach ($rows as $index => $cells) {
            // Humans count from 1, and a header occupies line 1 — so the row at
            // offset 0 is line 2. The point of the number is that the admin can
            // open the sheet and read the offending row.
            $line = $index + 2;

            $matric = strtoupper(trim((string) ($cells[$matricCol] ?? '')));
            $code   = strtoupper(trim((string) ($cells[$sectionCol] ?? '')));

            if ($matric === '' && $code === '') {
                continue;
            }

            $row = ['line' => $line, 'student_id' => $matric, 'section_code' => $code];

            if ($matric === '') {
                $report[] = $row + ['status' => 'error', 'message' => 'No matric number in this row.'];
                $failed++;
                continue;
            }

            $student = $students[$matric] ?? null;

            if ($student === null) {
                $report[] = $row + ['status' => 'error', 'message' => 'No student account with this matric number.'];
                $failed++;
                continue;
            }

            $section = $sections[Section::courseForProgramme($student->program_code).'|'.$code] ?? null;

            if ($section === null) {
                $report[] = $row + [
                    'status'  => 'error',
                    'message' => $code === ''
                        ? 'No section code in this row.'
                        : "No section {$code} for "
                            .Section::courseForProgramme($student->program_code)
                            .' (the course a '
                            .($student->program_code ?: 'this')
                            .' student takes) in this term.',
                ];
                $failed++;
                continue;
            }

            /**
             * The student must belong to the course the section teaches.
             *
             * `S1` exists twice in a term now — once per offering — and the
             * upload names only the code, so the student's programme is what
             * decides which of the two they are being placed in. A BIT student
             * named against the BIC group's S1 is refused here rather than
             * quietly seated in the wrong cohort's class.
             */
            $wanted = Section::courseForProgramme($student->program_code);

            if ($wanted !== $section->course_code) {
                $report[] = $row + [
                    'status'  => 'error',
                    'message' => "Section {$code} in this term is {$section->course_code}, "
                        ."but a student on {$student->program_code} takes {$wanted}.",
                ];
                $failed++;
                continue;
            }

            $row['section_id'] = $section->id;

            if ($student->section_id === $section->id) {
                $report[] = $row + ['status' => 'unchanged', 'message' => "Already placed in {$section->code}."];
                $already++;
                continue;
            }

            $before = $student->section_id;

            $student->forceFill(['section_id' => $section->id])->save();

            /**
             * One entry per placement, in the same shape the roster panel
             * writes. An auditor asking "who moved this student, and when" gets
             * the same answer whether the move was made one student at a time or
             * by a sheet — so the student's own history stays complete rather
             * than pointing at a bulk job that no longer exists.
             */
            $this->audit->log(
                action: AuditAction::StudentSectionAssigned,
                description: "{$student->student_id} placed in section {$section->code} (class list)",
                subject: $student,
                before: ['section_id' => $before],
                after: ['section_id' => $student->section_id],
                actor: $request->user(),
            );

            $report[] = $row + ['status' => 'placed', 'message' => "Placed in {$section->code}."];
            $placed++;
        }

        return $this->ok([
            'total'     => count($report),
            'placed'    => $placed,
            'unchanged' => $already,
            'failed'    => $failed,
            'rows'      => $report,
        ], "Class list processed: {$placed} placed, {$already} already in place, {$failed} skipped.");
    }

    /**
     * Split a class list into data rows and work out which column is which.
     *
     * A header row is read when there is one, because "Matric No, Name, Section"
     * says what the columns are and nothing else can beat being told. Plenty of
     * lists arrive without one, though — pasted straight out of a spreadsheet
     * that was never given headings — and a list whose only clue is its content
     * is still perfectly readable: `AI240009` is a matric number wherever it
     * sits, and `S1` is a section.
     *
     * So the content decides, but only when the header did not, and only when a
     * column really does explain the rows. Assuming positions instead is what
     * turned the second column of `AI240009,Nurul,S1` into a section called
     * "NURUL" and then refused the row for naming a section no term has — a
     * confusing thing to be told about a list that was in fact correct.
     *
     * @return array{0: int, 1: int, 2: array<int, array<int, string>>}
     */
    protected function readClassList(string $text): array
    {
        // Excel writes a UTF-8 byte-order mark, and it would otherwise ride
        // along on the first column's header and defeat the header test.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                // Quoted fields are honoured, but a quoted field containing a
                // newline is not: a matric number and a section code never need
                // one, and supporting it costs a real parser.
                $rows[] = str_getcsv($line);
            }
        }

        $matric  = null;
        $section = null;

        if ($rows !== [] && $this->looksLikeHeader($rows[0])) {
            foreach (array_map(fn ($c) => strtolower(trim((string) $c)), $rows[0]) as $i => $cell) {
                if (preg_match('/matric|student|\bid\b/', $cell)) {
                    $matric = $i;
                }

                if (preg_match('/section|group|class/', $cell)) {
                    $section = $i;
                }
            }

            array_shift($rows);
        }

        // Whatever the header did not settle, the data may. The old defaults are
        // kept as the last resort, since a two-column list really is matric then
        // section.
        $matric  ??= $this->guessColumn($rows, fn ($cell) => $this->looksLikeMatric($cell)) ?? 0;
        // The matric column is excluded from the search for the section: a bare
        // number is a section code as far as `looksLikeSectionCode()` is
        // concerned, so `240009, S1` would otherwise take the matric number for
        // the section and leave the real section unread.
        $section ??= $this->guessColumn($rows, fn ($cell) => $this->looksLikeSectionCode($cell), [$matric])
            ?? ($matric === 1 ? 0 : 1);

        return [$matric, $section, $rows];
    }

    /**
     * Which column holds this kind of value, if any column does?
     *
     * The winner has to account for at least half the rows it is shown, and ties
     * go to the leftmost column. A "winner" with one hit out of five hundred is
     * a coincidence — a student's programme code, a stray heading — and believing
     * it would move the misread from one column to another. Below that bar the
     * honest answer is "no column", and the caller's own "unknown matric number"
     * or "no section S9 in this term" says something true about the row.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  callable(string): bool  $looksRight
     * @param  array<int, int>  $skip  Columns already spoken for.
     */
    protected function guessColumn(array $rows, callable $looksRight, array $skip = []): ?int
    {
        if ($rows === []) {
            return null;
        }

        $best       = null;
        $bestHits   = 0;
        $width      = max(array_map('count', $rows));

        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }

            $hits = 0;

            foreach ($rows as $row) {
                if (isset($row[$i]) && $looksRight(trim((string) $row[$i]))) {
                    $hits++;
                }
            }

            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best     = $i;
            }
        }

        return $bestHits * 2 >= count($rows) ? $best : null;
    }

    /**
     * `AI240009`, `A123456`, `BIW-1234`: letters then a run of digits.
     *
     * A section code is not this shape — `S1` is one letter and one digit — and
     * neither is a person's name, which is what makes the test worth having. The
     * digit run is what keeps the two apart, so it is `S1` rather than `S123`
     * that the section guess can safely claim.
     */
    protected function looksLikeMatric(string $cell): bool
    {
        return (bool) preg_match('/^[a-z]{1,4}[\s._-]*\d{3,}$/i', $cell);
    }

    /**
     * `S1`, `Sec 2`, `Section-3`, `Group 4`, `Class 5`, and a bare `1`.
     *
     * A number on its own counts: "put the 3s in section 3" is a real list format
     * and there is nothing else in a class list a lone digit could be. A name or
     * a matric number is not this shape, which is the whole point of the test.
     */
    protected function looksLikeSectionCode(string $cell): bool
    {
        return (bool) preg_match('/^(?:s|sec|sect|section|g|grp|group|c|class)?[\s._-]*\d+$/i', $cell);
    }

    /**
     * Does this first row name columns rather than carry data?
     *
     * Only used to decide whether to skip a row. It looks for header *words*
     * rather than testing whether the row resolves, because a column headed
     * "Section" holds the same shape as the data under it, and mistaking a
     * header for data would try to place a student called "Matric No" in a
     * section called "Section".
     */
    protected function looksLikeHeader(array $row): bool
    {
        $joined = strtolower(implode(' ', array_map(fn ($c) => (string) $c, $row)));

        return (bool) preg_match('/matric|student|section|group|class|name/', $joined);
    }

    // -----------------------------------------------------------------
    // Shape
    // -----------------------------------------------------------------

    /**
     * The role values that may be appointed as a section's lecturer.
     *
     * Read from `Role::canLectureSection()` rather than written out here, so the
     * appointment rule lives in one place: the enum says who may hold the
     * seating, the validation rule and the picker both consult it, and a role
     * that gains or loses the right to teach a section changes behaviour without
     * anyone having to remember this file. The seating lasts a term; the *role*
     * is what qualifies somebody to be given it, and nothing here depends on
     * which terms they currently hold.
     *
     * @return array<int, string>
     */
    protected static function appointableRoles(): array
    {
        return array_values(array_map(
            fn (Role $role) => $role->value,
            array_filter(Role::cases(), fn (Role $role) => $role->canLectureSection()),
        ));
    }

    /**
     * The validation rules for a section's lecturer.
     *
     * Shared by `store()` and `update()` so the two ways of appointing somebody
     * cannot drift apart — the picker's candidate list comes from the same enum
     * value (`appointableRoles()`), and an appointment made by either route has
     * to pass the same test.
     *
     * @return array<int, mixed>
     */
    protected function lecturerRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            'exists:users,id',
            /**
             * Who may be appointed: a lecturer, or a supervisor.
             *
             * A supervisor is the ordinary case — the staff who teach a
             * coursework section usually supervise PSM projects as well, and
             * `users.role` holds exactly one value, so requiring the `lecturer`
             * role would mean stripping a supervisor of their supervision to let
             * them teach. The appointment is a seating, and the policy reads the
             * entitlement from it rather than from the role
             * (`Role::canLectureSection`).
             *
             * Coordinators and admins are excluded: they already correct any
             * section's marks, and naming one as a section's *own* lecturer
             * would redirect their corrections into that section (`saveMarks`).
             */
            Rule::exists('users', 'id')->whereIn('role', self::appointableRoles()),
        ];
    }

    /**
     * The *other* section of the same course, in the same term, that this person
     * already teaches — if any.
     *
     * One section per teacher per course per term is the faculty's rule: four
     * classes, four different people, and nobody was ever expected to teach the
     * BIC group and the BIT group at once. The rule is scoped to the **course**
     * rather than the term since 2026-11-02, because a term now runs two
     * offerings of the service course and a section can only be narrowed to one
     * of them by its own `course_code`.
     *
     * It is enforced here rather than by a unique index for two reasons. The
     * rule is about a *person*, so the rows it must forbid cannot be found by
     * looking at one column; and the person doing the appointing deserves a
     * sentence naming the class that is in the way rather than a constraint
     * violation. Across terms the same teacher is expected, so the check is
     * always narrowed to one term.
     *
     * `$exceptSectionId` is the row being edited, which is not its own rival.
     */
    protected function sectionTakenBy(
        int $lecturerId,
        ?int $semesterId,
        ?int $exceptSectionId = null,
        ?string $courseCode = null,
    ): ?Section {
        return Section::query()
            ->where('lecturer_id', $lecturerId)
            ->where('academic_semester_id', $semesterId)
            ->when($courseCode !== null,
                fn ($query) => $query->where('course_code', $courseCode))
            ->when($exceptSectionId !== null, fn ($query) => $query->whereKeyNot($exceptSectionId))
            ->first();
    }

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
            // The offering this class belongs to. Two courses share the title
            // "Projek Sarjana Muda I", so `S1` alone does not identify a class
            // when a term runs both — the payload always carries the course.
            'course_code'          => $section->course_code,
            'course'               => Section::courses()[$section->course_code] ?? null,
            'academic_semester_id' => $section->academic_semester_id,
            // The term travels with the section so the marking screen can label
            // itself without a second call, and so "you have no section in this
            // term" can name the term it means.
            'academic_semester'    => $section->academicSemester ? [
                'id'   => $section->academicSemester->id,
                'name' => $section->academicSemester->name,
            ] : null,
            'lecturer'             => $section->lecturer ? [
                'id'    => $section->lecturer->id,
                'name'  => $section->lecturer->name,
                'email' => $section->lecturer->email,
            ] : null,
        ];
    }

    protected function marksPayload(LecturerMark $mark): array
    {
        // Built from `components()` rather than listed, so the payload follows
        // the rubric: the 2026-11-02 change from quiz/C/P/A to
        // affective/cognitive/psychomotor needed no edit here.
        return array_merge(
            ['id' => $mark->id],
            collect(LecturerMark::components())
                ->mapWithKeys(fn ($spec, $key) => [$key => $mark->{$key}])
                ->all(),
            [
                'entered_at'      => $mark->entered_at?->toIso8601String(),
                'entered_by_name' => $mark->enteredBy?->name,
            ],
        );
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
