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
 * One window governs one Lampiran. The coordinator opens each form when that
 * form's marking period begins — the supervisor files Lampiran E when the
 * project is finished, the examiners file Lampiran I on seminar day — so each
 * carries its own dates and its own open/close state.
 *
 * Opening a window also allocates that form to every student in the batch, so
 * "marking is open" and "there is something to mark" cannot disagree. Closing
 * stops new marks but keeps what was filed.
 */
class AssessmentWindowController extends ApiController
{
    public function __construct(
        protected AssessmentWindowService $windows,
    ) {
    }

    /**
     * GET /api/assessment-windows
     *
     * The board the coordinator works from: every Lampiran of a batch with its
     * own state, ordered the way the forms are filed.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentWindow::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $windows = AssessmentWindow::query()
            ->forSemesterPart($semesterId, $request->input('psm_part'))
            ->with('academicSemester')
            ->orderBy('psm_part')
            ->orderBy('form_code')
            ->get();

        return $this->ok($windows->map(fn (AssessmentWindow $w) => $this->present($w))->all());
    }

    /**
     * GET /api/assessment-windows/forms
     *
     * The Lampiran a coordinator can open, grouped by batch, with a label and
     * the assessor role that marks each. Read from the rubric templates so the
     * list cannot drift from the forms that actually exist — a code offered here
     * with no rubric behind it would create a window that governs nothing, and
     * the coordinator would have no way to tell.
     */
    public function forms(Request $request): JsonResponse
    {
        $this->authorize('create', AssessmentWindow::class);

        $templates = \App\Models\RubricTemplate::query()
            ->whereIn('psm_part', ['PSM1', 'PSM2'])
            ->whereNotNull('form_code')
            ->where('is_active', true)
            ->where('is_published', true)
            ->get(['psm_part', 'form_code', 'assessor_type']);

        $grouped = [];

        foreach (['PSM1', 'PSM2'] as $part) {
            $forms = $templates
                ->where('psm_part', $part)
                ->unique('form_code')
                ->sortBy('form_code')
                ->map(fn ($t) => [
                    'form_code'     => $t->form_code,
                    'label'         => "Lampiran {$t->form_code}",
                    'assessor_type' => $t->assessor_type?->value,
                ])
                ->values()
                ->all();

            $grouped[$part] = [
                'forms' => $forms,
            ];
        }

        return $this->ok($grouped);
    }

    /**
     * GET /api/assessment-windows/current
     *
     * Every window governing the caller's own projects, so an assessor's marking
     * page can say whether marking is open without knowing the term or part.
     * One entry per Lampiran of each batch they have work in.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        /**
         * The (term, batch) pairs this user actually has work in.
         *
         * Derived from their assignments rather than asked for, so an assessor
         * needs no filter to get a correct answer. These are the *same* pairs
         * the marking gate resolves per form (`AssessmentWindow::markingOpenForForm`
         * reads the project's own term, batch and form), which is what keeps the
         * banner and the gate from disagreeing.
         *
         * Deliberately not restricted to the active term: an assessor can hold
         * work in a term other than the one currently running, and the banner
         * must describe the batch they are looking at, not the term the faculty
         * happens to be in.
         */
        $pairs = Project::query()
            ->whereIn('id', $this->projectIdsFor($user->id))
            ->whereNotNull('academic_semester_id')
            ->get(['academic_semester_id', 'psm_part'])
            ->map(fn (Project $p) => AssessmentWindow::termPartKey(
                $p->academic_semester_id,
                $p->psm_part
            ))
            ->unique()
            ->values();

