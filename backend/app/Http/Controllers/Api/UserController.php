<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\UserResource;
use App\Models\CoordinatorScope;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Module 2 — Account and profile administration.
 *
 * Creation is admin-only (UserPolicy::create). Coordinators manage the
 * *profiles* and their pairings through AssignmentController.
 */
class UserController extends ApiController
{
    public function __construct(
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    /**
     * GET /api/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->with(['studentProfile', 'supervisorProfile'])
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('staff_id', 'like', $term);
                });
            })
            ->when($request->filled('batch'), fn ($q) => $q->whereHas(
                'studentProfile',
                fn ($s) => $s->where('batch', $request->input('batch'))
            ))
            ->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->paginated(
            $paginator,
            fn (User $user) => (new UserResource($user))->resolve($request)
        );
    }

    /**
     * GET /api/users/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['studentProfile.supervisors', 'supervisorProfile.expertiseAreas', 'coordinatorScopes']);

        return $this->ok(new UserResource($user));
    }

    /**
     * POST /api/users
     *
     * Creates an account and its role-specific profile in one transaction, then
     * emails a set-password link. The account starts with a random password and
     * must_change_password = true, so no plaintext credential is ever
     * transmitted or chosen by an administrator.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'       => ['required', Rule::in(Role::values())],
            'status'     => ['sometimes', Rule::in(['active', 'inactive', 'pending'])],
            'phone'      => ['nullable', 'string', 'max:32'],
            'staff_id'   => ['nullable', 'string', 'max:64', 'unique:users,staff_id'],
            'department' => ['nullable', 'string', 'max:255'],

            // Student profile
            'student_id'     => ['required_if:role,student', 'nullable', 'string', 'max:32', 'unique:student_profiles,student_id'],
            'program'        => ['required_if:role,student', 'nullable', 'string', 'max:128'],
            'program_code'   => ['nullable', 'string', 'max:32'],
            'batch'          => ['required_if:role,student', 'nullable', 'string', 'max:32'],
            'faculty'        => ['nullable', 'string', 'max:255'],
            'thesis_title'   => ['nullable', 'string', 'max:255'],

            // Supervisor profile
            'staff_no'          => ['required_if:role,supervisor', 'nullable', 'string', 'max:32', 'unique:supervisor_profiles,staff_no'],
            'academic_title'    => ['nullable', 'string', 'max:64'],
            'max_supervisees'   => ['nullable', 'integer', 'min:0', 'max:50'],
            'expertise_area_ids'=> ['sometimes', 'array'],
            'expertise_area_ids.*' => ['integer', 'exists:expertise_areas,id'],

            // Coordinator scope
            'scopes'          => ['sometimes', 'array'],
            'scopes.*.batch'  => ['nullable', 'string', 'max:32'],
            'scopes.*.program'=> ['nullable', 'string', 'max:128'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name'                 => $validated['name'],
                'email'                => strtolower($validated['email']),
                'password'             => bcrypt(Str::random(32)),
                'role'                 => $validated['role'],
                'status'               => $validated['status'] ?? 'active',
                'phone'                => $validated['phone'] ?? null,
                'staff_id'             => $validated['staff_id'] ?? null,
                'department'           => $validated['department'] ?? null,
                'must_change_password' => true,
            ]);

            match ($user->role) {
                Role::Student => StudentProfile::create([
                    'user_id'        => $user->id,
                    'student_id'     => $validated['student_id'],
                    'program'        => $validated['program'],
                    'program_code'   => $validated['program_code'] ?? null,
                    'batch'          => $validated['batch'],
                    'faculty'        => $validated['faculty'] ?? null,
                    'thesis_title'   => $validated['thesis_title'] ?? null,
                ]),

                Role::Supervisor => tap(SupervisorProfile::create([
                    'user_id'          => $user->id,
                    'staff_no'         => $validated['staff_no'],
                    'academic_title'   => $validated['academic_title'] ?? null,
                    'max_supervisees'  => $validated['max_supervisees']
                        ?? config('psm.supervisor_max_capacity', 8),
                ]), function (SupervisorProfile $profile) use ($validated) {
                    if (! empty($validated['expertise_area_ids'])) {
                        $profile->expertiseAreas()->sync($validated['expertise_area_ids']);
                    }
                }),

                Role::Coordinator => collect($validated['scopes'] ?? [])
                    ->each(fn (array $scope) => CoordinatorScope::create([
                        'user_id' => $user->id,
                        'batch'   => $scope['batch'] ?? null,
                        'program' => $scope['program'] ?? null,
                    ])),

                default => null,
            };

            return $user;
        });

        // Send the set-password link (Module 6, account.created)
        Password::sendResetLink(['email' => $user->email]);

        $this->audit->log(
            action: AuditAction::UserCreated,
            description: "Created {$user->role->label()} account: {$user->email}",
            subject: $user,
            after: $user->getAttributes(),
        );

        return $this->created(
            new UserResource($user->load(['studentProfile', 'supervisorProfile', 'coordinatorScopes'])),
            'Account created. A set-password email has been sent.'
        );
    }

    /**
     * PATCH /api/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $isAdmin = $request->user()->isAdmin();

        $validated = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'email'      => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'      => ['nullable', 'string', 'max:32'],
            'department' => ['nullable', 'string', 'max:255'],

            // Role and status are admin-only; validated conditionally
            'role'   => ['sometimes', Rule::in(Role::values())],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'pending'])],

            'student_id'   => ['sometimes', 'string', 'max:32', Rule::unique('student_profiles', 'student_id')->ignore($user->studentProfile?->id)],
            'program'      => ['sometimes', 'string', 'max:128'],
            'program_code' => ['nullable', 'string', 'max:32'],
            'batch'        => ['sometimes', 'string', 'max:32'],
            'thesis_title' => ['nullable', 'string', 'max:255'],

            'academic_title'   => ['nullable', 'string', 'max:64'],
            'max_supervisees'  => ['sometimes', 'integer', 'min:0', 'max:50'],
            'is_accepting_students' => ['sometimes', 'boolean'],
            'expertise_area_ids'    => ['sometimes', 'array'],
            'expertise_area_ids.*'  => ['integer', 'exists:expertise_areas,id'],
        ]);

        // Guard the sensitive fields explicitly rather than silently ignoring
        if (isset($validated['role']) && ! $isAdmin) {
            return $this->fail('Only a system administrator may change a role.', 403);
        }

        if (isset($validated['status']) && ! $isAdmin) {
            return $this->fail('Only a system administrator may change an account status.', 403);
        }

        $before = $user->getAttributes();

        DB::transaction(function () use ($user, $validated) {
            $user->fill(collect($validated)->only([
                'name', 'email', 'phone', 'department', 'role', 'status',
            ])->all())->save();

            if ($user->isStudent() && $user->studentProfile) {
                $user->studentProfile->fill(collect($validated)->only([
                    'student_id', 'program', 'program_code', 'batch', 'thesis_title',
                ])->all())->save();
            }

            if ($user->isSupervisor() && $user->supervisorProfile) {
                $user->supervisorProfile->fill(collect($validated)->only([
                    'academic_title', 'max_supervisees', 'is_accepting_students',
                ])->all())->save();

                if (array_key_exists('expertise_area_ids', $validated)) {
                    $user->supervisorProfile->expertiseAreas()->sync($validated['expertise_area_ids']);
                }
            }
        });

        $this->audit->log(
            action: isset($validated['role']) && $validated['role'] !== $before['role']
                ? AuditAction::RoleChanged
                : AuditAction::UserUpdated,
            description: "Updated account {$user->email}",
            subject: $user,
            before: $before,
            after: $user->fresh()->getAttributes(),
        );

        return $this->ok(
            new UserResource($user->fresh(['studentProfile', 'supervisorProfile', 'coordinatorScopes'])),
            'Account updated.'
        );
    }

    /**
     * POST /api/users/{user}/deactivate
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('deactivate', $user);

        $before = $user->getAttributes();

        $user->update(['status' => 'inactive']);
        // Revoke every session so an active token cannot outlive the change
        $user->tokens()->delete();

        $this->audit->log(
            action: AuditAction::UserDeactivated,
            description: "Deactivated {$user->email}",
            subject: $user,
            before: $before,
            after: $user->getAttributes(),
        );

        return $this->ok(null, 'Account deactivated and all sessions revoked.');
    }

    /**
     * POST /api/users/{user}/reactivate
     */
    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('reactivate', $user);

        $before = $user->getAttributes();

        $user->update(['status' => 'active', 'locked_until' => null, 'failed_login_attempts' => 0]);

        $this->audit->log(
            action: AuditAction::UserReactivated,
            description: "Reactivated {$user->email}",
            subject: $user,
            before: $before,
            after: $user->getAttributes(),
        );

        return $this->ok(null, 'Account reactivated.');
    }

