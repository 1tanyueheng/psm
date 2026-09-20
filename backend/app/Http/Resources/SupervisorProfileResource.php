<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 2 — Supervisor profile payload, including live capacity figures
 * that the coordinator's assignment screen depends on.
 *
 * @mixin \App\Models\SupervisorProfile
 */
class SupervisorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'name'           => $this->whenLoaded('user', fn () => $this->user->name),
            'display_name'   => $this->label(),
            'email'          => $this->whenLoaded('user', fn () => $this->user->email),
            'staff_no'       => $this->staff_no,
            'academic_title' => $this->academic_title,
            'office_location'=> $this->office_location,
            'bio'            => $this->bio,
            'can_examine'    => (bool) $this->can_examine,

            // Module 2 — the capacity constraint, resolved server-side
            'max_supervisees'      => $this->max_supervisees,
            'current_load'         => $this->currentLoad(),
            'remaining_capacity'   => $this->remainingCapacity(),
            'utilisation_percent'  => $this->utilisationPercent(),
            'is_full'              => $this->isFull(),
            'is_overloaded'        => $this->isOverloaded(),
            'is_accepting_students'=> (bool) $this->is_accepting_students,

            'workload_release_percent' => (float) $this->workload_release_percent,

            'expertise_areas' => $this->whenLoaded('expertiseAreas', fn () => $this->expertiseAreas->map(fn ($a) => [
                'id'          => $a->id,
                'name'        => $a->name,
                'category'    => $a->category,
                'proficiency' => (int) $a->pivot->proficiency,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
