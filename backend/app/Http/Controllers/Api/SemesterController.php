<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\AcademicSemesterResource;
use App\Models\AcademicSemester;
use App\Services\SemesterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 3 — academic semester administration (requirement §4.1).
 *
 *   GET    /api/semesters            list, newest term first
 *   POST   /api/semesters            create
 *   GET    /api/semesters/{id}       detail, with per-batch stats
 *   PATCH  /api/semesters/{id}       update dates and flags
 *   POST   /api/semesters/{id}/close close the term
 *   GET    /api/semesters/current    the term the caller is working in
 *
 * `/current` is registered before `/{semester}` so "current" is never swallowed
 * as an id — the ordering of route registration is the whole mechanism here.
 */
class SemesterController extends ApiController
{
    public function __construct(
        protected SemesterService $semesters,
    ) {
    }

    /**
     * GET /api/semesters
     *
     * Readable by everyone, because every filter dropdown in the app needs it.
     * Cohort stats are attached only for coordinators and admins.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicSemester::class);

        $query = AcademicSemester::query()->chronological();

        if ($request->filled('academic_session')) {
            $query->ofSession($request->string('academic_session')->toString());
        }

        if ($request->boolean('active')) {
            $query->active();
        }

        return $this->ok(
            AcademicSemesterResource::collection(
                $query->get()->each(fn (AcademicSemester $s) => $this->attachStats($s, $request))
            )->resolve()
        );
    }

    /**
     * GET /api/semesters/current
     *
     * The term the caller is working in. Not `AcademicSemester::current()`
     * directly: a final-year student or supervisor is better served by the term
     * they are personally enrolled in, falling back to the faculty's active term.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicSemester::class);

        $semester = $this->semesters->currentFor($request->user());

        if ($semester === null) {
            return $this->ok(null, 'No semester has been set up yet.');
        }

        $this->attachStats($semester, $request);

        return $this->ok(
            AcademicSemesterResource::make($semester)->resolve(),
            $this->registrationMessage($semester)
        );
    }

    /**
     * GET /api/semesters/{semester}
     *
     * Detail with per-batch stats — the payload behind the coordinator's
     * "Manage Semesters" screen.
     */
    public function show(Request $request, AcademicSemester $semester): JsonResponse
    {
        $this->authorize('view', $semester);

        $this->attachStats($semester, $request);

        return $this->ok(AcademicSemesterResource::make($semester)->resolve());
    }

