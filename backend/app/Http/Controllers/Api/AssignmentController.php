<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\StudentProfileResource;
use App\Http\Resources\SupervisorProfileResource;
use App\Models\ExpertiseArea;
use App\Models\ExaminerAssignment;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisionAssignment;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    ) {
    }

    // -----------------------------------------------------------------
    // Supervisor ↔ student
    // -----------------------------------------------------------------

    /**
     * GET /api/assignments/supervisions
     */
    public function indexSupervisions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupervisionAssignment::class);

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
            'psm_part'  => $a->psm_part,
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
            'role'                  => ['sometimes', Rule::in(['primary', 'co', 'advisor'])],
            'responsibility_percent'=> ['sometimes', 'numeric', 'min:0', 'max:100'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);

        $student    = StudentProfile::findOrFail($validated['student_profile_id']);
        $supervisor = SupervisorProfile::findOrFail($validated['supervisor_profile_id']);

        $assignment = $this->assignments->assignSupervisor(
            student: $student,
            supervisor: $supervisor,
            actor: $request->user(),
            psmPart: $validated['psm_part'] ?? 'BOTH',
            role: $validated['role'] ?? 'primary',
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
     * POST /api/assignments/supervisors/{supervisor}/capacity
     */
    public function setCapacity(Request $request, SupervisorProfile $supervisor): JsonResponse
    {
        $this->authorize('setCapacity', $supervisor->user);

        $validated = $request->validate([
            'max_supervisees' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $updated = $this->assignments->setCapacity(
            $supervisor,
            $validated['max_supervisees'],
            $request->user()
        );

        return $this->ok([
            'max_supervisees'    => $updated->max_supervisees,
            'current_load'       => $updated->currentLoad(),
            'remaining_capacity' => $updated->remainingCapacity(),
            'is_overloaded'      => $updated->isOverloaded(),
        ], 'Capacity updated.');
    }

    /**
     * GET /api/assignments/suggest-supervisors/{student}
     *
     * Expertise-overlap suggestions for the pairing screen. Advisory only.
     */
    public function suggestSupervisors(StudentProfile $student): JsonResponse
    {
        $this->authorize('create', SupervisionAssignment::class);

        $suggestions = $this->assignments->suggestSupervisors($student);

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

        $paginator = ExaminerAssignment::query()
            ->with(['examiner', 'project'])
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('examiner_id'), fn ($q) => $q->where('examiner_id', $request->integer('examiner_id')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($paginator, fn (ExaminerAssignment $a) => [
            'id'          => $a->id,
            'project'     => [
                'id'    => $a->project?->id,
                'code'  => $a->project?->code,
                'title' => $a->project?->title,
                'batch' => $a->project?->batch,
            ],
            'examiner'    => [
                'id'   => $a->examiner?->id,
                'name' => $a->examiner?->displayName(),
            ],
            'psm_part'    => $a->psm_part,
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

        $assignment = $this->assignments->assignExaminer(
            project: $project,
            examiner: $examiner,
            actor: $request->user(),
            psmPart: $validated['psm_part'] ?? $project->psm_part,
            panelRole: $validated['panel_role'] ?? null,
        );

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
     */
    public function unassignedStudents(Request $request): JsonResponse
    {
        $this->authorize('create', SupervisionAssignment::class);

        $students = StudentProfile::query()
            ->with('user')
            ->whereDoesntHave('activeSupervisions')
            ->when($request->filled('batch'), fn ($q) => $q->where('batch', $request->input('batch')))
            ->where('is_active_cohort', true)
            ->orderBy('batch')
            ->orderBy('student_id')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated(
            $students,
            fn (StudentProfile $s) => (new StudentProfileResource($s))->resolve($request)
        );
    }
}
