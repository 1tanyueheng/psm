<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 1 / 2 — The canonical user payload sent to the SPA.
 *
 * Includes the role's permissions as booleans so the frontend can hide
 * navigation without duplicating the RBAC rules in JavaScript. The server
 * still enforces everything; these flags are purely for UI affordance.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'email' => $this->email,

            'role'          => $this->role->value,
            'role_label'    => $this->role->label(),
            'status'        => $this->status,
            'phone'         => $this->phone,
            'avatar_url'    => $this->avatar_path
                ? asset('storage/'.$this->avatar_path)
                : null,
            'department'    => $this->department,
            'staff_id'      => $this->staff_id,

            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at'     => $this->last_login_at?->toIso8601String(),

            // Forces the SPA into the change-password flow
            'must_change_password' => (bool) $this->must_change_password,

            // -----------------------------------------------------------------
            // UI affordances. Each mirrors a policy; when a policy changes,
            // this is the single place to update so the UI stays in step.
            // -----------------------------------------------------------------
            'permissions' => [
                'can_assess'               => $this->canAssess(),
                'can_manage_users'         => $this->canManageUsers(),
                'can_view_cohort_analytics'=> $this->canViewCohortAnalytics(),
                'can_access_archive'       => $this->canAccessArchive(),
                'can_publish_leaderboard'  => $this->hasRole('admin', 'coordinator'),
                'can_manage_rubrics'       => $this->hasRole('admin', 'coordinator'),
                'is_student'               => $this->isStudent(),
                'is_supervisor'            => $this->isSupervisor(),
                'is_coordinator'           => $this->isCoordinator(),
                'is_examiner'              => $this->isExaminer(),
                'is_admin'                 => $this->isAdmin(),
            ],

            // Where this role's dashboard lives
            'home' => $this->role->homeRoute(),

            'student_profile'    => new StudentProfileResource($this->whenLoaded('studentProfile')),
            'supervisor_profile' => new SupervisorProfileResource($this->whenLoaded('supervisorProfile')),
            'coordinator_scopes' => $this->whenLoaded('coordinatorScopes', fn () => $this->coordinatorScopes->map(fn ($s) => [
                'id'      => $s->id,
                'batch'   => $s->batch,
                'program' => $s->program,
                'label'   => $s->describe(),
            ])),

            'notification_preferences' => $this->notification_preferences ?? new \stdClass(),
            'digest_only'              => (bool) $this->digest_only,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
