<?php

namespace App\Http\Controllers\Api;

use App\Enums\PsmPart;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\StudentProfileResource;
use App\Http\Resources\SupervisorProfileResource;
use App\Models\AcademicSemester;
use App\Models\ExpertiseArea;
use App\Models\ExaminerAssignment;
use App\Models\ExaminerPair;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisionAssignment;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\ExaminerPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 2 — Coordinator-facing assignment management.
 *
 * Thin by design: every rule (capacity, duplication, conflict of interest)
 * lives in AssignmentService, so this controller only handles transport.
 */
class AssignmentController extends ApiController
{
    public function __construct(
        protected AssignmentService $assignments,
        protected ExaminerPairingService $pairing,
    ) {
    }

    // -----------------------------------------------------------------
    // Supervisor ↔ student
    // -----------------------------------------------------------------

    /**
     * GET /api/assignments/supervisions
     *
     * `semester_id` reaches the student's project rather than being stored on
     * the assignment: an assignment is scoped to a term indirectly, through the
     * student it belongs to.
     */
    public function indexSupervisions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupervisionAssignment::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));

        $paginator = SupervisionAssignment::query()
            ->with([
                'studentProfile.user',
                'supervisorProfile.user',
                'assignedBy',
            ])
            ->when($request->filled('supervisor_id'), fn ($q) => $q->where(
                'supervisor_profile_id',
                $request->integer('supervisor_id')
            ))
            ->when($request->filled('student_id'), fn ($q) => $q->where(
                'student_profile_id',
                $request->integer('student_id')
            ))
            ->when($request->filled('psm_part'), fn ($q) => $q->where('psm_part', $request->input('psm_part')))
            ->when($semesterId !== null || $request->filled('psm_part'), fn ($q) => $q->whereHas(
                'studentProfile',
                fn ($s) => $s->inSemesterPart($semesterId, $request->input('psm_part'))
            ))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('batch'), fn ($q) => $q->whereHas(
                'studentProfile',
                fn ($s) => $s->where('batch', $request->input('batch'))
            ))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($paginator, fn (SupervisionAssignment $a) => [
            'id' => $a->id,
            'student' => [
                'profile_id' => $a->studentProfile?->id,
                'name'       => $a->studentProfile?->user?->name,
                'student_id' => $a->studentProfile?->student_id,
                'batch'      => $a->studentProfile?->batch,
                'program'    => $a->studentProfile?->program,
            ],
            'supervisor' => [
                'profile_id' => $a->supervisorProfile?->id,
                'name'       => $a->supervisorProfile?->label(),
                'staff_no'   => $a->supervisorProfile?->staff_no,
            ],
            'psm_part'    => $a->psm_part,
            'psm_part_label' => PsmPart::tryParse($a->psm_part)?->label(),
            'role'      => $a->role,
            'responsibility_percent' => (float) $a->responsibility_percent,
            'is_active' => (bool) $a->is_active,
            'assignment_note' => $a->assignment_note,
            'assigned_by' => $a->assignedBy?->name,
            'effective_from' => $a->effective_from?->toDateString(),
            'ended_at' => $a->ended_at?->toIso8601String(),
            'end_reason' => $a->end_reason,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/assignments/supervisions
     */
    public function storeSupervision(Request $request): JsonResponse
    {
        $this->authorize('create', SupervisionAssignment::class);

        $validated = $request->validate([
            'student_profile_id'    => ['required', 'integer', 'exists:student_profiles,id'],
            'supervisor_profile_id' => ['required', 'integer', 'exists:supervisor_profiles,id'],
            'psm_part'              => ['sometimes', Rule::in(['PSM1', 'PSM2', 'BOTH'])],
            'semester_id'           => ['nullable', 'integer', 'exists:academic_semesters,id'],
            // Primary-only: the co-supervisor role was removed, so anything
            // other than 'primary' is refused with a 422 rather than silently
            // stored. The key stays optional so existing clients that send
            // role: 'primary' keep working.
            'role'                  => ['sometimes', Rule::in([SupervisionAssignment::ROLE_PRIMARY])],
            'responsibility_percent'=> ['sometimes', 'numeric', 'min:0', 'max:100'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);

        $student    = StudentProfile::findOrFail($validated['student_profile_id']);
        $supervisor = SupervisorProfile::findOrFail($validated['supervisor_profile_id']);

        // Default the batch to the student's own project part rather than BOTH.
        // Defaulting to BOTH would charge the supervisor room in *both* batches
        // for a student who is only in one of them — a PSM 2 student would
        // consume a PSM 1 slot they can never fill, and the coordinator would
        // see a full PSM 1 column with nobody in it.
        $psmPart = $validated['psm_part'] ?? $student->currentProject()?->psm_part ?? 'BOTH';

        // A named semester must be the student's enrolment term, otherwise the
        // allocation lands in a term the student is not in and will not show up
        // on their own dashboard.
        $semesterId = AcademicSemester::resolveFilterId($validated['semester_id'] ?? null);

        if ($semesterId !== null && $student->academic_semester_id !== null && $student->academic_semester_id !== $semesterId) {
            return $this->fail(
                'That student is enrolled in a different semester.',
                422,
                ['semester_id' => [$student->academic_semester_id]]
            );
        }

        $assignment = $this->assignments->assignSupervisor(
            student: $student,
            supervisor: $supervisor,
            actor: $request->user(),
            psmPart: $psmPart,
            role: $validated['role'] ?? SupervisionAssignment::ROLE_PRIMARY,
            responsibility: isset($validated['responsibility_percent'])
                ? (float) $validated['responsibility_percent']
                : null,
            note: $validated['note'] ?? null,
        );

        return $this->created([
            'id' => $assignment->id,
            'student' => $student->label(),
            'supervisor' => $supervisor->label(),
            'psm_part' => $assignment->psm_part,
            'psm_part_label' => PsmPart::tryParse($assignment->psm_part)?->label(),
            'semester_id' => $student->academic_semester_id,
            'role' => $assignment->role,
        ], 'Supervisor assigned.');
    }

    /**
     * DELETE /api/assignments/supervisions/{assignment}
     */
    public function destroySupervision(Request $request, SupervisionAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assignments->removeSupervisor(
            $assignment,
            $request->user(),
            $validated['reason'] ?? null
        );

return $this->ok(null, 'Supervisor removed. The record has been retained for audit.');
    }

    /**
     * GET /api/assignments/supervisors
     *
     * The pool the coordinator picks a supervisor from, in `supervisor_profiles`
     * terms.
     *
     * This deliberately does not reuse `/users/options`. That endpoint is the
     * generic picker vocabulary — `{value, label, meta}` keyed on *user* ids —
     * and the two id spaces are disjoint: user 3 is supervisor_profiles 1, while
     * supervisor_profiles 3 is a different person entirely. `storeSupervision`
     * validates `supervisor_profile_id` against `supervisor_profiles`, so a
     * picker fed from `/users/options` sends a user id that either 422s or, when
     * it happens to exist, silently assigns the wrong supervisor. Returning
     * profile ids is what makes the pairing correct rather than merely renderable.
     */
    public function indexSupervisors(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupervisionAssignment::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));
        $part = $request->filled('psm_part') ? PsmPart::tryParse($request->input('psm_part')) : null;

        $hasRoom = fn (SupervisorProfile $p) => $part !== null
            ? $p->hasCapacityForInSemester($part, $semesterId)
            : ! $p->isFullInSemester($semesterId);

        $paginator = SupervisorProfile::query()
            // `supervisor_profiles` is shared with the examiner roster, so a
            // SUP-* row and an EXM-* row sit in the same table. Without this the
            // picker offers examiners as supervisors.
            ->whereHas('user', fn ($u) => $u->active()->withRole(Role::Supervisor))
            ->with(['user', 'expertiseAreas'])
            ->when($request->filled('q'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where(
                'name',
                'like',
                '%' . $request->input('q') . '%'
            )))
            ->when($request->boolean('available'), fn ($q) => $q->accepting())
            ->orderBy('staff_no')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($paginator, fn (SupervisorProfile $p) => array_merge(
            // Resolved rather than returned as a Resource: paginated() puts the
            // transform's return value straight into the JSON body, and the
            // resource's `additional()` data does not survive that path.
            (new SupervisorProfileResource($p))->toArray($request),
            [
                // The one field the resource does not carry, because "can this
                // supervisor be handed a student right now" is a question about
                // the pairing screen rather than about the profile: paused intake
                // and a full workload both make them unassignable.
                'has_capacity' => (bool) $p->is_accepting_students && $hasRoom($p),
            ]
        ));
    }

    /**
     * POST /api/assignments/supervisors/{supervisor}/capacity
 *
 * `psm_part` narrows the write to that batch's own cap; without it the flat
 * aggregate is set. They are deliberately separate fields rather than one
 * `max_supervisees` that gets divided, because a coordinator lowering the total
 * must not silently cut one batch's cap — see AssignmentService::setCapacity().
 */
    public function setCapacity(Request $request, SupervisorProfile $supervisor): JsonResponse
    {
        $this->authorize('setCapacity', $supervisor->user);

        $validated = $request->validate([
            'max_supervisees' => ['required_without:psm_part', 'integer', 'min:0', 'max:50'],
            'psm_part'        => ['nullable', Rule::in(['PSM1', 'PSM2'])],
        ]);

        try {
            $updated = $this->assignments->setCapacity(
                $supervisor,
                (int) ($validated['max_supervisees'] ?? 0),
                $request->user(),
                $validated['psm_part'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $updated->load('activeSupervisions');

        return $this->ok([
            'max_supervisees'    => $updated->max_supervisees,
            'current_load'       => $updated->currentLoad(),
            'remaining_capacity' => $updated->remainingCapacity(),

            // The per-part figures, so the coordinator's capacity bar updates
            // from this one response instead of needing a refetch.
            'by_part'            => $updated->capacityReport(),
            'is_overloaded'      => $updated->isOverloaded(),
        ], 'Capacity updated.');
    }

    /**
     * GET /api/assignments/suggest-supervisors/{student}
     *
     * Expertise-overlap suggestions for the pairing screen. Advisory only.
     *
     * Pass `?psm_part=PSM1` to filter on room in that batch's cap — without it a
     * supervisor who is full in PSM 1 still appears, because they may be the
     * right answer for a PSM 2 student.
     */
    public function suggestSupervisors(Request $request, StudentProfile $student): JsonResponse
    {
        $this->authorize('create', SupervisionAssignment::class);

        $psmPart = $request->validate([
            'psm_part' => ['nullable', Rule::in(['PSM1', 'PSM2'])],
        ])['psm_part'] ?? null;

        $suggestions = $this->assignments->suggestSupervisors($student, 5, $psmPart);

        return $this->ok(
            collect($suggestions)->map(fn (array $s) => [
                'supervisor' => new SupervisorProfileResource($s['supervisor']->load('user', 'expertiseAreas')),
                'score'      => $s['score'],
                'matched_on' => $s['matched_on'],
                'reason'     => $s['reason'],
            ])
        );
    }

    // -----------------------------------------------------------------
    // Examiner allocation
    // -----------------------------------------------------------------

    /**
 * GET /api/assignments/examiners
     */
    public function indexExaminers(Request $request): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));
        $psmPart = $request->validate([
            'psm_part' => ['nullable', Rule::in(['PSM1', 'PSM2'])],
        ])['psm_part'] ?? null;

        $paginator = ExaminerAssignment::query()
            ->with(['examiner', 'project', 'student.user'])
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('examiner_id'), fn ($q) => $q->where('examiner_id', $request->integer('examiner_id')))
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
            ->when($semesterId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->where('academic_semester_id', $semesterId)
            ))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($paginator, fn (ExaminerAssignment $a) => [
            'id'          => $a->id,
            // The panel is allocated to the student before a project exists, so
            // the student is the durable identity here and the project may be
            // null until Lampiran B registers one.
            'student'     => [
                'id'         => $a->student?->id,
                'student_id' => $a->student?->student_id,
                'name'       => $a->student?->user?->name,
            ],
            'project'     => [
                'id'            => $a->project?->id,
                'code'          => $a->project?->code,
                'title'         => $a->project?->title,
                'batch'         => $a->project?->batch,
                'psm_part'      => $a->project?->psm_part,
                'semester_id'   => $a->project?->academic_semester_id,
            ],
            'examiner'    => [
                'id'   => $a->examiner?->id,
                'name' => $a->examiner?->displayName(),
            ],
            'psm_part'    => $a->psm_part,
            'psm_part_label' => PsmPart::tryParse($a->psm_part)?->label(),
            'pair_id'     => $a->examiner_pair_id,
            'panel_role'  => $a->panel_role,
            'is_active'   => (bool) $a->is_active,
            'notified_at' => $a->notified_at?->toIso8601String(),
            'created_at'  => $a->created_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/assignments/examiners
     */
    public function storeExaminer(Request $request): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $validated = $request->validate([
            'project_id'  => ['required', 'integer', 'exists:projects,id'],
            'examiner_id' => ['required', 'integer', 'exists:users,id'],
            'psm_part'    => ['sometimes', Rule::in(['PSM1', 'PSM2'])],
            'panel_role'  => ['nullable', Rule::in(['chair', 'member', 'reserve'])],
        ]);

        $project  = Project::findOrFail($validated['project_id']);
        $examiner = User::findOrFail($validated['examiner_id']);

        /**
         * AssignmentService rejects the allocation when the panel is already
         * full, the examiner already sits on it, or the examiner supervises the
         * project. Those are all client errors, so they are translated into a
         * 422 with the reason — without this the panel-full message surfaced as
         * a 500 with a stack trace, which reads like a server fault rather than
         * "you have already allocated both examiners".
         */
        try {
            $assignment = $this->assignments->assignExaminer(
                project: $project,
                examiner: $examiner,
                actor: $request->user(),
                psmPart: $validated['psm_part'] ?? $project->psm_part,
                panelRole: $validated['panel_role'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created([
            'id'       => $assignment->id,
            'project'  => $project->code,
            'examiner' => $examiner->displayName(),
            'psm_part' => $assignment->psm_part,
        ], 'Examiner allocated.');
    }

    /**
     * DELETE /api/assignments/examiners/{assignment}
     */
    public function destroyExaminer(Request $request, ExaminerAssignment $assignment): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        if ($assignment->project->evaluations()->where('assessor_id', $assignment->examiner_id)->exists()) {
            // Deactivate rather than delete: an evaluation already exists, so
            // the allocation is part of the grading record.
            $assignment->update(['is_active' => false]);
        } else {
            $assignment->delete();
        }

        return $this->ok(null, 'Examiner allocation removed.');
    }

    // -----------------------------------------------------------------
    // One student's panel — the coordinator picks the pair
    // -----------------------------------------------------------------
    //
    // The endpoints above seat one examiner at a time against a project. These
    // seat a **pair** against a **student**, which is what a panel actually is:
    // the same two people decide the proposal and give the final mark, and the
    // proposal is reviewed before the project exists.
    //
    // The student's own supervisor is never offered — that is the point of the
    // screen, not a nicety. `ExaminerPairingService::panelCandidates()` leaves
    // them out of the list and `assignPair()` refuses them again on submit, so a
    // stale page cannot seat one.

    /**
     * GET /api/assignments/students/{student}/panel
     *
     * The current panel, who may be seated on it, and who was excluded because
     * they supervise this student — the screen shows the exclusion rather than
     * leaving the coordinator to wonder where a name went.
     */
    public function showPanel(Request $request, StudentProfile $student): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $psmPart = $this->resolvePanelPart($request, $student);

        $student->loadMissing(['user', 'activeSupervisions.supervisorProfile.user']);

        $seated = ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('psm_part', $psmPart)
            ->where('is_active', true)
            ->with('examiner.supervisorProfile')
            ->orderBy('id')
            ->get();

        $supervisors = $student->activeSupervisions
            ->map(fn ($a) => [
                'id'         => $a->supervisorProfile?->user_id,
                'name'       => $a->supervisorProfile?->label(),
                'staff_no'   => $a->supervisorProfile?->staff_no,
            ])
            ->filter(fn ($s) => $s['id'] !== null)
            ->values();

        return $this->ok([
            'student' => [
                'id'         => $student->id,
                'student_id' => $student->student_id,
                'name'       => $student->user?->name,
                'batch'      => $student->batch,
                'program'    => $student->program,
            ],
            'psm_part'    => $psmPart,
            'project'     => ($p = $student->projectForPart($psmPart)) ? [
                'id'    => $p->id,
                'code'  => $p->code,
                'title' => $p->title,
            ] : null,
            // Shown as "cannot examine — supervises this student".
            'supervisors' => $supervisors,
            'panel_size'  => (int) config('psm.examiner_panel_size', 2),
            'seated'      => $seated->map(fn (ExaminerAssignment $a) => [
                'id'          => $a->id,
                'examiner_id' => $a->examiner_id,
                'name'        => $a->examiner?->displayName(),
                'panel_role'  => $a->panel_role,
            ])->values(),
            'candidates'  => $this->pairing->panelCandidates($student, $psmPart)
                ->map(fn (User $u) => [
                    'examiner_id' => $u->id,
                    'name'        => $u->displayName(),
                    'staff_no'    => $u->supervisorProfile?->staff_no,
                ])->values(),
        ]);
    }

    /**
     * POST /api/assignments/students/{student}/panel
     *
     * Seat a pair. The first id is the chair. Re-pairing a student replaces the
     * previous panel rather than being refused — the retired rows are kept so
     * the audit trail still shows who examined before.
     */
    public function storePanel(Request $request, StudentProfile $student): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $validated = $request->validate([
            'examiner_ids'   => ['required', 'array', 'min:2', 'max:2'],
            'examiner_ids.*' => ['integer', 'exists:users,id'],
            'psm_part'       => ['sometimes', Rule::in(['PSM1', 'PSM2'])],
        ], [
            'examiner_ids.required' => 'Pick two examiners — a panel is a pair.',
            'examiner_ids.min'      => 'A panel is a pair — pick two examiners.',
            'examiner_ids.max'      => 'A panel is a pair — pick exactly two examiners.',
        ]);

        $psmPart = $validated['psm_part'] ?? $this->resolvePanelPart($request, $student);

        try {
            $seated = $this->pairing->assignPair(
                $student,
                $validated['examiner_ids'],
                $request->user(),
                $psmPart,
            );
        } catch (InvalidArgumentException $e) {
            // Conflict of interest, a duplicate seat and a missing examiner are
            // all client errors; without this they surface as a 500.
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created([
            'student'  => $student->student_id,
            'psm_part' => $psmPart,
            'panel'    => $seated->map(fn (ExaminerAssignment $a) => [
                'id'          => $a->id,
                'examiner_id' => $a->examiner_id,
                'panel_role'  => $a->panel_role,
            ])->values(),
        ], 'Panel seated.');
    }

    /**
     * The batch a panel is being seated for.
     *
     * Taken from the request when given, otherwise read off the student's own
     * project. A panel is per batch — the PSM 1 and PSM 2 marking forms differ —
     * so guessing wrong would seat the wrong pair of forms.
     */
    protected function resolvePanelPart(Request $request, StudentProfile $student): string
    {
        $requested = $request->input('psm_part');

        if (in_array($requested, ['PSM1', 'PSM2'], true)) {
            return $requested;
        }

        return $student->projectForPart()?->psm_part ?? 'PSM1';
    }

    /**
     * GET /api/assignments/panel-matching
     *
     * Every student beside every examiner who could examine them.
     *
     * The overview the coordinator works from: for each registered student, who
     * **cannot** examine them (their own supervisors), who is already seated, and
     * who is left to choose from. It answers the same question
     * `showPanel()` answers for one student, in a fixed number of queries rather
     * than one per student — the cohort is the unit here, not the individual.
     */
    public function panelMatching(Request $request): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $validated = $request->validate([
            'psm_part' => ['nullable', Rule::in(['PSM1', 'PSM2'])],
        ]);

        return $this->ok($this->pairing->matchingOverview($validated['psm_part'] ?? null));
    }

    // -----------------------------------------------------------------
    // Examiner pairs (the faculty's fixed-panel model)
    // -----------------------------------------------------------------

    /**
     * GET /api/assignments/examiner-pairs
     *
     * Panels for one term and one batch by default. The batch label and the
     * per-panel load are both returned because requirement §1 records the gap
     * this closes: the coordinator could not see at a glance which batch a pair
     * belonged to, or how much of its allocation it had used.
     */
    public function indexExaminerPairs(Request $request): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $semesterId = AcademicSemester::resolveFilterId(
            $request->input('semester_id') ?? $request->input('academic_session')
        );

        $pairs = ExaminerPair::query()
            ->with(['examiner1', 'examiner2', 'academicSemester'])
            ->forSemester($semesterId)
            ->when(
                $request->filled('psm_part'),
                fn ($q) => $q->forPart($request->string('psm_part')->toString())
            )
            ->orderBy('psm_part')
            ->orderBy('name')
            ->get()
            ->map(fn (ExaminerPair $p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'psm_part'       => $p->psm_part,
                'psm_part_label' => PsmPart::tryParse($p->psm_part)?->label(),
                'semester_id'    => $p->academic_semester_id,
                'semester_name'  => $p->academicSemester?->name,
                'members'        => $p->memberNames(),
                'is_active'      => $p->is_active,
                'project_count'  => $p->projectCount(),
                'capacity'       => $p->capacityForPart(),
                'remaining'      => $p->remainingCapacity(),
                'is_full'        => $p->isFull(),
            ]);

        return $this->ok($pairs);
    }

    /**
     * POST /api/assignments/examiner-pairs/auto-assign
     *
     * The automatic panel assignment. Give it a set of students — normally one
     * batch of one term, an explicit student list, or the students whose
     * proposals are waiting on a panel — and it batches them across the standing
     * examiner pairs, two examiners to a student.
     *
     * It allocates against the *student*, not the project, because the panel
     * must exist before Lampiran B creates the project: the same two examiners
     * review the proposal and give the final mark.
     *
     * It will not seat an examiner on a student they supervise, and it refuses
     * the run outright if excluding those people leaves fewer than two
     * examiners, rather than quietly filling the gap with a conflicted one.
     *
     * `semester_id` is requirement §4.2: without it the run spans whichever
     * terms the students happen to belong to, and a panel appointed for one
     * term would mark another term's work.
     */
    public function autoAssignExaminerPairs(Request $request): JsonResponse
    {
        $this->authorize('allocateExaminer', User::class);

        $validated = $request->validate([
            'psm_part'           => ['nullable', Rule::in(['PSM1', 'PSM2'])],
            'semester_id'        => ['nullable', 'integer', 'exists:academic_semesters,id'],
            'academic_session'   => ['nullable', 'string', 'max:32'],
            'per_pair'           => ['nullable', 'integer', 'min:1', 'max:100'],
            'project_ids'        => ['sometimes', 'array'],
            'project_ids.*'      => ['integer', 'exists:projects,id'],
            'student_profile_ids' => ['sometimes', 'array'],
            'student_profile_ids.*' => ['integer', 'exists:student_profiles,id'],
            // The panel-allocation roster: students whose Lampiran A the
            // supervisor has acknowledged and the panel has yet to decide.
            'awaiting_panel'     => ['sometimes', 'boolean'],
        ]);

        $semesterId = AcademicSemester::resolveFilterId(
            $validated['semester_id'] ?? $validated['academic_session'] ?? null
        );

        $students = $this->studentsForAssignment($validated, $semesterId);

        try {
            $result = $this->pairing->autoAssign(
                $students,
                $request->user(),
                (int) ($validated['per_pair'] ?? 0),
                $semesterId,
                $validated['psm_part'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            $result,
            sprintf(
                'Assigned %d student(s) across %d %s panel(s).',
                $result['assigned'],
                count($result['pairs']),
                $result['psm_part'],
            )
        );
    }

    /**
     * Resolve the student set the run applies to.
     *
     * An explicit project list, an explicit student list, the students awaiting
     * a panel verdict, or one batch of one term — in that order of specificity.
     * Students already carrying a full active pair are still included: the run
     * is idempotent and re-seating them on the same pair is how the batch stays
     * consistent.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, StudentProfile>
     */
    protected function studentsForAssignment(array $filters, ?int $semesterId = null)
    {
        if (! empty($filters['project_ids'])) {
            return StudentProfile::query()
                ->whereHas('projects', fn ($q) => $q->whereIn('projects.id', $filters['project_ids']))
                ->with($this->assignmentEagerLoads())
                ->orderBy('id')
                ->get();
        }

        if (! empty($filters['student_profile_ids'])) {
            return StudentProfile::query()
                ->whereIn('id', $filters['student_profile_ids'])
                ->with($this->assignmentEagerLoads())
                ->orderBy('id')
                ->get();
        }

        if (! empty($filters['awaiting_panel'])) {
            return $this->studentsAwaitingPanel($filters, $semesterId);
        }

        return StudentProfile::query()
            ->inSemesterPart($semesterId, $filters['psm_part'] ?? null)
            ->with($this->assignmentEagerLoads())
            ->orderBy('id')
            ->get();
    }

    /**
     * The students who still need a panel seated.
     *
     * A registered project whose panel has not been allocated yet. This is the
     * coordinator's "seat these next" roster, and it matters more than it used
     * to: the proposal milestone cannot be decided until two examiners are
     * seated, so a student left here cannot start the rest of their chain.
     *
     * It used to be drawn from the roster of a title-defence sitting; the
     * defence was folded onto the proposal milestone, so the set is now simply
     * "has a live project, has no active panel".
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, StudentProfile>
     */
    protected function studentsAwaitingPanel(array $filters, ?int $semesterId = null)
    {
        return StudentProfile::query()
            ->inSemesterPart($semesterId, $filters['psm_part'] ?? null)
            ->whereHas('projects', fn ($q) => $q->whereNull('archived_at'))
            ->whereDoesntHave(
                'projects.examinerAssignments',
                fn ($q) => $q->where('is_active', true)
            )
            ->with($this->assignmentEagerLoads())
            ->orderBy('id')
            ->get();
    }

    /**
     * The relations both the conflict-of-interest check and the panel lookup
     * walk, loaded up front so a cohort run does not issue a query per student.
     *
     * @return array<int, string>
     */
    protected function assignmentEagerLoads(): array
    {
        return [
            'activeSupervisions.supervisorProfile',
            'projects',
        ];
    }

    // -----------------------------------------------------------------
    // Reference data
    // -----------------------------------------------------------------

    /**
     * GET /api/assignments/expertise-areas
     */
    public function expertiseAreas(): JsonResponse
    {
        $areas = ExpertiseArea::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category ?: 'Other',
                'areas'    => $group->map(fn (ExpertiseArea $a) => [
                    'id'   => $a->id,
                    'name' => $a->name,
                    'slug' => $a->slug,
                ])->values(),
            ])
            ->values();

        return $this->ok($areas);
    }

/**
     * GET /api/assignments/students/unassigned
     *
     * Students with no active supervisor — the coordinator's work queue.
     *
     * The semester filter is on the student's *enrolment*, not on their project:
     * a student waiting for a supervisor has a profile enrolled in this term but
     * often no project at all, and filtering on the project would hide exactly
     * the people this queue exists to surface.
     *
     * A `psm_part` filter does narrow through the project, which means a student
     * with no project yet drops out of both batch tabs. That is deliberate: they
     * cannot be attributed to a batch, and listing them under both would double
     * them up in a queue the coordinator works through once.
     */
    public function unassignedStudents(Request $request): JsonResponse
    {
        $this->authorize('create', SupervisionAssignment::class);

        $semesterId = AcademicSemester::resolveFilterId($request->input('semester_id'));
        $psmPart = $request->validate([
            'psm_part' => ['nullable', Rule::in(['PSM1', 'PSM2'])],
        ])['psm_part'] ?? null;

        $students = StudentProfile::query()
            ->with('user')
            ->whereDoesntHave('activeSupervisions')
            ->when($semesterId !== null && $psmPart === null, fn ($q) => $q->where(
                'academic_semester_id',
                $semesterId
            ))
            ->when($request->filled('batch'), fn ($q) => $q->where('batch', $request->input('batch')))
            ->where('is_active_cohort', true)
            ->when($psmPart !== null, fn ($q) => $q->inSemesterPart($semesterId, $psmPart))
            ->orderBy('batch')
            ->orderBy('student_id')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated(
            $students,
            fn (StudentProfile $s) => (new StudentProfileResource($s))->resolve($request)
        );
    }
}
