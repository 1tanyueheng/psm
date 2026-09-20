<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 — Project payload.
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'title'          => $this->title,
            'abstract'       => $this->abstract,
            'objectives'     => $this->objectives,
            'scope'          => $this->scope,

            'category'       => $this->category->value,
            'category_label' => $this->category->label(),
            'psm_part'       => $this->psm_part,

            'academic_session' => $this->academic_session,
            'batch'            => $this->batch,
            'program'          => $this->program,

            'status'           => $this->status,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),
            'approved_at'      => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'archived_at'      => $this->archived_at?->toIso8601String(),

            // Module 3 — progress figures the dashboard renders directly
            'milestone_progress' => $this->milestoneProgressPercent(),
            'current_stage'      => $this->when(
                $request->boolean('with_stage'),
                fn () => $this->currentStageLabel()
            ),

            // Module 8 consent
            'leaderboard_opt_out' => (bool) $this->leaderboard_opt_out,

            'students'    => $this->whenLoaded('students', fn () => $this->students->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->user?->name,
                'student_id' => $s->student_id,
                'program'    => $s->program,
                'batch'      => $s->batch,
                'email'      => $s->user?->email,
                'is_leader'  => (bool) $s->pivot->is_leader,
            ])),

            'supervisors' => $this->whenLoaded('students', function () {
                return $this->students
                    ->flatMap(fn ($s) => $s->activeSupervisions ?? collect())
                    ->unique('supervisor_profile_id')
                    ->map(fn ($a) => [
                        'id'       => $a->supervisorProfile?->id,
                        'name'     => $a->supervisorProfile?->label(),
                        'role'     => $a->role,
                        'psm_part' => $a->psm_part,
                    ])
                    ->values();
            }),

            'examiners' => $this->whenLoaded('examinerAssignments', fn () => $this->examinerAssignments->map(fn ($a) => [
                'id'         => $a->id,
                'user_id'    => $a->examiner_id,
                'name'       => $a->examiner?->displayName(),
                'panel_role' => $a->panel_role,
                'psm_part'   => $a->psm_part,
                'is_active'  => (bool) $a->is_active,
            ])),

            'milestones' => MilestoneResource::collection($this->whenLoaded('milestones')),

            // Aggregate result, exposed only when it is safe to show
            'final_grade' => $this->whenLoaded('finalGrades', function () {
                $grade = $this->finalGrades->sortByDesc('aggregate_percent')->first();

                if ($grade === null) {
                    return null;
                }

                return [
                    'final_mark'        => (float) $grade->final_mark,
                    'grade_letter'      => $grade->grade_letter,
                    'grade_point'       => (float) $grade->grade_point,
                    'aggregate_percent' => (float) $grade->aggregate_percent,
                    'milestone_score'   => (float) $grade->milestone_score,
                    'status'            => $grade->status,
                    'assessor_count'    => $grade->assessor_count,
                    'released_at'       => $grade->released_at?->toIso8601String(),
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
