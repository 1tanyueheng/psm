<?php

namespace App\Http\Controllers\Api;

use App\Enums\EnrolmentStatus;
use App\Enums\PsmPart;
use App\Http\Controllers\ApiController;
use App\Models\AcademicSemester;
use App\Models\StudentEnrolment;
use App\Models\StudentProfile;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Enrolments — placing students into a PSM part for a semester.
 *
 *   GET   /api/enrolments                  the cohort for a term, filterable
 *   POST  /api/enrolments                  enrol one student (or many)
 *   GET   /api/enrolments/eligible         students who could be enrolled now
 *   POST  /api/enrolments/{id}/place       put an opened repeat into a term
 *   POST  /api/enrolments/{id}/withdraw    a deferral, not a failure
 *   GET   /api/students/{id}/enrolments    one student's whole history
 *
 * This is the admin's start-of-term screen. It exists because the term used to
 * live only on the project, which meant a student had no existence in a term
 * until they had registered a title — so the cohort could not be set up in
 * advance, and a repeat had to guess which term it belonged to.
 *
 * Bulk enrolment is a first-class case, not a loop in the client: registering a
 * batch of thirty is the normal act, and doing it as one request means one
 * transaction and one round trip.
 */
class EnrolmentController extends ApiController
{
    public function __construct(
        protected EnrolmentService $enrolments,
    ) {
    }

