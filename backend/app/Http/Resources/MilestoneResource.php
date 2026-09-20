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
