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

            /**
             * The 0-5 score the marker chose, and the five it was out of.
             *
             * `marks_awarded` is that score already weighted (`(item/5) x
             * max_marks`), which is what the totals are built from. The UI needs
             * both: the input shows `item_score`, the running total shows
             * `marks_awarded`. Null on rows entered before the 0-5 screen, where
             * the marker typed the weighted mark directly — the UI then shows
             * the mark alone rather than inventing an item score.
             */
            'item_score'     => $this->itemScore(),
            'item_score_max' => \App\Services\EvaluationService::MAX_ITEM_SCORE,

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