    /**
     * GET /api/enrolments
     *
     * The cohort for a term. Not paginated: a term's cohort is tens of rows, and
     * the screen filters it client-side — the same choice the verdict board
     * makes, for the same reason.
     *
     * `semester_id` may be omitted, in which case the caller gets every unplaced
     * repeat. That is deliberate: "who is waiting to be placed" is the question
     * the admin has after a round of verdicts, and it is the one list that has
     * no term of its own.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentEnrolment::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $query = StudentEnrolment::query()
            ->with([
                'studentProfile.user',
                'semester',
                'project',
                'repeatsEnrolment.semester',
            ])
            ->when(
                $request->boolean('unplaced'),
                fn ($q) => $q->whereNull('academic_semester_id'),
                fn ($q) => $q->forSemester($semesterId),
            )
            ->forPart($request->input('psm_part'))
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status')),
            )
            ->orderBy('psm_part')
            ->orderBy('attempt_no')
            ->orderBy('id');

        $rows = $query->get()->map(fn (StudentEnrolment $e) => $this->present($e))->values();

        return $this->ok([
            'semester'    => $semesterId === null ? null : AcademicSemester::find($semesterId)?->name,
            'semester_id' => $semesterId,
            'enrolments'  => $rows,
            'counts'      => [
                'total'     => $rows->count(),
                'enrolling' => $rows->where('status', 'enrolling')->count(),
                'active'    => $rows->where('status', 'active')->count(),
                'completed' => $rows->where('status', 'completed')->count(),
                'failed'    => $rows->where('status', 'failed')->count(),
                'withdrawn' => $rows->where('status', 'withdrawn')->count(),
            ],
        ]);
    }

    /**
     * GET /api/enrolments/eligible
     *
     * Students who could be enrolled into a given term and part, with the reason
     * the others cannot. Shaped like the verdict worklist: everyone in the cohort
     * comes back with a `blocker`, because a student missing from a picker is
     * exactly the thing an admin cannot debug.
     */
    public function eligible(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentEnrolment::class);

        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'    => ['required', Rule::in(PsmPart::deliverableValues())],
        ]);

        $part = PsmPart::parseOrFail($validated['psm_part']);

        $students = StudentProfile::query()
            ->with(['user', 'enrolments.semester'])
            ->orderBy('student_id')
            ->get();

        $rows = $students->map(function (StudentProfile $student) use ($part, $validated) {
            $forPart = $student->enrolments->where('psm_part', $part->value);

            $live = $forPart->first(fn ($e) => $e->status->isLive());
            $done = $forPart->first(fn ($e) => $e->status === EnrolmentStatus::Completed);
            $here = $forPart->first(fn ($e) => $e->academic_semester_id === (int) $validated['semester_id']);

            $blocker = match (true) {
                $here !== null      => "Already enrolled for {$part->label()} this term.",
                $live !== null      => "Already has a live {$part->label()} attempt in "
                    .($live->semester?->name ?? 'another term').'.',
                $done !== null      => "Has already completed {$part->label()}.",
                default => null,
            };

            return [
                'student_profile_id' => $student->id,
                'student_id'         => $student->student_id,
                'name'               => $student->user?->name,
                'program_code'       => $student->program_code,
                'batch'              => $student->batch,
                'attempts'           => $forPart->count(),
                'attempt_no'         => $forPart->count() + 1,
                'blocker'            => $blocker,
                'eligible'           => $blocker === null,
            ];
        })->values();

        return $this->ok([
            'psm_part'   => $part->value,
            'students'   => $rows,
            'eligible'   => $rows->where('eligible', true)->count(),
        ]);
    }

    /**
     * POST /api/enrolments
     *
     * Enrol one student or a list. Partial success is the expected outcome for a
     * batch — one student with a clash should not stop the other twenty-nine —
     * so the response carries who was enrolled and why each of the rest was not.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', StudentEnrolment::class);

        $validated = $request->validate([
            'semester_id'         => ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'            => ['required', Rule::in(PsmPart::deliverableValues())],
            'student_profile_ids' => ['required', 'array', 'min:1'],
            'student_profile_ids.*' => ['integer', 'exists:student_profiles,id'],
            'note'                => ['nullable', 'string', 'max:1000'],
        ]);

        $term = AcademicSemester::findOrFail($validated['semester_id']);
        $part = PsmPart::parseOrFail($validated['psm_part']);
        $actor = $request->user();

        $enrolled = [];
        $skipped = [];

        foreach ($validated['student_profile_ids'] as $id) {
            $student = StudentProfile::find($id);

            if ($student === null) {
                $skipped[] = ['student_profile_id' => $id, 'reason' => 'No such student.'];

                continue;
            }

            try {
                $enrolment = $this->enrolments->enrol($student, $term, $part, $actor, $validated['note'] ?? null);
                $enrolled[] = $this->present($enrolment->load(['studentProfile.user', 'semester']));
            } catch (InvalidArgumentException $e) {
                $skipped[] = [
                    'student_profile_id' => $id,
                    'student_id'         => $student->student_id,
                    'reason'             => $e->getMessage(),
                ];
            }
        }

        $message = $enrolled === []
            ? 'No student could be enrolled.'
            : count($enrolled).' student(s) enrolled in '.$part->label()." for {$term->name}.";

        return $this->ok([
            'enrolled' => $enrolled,
            'skipped'  => $skipped,
        ], $message, $enrolled === [] ? 422 : 200);
    }

    /**
     * POST /api/enrolments/{enrolment}/place
     *
     * Put an opened repeat into a semester. This is the only way a repeat
     * acquires a term, and it refuses a term that is not later than the attempt
     * being repeated.
     */
    public function place(Request $request, StudentEnrolment $enrolment): JsonResponse
    {
        $this->authorize('update', $enrolment);

        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:academic_semesters,id'],
        ]);

        try {
            $placed = $this->enrolments->placeRepeat(
                $enrolment,
                AcademicSemester::findOrFail($validated['semester_id']),
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            $this->present($placed->load(['studentProfile.user', 'semester', 'project'])),
            'The repeat is now in '.$placed->semester?->name.'.',
        );
    }

    /** POST /api/enrolments/{enrolment}/withdraw */
    public function withdraw(Request $request, StudentEnrolment $enrolment): JsonResponse
    {
        $this->authorize('update', $enrolment);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $settled = $this->enrolments->withdraw(
                $enrolment,
                $request->user(),
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            $this->present($settled->load(['studentProfile.user', 'semester'])),
            'The enrolment has been withdrawn.',
        );
    }

    /**
     * GET /api/students/{student}/enrolments
     *
     * One student's whole PSM history, oldest first. This is the query the
     * project-centric model could not answer cheaply: every attempt at every
     * part, in order, with the outcome of each.
     */
    public function forStudent(StudentProfile $student): JsonResponse
    {
        $this->authorize('viewAny', StudentEnrolment::class);

        $rows = StudentEnrolment::where('student_profile_id', $student->id)
            ->with(['semester', 'project', 'repeatsEnrolment.semester'])
            ->orderBy('psm_part')
            ->orderBy('attempt_no')
            ->get()
            ->map(fn (StudentEnrolment $e) => [
                ...$this->present($e),
                'semester_name' => $e->semester?->name,
            ])
            ->values();

        return $this->ok([
            'student_id' => $student->student_id,
            'enrolments' => $rows,
        ]);
    }

    /** The wire shape. Kept in one place so the client has one thing to read. */
    protected function present(StudentEnrolment $e): array
    {
        return [
            'id'                   => $e->id,
            'student_profile_id'   => $e->student_profile_id,
            'student_id'           => $e->studentProfile?->student_id,
            'student_name'         => $e->studentProfile?->user?->name,
            'program_code'         => $e->studentProfile?->program_code,
            'batch'                => $e->studentProfile?->batch,
            'academic_semester_id' => $e->academic_semester_id,
            'semester'             => $e->semester?->name,
            'psm_part'             => $e->psm_part?->value,
            'attempt_no'           => $e->attempt_no,
            'status'               => $e->status?->value,
            'status_label'         => $e->status?->label(),
            'status_tone'          => $e->status?->tone(),
            'is_live'              => $e->status?->isLive() ?? false,
            'project_id'           => $e->project_id,
            'project_code'         => $e->project?->code,
            'project_title'        => $e->project?->title,
            'project_status'       => $e->project?->status,
            // Null term is meaningful, not missing: an opened repeat waiting to
            // be placed into a semester.
            'unplaced'             => $e->academic_semester_id === null,
            'repeats'              => $e->repeatsEnrolment === null ? null : [
                'id'       => $e->repeatsEnrolment->id,
                'semester' => $e->repeatsEnrolment->semester?->name,
                'attempt'  => $e->repeatsEnrolment->attempt_no,
            ],
            'note'         => $e->note,
            'enrolled_at'  => $e->enrolled_at?->toIso8601String(),
            'settled_at'   => $e->settled_at?->toIso8601String(),
        ];
    }
}
