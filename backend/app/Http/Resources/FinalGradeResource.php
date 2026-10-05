<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 / 5 — Final mark payload.
 *
 * `computation_breakdown` is included for coordinators and withheld from
 * students, who see their mark but not the arithmetic of other assessors.
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
                // The grade list is sorted and filtered by category, so it has
                // to travel with the grade rather than cost another request.
                'category'       => $this->project->category?->value,
                'category_label' => $this->project->category?->label(),
            ] : null),

            // -----------------------------------------------------------------
            // The published mark
            // -----------------------------------------------------------------
            /**
             * Withheld from students.
             *
             * `final_mark` / `aggregate_percent` are the weighted form subtotals
             * **rescaled onto 0-100**, because the system holds only part of the
             * official assessment (PSM 1: 65 of it, PSM 2: 95; the remainder is
             * marked outside the system). That rescaling makes the number a fair
             * comparator between students, but it is not a mark anybody awarded
             * — so sending it to a student put an 80.8 next to a breakdown that
             * read "40.4 / 95" and made the page contradict itself.
             *
             * A student reads their Lampiran totals from the mark-breakdown
             * endpoint instead, which returns the awarded marks and the part's
             * own denominator. Staff still see this, because ranking a cohort is
             * precisely what the rescaled figure is for.
             */
            'final_mark'        => $this->when($seesBreakdown, fn () => $this->final_mark !== null ? (float) $this->final_mark : null),
            'aggregate_percent' => $this->when($seesBreakdown, fn () => $this->aggregate_percent !== null ? (float) $this->aggregate_percent : null),
            'milestone_score'   => $this->when($seesBreakdown, fn () => $this->milestone_score !== null ? (float) $this->milestone_score : null),

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
