<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Resources\UserResource;
use App\Models\SupervisorProfile;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 2 — Self-service profile management.
 *
 * A user may edit their own contact details and, where they exist, their
 * profile attributes. Role, status and capacity are deliberately excluded —
 * those belong to UserController and its admin-only guards.
 */
class ProfileController extends ApiController
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'studentProfile.supervisors.user',
            'studentProfile.projects',
            'supervisorProfile.expertiseAreas',
            'coordinatorScopes',
        ]);

        return $this->ok(new UserResource($user));
    }

    /**
     * PUT /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            // Student attributes
            'thesis_title'    => ['nullable', 'string', 'max:255'],
            'thesis_abstract' => ['nullable', 'string', 'max:5000'],
            'phone_emergency' => ['nullable', 'string', 'max:32'],

            // Supervisor attributes
            'academic_title'        => ['nullable', 'string', 'max:64'],
            'office_location'       => ['nullable', 'string', 'max:255'],
            'bio'                   => ['nullable', 'string', 'max:3000'],
            'is_accepting_students' => ['sometimes', 'boolean'],
            'expertise_area_ids'    => ['sometimes', 'array'],
            'expertise_area_ids.*'  => ['integer', 'exists:expertise_areas,id'],
        ]);

        $before = $user->getAttributes();

        // Contact details (any role)
        $user->fill(collect($validated)->only(['name', 'phone'])->all())->save();

        if ($user->isStudent() && $user->studentProfile) {
            $user->studentProfile->fill(collect($validated)->only([
                'thesis_title', 'thesis_abstract', 'phone_emergency',
            ])->all())->save();
        }

        if ($user->isSupervisor() && $user->supervisorProfile) {
            $user->supervisorProfile->fill(collect($validated)->only([
                'academic_title', 'office_location', 'bio', 'is_accepting_students',
            ])->all())->save();

            if (array_key_exists('expertise_area_ids', $validated)) {
                $user->supervisorProfile->expertiseAreas()->sync($validated['expertise_area_ids']);
            }
        }

        $this->audit->log(
            action: AuditAction::ProfileUpdated,
            description: 'Updated own profile',
            subject: $user,
            before: $before,
            after: $user->fresh()->getAttributes(),
            actor: $user,
        );

        return $this->ok(
            new UserResource($user->fresh([
                'studentProfile.supervisors.user',
                'supervisorProfile.expertiseAreas',
            ])),
            'Profile updated.'
        );
    }

    /**
     * POST /api/profile/availability
     *
     * A supervisor taking themselves off the assignment list — they can do
     * this, but not change their capacity ceiling (that is a coordinator act).
     */
    public function setAvailability(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSupervisor() || $user->supervisorProfile === null) {
            return $this->fail('Only a supervisor has an availability setting.', 403);
        }

        $validated = $request->validate([
            'is_accepting_students' => ['required', 'boolean'],
        ]);

        $profile = $user->supervisorProfile;
        $before = $profile->getAttributes();

        $profile->update(['is_accepting_students' => $validated['is_accepting_students']]);

        $this->audit->log(
            action: AuditAction::ProfileUpdated,
            description: $validated['is_accepting_students']
                ? 'Set availability to accepting students'
                : 'Set availability to not accepting students',
            subject: $profile,
            before: $before,
            after: $profile->fresh()->getAttributes(),
            actor: $user,
        );

        return $this->ok([
            'is_accepting_students' => (bool) $profile->fresh()->is_accepting_students,
            'current_load'          => $profile->currentLoad(),
            'max_supervisees'       => $profile->max_supervisees,
        ], $validated['is_accepting_students']
            ? 'You are now visible to coordinators for new assignments.'
            : 'You will not be assigned new students.');
    }

    /**
     * GET /api/profile/workload
     *
     * A supervisor's own supervision load and outstanding review queue.
     */
    public function workload(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSupervisor() || $user->supervisorProfile === null) {
            return $this->fail('Only a supervisor has a workload view.', 403);
        }

        $profile = $user->supervisorProfile;

        $supervisees = $profile->activeSupervisions()
            ->with(['studentProfile.user', 'studentProfile.projects.milestones'])
            ->get()
            ->map(fn ($a) => [
                'student_id' => $a->studentProfile?->student_id,
                'name'       => $a->studentProfile?->user?->name,
                'program'    => $a->studentProfile?->program,
                'batch'      => $a->studentProfile?->batch,
                'role'       => $a->role,
                'psm_part'   => $a->psm_part,
                'projects'   => $a->studentProfile?->projects->map(fn ($p) => [
                    'id'        => $p->id,
                    'code'      => $p->code,
                    'title'     => $p->title,
                    'psm_part'  => $p->psm_part,
                    'progress'  => $p->milestoneProgressPercent(),
                    'current_stage' => $p->currentStageLabel(),
                ]) ?? [],
            ]);

        // Milestones awaiting this supervisor's review
        $studentIds = $profile->activeSupervisions()->pluck('student_profile_id');

        $projectIds = \Illuminate\Support\Facades\DB::table('project_members')
            ->whereIn('student_profile_id', $studentIds)
            ->pluck('project_id');

        $pendingReviews = \App\Models\Milestone::query()
            ->with(['project'])
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', ['submitted', 'reviewed'])
            ->orderBy('due_at')
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'project_id' => $m->project_id,
                'project_code' => $m->project?->code,
                'title'      => $m->title,
                'submitted_at' => $m->submitted_at?->toIso8601String(),
                'days_waiting' => $m->submitted_at ? (int) $m->submitted_at->diffInDays(now()) : null,
            ]);

        return $this->ok([
            'supervisor' => [
                'name'       => $profile->label(),
                'capacity'   => $profile->max_supervisees,
                'load'       => $profile->currentLoad(),
                'remaining'  => $profile->remainingCapacity(),
                'utilisation'=> $profile->utilisationPercent(),
                'overloaded' => $profile->isOverloaded(),
                'accepting'  => (bool) $profile->is_accepting_students,
            ],
            'supervisees'    => $supervisees,
            'pending_reviews'=> $pendingReviews,
            'pending_count'  => $pendingReviews->count(),
        ]);
    }

    /**
     * PUT /api/profile/expertise
     *
     * Separate from the main update because the UI edits it as its own list.
     */
    public function updateExpertise(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSupervisor() || $user->supervisorProfile === null) {
            return $this->fail('Only a supervisor has expertise areas.', 403);
        }

        $validated = $request->validate([
            'areas'                => ['required', 'array'],
            'areas.*.id'           => ['required', 'integer', 'exists:expertise_areas,id'],
            'areas.*.proficiency'  => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $sync = collect($validated['areas'])
            ->mapWithKeys(fn (array $a) => [
                $a['id'] => ['proficiency' => $a['proficiency'] ?? 3],
            ])
            ->all();

        $profile = $user->supervisorProfile;
        $before = $profile->expertiseAreas()->get()->pluck('id')->all();

        $profile->expertiseAreas()->sync($sync);

        $this->audit->log(
            action: AuditAction::ProfileUpdated,
            description: 'Updated expertise areas',
            subject: $profile,
            before: ['expertise_area_ids' => $before],
            after: ['expertise_area_ids' => array_keys($sync)],
            actor: $user,
        );

        return $this->ok(
            $profile->fresh()->expertiseAreas->map(fn ($a) => [
                'id'          => $a->id,
                'name'        => $a->name,
                'category'    => $a->category,
                'proficiency' => (int) $a->pivot->proficiency,
            ]),
            'Expertise areas updated.'
        );
    }
}
