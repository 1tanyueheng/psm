<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 2 — Student profile payload.
 *
 * @mixin \App\Models\StudentProfile
 */
class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'name'          => $this->whenLoaded('user', fn () => $this->user->name),
            'email'         => $this->whenLoaded('user', fn () => $this->user->email),
            'student_id'    => $this->student_id,
            'program'       => $this->program,
            'program_code'  => $this->program_code,
            'batch'         => $this->batch,
            'faculty'       => $this->faculty,
            'current_semester' => $this->current_semester,
            'phone_emergency'  => $this->phone_emergency,

            // Module 3 — declared topic, before or alongside registration
            'thesis_title'    => $this->thesis_title,
            'thesis_abstract' => $this->thesis_abstract,

            'max_supervisors'          => $this->max_supervisors,
            'remaining_supervisor_slots'=> $this->remainingSupervisorSlots(),
            'is_active_cohort'         => (bool) $this->is_active_cohort,

            'supervisors' => $this->whenLoaded('supervisors', fn () => $this->supervisors->map(fn ($s) => [
                'id'                     => $s->id,
                'name'                   => $s->label(),
                'staff_no'               => $s->staff_no,
                'role'                   => $s->pivot->role,
                'psm_part'               => $s->pivot->psm_part,
                'responsibility_percent' => (float) $s->pivot->responsibility_percent,
                'is_active'              => (bool) $s->pivot->is_active,
            ])),

            'projects' => ProjectResource::collection($this->whenLoaded('projects')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
