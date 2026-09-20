<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 4 — Rubric template with its component/criterion tree.
 *
 * Includes the balance check so the rubric editor can warn immediately when
 * weights do not sum to 100, rather than failing later at marking time.
 *
 * @mixin \App\Models\RubricTemplate
 */
class RubricTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,

            'category'       => $this->category->value,
            'category_label' => $this->category->label(),
            'psm_part'       => $this->psm_part,

            'assessor_type'       => $this->assessor_type->value,
            'assessor_type_label' => $this->assessor_type->label(),

            'version'      => $this->version,
            'is_active'    => (bool) $this->is_active,
            'is_published' => (bool) $this->is_published,

            'total_marks' => (float) $this->total_marks,
            'pass_mark'   => (float) $this->pass_mark,

            'description'   => $this->description,
            'grading_guide' => $this->grading_guide,

            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => [
                'id'             => $c->id,
                'code'           => $c->code,
                'title'          => $c->title,
                'description'    => $c->description,
                'weight_percent' => (float) $c->weight_percent,
                'absolute_max_marks' => $c->absoluteMaxMarks(),
                'sequence'       => $c->sequence,
                'requires_comment_below' => (bool) $c->requires_comment_below,
                'comment_threshold_percent' => (float) $c->comment_threshold_percent,
                'criteria'       => $c->relationLoaded('criteria')
                    ? $c->criteria->map(fn ($cr) => [
                        'id'             => $cr->id,
                        'code'           => $cr->code,
                        'title'          => $cr->title,
                        'description'    => $cr->description,
                        'guidance'       => $cr->guidance,
                        'weight_percent' => (float) $cr->weight_percent,
                        'max_marks'      => (float) $cr->max_marks,
                        'sequence'       => $cr->sequence,
                        'is_required'    => (bool) $cr->is_required,
                    ])->all()
                    : [],
            ])),

            // Validation state for the editor
            'component_weight_total' => $this->totalComponentWeight(),
            'weights_balance'        => $this->weightsBalance(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
