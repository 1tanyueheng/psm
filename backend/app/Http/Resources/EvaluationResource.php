<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 — One assessor's evaluation form.
 *
 * The `rubric_snapshot` is included so the marking UI renders the exact rubric
 * that was frozen when the form was created, not whatever the template says
 * today.
 *
 * @mixin \App\Models\Evaluation
 */
class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Only the owning assessor, or a moderator, sees in-progress detail
        $includeScores = $request->boolean('with_scores', true);

        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'psm_part'   => $this->psm_part,

            'assessor_id'   => $this->assessor_id,
            'assessor_type' => $this->assessor_type->value,
            'assessor_type_label' => $this->assessor_type->label(),

            'assessor' => $this->whenLoaded('assessor', fn () => $this->assessor ? [
                'id'   => $this->assessor->id,
                'name' => $this->assessor->displayName(),
                'role' => $this->assessor->role->value,
            ] : null),

            'rubric_template_id' => $this->rubric_template_id,
            'rubric'             => $this->whenLoaded('rubricTemplate', fn () => $this->rubricTemplate ? [
                'id'      => $this->rubricTemplate->id,
                'name'    => $this->rubricTemplate->name,
                'version' => $this->rubricTemplate->version,
                'total_marks' => (float) $this->rubricTemplate->total_marks,
            ] : null),

            // The frozen rubric — what the form must be rendered from
            'rubric_snapshot' => $this->rubric_snapshot,

            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone'  => $this->status->tone(),
            'is_editable'  => $this->status->isEditable(),
            'is_locked'    => $this->status->isLocked(),

            // -----------------------------------------------------------------
            // Marks
            // -----------------------------------------------------------------
            'raw_score'      => $this->raw_score !== null ? (float) $this->raw_score : null,
            'max_score'      => (float) $this->max_score,
            'score_percent'  => $this->score_percent !== null ? (float) $this->score_percent : null,

            // The moderated figure that feeds the aggregate
            'final_score'     => $this->final_score !== null ? (float) $this->final_score : null,
            'effective_mark'  => $this->effectiveMark(),
            'effective_percent' => $this->effectivePercent(),
            'has_been_moderated' => $this->hasBeenModerated(),

            'component_breakdown' => $this->when(
                $includeScores && $this->relationLoaded('scores'),
                fn () => $this->componentBreakdown()
            ),

            'scores' => $this->when(
                $includeScores,
                fn () => EvaluationScoreResource::collection($this->whenLoaded('scores'))
            ),

            // -----------------------------------------------------------------
            // Narrative feedback
            // -----------------------------------------------------------------
            'comment'      => $this->comment,
            'strengths'    => $this->strengths,
            'improvements' => $this->improvements,
            'coi_declaration' => $this->coi_declaration,

            // -----------------------------------------------------------------
            // Moderation record
            // -----------------------------------------------------------------
            'moderation_delta'  => $this->moderation_delta !== null ? (float) $this->moderation_delta : null,
            'moderation_reason' => $this->moderation_reason,
            'moderated_at'      => $this->moderated_at?->toIso8601String(),
            'moderated_by'      => $this->whenLoaded('moderatedBy', fn () => $this->moderatedBy ? [
                'id'   => $this->moderatedBy->id,
                'name' => $this->moderatedBy->name,
            ] : null),

            'is_late_assessment' => (bool) $this->is_late_assessment,
            'submitted_at'       => $this->submitted_at?->toIso8601String(),
            'released_at'        => $this->released_at?->toIso8601String(),

            // Validation problems, so the UI can show why submit is blocked
            'validation_errors' => $this->when(
                $this->status->isEditable() && $this->relationLoaded('scores'),
                fn () => $this->validationErrors()
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
