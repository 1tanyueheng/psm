<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 — The coordinator's mark submission for one student on one project.
 *
 * Carries the readiness checklist and the forms so the coordinator can open,
 * watch, and lock without extra round-trips.
 *
 * @mixin \App\Models\MarkSubmission
 */
class MarkSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // `readiness` is a computed method on the model, not a relation, so it
        // is called directly rather than through whenLoaded().
        $readiness = $this->readiness();

        return [
            'id'                    => $this->id,
            'project_id'            => $this->project_id,
            'student_profile_id'    => $this->student_profile_id,
            'psm_part'              => $this->psm_part,
            'academic_semester_id'  => $this->academic_semester_id,
            'status'                => $this->status?->value,
            'status_label'          => $this->status?->label(),
            'status_tone'           => $this->status?->tone(),
            'expected_panel_size'   => $this->expected_panel_size,
            'opened_by'             => $this->whenLoaded('openedBy', fn () => new UserResource($this->openedBy)),
            'opened_at'             => $this->opened_at?->toIso8601String(),
            'locked_by'             => $this->whenLoaded('lockedBy', fn () => new UserResource($this->lockedBy)),
            'locked_at'             => $this->locked_at?->toIso8601String(),
            'unlock_reason'         => $this->unlock_reason,
            'final_grade_id'        => $this->final_grade_id,
            'notes'                 => $this->notes,
            'created_at'            => $this->created_at?->toIso8601String(),
            'updated_at'            => $this->updated_at?->toIso8601String(),

            // The readiness checklist and all forms
            'readiness'             => $readiness,

            // Eager-loaded project + student for the header
            'project'               => $this->whenLoaded('project', fn () => [
                'id'    => $this->project?->id,
                'code'  => $this->project?->code,
                'title' => $this->project?->title,
            ]),
            'student'               => $this->whenLoaded('studentProfile', fn () => [
                'id'    => $this->studentProfile?->id,
                'user'  => $this->studentProfile?->user ? new UserResource($this->studentProfile->user) : null,
            ]),
        ];
    }
}