    /**
     * POST /api/users/{user}/reset-password
     *
     * Issues a set-password link and flags a mandatory change. Used when a
     * user is locked out and the token itself is not recoverable.
     */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update(['must_change_password' => true]);
        $user->tokens()->delete();

        Password::sendResetLink(['email' => $user->email]);

        $this->audit->log(
            action: AuditAction::PasswordReset,
            description: "Password reset link issued for {$user->email}",
            subject: $user,
        );

        return $this->ok(null, 'A password reset link has been sent to the user.');
    }

    /**
     * POST /api/users/{user}/unlock
     */
    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->authorize('unlock', $user);

        $user->unlock();

        $this->audit->log(
            action: AuditAction::UserReactivated,
            description: "Unlocked account {$user->email}",
            subject: $user,
        );

        return $this->ok(null, 'Account unlocked.');
    }

    /**
     * DELETE /api/users/{user}
     *
     * Soft-deletes and anonymises the contact details, retaining the academic
     * record (a student's project history must survive their account).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $before = $user->getAttributes();

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();

            // Keep the record readable but strip the identifiers
            $user->forceFill([
                'email'  => "deleted+{$user->id}@removed.local",
                'phone'  => null,
                'status' => 'inactive',
            ])->save();

            $user->delete();
        });

        $this->audit->log(
            action: AuditAction::UserDeactivated,
            description: "Deleted account (was {$before['email']})",
            subject: $user,
            before: $before,
        );

        return $this->ok(null, 'Account deleted. The academic record has been retained.');
    }

    /**
     * GET /api/users/staff/options
     *
     * Lightweight lists for dropdowns (supervisors, examiners, students),
     * avoiding a full resource payload per option.
     */
    public function options(Request $request): JsonResponse
    {
        $role = $request->input('role', 'supervisor');

        $users = User::query()
            ->active()
            ->withRole($role)
            ->with(['supervisorProfile', 'studentProfile'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'value' => $u->id,
                'label' => $u->displayName(),
                'meta'  => match ($role) {
                    'student' => [
                        'student_id' => $u->studentProfile?->student_id,
                        'batch'      => $u->studentProfile?->batch,
                        'program'    => $u->studentProfile?->program,
                    ],
                    'supervisor', 'examiner' => [
                        'staff_no'   => $u->supervisorProfile?->staff_no,
                        'capacity'   => $u->supervisorProfile?->max_supervisees,
                        'load'       => $u->supervisorProfile?->currentLoad(),
                        'available'  => $u->isAvailableSupervisor(),
                    ],
                    default => [],
                },
            ]);

        return $this->ok($users);
    }
}
