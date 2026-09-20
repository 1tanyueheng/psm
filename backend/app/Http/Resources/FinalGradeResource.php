<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 / 5 — Final grade payload.
 *
 * `computation_breakdown` is included for coordinators and withheld from
 * students, who see their mark and band but not the moderation arithmetic of
 * other assessors.
 *
 * @mixin \App\Models\FinalGrade
 */
class FinalGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $seesBreakdown = $viewer !== null
            && ($viewer->hasRole('admin', 'coordinator') || $viewer->canViewCohortAnalytics());

        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'psm_part'   => $this->psm_part,

            'student_profile_id' => $this->student_profile_id,
            'student' => $this->whenLoaded('studentProfile', fn () => $this->studentProfile ? [
                'id'         => $this->studentProfile->id,
                'name'       => $this->studentProfile->user?->name,
                'student_id' => $this->studentProfile->student_id,
                'program'    => $this->studentProfile->program,
            ] : null),

            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id'    => $this->project->id,
                'code'  => $this->project->code,
                'title' => $this->project->title,
                'batch' => $this->project->batch,
            ] : null),

            // -----------------------------------------------------------------
            // The mark
            // -----------------------------------------------------------------
            'final_mark'        => $this->final_mark !== null ? (float) $this->final_mark : null,
            'aggregate_percent' => $this->aggregate_percent !== null ? (float) $this->aggregate_percent : null,
            'milestone_score'   => $this->milestone_score !== null ? (float) $this->milestone_score : null,

            'grade_letter' => $this->grade_letter,
            'grade_point'  => $this->grade_point !== null ? (float) $this->grade_point : null,
            'grade_label'  => $this->gradeLabel(),
            'is_pass'      => $this->is_pass !== null ? (bool) $this->is_pass : null,

            // -----------------------------------------------------------------
            // Per-assessor subtotals. Shown to staff only: a student seeing
            // "supervisor 72, examiner 58" invites disputes the system is not
            // designed to adjudicate.
            // -----------------------------------------------------------------
            'supervisor_score'  => $this->when($seesBreakdown, fn () => $this->supervisor_score !== null ? (float) $this->supervisor_score : null),
            'examiner_score'    => $this->when($seesBreakdown, fn () => $this->examiner_score !== null ? (float) $this->examiner_score : null),
            'coordinator_score' => $this->when($seesBreakdown, fn () => $this->coordinator_score !== null ? (float) $this->coordinator_score : null),

            'assessor_count' => $this->assessor_count,

            'status'       => $this->status,
            'is_released'  => $this->isReleased(),
            'is_publishable'=> (bool) $this->is_publishable,

            'computation_breakdown' => $this->when($seesBreakdown, fn () => $this->computation_breakdown),

            'computed_at' => $this->computed_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),

            'released_by' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy ? [
                'id'   => $this->releasedBy->id,
                'name' => $this->releasedBy->name,
            ] : null),
        ];
    }
}
