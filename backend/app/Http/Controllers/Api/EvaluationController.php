<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Resources\EvaluationResource;
use App\Http\Resources\RubricTemplateResource;
use App\Models\Evaluation;
use App\Models\FinalGrade;
use App\Models\Project;
use App\Models\RubricTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EvaluationService;
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
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/evaluations
     *
     * An assessor's own queue by default; coordinators may see all.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Evaluation::class);

        $user = $request->user();

        $paginator = Evaluation::query()
            ->with(['project.students.user', 'assessor', 'rubricTemplate'])
            ->when(
                $request->filled('assessor_id'),
                fn ($q) => $q->where('assessor_id', $request->integer('assessor_id')),
                // Default: an assessor sees only their own forms
                fn ($q) => $q->when(! $user->hasRole('admin', 'coordinator'), fn ($sub) => $sub->where('assessor_id', $user->id))
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('assessor_type'), fn ($q) => $q->where('assessor_type', $request->input('assessor_type')))
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
            new EvaluationResource($evaluation->load('scores', 'assessor', 'rubricTemplate')),
            'Evaluation form ready.'
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

        $updated->load('scores', 'assessor', 'rubricTemplate');

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
            new EvaluationResource($submitted->load('scores', 'assessor', 'rubricTemplate')),
            'Marks submitted and locked. A coordinator can now moderate them.'
        );
    }

    /**
     * POST /api/evaluations/{evaluation}/moderate
     *
     * Coordinator adjustment. The original mark is preserved on raw_score and
     * the delta plus reason are recorded on the evaluation and in the audit
     * trail.
     */
    public function moderate(Request $request, Evaluation $evaluation): JsonResponse
    {
        $this->authorize('moderate', $evaluation);

        $validated = $request->validate([
            'score_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'reason'        => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $moderated = $this->evaluations->moderate(
                $evaluation,
                (float) $validated['score_percent'],
                $validated['reason'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new EvaluationResource($moderated->load('scores', 'assessor', 'rubricTemplate', 'moderatedBy')),
            'Marks moderated. The change is recorded in the audit trail.'
        );
    }

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
     */
    public function rubrics(Request $request): JsonResponse
    {
        $rubrics = RubricTemplate::query()
            ->with(['components.criteria'])
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->input('category')))
            ->when($request->filled('assessor_type'), fn ($q) => $q->forAssessor($request->input('assessor_type')))
            ->when($request->filled('psm_part'), fn ($q) => $q->whereIn('psm_part', [$request->input('psm_part'), 'BOTH']))
            ->when($request->boolean('published_only'), fn ($q) => $q->published())
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

        $paginator = FinalGrade::query()
            ->with(['project', 'studentProfile.user'])
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('psm_part'), fn ($q) => $q->where('psm_part', $request->input('psm_part')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('grade_letter'), fn ($q) => $q->where('grade_letter', $request->input('grade_letter')))
            ->orderByDesc('final_mark')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated(
            $paginator,
            fn (FinalGrade $g) => (new \App\Http\Resources\FinalGradeResource($g))->resolve($request)
        );
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
                'pass_mark'      => $scheme?->pass_mark !== null ? (float) $scheme->pass_mark : null,
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
            'Grade recomputed.'
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
            'Grade released to the student.'
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
            'pass_mark'                => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $total = collect($validated['weights'])->sum(fn ($w) => (float) $w['weight']);

        if (abs($total - 100.0) > 0.01) {
            return $this->fail("Assessor weights must total 100% (currently {$total}%).", 422);
        }

        $scheme = \App\Models\GradeScheme::ensureFor($project);

        $before = $scheme->getAttributes();

        $scheme->update(collect($validated)->only([
            'weights', 'aggregation', 'trim_extremes', 'pass_mark',
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
}
