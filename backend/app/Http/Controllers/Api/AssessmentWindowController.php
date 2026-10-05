<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\AcademicSemester;
use App\Models\AssessmentWindow;
use App\Models\Project;
use App\Services\AssessmentWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 4 — the coordinator's assessment window.
 *
 * The coordinator opens it, which allocates every Lampiran the batch needs and
 * starts accepting marks; assessors then pick a student and file their form.
 * Closing stops new marks but keeps what was filed.
 */
class AssessmentWindowController extends ApiController
{
    public function __construct(
        protected AssessmentWindowService $windows,
    ) {
    }

    /**
     * GET /api/assessment-windows
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentWindow::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $windows = AssessmentWindow::query()
            ->forSemesterPart($semesterId, $request->input('psm_part'))
            ->with('academicSemester')
            ->orderByDesc('id')
            ->get();

        return $this->ok($windows->map(fn (AssessmentWindow $w) => $this->present($w))->all());
    }

    /**
     * GET /api/assessment-windows/current
     *
     * The window governing the caller's own projects, so an assessor's marking
     * page can say whether marking is open without knowing the term or part.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        // Which batches does this user actually have work in? Derived from
        // their assignments rather than asked for, so an assessor needs no
        // filter to get a correct answer.
        $parts = Project::query()
            ->whereIn('id', $this->projectIdsFor($user->id))
            ->distinct()
            ->pluck('psm_part')
            ->all();

        $windows = AssessmentWindow::query()
            ->whereIn('psm_part', $parts === [] ? ['__none__'] : $parts)
            ->with('academicSemester')
            ->get();

        return $this->ok($windows->map(fn (AssessmentWindow $w) => $this->present($w))->all());
    }

    /**
     * POST /api/assessment-windows
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AssessmentWindow::class);

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:120'],
            'academic_semester_id'=> ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'            => ['required', Rule::in(['PSM1', 'PSM2'])],
            'scheduled_start_at'  => ['nullable', 'date'],
            'scheduled_end_at'    => ['nullable', 'date', 'after:scheduled_start_at'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        $semester = AcademicSemester::findOrFail($validated['academic_semester_id']);

        // One window per (term, part): two would leave two answers to "is
        // marking open?".
        if (AssessmentWindow::query()
            ->where('academic_semester_id', $semester->id)
            ->where('psm_part', $validated['psm_part'])
            ->exists()
        ) {
            return $this->fail(
                "An assessment window for {$validated['psm_part']} in {$semester->name} already exists. Open that one instead of creating a second.",
                422
            );
        }

        $window = AssessmentWindow::create([
            ...$validated,
            'academic_session' => $semester->academic_session,
            'status'           => AssessmentWindow::STATUS_SCHEDULED,
            'created_by'       => $request->user()->id,
        ]);

        return $this->ok($this->present($window), 'Assessment window created. Open it when marking should begin.');
    }

    /**
     * GET /api/assessment-windows/{window}
     */
    public function show(Request $request, AssessmentWindow $window): JsonResponse
    {
        $this->authorize('view', $window);

        return $this->ok([
            ...$this->present($window),
            'progress' => $this->windows->progress($window),
            'roster'   => $this->windows->roster($window),
        ]);
    }

    /**
     * POST /api/assessment-windows/{window}/open
     *
     * Allocates every student's forms, then starts accepting marks. Idempotent:
     * re-opening tops up any student who was missed and never duplicates a form.
     */
    public function open(Request $request, AssessmentWindow $window): JsonResponse
    {
        $this->authorize('open', $window);

        $result = $this->windows->open($window, $request->user());

        $message = $result['allocated'] === 0
            ? 'Window opened, but no forms were allocated.'
            : "Window opened. {$result['allocated']} student(s) had their forms allocated.";

        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' could not be allocated — see the list.';
        }

        return $this->ok([
            ...$this->present($window->fresh()),
            'allocated' => $result['allocated'],
            'failed'    => $result['failed'],
        ], $message);
    }

    /**
     * POST /api/assessment-windows/{window}/close
     */
    public function close(Request $request, AssessmentWindow $window): JsonResponse
    {
        $this->authorize('close', $window);

        $closed = $this->windows->close($window, $request->user());

        return $this->ok(
            $this->present($closed),
            "Marking closed for {$closed->psm_part}. Filed marks are kept; nothing new can be submitted."
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function present(AssessmentWindow $window): array
    {
        return [
            'id'                 => $window->id,
            'name'               => $window->name,
            'academic_session'   => $window->academic_session,
            'academic_semester_id' => $window->academic_semester_id,
            'semester'           => $window->relationLoaded('academicSemester')
                ? $window->academicSemester?->name
                : null,
            'psm_part'           => $window->psm_part,
            'status'             => $window->status,
            'state_label'        => $window->stateLabel(),
            'is_open'            => $window->isOpen(),
            'accepts_marks'      => $window->acceptsMarks(),
            'scheduled_start_at' => $window->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at'   => $window->scheduled_end_at?->toIso8601String(),
            'window_label'       => $this->windowLabel($window),
            'opened_at'          => $window->opened_at?->toIso8601String(),
            'closed_at'          => $window->closed_at?->toIso8601String(),
            'notes'              => $window->notes,
        ];
    }

    protected function windowLabel(AssessmentWindow $window): string
    {
        if ($window->scheduled_start_at === null && $window->scheduled_end_at === null) {
            return 'No fixed period';
        }

        return trim(sprintf(
            '%s – %s',
            $window->scheduled_start_at?->format('j M Y H:i') ?? 'any time',
            $window->scheduled_end_at?->format('j M Y H:i') ?? 'open ended'
        ));
    }

    /** Projects this user is a supervisor or examiner on. */
    protected function projectIdsFor(int $userId): array
    {
        $supervised = \App\Models\SupervisionAssignment::query()
            ->where('is_active', true)
            ->whereHas('supervisorProfile', fn ($q) => $q->where('user_id', $userId))
            ->with('studentProfile.projects')
            ->get()
            ->flatMap(fn ($a) => $a->studentProfile?->projects?->pluck('id') ?? []);

        $examined = \App\Models\ExaminerAssignment::query()
            ->where('is_active', true)
            ->where('examiner_id', $userId)
            ->pluck('project_id');

        return $supervised->merge($examined)->unique()->values()->all();
    }
}
