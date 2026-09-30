<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 / 6 — Milestone payload.
 *
 * Includes the deadline arithmetic (days remaining, overdue flag, whether a
 * submission is currently accepted) so the SPA never reimplements date logic —
 * the rules for late windows and extensions live on the model.
 *
 * @mixin \App\Models\Milestone
 */
class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'code'       => $this->code,
            'title'      => $this->title,
            'description'=> $this->description,

            'sequence'       => $this->sequence,
            'weight_percent' => (float) $this->weight_percent,

            /**
             * How far this chapter has got, and what it is worth.
             *
             * `completion_percent` is the chapter's own progress; the
             * contribution is how many points of the project-wide percentage
             * this chapter is currently adding. Resolved here rather than in
             * the SPA so the chapter breakdown a supervisor reads always sums
             * to the project's headline progress figure.
             */
            'completion_percent'    => $this->completionPercent(),
            'progress_contribution' => $this->progressContribution(),

            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone'  => $this->status->tone(),

            // -----------------------------------------------------------------
            // Deadline arithmetic, resolved server-side
            // -----------------------------------------------------------------
            'opens_at'              => $this->opens_at?->toDateString(),
            'due_at'                => $this->due_at?->toDateString(),
            'effective_due_at'      => $this->effectiveDueAt()?->toIso8601String(),
            'extended_until'        => $this->extended_until?->toIso8601String(),
            'days_until_due'        => $this->daysUntilDue(),
            'is_overdue'            => $this->isOverdue(),
            'is_within_late_window' => $this->isWithinLateWindow(),
            'accepts_submission'    => $this->acceptsSubmission(),

            'allow_late_submission'        => (bool) $this->allow_late_submission,
            'late_window_days'             => $this->late_window_days,
            'requires_supervisor_approval' => (bool) $this->requires_supervisor_approval,

            // -----------------------------------------------------------------
            // Review outcome
            // -----------------------------------------------------------------
            'submitted_at'   => $this->submitted_at?->toIso8601String(),
            'reviewed_at'    => $this->reviewed_at?->toIso8601String(),
            'approved_at'    => $this->approved_at?->toIso8601String(),
            'review_comment' => $this->review_comment,
            'revision_count' => $this->revision_count,

            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),

            /**
             * The owning project.
             *
             * The cross-project milestone worklist renders the project on every
             * row, and that screen cannot issue a second request per row to
             * resolve it.
             */
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id'          => $this->project->id,
                'code'        => $this->project->code,
                'title'       => $this->project->title,
                'psm_part'    => $this->project->psm_part,
                'batch'       => $this->project->batch,
                'category'    => $this->project->category->value,
                'category_label' => $this->project->category->label(),

                'students' => $this->project->relationLoaded('students')
                    ? $this->project->students->map(fn ($student) => [
                        'id'         => $student->id,
                        'student_id' => $student->student_id,
                        'name'       => $student->user?->name,
                        // Needed by the UI to decide whether the signed-in
                        // student owns this milestone.
                        'user_id'    => $student->user_id,
                    ])->values()->all()
                    : null,

                /**
                 * Everyone who supervises this project, derived from its
                 * students' active supervision assignments. The detail screen
                 * needs this to show who may act on the submission.
                 */
                'supervisors' => $this->project->relationLoaded('students')
                    ? $this->project->students
                        ->flatMap(fn ($student) => $student->activeSupervisions)
                        ->map(fn ($assignment) => $assignment->supervisorProfile?->user)
                        ->filter()
                        ->unique('id')
                        ->map(fn ($user) => [
                            'id'   => $user->id,
                            'name' => $user->name,
                        ])->values()->all()
                    : null,
            ] : null),

            /**
             * What a good submission looks like, copied from the template item
             * at instantiation. Lives on the template rather than the milestone
             * row so the wording can be corrected for future projects without
             * rewriting history for ones already under way.
             */
            'deliverable_expectation' => $this->whenLoaded(
                'templateItem',
                fn () => $this->templateItem?->deliverable_expectation,
            ),

            // Deadline override audit (Module 7)
            'deadline_override_reason' => $this->deadline_override_reason,

            // -----------------------------------------------------------------
            // Files and history
            // -----------------------------------------------------------------
            'files' => SubmissionFileResource::collection($this->whenLoaded('currentFiles')),

            'file_count'   => $this->whenLoaded('currentFiles', fn () => $this->currentFiles->count()),
            'max_files'    => $this->max_files,
            'allowed_file_types' => $this->allowed_file_types,

            'events' => SubmissionEventResource::collection($this->whenLoaded('events')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