    /** POST /api/semesters */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AcademicSemester::class);

        $validated = $request->validate([
            'name'                 => ['nullable', 'string', 'max:64'],
            'academic_session'     => ['required', 'string', 'max:32'],
            'semester_number'      => ['required', 'integer', Rule::in([1, 2])],
            'starts_at'            => ['nullable', 'date'],
            'ends_at'              => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active'            => ['nullable', 'boolean'],
            'is_registration_open' => ['nullable', 'boolean'],
            'metadata'             => ['nullable', 'array'],
            'metadata.coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $semester = $this->semesters->create($validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->attachStats($semester, $request);

        return $this->created(
            AcademicSemesterResource::make($semester)->resolve(),
            $semester->is_active
                ? "{$semester->name} is now the active semester."
                : "{$semester->name} created. Activate it when it starts."
        );
    }

    /**
     * PATCH /api/semesters/{semester}
     *
     * Grade release is split out behind its own gate. Patching
     * `is_marks_released` publishes every mark in the term, and that is not an
     * ability a caller should pick up incidentally by holding `update`.
     */
    public function update(Request $request, AcademicSemester $semester): JsonResponse
    {
        if ($request->has('is_marks_released')) {
            $this->authorize('releaseMarks', $semester);
        } else {
            $this->authorize('update', $semester);
        }

        $validated = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:64'],
            'academic_session'     => ['sometimes', 'string', 'max:32'],
            'semester_number'      => ['sometimes', 'integer', Rule::in([1, 2])],
            'starts_at'            => ['nullable', 'date'],
            'ends_at'              => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active'            => ['nullable', 'boolean'],
            'is_registration_open' => ['nullable', 'boolean'],
            'is_marks_released'   => ['nullable', 'boolean'],
            'metadata'             => ['nullable', 'array'],
            'metadata.coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($request->has('is_registration_open')) {
            $this->authorize('manageRegistration', $semester);
        }

        try {
            $updated = $this->semesters->update($semester, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->attachStats($updated, $request);

        return $this->ok(
            AcademicSemesterResource::make($updated)->resolve(),
            $this->changeMessage($updated, $validated)
        );
    }

    /** POST /api/semesters/{semester}/close */
    public function close(Request $request, AcademicSemester $semester): JsonResponse
    {
        $this->authorize('close', $semester);

        try {
            $closed = $this->semesters->close($semester, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            AcademicSemesterResource::make($closed)->resolve(),
            "{$closed->name} is closed. Students can no longer register for it."
        );
    }

    /**
     * POST /api/semesters/{semester}/reopen
     *
     * The counterpart to close(). Closing used to be a one-way door — nothing
     * reopened a term, through the API or the screen — so an accidental close
     * was unrecoverable. Registration is left closed: opening it is a separate
     * decision with its own control.
     */
    public function reopen(Request $request, AcademicSemester $semester): JsonResponse
    {
        $this->authorize('reopen', $semester);

        try {
            $reopened = $this->semesters->reopen($semester, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            AcademicSemesterResource::make($reopened)->resolve(),
            "{$reopened->name} is open again. Registration is still closed — open it when you are ready."
        );
    }

    /**
     * POST /api/semesters/{semester}/registration
     *
     * Convenience endpoint for the toggle on the semester screen, kept separate
     * from PATCH so the UI does not have to send the whole object back — and so
     * opening registration cannot be a side effect of renaming a term.
     */
    public function registration(Request $request, AcademicSemester $semester): JsonResponse
    {
        $this->authorize('manageRegistration', $semester);

        $validated = $request->validate([
            'is_registration_open' => ['required', 'boolean'],
        ]);

        try {
            $open = $this->semesters->setRegistrationOpen(
                $semester,
                (bool) $validated['is_registration_open'],
                $request->user()
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->attachStats($open, $request);

        return $this->ok(
            AcademicSemesterResource::make($open)->resolve(),
            $open->is_registration_open
                ? "Registration is open for {$open->name}."
                : "Registration is closed for {$open->name}."
        );
    }

    /**
     * POST /api/semesters/{semester}/release-marks
     *
     * Requirement §4.2 — release is per semester, so this is an explicit action
     * rather than a field edit. The response reports how many projects it
     * touched, because a coordinator publishing a term's results needs to know
     * whether that was the twelve projects they expected.
     */
    public function releaseMarks(Request $request, AcademicSemester $semester): JsonResponse
    {
        $this->authorize('releaseMarks', $semester);

        $validated = $request->validate([
            'is_marks_released' => ['required', 'boolean'],
        ]);

        $released = (bool) $validated['is_marks_released'];

        $updated = $this->semesters->setMarkRelease($semester, $released, $request->user());

        $this->attachStats($updated, $request);

        return $this->ok(
            AcademicSemesterResource::make($updated)->resolve(),
            $released
                ? "Marks released for {$updated->name}. Students in both batches can now see their results."
                : "Marks withheld for {$updated->name}."
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Attach cohort stats, if the caller is allowed to see them.
     *
     * Set as a relation rather than an attribute so AcademicSemesterResource's
     * `whenLoaded` check gates the payload, and so the same code path serves
     * both the list and the detail endpoint.
     */
    protected function attachStats(AcademicSemester $semester, Request $request): AcademicSemester
    {
        if (! $request->user()?->can('viewStats', $semester)) {
            return $semester;
        }

        return $semester->setRelation('stats', $this->semesters->stats($semester));
    }

    /**
     * A sentence describing what the flags now mean.
     *
     * The point of returning this alongside the payload is that every one of
     * these changes is invisible until it is explained: "Semester updated" tells
     * a coordinator nothing about whether they just published a term's results.
     */
    protected function changeMessage(AcademicSemester $semester, array $validated): string
    {
        if (array_key_exists('is_marks_released', $validated)) {
            return $semester->is_marks_released
                ? "Marks released for {$semester->name}."
                : "Marks withheld for {$semester->name}.";
        }

        if (array_key_exists('is_registration_open', $validated)) {
            return $semester->is_registration_open
                ? "Registration is open for {$semester->name}."
                : "Registration is closed for {$semester->name}.";
        }

        if (array_key_exists('is_active', $validated) && $semester->is_active) {
            return "{$semester->name} is now the active semester.";
        }

        return "{$semester->name} updated.";
    }

    /** The registration gate's reason, for `/current`. */
    protected function registrationMessage(AcademicSemester $semester): string
    {
        $gate = $this->semesters->registrationGate($semester);

        return $gate['reason'] ?? "{$semester->name} — registration is open.";
    }
}