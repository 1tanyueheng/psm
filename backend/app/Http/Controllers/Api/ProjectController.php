<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ProjectResource;
use App\Models\GradeScheme;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Services\AuditLogger;
use App\Services\ArchiveService;
use App\Services\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Module 3 — Project registration and lifecycle.
 */
class ProjectController extends ApiController
{
    public function __construct(
        protected MilestoneService $milestones,
        protected ArchiveService $archive,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/projects
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $paginator = Project::query()
            ->visibleTo($request->user())
            ->with(['students.user', 'students.activeSupervisions.supervisorProfile.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('category'), fn ($q) => $q->ofCategory($request->input('category')))
            ->when($request->filled('psm_part'), fn ($q) => $q->forPart($request->input('psm_part')))
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('abstract', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(
            $paginator,
            fn (Project $p) => (new ProjectResource($p))->resolve($request)
        );
    }

    /**
     * GET /api/projects/{project}
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load([
            'students.user',
            'students.activeSupervisions.supervisorProfile.user',
            'milestones.currentFiles.uploader',
            'examinerAssignments.examiner',
            'finalGrades.studentProfile.user',
            'creator',
        ]);

        return $this->ok(new ProjectResource($project));
    }

    /**
     * POST /api/projects
     *
     * Registers a project, attaches the student, instantiates the milestone
     * chain from the category's template, and creates the default grade scheme.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $student = $request->user()->studentProfile;

        if ($student === null) {
            return $this->fail('Only a student with a profile may register a project.', 403);
        }

        $validated = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'abstract'         => ['required', 'string', 'min:50', 'max:5000'],
            'objectives'       => ['nullable', 'string', 'max:3000'],
            'scope'            => ['nullable', 'string', 'max:3000'],
            'category'         => ['required', Rule::in(ProjectCategory::values())],
            'psm_part'         => ['required', Rule::in(['PSM1', 'PSM2'])],
            'academic_session' => ['required', 'string', 'max:32'],
            'members'          => ['sometimes', 'array'],
            'members.*'        => ['integer', 'exists:student_profiles,id'],
        ]);

        // One active project per student per PSM part
        $existing = $student->projects()
            ->where('psm_part', $validated['psm_part'])
            ->whereNotIn('status', ['archived'])
            ->exists();

        if ($existing) {
            return $this->fail(
                "You already have a {$validated['psm_part']} project registered.",
                422
            );
        }

        $project = DB::transaction(function () use ($validated, $student, $request) {
            $project = Project::create([
                'code'             => Project::nextCode(
                    $validated['psm_part'],
                    $validated['academic_session'],
                    $student->program_code
                ),
                'title'            => $validated['title'],
                'abstract'         => $validated['abstract'],
                'objectives'       => $validated['objectives'] ?? null,
                'scope'            => $validated['scope'] ?? null,
                'category'         => $validated['category'],
                'psm_part'         => $validated['psm_part'],
                'academic_session' => $validated['academic_session'],
                'batch'            => $student->batch,
                'program'          => $student->program,
                'status'           => 'draft',
                'created_by'       => $request->user()->id,
            ]);

            // The registrant is the leader
            $project->members()->create([
                'student_profile_id'   => $student->id,
                'is_leader'            => true,
                'contribution_percent' => 100,
            ]);

            // Attach co-members, splitting contribution evenly
            $memberIds = collect($validated['members'] ?? [])
                ->reject(fn ($id) => (int) $id === $student->id)
                ->unique();

            if ($memberIds->isNotEmpty()) {
                $share = round(100 / ($memberIds->count() + 1), 2);

                $project->members()->update(['contribution_percent' => $share]);

                foreach ($memberIds as $id) {
                    $project->members()->create([
                        'student_profile_id'   => $id,
                        'is_leader'            => false,
                        'contribution_percent' => $share,
                    ]);
                }
            }

            // Default weighting so the aggregate is always computable
            GradeScheme::ensureFor($project);

            return $project;
        });

        $this->audit->log(
            action: AuditAction::ProjectCreated,
            description: "Registered {$project->code} ({$project->category->label()})",
            subject: $project,
            after: $project->getAttributes(),
        );

        return $this->created([
            'project' => new ProjectResource($project->load('students.user')),
            // The SPA shows this so the student knows what happens next
            'next_step' => 'Add a supervisor, then submit for approval once your title is confirmed.',
        ], 'Project registered as a draft.');
    }

    /**
     * PATCH /api/projects/{project}
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'title'      => ['sometimes', 'string', 'max:255'],
            'abstract'   => ['sometimes', 'string', 'min:50', 'max:5000'],
            'objectives' => ['nullable', 'string', 'max:3000'],
            'scope'      => ['nullable', 'string', 'max:3000'],
            'category'   => ['sometimes', Rule::in(ProjectCategory::values())],
            'metadata'   => ['sometimes', 'array'],
        ]);

        $before = $project->getAttributes();

        // Changing category after milestones exist would leave an incoherent
        // chain, so it is blocked once the project is approved.
        if (isset($validated['category'])
            && $validated['category'] !== $project->category->value
            && $project->milestones()->exists()) {
            return $this->fail(
                'The category cannot be changed once milestones have been generated. '
                .'Contact the coordinator to re-register the project.',
                422
            );
        }

        $project->update($validated);

        $this->audit->log(
            action: AuditAction::ProjectUpdated,
            description: 'Updated project details',
            subject: $project,
            before: $before,
            after: $project->fresh()->getAttributes(),
        );

        return $this->ok(new ProjectResource($project->fresh(['students.user'])), 'Project updated.');
    }

    /**
     * POST /api/projects/{project}/submit
     *
     * Submits a draft for coordinator approval and generates the milestone
     * chain at this point (not at creation), so a draft that is never
     * submitted leaves no orphaned milestones behind.
     */
    public function submit(Request $request, Project $project): JsonResponse
    {
        $this->authorize('submit', $project);

        $before = $project->getAttributes();

        DB::transaction(function () use ($project, $request) {
            $project->update([
                'status'       => 'submitted',
                'submitted_at' => now(),
                'rejection_reason' => null,
            ]);

            // Instantiate milestones on first submission only
            if (! $project->milestones()->exists()) {
                $this->milestones->instantiateFor($project, now());
            }
        });

        $this->audit->log(
            action: AuditAction::ProjectSubmitted,
            description: "Submitted {$project->code} for approval",
            subject: $project,
            before: $before,
            after: $project->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok(
            new ProjectResource($project->fresh(['milestones', 'students.user'])),
            'Project submitted for approval.'
        );
    }

    /**
     * POST /api/projects/{project}/approve
     */
    public function approve(Request $request, Project $project): JsonResponse
    {
        $this->authorize('approve', $project);

        if ($project->status !== 'submitted') {
            return $this->fail('Only a submitted project can be approved.', 422);
        }

        $before = $project->getAttributes();

        $project->update([
            'status'      => 'in_progress',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // Ensure the chain exists even if approval came before submission
        if (! $project->milestones()->exists()) {
            $this->milestones->instantiateFor($project, now());
        }

        $this->audit->log(
            action: AuditAction::ProjectApproved,
            description: "Approved {$project->code}",
            subject: $project,
            before: $before,
            after: $project->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok(new ProjectResource($project->fresh(['milestones'])), 'Project approved and milestones opened.');
    }

    /**
     * POST /api/projects/{project}/reject
     */
    public function reject(Request $request, Project $project): JsonResponse
    {
        $this->authorize('reject', $project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $before = $project->getAttributes();

        $project->update([
            'status'           => 'rejected',
            'rejection_reason' => $validated['reason'],
        ]);

        $this->audit->log(
            action: AuditAction::ProjectRejected,
            description: "Rejected: {$validated['reason']}",
            subject: $project,
            before: $before,
            after: $project->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok(
            new ProjectResource($project->fresh()),
            'Project returned to the student for revision.'
        );
    }

    /**
     * POST /api/projects/{project}/leaderboard-consent
     *
     * Module 8 — the student's opt-out. Recorded, because consent is a
     * decision the system must be able to evidence.
     */
    public function toggleLeaderboardConsent(Request $request, Project $project): JsonResponse
    {
        $this->authorize('toggleLeaderboardConsent', $project);

        $validated = $request->validate([
            'opt_out' => ['required', 'boolean'],
        ]);

        $before = $project->getAttributes();

        $project->update(['leaderboard_opt_out' => $validated['opt_out']]);

        $this->audit->log(
            action: AuditAction::ProfileUpdated,
            description: $validated['opt_out']
                ? 'Opted out of the public showcase'
                : 'Opted in to the public showcase',
            subject: $project,
            before: $before,
            after: $project->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok([
            'leaderboard_opt_out' => (bool) $project->fresh()->leaderboard_opt_out,
        ], $validated['opt_out']
            ? 'Your project will not appear in the public showcase.'
            : 'Your project is eligible for the public showcase.');
    }

    /**
     * POST /api/projects/{project}/archive
     */
    public function archiveProject(Request $request, Project $project): JsonResponse
    {
        $this->authorize('archive', $project);

        $validated = $request->validate([
            'note'   => ['nullable', 'string', 'max:2000'],
            'public' => ['sometimes', 'boolean'],
        ]);

        $record = $this->archive->archive(
            $project,
            $request->user(),
            $validated['note'] ?? null,
            $request->boolean('public')
        );

        return $this->created([
            'archived_project_id' => $record->id,
            'code'  => $record->code,
            'title' => $record->title,
            'document_count' => $record->documentCount(),
        ], 'Project archived.');
    }

    /**
     * GET /api/projects/options
     *
     * Minimal list for selects that need to reference a project.
     */
    public function options(Request $request): JsonResponse
    {
        $projects = Project::query()
            ->visibleTo($request->user())
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('psm_part'), fn ($q) => $q->forPart($request->input('psm_part')))
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'batch', 'status']);

        return $this->ok($projects->map(fn (Project $p) => [
            'value' => $p->id,
            'label' => "{$p->code} — {$p->title}",
            'meta'  => ['batch' => $p->batch, 'status' => $p->status],
        ]));
    }

    /**
     * GET /api/projects/summary
     *
     * The student's own dashboard: their projects plus progress.
     */
    public function summary(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;

        if ($student === null) {
            return $this->fail('No student profile is associated with this account.', 403);
        }

        $projects = $student->projects()
            ->with(['milestones', 'students.user', 'finalGrades'])
            ->get();

        return $this->ok([
            'student' => [
                'name'       => $request->user()->name,
                'student_id' => $student->student_id,
                'program'    => $student->program,
                'batch'      => $student->batch,
            ],
            'projects' => $projects->map(fn (Project $p) => [
                'id'             => $p->id,
                'code'           => $p->code,
                'title'          => $p->title,
                'psm_part'       => $p->psm_part,
                'status'         => $p->status,
                'progress'       => $p->milestoneProgressPercent(),
                'current_stage'  => $p->currentStageLabel(),
                'next_deadline'  => $p->currentMilestone()?->due_at?->toDateString(),
                'days_remaining' => $p->currentMilestone()?->daysUntilDue(),
                // Counts for the dashboard tiles
                'milestone_counts' => collect(MilestoneStatus::cases())
                    ->mapWithKeys(fn ($s) => [
                        $s->value => $p->milestones->where('status', $s)->count(),
                    ]),
                'grade' => ($g = $p->finalGrades->sortByDesc('aggregate_percent')->first())
                    ? [
                        'final_mark'   => (float) $g->final_mark,
                        'grade_letter' => $g->grade_letter,
                        'status'       => $g->status,
                    ]
                    : null,
            ]),
            'supervisors' => $student->activeSupervisions()
                ->with('supervisorProfile.user')
                ->get()
                ->map(fn ($a) => [
                    'name'     => $a->supervisorProfile?->label(),
                    'role'     => $a->role,
                    'psm_part' => $a->psm_part,
                    'email'    => $a->supervisorProfile?->user?->email,
                ]),
        ]);
    }
}