        $windows = AssessmentWindow::query()
            ->with('academicSemester')
            ->where(function ($q) use ($pairs) {
                if ($pairs->isEmpty()) {
                    // No work at all: match nothing rather than everything.
                    $q->whereRaw('1 = 0');

                    return;
                }

                foreach ($pairs as $key) {
                    [$semesterId, $part] = explode(':', $key, 2);

                    $q->orWhere(fn ($sub) => $sub
                        ->where('academic_semester_id', $semesterId)
                        ->where('psm_part', $part));
                }
            })
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
            // Required: every window governs exactly one Lampiran. Validated
            // against the forms that actually exist for the part, so a typo
            // cannot create a window governing nothing.
            'form_code'           => [
                'required',
                'string',
                Rule::in($this->formCodesFor($request->input('psm_part'))),
            ],
            'scheduled_start_at'  => ['nullable', 'date'],
            'scheduled_end_at'    => ['nullable', 'date', 'after:scheduled_start_at'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        $semester = AcademicSemester::findOrFail($validated['academic_semester_id']);

        /**
         * A window may only be opened for the term that is running.
         *
         * Without this, the Term dropdown offered every semester the faculty had
         * ever created, and a coordinator could create a window against a term
         * that had already closed — or one that had not started and held no
         * students at all. The window would then faithfully list whatever
         * projects that term happened to contain, so a window *named* "PSM 2"
         * could show a PSM 1 cohort and nothing on the screen would contradict
         * the name. That is the bug this check closes: the roster is filtered by
         * part, so the only way to see the wrong batch is to create the window
         * against the wrong term.
         */
        if (! $semester->is_active) {
            return $this->fail(
                "Marking can only be opened for the current semester. {$semester->name} is not "
                .'the active semester — marking it now would let assessors file marks against a '
                .'term nobody is enrolled in.',
                422,
                ['academic_semester_id' => [
                    "Only the active semester can be marked. {$semester->name} is not active.",
                ]]
            );
        }

        $formCode = $validated['form_code'];

        /**
         * One window per (term, batch, form).
         *
         * Two rows claiming the same Lampiran would leave two answers to "is
         * this form open?" and the gate would depend on which one the query
         * happened to reach first. The table's unique index enforces the same
         * rule underneath. Windows are normally provisioned with the term, so
         * this is the guard for a hand-made duplicate rather than the usual
         * path.
         */
        if (AssessmentWindow::query()
            ->where('academic_semester_id', $semester->id)
            ->where('psm_part', $validated['psm_part'])
            ->where('form_code', $formCode)
            ->exists()
        ) {
            return $this->fail(
                "An assessment window for {$validated['psm_part']} — Lampiran {$formCode} in "
                ."{$semester->name} already exists. Open that one instead of creating a second.",
                422
            );
        }

        $window = AssessmentWindow::create([
            ...$validated,
            'academic_session' => $semester->academic_session,
            'status'           => AssessmentWindow::STATUS_SCHEDULED,
            'created_by'       => $request->user()->id,
        ]);

        // Name the Lampiran in the message. A coordinator who mis-picked the
        // form finds out here rather than from a roster that looks like the
        // other one, which is how the mixed-up windows were created.
        return $this->ok(
            $this->present($window),
            "{$validated['psm_part']} — Lampiran {$formCode} assessment window created for "
                ."{$semester->name}. Open it when that form's marking should begin."
        );
    }

    /**
     * PATCH /api/assessment-windows/{window}
     *
     * Set the published period a Lampiran accepts marks for.
     *
     * Deliberately separate from open/close. Publishing "Lampiran I closes 20
     * December" in advance and opening it on the day is how a real marking
     * period is arranged; folding the two together would force the coordinator
     * to open a form in order to state when it should close.
     *
     * Only the dates and the note are editable. The batch and the form are what
     * the window *is* — changing them would silently repoint a window that
     * assessors are already filing against, so a mistake there is corrected by
     * closing this one and creating the right one.
     */
    public function update(Request $request, AssessmentWindow $window): JsonResponse
    {
        $this->authorize('update', $window);

        $validated = $request->validate([
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at'   => ['nullable', 'date', 'after:scheduled_start_at'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);

        $window->update($validated);

        return $this->ok(
            $this->present($window->fresh()),
            "Lampiran {$window->form_code} marking period updated."
        );
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
            'form_code'          => $window->form_code,
            // The scope in words, so the UI does not have to reconstruct
            // "whole batch vs one Lampiran" from a null check in three places.
            'scope_label'        => $this->scopeLabel($window),
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

    /**
     * What this window governs, in words.
     *
     * Spelled out because the coordinator is choosing between forms that share
     * a batch: rendering Lampiran E and Lampiran I both as "PSM 1" is how
     * someone opens the wrong one and believes marking has started for a form
     * it has not.
     */
    protected function scopeLabel(AssessmentWindow $window): string
    {
        return "{$window->psm_part} — Lampiran {$window->form_code}";
    }

    /**
     * The form codes that exist for a PSM part, for validating `form_code`.
     *
     * Read from the rubric templates rather than hardcoded, so adding a Lampiran
     * to the seeder makes it selectable here without a second edit — and a code
     * that has no form behind it is refused instead of creating a window that
     * governs nothing.
     *
     * @return array<int, string>
     */
    protected function formCodesFor(?string $psmPart): array
    {
        if (! in_array($psmPart, ['PSM1', 'PSM2'], true)) {
            return [];
        }

        return \App\Models\RubricTemplate::query()
            ->where('psm_part', $psmPart)
            ->whereNotNull('form_code')
            ->distinct()
            ->orderBy('form_code')
            ->pluck('form_code')
            ->all();
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
