<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use App\Enums\PsmPart;
use App\Http\Controllers\ApiController;
use App\Http\Resources\AcademicSemesterResource;
use App\Http\Resources\ProjectResource;
use App\Models\AcademicSemester;
use App\Models\GradeScheme;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Services\AuditLogger;
use App\Services\ArchiveService;
use App\Services\MilestoneService;
use App\Services\ProgressionService;
use App\Services\SemesterService;
use InvalidArgumentException;
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
        protected SemesterService $semesters,
        protected ProgressionService $progression,
    ) {
    }

    /**
     * GET /api/projects
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        // Requirement §4.2: the project list accepts `semester_id` and defaults
        // to the active term. A list that silently merged every term is the worst
        // possible screen to be ambiguous on, because it is the one a
        // coordinator uses to decide who still needs a supervisor.
        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $paginator = Project::query()
            ->visibleTo($request->user())
            // `academicSemester` for the term column, `milestones` for the
            // progress bar and next-milestone date the list renders. Without
            // the latter the list showed 0% and "—" on every row.
            ->with([
                'academicSemester',
                'students.user',
                'students.activeSupervisions.supervisorProfile.user',
                'milestones',
                // The student dashboard shows the released mark from this list,
                // so the grade has to travel with it. The resource withholds
                // the number until it is released.
                'finalGrades',
            ])
            ->when($semesterId !== null, fn ($q) => $q->forSemester($semesterId))
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
            fn (Project $p) => (new ProjectResource($p))->resolve($request),
            // Echoed back so the UI can tell "the active term has no projects"
            // apart from "you filtered by a term that does not exist" — two very
            // different empty states that look identical otherwise.
            ['semester_id' => $semesterId]
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

            // Requirement §3.2: a project belongs to a semester. Omitting it falls
            // back to the active term, which is what a student registering through
            // Lampiran A means; sending it explicitly is what a coordinator
            // back-filling a cohort for a closed term needs.
            'semester_id'      => ['nullable', 'integer', 'exists:academic_semesters,id'],
            'academic_session' => ['nullable', 'string', 'max:32'],

            'members'          => ['sometimes', 'array'],
            'members.*'        => ['integer', 'exists:student_profiles,id'],
        ]);

        $semester = $request->filled('semester_id')
            ? AcademicSemester::resolve($validated['semester_id'])
            : AcademicSemester::current();

        if ($request->filled('semester_id') && $semester === null) {
            return $this->fail('That semester does not exist.', 422);
        }

        // `academic_session` stays on the row as the denormalised label that most
        // list queries filter on, but it is copied from the resolved term so the
        // two can never disagree.
        $academicSession = $semester?->academic_session
            ?? $validated['academic_session']
            ?? $student->academic_session;

        // Requirement §6.2's integrity rule: one live project per student, per
        // semester, per batch.
        //
        // Scoped to the semester rather than checked globally, which is what lets
        // a PSM 1 student re-register for PSM 2 next term (acceptance criterion
        // #9) without having to archive anything by hand. The PSM part stays in
        // the predicate so a student holding PSM 1 and PSM 2 in the same term —
        // the concurrent-batch case this requirement exists for — is allowed,
        // while a second PSM 1 project in one term is not.
        if ($semester !== null && $student->hasActiveProjectInSemester($semester, $validated['psm_part'])) {
            return $this->fail(
                "You already have a {$validated['psm_part']} project registered for {$semester->name}.",
                422
            );
        }

        // Fall back to the legacy global check only when no term could be
        // resolved, so a student on a pre-semester record is still protected.
        if ($semester === null) {
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
        }

        $project = DB::transaction(function () use ($validated, $student, $request, $semester, $academicSession) {
            $project = Project::create([
                'code'                => Project::nextCode(
                    $validated['psm_part'],
                    $academicSession,
                    $student->program_code
                ),
                'title'               => $validated['title'],
                'abstract'            => $validated['abstract'],
                'objectives'          => $validated['objectives'] ?? null,
                'scope'               => $validated['scope'] ?? null,
                'category'            => $validated['category'],
                'psm_part'            => $validated['psm_part'],
                'academic_semester_id'=> $semester?->id,
                'academic_session'    => $academicSession,
                'batch'               => $student->batch,
                'program'             => $student->program,
                'status'              => 'draft',
                'created_by'          => $request->user()->id,
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
     * POST /api/projects/{project}/progress-to-psm2
     *
     * Move the student from this PSM 1 project into PSM 2.
     *
     * PSM 1 and PSM 2 are one project across two continuous terms on one title,
     * and only PSM 1 registers a title — so PSM 2 is reached by progressing, not
     * by filing a second Lampiran A. The title, the supervisor and the examiner
     * pair all carry over, and PSM 1 is archived in the same step.
     *
     * Refused until the PSM 1 marks have been released, because progressing
     * earlier would enrol the student in PSM 2 while PSM 1 is still unresolved.
     */
    public function progressToPsm2(Request $request, Project $project): JsonResponse
    {
        $this->authorize('progress', $project);

        try {
            $psm2 = $this->progression->progress($project, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created([
            'project_id' => $psm2->id,
            'code'       => $psm2->code,
            'title'      => $psm2->title,
            'psm_part'   => $psm2->psm_part,
            'semester'   => $psm2->academicSemester?->name,
            'milestones' => $psm2->milestones->map(fn ($milestone) => [
                'code'  => $milestone->code,
                'title' => $milestone->title,
            ])->all(),
        ], 'Student progressed to PSM 2 — the PSM 1 project has been archived.');
    }

    /**
     * GET /api/projects/options
     *
     * Minimal list for selects that need to reference a project.
     */
    public function options(Request $request): JsonResponse
    {
        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $projects = Project::query()
            ->visibleTo($request->user())
            ->when($semesterId !== null, fn ($q) => $q->forSemester($semesterId))
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('psm_part'), fn ($q) => $q->forPart($request->input('psm_part')))
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'batch', 'status', 'academic_semester_id']);

        return $this->ok($projects->map(fn (Project $p) => [
            'value' => $p->id,
            'label' => "{$p->code} — {$p->title}",
            'meta'  => [
                'batch'               => $p->batch,
                'status'              => $p->status,
                'academic_semester_id'=> $p->academic_semester_id,
            ],
        ]));
    }

    /**
     * GET /api/projects/registration-meta
     *
     * Everything the Lampiran A form needs to render: the supervisors with room,
     * the two batches, and the terms registration is open for.
     *
     * The term list and the registration gate used to be absent, which left the
     * form asking a student to type an academic session as free text — so the
     * project it created had no term on it, and was invisible to every
     * semester-scoped screen afterwards.
     */
    public function registrationMeta(Request $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $gate = $this->semesters->registrationGate();
        $semester = $gate['semester'];

        // Room is judged against the term being registered into, not across all
        // time. A supervisor carrying last term's finished cohort still has a
        // full cap this term; filtering them out here left the Lampiran A form
        // with a shortlist the coordinator could not act on.
        $supervisors = SupervisorProfile::query()
            ->with(['user', 'expertiseAreas'])
            ->where('is_accepting_students', true)
            ->get()
            ->filter(fn (SupervisorProfile $sp) => ! $sp->isFullInSemester($semester?->id))
            ->map(fn (SupervisorProfile $sp) => [
                'id'                => $sp->id,
                'name'              => $sp->label(),
                'staff_no'          => $sp->staff_no,
                'max_supervisees'   => $sp->max_supervisees,
                'current_load'      => $sp->currentLoad(),
                'capacity_by_part'  => $sp->capacityByPart(),
                'load_by_part'      => $semester !== null
                    ? $sp->currentLoadByPartInSemester($semester)
                    : $sp->currentLoadByPart(),
                'load_by_part_all_terms' => $sp->currentLoadByPart(),
                'available'         => ! $sp->isFullInSemester($semester?->id),
            ]);

        return $this->ok([
            'supervisors'  => $supervisors->values(),
            'psm_parts'    => PsmPart::deliverableValues(),

            // Terms the student could actually register into. Only the active term
            // can have an open window, so this is normally a single option — which
            // is the point: it removes the student's ability to invent a session
            // string that matches no term.
            'semesters'    => AcademicSemesterResource::collection(
                AcademicSemester::query()->active()->chronological()->get()
            )->resolve(),

            'registration' => [
                'open'   => $gate['open'],
                'reason' => $gate['reason'],
                'semester_id' => $gate['semester']?->id,
            ],
        ]);
    }

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
                        'final_mark' => (float) $g->final_mark,
                        'status'     => $g->status,
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
