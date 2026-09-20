<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 — One criterion mark on an evaluation form.
 *
 * Includes the derived percentage and qualitative descriptor so the marking
 * UI can show live feedback as the assessor moves a slider.
 *
 * @mixin \App\Models\EvaluationScore
 */
class EvaluationScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'evaluation_id' => $this->evaluation_id,

            'rubric_component_id' => $this->rubric_component_id,
            'rubric_criterion_id' => $this->rubric_criterion_id,

            'component_code' => $this->component_code,
            'criterion_code' => $this->criterion_code,
            'criterion_title'=> $this->criterion_title,

            'max_marks'     => (float) $this->max_marks,
            'marks_awarded' => (float) $this->marks_awarded,

            // Derived, so the UI does not compute percentages itself
            'percent'    => $this->percent(),
            'descriptor' => $this->descriptor(),

            'weighted_contribution' => $this->weighted_contribution !== null
                ? (float) $this->weighted_contribution
                : null,

            'comment'    => $this->comment,
            'is_flagged' => (bool) $this->is_flagged,

            // Guidance text from the criterion, if it was loaded
            'guidance' => $this->whenLoaded('criterion', fn () => $this->criterion?->guidance),
        ];
    }
}
