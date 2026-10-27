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
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Models\SupervisorAgreement;
use App\Services\AuditLogger;
use App\Services\ArchiveService;
use App\Services\MilestoneService;
use App\Services\ProgressionService;
use App\Services\ProjectResolutionService;
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
        protected ProjectResolutionService $resolution,
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
            // `role` narrows the permission-scoped list to one *relationship*.
            // `visibleTo()` returns supervised **and** examined projects because
            // being on a panel is a reason to read a project — but the
            // supervisor's roster is a list of supervisees, and returning the
            // examined ones there showed other people's students as this
            // supervisor's. Absent `role` keeps the old, wider behaviour.
            ->when(
                in_array($request->input('role'), ['supervisor', 'examiner'], true),
                fn ($q) => $request->input('role') === 'supervisor'
                    ? $q->supervisedBy($request->user())
                    : $q->examinedBy($request->user())
            )
            // `academicSemester` for the term column, `milestones` for the
            // progress bar and next-milestone date the list renders. Without
            // the latter the list showed 0% and "—" on every row.
            ->with([
                'academicSemester',
                'students.user',
                'students.activeSupervisions.supervisorProfile.user',
                /**
                 * `milestones` for the progress bar and next-milestone date the
                 * list renders — without it the list showed 0% and "—" on every
                 * row.
                 *
                 * The nested `project` matters as much as the milestones
                 * themselves: `MilestoneResource` renders a `project` block per
                 * row, and `MilestonePolicy::decideTitle()` reads
                 * `$milestone->project` while the list is being authorised.
                 * Loading only `milestones` left each row to lazy-load its own
                 * project — 15 extra queries on a 26-project cohort, each one a
                 * network round trip on a hosted database.
                 *
                 * `project.examinerAssignments` is here for the proposal row's
                 * panel: it is read per row, so without it the list issued one
                 * `examiner_assignments` query per project. The nested
                 * `examiner.supervisorProfile` comes along because
                 * `User::displayName()` reads the academic title from it.
                 */
                'milestones.project.examinerAssignments.examiner.supervisorProfile',
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

            /**
             * The abstract and scope are **not** collected here.
             *
             * They are not on any of the faculty's registration forms. Lampiran
             * B's Part B asks for the supervisor, the title, the project type
             * and the field of study, and then says in as many words: *"attach a
             * proposal containing at least Introduction, Problem Statement,
             * Objective, Scope."* The proposal is a document the student uploads
             * at the proposal milestone, and those sections live inside it.
             *
             * Asking for them at registration made the form ask for content the
             * student could not legitimately have yet — the title is still a
             * proposal at this point — so the fields were filled with placeholders
             * to get past them, and then nothing downstream ever read them.
             */
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

        /**
         * PSM 1 is registered through **Lampiran A**, and only through it.
         *
         * This endpoint creates a project directly, and that is correct for a
         * PSM 2 attempt — PSM 2 has no registration step, because the student
         * already has a title and a supervisor carried over from PSM 1.
         *
         * For PSM 1 it is a shortcut that skips the whole registration chain.
         * The path requires the student to name a supervisor in Lampiran A, the
         * supervisor to acknowledge it, and *that acknowledgement* to fix the
         * agreed title — which is then what Lampiran B registers. Coming in
         * through this door instead produced a project with:
         *
         *   - no supervisor pairings at all, so the supervisor named on the form
         *     was never notified and never saw anything;
         *   - no Lampiran A agreement, so there was no agreed title and no record
         *     of who approved what;
         *   - a title the student typed freely, bypassing the three-candidate
         *     panel decision the faculty requires.
         *
         * Reproduced: a fresh student account with no agreement called
         * `POST /api/projects` then `POST /api/projects/{id}/submit` and ended up
         * with a PSM 1 project in `submitted` state that nobody was supervising.
         *
         * Refused rather than silently redirected, because a redirect would leave
         * whatever the student typed unsubmitted-and-forgotten. The message names
         * the step they skipped.
         */
        if ($validated['psm_part'] === 'PSM1') {
            $acknowledged = SupervisorAgreement::query()
                ->where('student_profile_id', $student->id)
                ->where('status', SupervisorAgreement::STATUS_APPROVED)
                ->exists();

            if (! $acknowledged) {
                return $this->fail(
                    'A PSM 1 project is registered through Lampiran A, not directly: file Lampiran A '
                    .'naming your supervisor, and once they acknowledge it the agreed title is '
                    .'registered here. You have no acknowledged Lampiran A yet.',
                    422,
                );
            }
        }

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
                // No abstract: it belongs to the uploaded proposal, not to the
                // registration record. See the note on the validation rules.
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
     * Refused until the semester is closed and every PSM 1 form is in, because
     * progressing earlier would enrol the student in PSM 2 while PSM 1 is still
     * unresolved.
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
     * POST /api/projects/{project}/resolve
     *
     * The committee's verdict: this project passed, or the student repeats.
     *
     * This is the step the lifecycle was missing. A project used to end only by
     * an administrator archiving it, which said nothing about whether the work
     * passed — so a failed student stayed `in_progress` for ever, kept their
     * supervisor's capacity consumed, and had no route to repeat.
     *
     * Resolving closes the people attached to the project: the supervisor stops
     * carrying the student (unless another part is still running), and the panel
     * is released. A fail requires a reason.
     */
    public function resolveProject(Request $request, Project $project): JsonResponse
    {
        $this->authorize('resolve', $project);

        $validated = $request->validate([
            'outcome' => ['required', 'string', Rule::in([
                ProjectResolutionService::OUTCOME_PASSED,
                ProjectResolutionService::OUTCOME_FAILED,
            ])],
            'reason'  => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $resolved = $this->resolution->resolve(
                $project,
                $validated['outcome'],
                $validated['reason'] ?? null,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok([
            'project_id' => $resolved->id,
            'code'       => $resolved->code,
            'psm_part'   => $resolved->psm_part,
            'status'     => $resolved->status,
            'outcome'    => $validated['outcome'],
        ], $validated['outcome'] === ProjectResolutionService::OUTCOME_FAILED
            ? 'Project recorded as failed. The student may now repeat this part.'
            : 'Project recorded as completed.');
    }

    /**
     * POST /api/projects/{project}/repeat
     *
     * Start a repeat of a failed part, for the same student.
     *
     * The two parts behave differently here, by the faculty's rule:
     *
     *  - **PSM 1** starts over **from registration**. A student who failed
     *    PSM 1 files Lampiran A again — new supervisor pairing if they choose,
     *    new title, and the whole chain (Lampiran B, proposal, OSHE, Chapters
     *    1-4). This endpoint therefore does *not* create a project for PSM 1:
     *    a project only exists once Lampiran B registers it, and creating one
     *    here would skip the registration the faculty requires.
     *  - **PSM 2** repeats **in place**. There is no second registration — the
     *    student already has a title and a supervisor — so a new PSM 2 project
     *    is created directly, with the same supervisor and panel, and the
     *    Chapters 5-7 chain is instantiated fresh.
     *
     * `keep_title` is only meaningful for PSM 2: a PSM 1 repeat re-proposes
     * through Lampiran A, so the old title is not carried automatically even if
     * the student chooses to reuse it.
     */
    public function repeatProject(Request $request, Project $project): JsonResponse
    {
        $this->authorize('resolve', $project);

        $validated = $request->validate([
            'keep_title' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->resolution->repeat(
                $project,
                $request->user(),
                $request->boolean('keep_title', true),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            $result,
            $result['mode'] === 'registration'
                ? 'PSM 1 repeats from registration — the student must file Lampiran A again.'
                : 'A new PSM 2 attempt has been created.'
        );
    }

    /**
     * GET /api/projects/verdicts
     *
     * The committee's worklist: every project in a term with whether a verdict
     * can be given yet, and what that verdict was if it already has one.
     *
     * Separate from `/projects/rollover` because it answers a different question.
     * Rollover asks "who may move to PSM 2" — PSM 1 only, gated on the whole
     * term. This asks "what is the outcome of each project" — **both parts**,
     * gated per project on its own marks, because that is how a committee
     * actually sits: it rules on the PSM 2 cohort in the same meeting as the
     * PSM 1 one, and a single student's ruling does not wait on their neighbour.
     *
     * Every project is returned, decidable or not. The reason travels on the
     * row, so a coordinator sees *why* a student cannot be ruled on rather than
     * finding them missing from the list — the same principle the rollover
     * screen already follows.
     */
    public function verdictCandidates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        if ($semesterId === null) {
            return $this->ok([
                'semester'   => null,
                'candidates' => [],
            ]);
        }

        $projects = Project::query()
            ->where('academic_semester_id', $semesterId)
            ->when($request->filled('psm_part'), fn ($q) => $q->where('psm_part', $request->input('psm_part')))
            ->with([
                'members.studentProfile.user',
                'members.studentProfile.activeSupervisions.supervisorProfile.user',
            ])
            ->orderBy('code')
            ->get();

        $submissions = MarkSubmission::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->with('finalGrade')
            ->get()
            ->keyBy('project_id');

        $candidates = $projects->map(function (Project $project) use ($submissions) {
            $student = $project->leader();
            $submission = $submissions->get($project->id);

            /**
             * Three possible reasons a verdict is not available, in the order a
             * coordinator would hit them: no student, no window, marks not in.
             */
            $blocker = match (true) {
                $student === null   => 'No student on this project.',
                $submission === null => 'No assessment window has been opened for this project.',
                $submission->status->value !== 'locked' => 'Marks are not locked yet ('
                    .$submission->status->value.').',
                default => null,
            };

            $metadata = $project->metadata ?? [];

            return [
                'project_id'   => $project->id,
                'code'         => $project->code,
                'title'        => $project->title,
                'psm_part'     => $project->psm_part,
                'batch'        => $project->batch,
                'program'      => $project->program,
                'student_id'   => $student?->student_id,
                'student_name' => $student?->user?->name,
                'supervisor'   => $student?->activeSupervisions
                    ?->firstWhere('is_active', true)?->supervisorProfile?->user?->name,
                'status'       => $project->status,
                'finished'     => $project->isFinished(),
                'passed'       => $project->isPassed(),
                // Null means "a verdict may be given now"; a string says why not.
                'blocker'      => $blocker,
                'can_resolve'  => $blocker === null && ! $project->isFinished(),
                // A failed project may be repeated — subject to the term having
                // closed, which the service enforces and the screen restates.
                'can_repeat'   => $project->status === 'failed',
                'outcome_reason' => $metadata['outcome_reason'] ?? null,
                'resolved_at'  => $metadata['resolved_at'] ?? null,
                'resolved_by'  => $metadata['resolved_by_name'] ?? null,
                'mark'         => $submission?->finalGrade?->final_mark,
                'submission_status' => $submission?->status->value,
            ];
        })->values()->all();

        return $this->ok([
            'semester'   => AcademicSemester::find($semesterId)?->name,
            'semester_id'=> $semesterId,
            'candidates' => $candidates,
        ]);
    }

    /**
     * GET /api/projects/rollover
     *
     * Who could move to PSM 2, so the admin has something to tick.
     *
     * Returns every live PSM 1 project in the term with the figures the screen
     * needs to show the choice honestly: the title that will carry over, the
     * supervisor who will keep the student, and whether this student can move
     * yet. The list is *not* filtered to the eligible — a coordinator looking
     * for a missing student needs to see them present and blocked, not absent.
     *
     * The term's own readiness is returned alongside so the screen can explain
     * a blanket refusal once instead of printing the same reason on every row.
     */
    public function rolloverCandidates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        if ($semesterId === null) {
            return $this->ok([
                'semester'    => null,
                'term_ready'  => false,
                'term_reason' => 'No semester is selected, so there is nothing to roll over.',
                'candidates'  => [],
            ]);
        }

        $semester = AcademicSemester::with('markSubmissions')->find($semesterId);

        if ($semester === null) {
            return $this->fail('That semester does not exist.', 422);
        }

        $marks = $this->semesters->marksState($semester);

        $termReady = $semester->isClosed() && $marks['outstanding'] === 0;

        $termReason = match (true) {
            ! $semester->isClosed() =>
                "{$semester->name} is still open. Close the semester before rolling students over.",
            $marks['outstanding'] > 0 =>
                "{$marks['outstanding']} of {$marks['total']} mark submission(s) in {$semester->name} "
                    .'are still incomplete. Every PSM 1 form has to be in first.',
            default => null,
        };

        /**
         * Rollover candidates are PSM 1 projects still **running**.
         *
         * `active()`, not `whereNull('archived_at')`: a PSM 1 project the
         * committee already resolved — passed or failed — is not a candidate.
         * A failed one in particular must drop off this list, because the
         * student repeats from registration rather than being progressed, and
         * offering it here would let a coordinator progress a student who has
         * to redo PSM 1.
         */
        $projects = Project::query()
            ->where('psm_part', 'PSM1')
            ->where('academic_semester_id', $semester->id)
            ->active()
            ->with([
                'members.studentProfile.user',
                'members.studentProfile.activeSupervisions.supervisorProfile.user',
            ])
            ->orderBy('code')
            ->get();

        $candidates = $projects->map(function (Project $project) {
            $student = $project->leader();
            $supervision = $student?->activeSupervisions
                ?->firstWhere('is_active', true);

            // Already moved. Shown so a second run is visibly a no-op on that
            // row rather than a mystery refusal.
            $existing = $student === null ? null : $this->progression->livePsm2For($student);

            return [
                'project_id'    => $project->id,
                'code'          => $project->code,
                'title'         => $project->title,
                'category'      => $project->category?->value,
                'student_id'    => $student?->student_id,
                'student_name'  => $student?->user?->name,
                'program'       => $student?->program,
                'batch'         => $project->batch,
                'supervisor'    => $supervision?->supervisorProfile?->user?->name,
                'already_psm2'  => $existing !== null,
                'psm2_code'     => $existing?->code,
            ];
        })->values()->all();

        return $this->ok([
            'semester'         => $semester->name,
            'semester_id'      => $semester->id,
            'semester_closed'  => $semester->isClosed(),
            'marks'            => $marks,
            'term_ready'       => $termReady,
            'term_reason'      => $termReason,
            'candidates'       => $candidates,
        ]);
    }

    /**
     * POST /api/projects/rollover
     *
     * Move the ticked students into PSM 2. Partial success is the expected
     * outcome, so the response reports who moved and why each of the rest did
     * not, rather than failing the whole batch on the first refusal.
     */
    public function rollover(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $validated = $request->validate([
            'project_ids'   => ['required', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ]);

        $result = $this->progression->progressBatch(
            array_map('intval', $validated['project_ids']),
            $request->user(),
        );

        $moved = count($result['progressed']);
        $failed = count($result['failed']);

        $message = match (true) {
            $moved === 0 && $failed === 0 => 'Nothing was selected, so nothing moved.',
            $moved === 0 => "No students were progressed — see the reasons below.",
            $failed === 0 => "{$moved} student(s) progressed to PSM 2. Their PSM 1 projects are archived.",
            default => "{$moved} student(s) progressed to PSM 2. {$failed} could not be — see the reasons.",
        };

        return $this->ok([
            'progressed' => $result['progressed'],
            'failed'     => $result['failed'],
            'moved'      => $moved,
            'blocked'    => $failed,
        ], $message);
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

        /**
         * The student's own programme, which decides who may be offered.
         *
         * Lampiran A is filed by a student naming a supervisor, and FSKTM pairs
         * staff with students of their **own** programme only. This endpoint
         * used to return every supervisor with room, of every programme, so a
         * BIS student was offered eleven staff who could never supervise them
         * and choosing one produced an agreement the faculty cannot act on.
         *
         * The rule was already enforced when a *coordinator* allocates — see
         * `AssignmentService`, which calls `programmeRefusal()` — but not here,
         * so the two paths disagreed about the same pairing.
         */
        $studentProgramme = $request->user()->studentProfile?->program_code;

        // Room is judged against the term being registered into, not across all
        // time. A supervisor carrying last term's finished cohort still has a
        // full cap this term; filtering them out here left the Lampiran A form
        // with a shortlist the coordinator could not act on.
        $supervisors = SupervisorProfile::query()
            ->with(['user', 'expertiseAreas'])
            ->where('is_accepting_students', true)
            ->get()
            ->filter(fn (SupervisorProfile $sp) => ! $sp->isFullInSemester($semester?->id))
            /**
             * Only supervisors of the student's programme.
             *
             * `canSuperviseProgramme()` fails open when either side has no
             * recorded programme — a legacy student row, or a supervisor whose
             * programme was never set. Refusing on missing data would lock out
             * every such account, and the faculty's answer to a blank field is
             * "fill it in", not "this person can never pair". Where the
             * programme *is* recorded, the rule applies.
             */
            ->filter(fn (SupervisorProfile $sp) => $sp->canSuperviseProgramme($studentProgramme))
            ->map(fn (SupervisorProfile $sp) => [
                'id'                => $sp->id,
                'name'              => $sp->label(),
                'staff_no'          => $sp->staff_no,
                'programme'         => $sp->programme?->value,
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

            /**
             * The programme the shortlist was filtered to, so the form can say
             * so — otherwise a student wonders why a supervisor they know is
             * missing from the list.
             */
            'student_programme' => $studentProgramme,

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

            /**
             * Whether this student may create a PSM 1 project from this form.
             *
             * Only true once a supervisor has acknowledged their Lampiran A —
             * that acknowledgement is what fixes the agreed title, and the
             * project is meant to register *that* title, not one the student
             * typed here. The screen uses this to explain the sequence instead
             * of presenting a form the server will refuse.
             */
            'has_acknowledged_agreement' => $request->user()->studentProfile?->id !== null
                && SupervisorAgreement::query()
                    ->where('student_profile_id', $request->user()->studentProfile->id)
                    ->where('status', SupervisorAgreement::STATUS_APPROVED)
                    ->exists(),
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
