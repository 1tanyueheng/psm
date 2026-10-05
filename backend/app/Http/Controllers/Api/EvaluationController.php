<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Resources\EvaluationResource;
use App\Http\Resources\RubricTemplateResource;
use App\Models\AcademicSemester;
use App\Models\Evaluation;
use App\Models\FinalGrade;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\RubricTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EvaluationService;
use App\Services\MarkSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 4 — Evaluation forms, marking, moderation and grade release.
 */
class EvaluationController extends ApiController
{
    public function __construct(
        protected EvaluationService $evaluations,
        protected MarkSubmissionService $submissions,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/evaluations
     *
     * An assessor's own queue by default; coordinators may see all.
     * Supports `mine=true` to filter to current user's evaluations
     * and `as=examiner|supervisor` to filter by assessor type.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Evaluation::class);

        $user = $request->user();

        // Handle `mine=true` - filter to current user's evaluations
        $mine = $request->boolean('mine');

        // Handle `as=examiner|supervisor` - filter by assessor type
        $as = $request->input('as');

        $paginator = Evaluation::query()
            ->with(['project.students.user', 'assessor', 'rubricTemplate'])
            ->when(
                $request->filled('assessor_id'),
                fn ($q) => $q->where('assessor_id', $request->integer('assessor_id')),
                // Default: an assessor sees only their own forms
                fn ($q) => $q->when(
                    ! $user->hasRole('admin', 'coordinator') || $mine,
                    fn ($sub) => $sub->where('assessor_id', $user->id)
                )
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('assessor_type'), fn ($q) => $q->where('assessor_type', $request->input('assessor_type')))
            ->when($as !== null, fn ($q) => $q->where('assessor_type', $as))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(
            $paginator,
            fn (Evaluation $e) => (new EvaluationResource($e))->resolve($request)
        );
    }

    /**
     * GET /api/evaluations/{evaluation}
     */
    public function show(Request $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $evaluation);

        $evaluation->load([
            'project.students.user',
            'project.milestones',
            'assessor',
            'rubricTemplate',
            'scores.criterion',
            'moderatedBy',
        ]);

        return $this->ok(new EvaluationResource($evaluation));
    }

    /**
     * POST /api/evaluations
     *
     * Creates (or returns) an evaluation form for an assessor.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Evaluation::class);

        $validated = $request->validate([
            'project_id'    => ['required', 'integer', 'exists:projects,id'],
            'assessor_id'   => ['required', 'integer', 'exists:users,id'],
            'assessor_type' => ['required', Rule::in(AssessorType::values())],
        ]);

        $project  = Project::findOrFail($validated['project_id']);
        $assessor = User::findOrFail($validated['assessor_id']);

        try {
            $evaluation = $this->evaluations->createForm(
                $project,
                $assessor,
                AssessorType::from($validated['assessor_type']),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            new EvaluationResource(
                $evaluation->load('scores', 'assessor', 'rubricTemplate', 'project.milestones')
            ),
            'Evaluation form ready.'
        );
    }

    /**
     * POST /api/evaluations/progress-report
     *
     * Lampiran H — the PSM 2 progress report, taken by the supervisor for
     * Laporan Kemajuan 1 and then again for Laporan Kemajuan 2. It has its own
     * endpoint because H shares (PSM2, supervisor) with Lampiran G and is never
     * the form an assessor creates by default.
     *
     * Allocated by a coordinator like any other form — assessors do not
     * self-allocate (EvaluationPolicy::create).
     */
    public function storeProgressReport(Request $request): JsonResponse
    {
        $this->authorize('create', Evaluation::class);

        $validated = $request->validate([
            'project_id'  => ['required', 'integer', 'exists:projects,id'],
            'assessor_id' => ['required', 'integer', 'exists:users,id'],
            'laporan_num' => ['required', 'integer', Rule::in([1, 2])],
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if ($project->psm_part !== 'PSM2') {
            return $this->fail('The progress report only applies to PSM 2 projects.', 422);
        }

        $supervisor = User::findOrFail($validated['assessor_id']);

        try {
            $evaluation = $this->evaluations->createProgressReportForm(
                $project,
                $supervisor,
                $validated['laporan_num'],
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            new EvaluationResource(
                $evaluation->load('scores', 'assessor', 'rubricTemplate', 'project.milestones')
            ),
            "Laporan Kemajuan {$validated['laporan_num']} form ready."
        );
    }

    /**
     * PUT /api/evaluations/{evaluation}/marks
     *
     * Saves a batch of criterion marks. The form stays a draft until submitted.
     */
    public function saveMarks(Request $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('update', $evaluation);

        $validated = $request->validate([
            'marks'                     => ['required', 'array', 'min:1'],
            'marks.*.criterion_code'    => ['required', 'string'],
            'marks.*.marks'             => ['required', 'numeric', 'min:0'],
            'marks.*.comment'           => ['nullable', 'string', 'max:2000'],
            'comment'                   => ['nullable', 'string', 'max:5000'],
            'strengths'                 => ['nullable', 'string', 'max:3000'],
            'improvements'              => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $updated = $this->evaluations->saveMarks(
                $evaluation,
                $validated['marks'],
                $request->user()
            );

            // Narrative fields are saved alongside the marks
            $updated->fill(collect($validated)->only([
                'comment', 'strengths', 'improvements',
            ])->all())->save();
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $updated->load('scores', 'assessor', 'rubricTemplate', 'project.milestones');

        return $this->ok(new EvaluationResource($updated), 'Marks saved.');
    }

    /**
     * POST /api/evaluations/{evaluation}/submit
     *
     * Locks the form and recomputes the project's aggregate.
     */
    public function submit(Request $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('submit', $evaluation);

        try {
            $submitted = $this->evaluations->submit($evaluation, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new EvaluationResource($submitted->load('scores', 'assessor', 'rubricTemplate', 'project.milestones')),
            'Marks submitted and locked. A coordinator can now moderate them.'
        );
    }

    /*
     * `POST /api/evaluations/{evaluation}/moderate` used to live here.
     *
     * Removed: a coordinator does not award or alter marks. Only the assessor
     * who holds the form marks against it, and the aggregate reads those marks
     * unchanged. `EvaluationStatus::Moderated`, the audit action and the
     * notification type are all kept so any historical row that carries them
     * still loads and renders — nothing new can produce one.
     *
     * If a mark is genuinely wrong, the assessor reopens the form (a submitted
     * form is locked, so a coordinator returns it) rather than a second party
     * editing the number.
     */

    /**
     * POST /api/evaluations/{evaluation}/declare-conflict
     *
     * Voluntary recusal, e.g. a personal connection to the student.
     */
    public function declareConflict(Request $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('declareConflict', $evaluation);

        $validated = $request->validate([
            'declaration' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $evaluation->update([
            'status'          => \App\Enums\EvaluationStatus::Recused,
            'coi_declaration' => $validated['declaration'],
        ]);

        $this->audit->log(
            action: AuditAction::EvaluationUpdated,
            description: "Recused from assessing: {$validated['declaration']}",
            subject: $evaluation,
            actor: $request->user(),
        );

        // Tell the coordinators so a replacement can be allocated
        app(\App\Services\NotificationDispatcher::class)->notify(
            User::withRole('coordinator')->active()->get(),
            \App\Enums\NotificationType::EvaluationAssigned,
            [
                'title'      => 'Assessor recused',
                'body'       => "{$request->user()->name} declared a conflict of interest on "
                                ."{$evaluation->project->code}. A replacement is needed.",
                'action_url' => "/reports/projects/{$evaluation->project_id}",
                'urgent'     => true,
            ],
            $evaluation,
        );

        return $this->ok(null, 'Your conflict of interest has been recorded. Please do not assess this project.');
    }

    /**
     * GET /api/rubrics
     *
     * Scoped to the official forms. A rubric with no `form_code` is not a form
     * the faculty issues, so it is not listable — see
     * RubricTemplate::scopeOfficialForms().
     */
    public function rubrics(Request $request): JsonResponse
    {
        $rubrics = RubricTemplate::query()
            ->officialForms()
            ->with(['components.criteria'])
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->input('category')))
            ->when($request->filled('assessor_type'), fn ($q) => $q->forAssessor($request->input('assessor_type')))
            ->when($request->filled('psm_part'), fn ($q) => $q->whereIn('psm_part', [$request->input('psm_part'), 'BOTH']))
            ->when($request->boolean('published_only'), fn ($q) => $q->published())
            ->orderBy('form_code')
            ->orderBy('category')
            ->orderByDesc('version')
            ->get();

        return $this->ok(RubricTemplateResource::collection($rubrics));
    }

    // -----------------------------------------------------------------
    // Grades
    // -----------------------------------------------------------------

    /**
     * GET /api/grades
     */
    public function grades(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinalGrade::class);

        // Requirement §4.2: the grade list is filterable by term and by batch,
        // defaulting to the active term. A list that merged every term is how a
        // coordinator ends up releasing last year's results from this year's
        // screen.
        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $paginator = FinalGrade::query()
            ->with(['project', 'studentProfile.user'])
            ->forSemesterPart($semesterId, $request->input('psm_part'))
            ->when($request->filled('q'), fn ($q) => $q->search(trim($request->input('q'))))
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('final_mark')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated(
            $paginator,
            fn (FinalGrade $g) => (new \App\Http\Resources\FinalGradeResource($g))->resolve($request),
            [
                'semester_id'         => $semesterId,
                'semester_released'   => $semesterId !== null
                    ? (bool) AcademicSemester::find($semesterId)?->is_marks_released
                    : null,
            ]
        );
    }

    /**
     * GET /api/projects/{project}/students/{student}/mark-breakdown
     *
     * One student's mark broken down by form and component, so the total is
     * explainable at the level it was awarded.
     *
     * The totals are the raw marks the Lampiran forms print — never rescaled to
     * a 0-100 percentage. The system scores only part of the assessment, so a
     * percentage of its own share would misrepresent the student's result.
     *
     * Withheld entirely until the mark is released — otherwise a student could
     * read a provisional total here that the project page deliberately hides.
     * A student may only read their own breakdown; staff may read anyone's.
     */
    public function markBreakdown(Request $request, Project $project, int $studentProfileId): JsonResponse
    {
        $this->authorize('view', $project);

        $viewer = $request->user();
        $isStaff = $viewer->hasRole('admin', 'coordinator');

        // A student may only look at themselves. Matched on the project roster
        // rather than trusted from the path, so the id cannot be swapped.
        if ($viewer->isStudent()) {
            $isOwn = $project->students()
                ->where('student_profiles.id', $studentProfileId)
                ->where('student_profiles.user_id', $viewer->id)
                ->exists();

            if (! $isOwn) {
                return $this->fail('You may only view your own mark breakdown.', 403);
            }
        }

        $grade = FinalGrade::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->first();

        $released = $grade !== null && $grade->status === 'released';

        if (! $released && ! $isStaff) {
            return $this->ok([
                'released'    => false,
                'total_marks' => null,
                'total_max'   => null,
                'forms'       => [],
            ], 'The mark has not been released yet.');
        }

        $forms = $this->evaluations->componentBreakdown($project);

        // Totals over the forms actually returned, in the Lampiran's own units.
        $awarded = array_values(array_filter(
            array_column($forms, 'marks'),
            fn ($mark) => $mark !== null
        ));
        $max = array_sum(array_column($forms, 'max'));

        return $this->ok([
            'released'    => $released,
            'total_marks' => $awarded === [] ? null : round(array_sum($awarded), 2),
            'total_max'   => round((float) $max, 2),
            'forms'       => $forms,
        ]);
    }

    /**
     * GET /api/projects/{project}/grades
     *
     * The full assessment picture for one project: every evaluation plus the
     * computed aggregate, with the arithmetic exposed for coordinators.
     */
    public function projectGrades(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load([
            'students.user',
            'evaluations.assessor',
            'evaluations.scores',
            'finalGrades.studentProfile.user',
            'gradeScheme',
        ]);

        $scheme = $project->gradeScheme;

        return $this->ok([
            'project' => [
                'id'    => $project->id,
                'code'  => $project->code,
                'title' => $project->title,
                'batch' => $project->batch,
            ],
            'grade_scheme' => [
                'weights'        => $scheme?->weights,
                'aggregation'    => $scheme?->aggregation,
                'trim_extremes'  => (bool) $scheme?->trim_extremes,
                'weights_balance'=> $scheme?->weightsBalance(),
                'is_locked'      => (bool) $scheme?->is_locked,
            ],
            'evaluations' => EvaluationResource::collection($project->evaluations),
            'final_grades'=> \App\Http\Resources\FinalGradeResource::collection($project->finalGrades),
            'milestone_progress' => $project->milestoneProgressPercent(),
        ]);
    }

    /**
     * POST /api/grades/{grade}/recompute
     */
    public function recompute(Request $request, FinalGrade $grade): JsonResponse
    {
        $this->authorize('recompute', $grade);

        $updated = $this->evaluations->computeFinalGrade(
            $grade->project,
            $grade->student_profile_id
        );

        return $this->ok(
            new \App\Http\Resources\FinalGradeResource($updated->load('studentProfile.user', 'project')),
            'Mark recomputed.'
        );
    }

    /**
     * POST /api/grades/{grade}/release
     */
    public function releaseGrade(Request $request, FinalGrade $grade): JsonResponse
    {
        $this->authorize('release', $grade);

        $released = $this->evaluations->releaseGrade($grade, $request->user());

        return $this->ok(
            new \App\Http\Resources\FinalGradeResource($released->load('studentProfile.user', 'project')),
            'Mark released to the student.'
        );
    }

    /**
     * POST /api/projects/{project}/grades/release-all
     */
    public function releaseAll(Request $request, Project $project): JsonResponse
    {
        $this->authorize('approve', $project);

        $released = 0;
        $skipped  = 0;

        foreach ($project->finalGrades()->get() as $grade) {
            if ($grade->isReleased()) {
                $skipped++;
                continue;
            }

            $this->evaluations->releaseGrade($grade, $request->user());
            $released++;
        }

        return $this->ok(
            ['released' => $released, 'skipped' => $skipped],
            "{$released} grade(s) released."
        );
    }

    /**
     * PUT /api/projects/{project}/grade-scheme
     *
     * Adjust how assessor marks combine. Blocked once any grade is released,
     * so a published result cannot be silently reinterpreted.
     */
    public function updateGradeScheme(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageGradeScheme', $project);

        $validated = $request->validate([
            'weights'                  => ['required', 'array', 'min:1'],
            'weights.*.assessor_type'  => ['required', Rule::in(AssessorType::values())],
            'weights.*.weight'         => ['required', 'numeric', 'min:0', 'max:100'],
            'aggregation'              => ['sometimes', Rule::in(['mean', 'weighted_mean', 'max', 'min'])],
            'trim_extremes'            => ['sometimes', 'boolean'],
        ]);

        $total = collect($validated['weights'])->sum(fn ($w) => (float) $w['weight']);

        if (abs($total - 100.0) > 0.01) {
            return $this->fail("Assessor weights must total 100% (currently {$total}%).", 422);
        }

        $scheme = \App\Models\GradeScheme::ensureFor($project);

        $before = $scheme->getAttributes();

        $scheme->update(collect($validated)->only([
            'weights', 'aggregation', 'trim_extremes',
        ])->all());

        $this->audit->log(
            action: AuditAction::GradeRecalculated,
            description: 'Grade scheme updated',
            subject: $scheme,
            before: $before,
            after: $scheme->fresh()->getAttributes(),
            actor: $request->user(),
        );

        // Recompute every affected student under the new weights
        foreach ($project->students as $student) {
            $this->evaluations->computeFinalGrade($project, $student->id);
        }

        return $this->ok([
            'weights'        => $scheme->fresh()->weights,
            'aggregation'    => $scheme->aggregation,
            'weights_balance'=> $scheme->weightsBalance(),
        ], 'Grade scheme updated and grades recomputed.');
    }

    // ============================================================
    // MarkSubmission lifecycle
    // ============================================================

    /**
     * POST /api/projects/{project}/students/{student}/mark-submission/open
     *
     * Coordinator only. Allocates the forms (supervisor + every active panel
     * examiner) and records the submission contract. Idempotent.
     */
    public function openMarkSubmission(Request $request, Project $project, int $studentProfileId): JsonResponse
    {
        // Gate resolves the policy from the subject's class, so passing the
        // Project would look up ProjectPolicy (which has no `open`). The
        // ability lives on MarkSubmissionPolicy and takes no model instance,
        // so the class name is the correct subject.
        $this->authorize('open', MarkSubmission::class);

        $submission = $this->submissions->open($project, $studentProfileId, $request->user());

        // `forms` is a computed method on the model, not a relation, so it is
        // deliberately not eager-loaded here — the resource calls it directly.
        return $this->ok(
            new \App\Http\Resources\MarkSubmissionResource($submission->load(['project', 'studentProfile.user'])),
            'Mark submission opened and forms allocated.'
        );
    }

    /**
     * GET /api/projects/{project}/students/{student}/mark-submission
     *
     * Coordinator and assessors may view. Returns the submission with readiness
     * checklist and all forms.
     */
    public function showMarkSubmission(Request $request, Project $project, int $studentProfileId): JsonResponse
    {
        $submission = MarkSubmission::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->firstOrFail();

        $this->authorize('view', $submission);

        return $this->ok(
            new \App\Http\Resources\MarkSubmissionResource($submission->load(['project', 'studentProfile.user', 'openedBy', 'lockedBy'])),
            'Mark submission retrieved.'
        );
    }

    /**
     * POST /api/projects/{project}/students/{student}/mark-submission/lock
     *
     * Coordinator only. Gates on readiness (all forms submitted, panel whole),
     * then freezes the aggregate.
     */
    public function lockMarkSubmission(Request $request, Project $project, int $studentProfileId): JsonResponse
    {
        $submission = MarkSubmission::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->firstOrFail();

        $this->authorize('lock', $submission);

        $validated = $request->validate([
            'notes' => ['sometimes', 'string', 'max:1000'],
        ]);

        $submission = $this->submissions->lock($submission, $request->user());

        if ($validated['notes'] ?? null) {
            $submission->update(['notes' => $validated['notes']]);
        }

        return $this->ok(
            new \App\Http\Resources\MarkSubmissionResource($submission->fresh()->load(['finalGrade', 'lockedBy'])),
            'Mark submission locked.'
        );
    }

    /**
     * POST /api/projects/{project}/students/{student}/mark-submission/unlock
     *
     * Coordinator only. Requires a reason, resets to open.
     */
    public function unlockMarkSubmission(Request $request, Project $project, int $studentProfileId): JsonResponse
    {
        $submission = MarkSubmission::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->firstOrFail();

        $this->authorize('unlock', $submission);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $submission = $this->submissions->unlock($submission, $request->user(), $validated['reason']);

        return $this->ok(
            new \App\Http\Resources\MarkSubmissionResource($submission->fresh()),
            'Mark submission unlocked.'
        );
    }
}
