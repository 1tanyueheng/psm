<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 — One criterion mark inside an evaluation.
 */
class EvaluationScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'evaluation_id',
        'rubric_component_id',
        'rubric_criterion_id',
        'component_code',
        'criterion_code',
        'criterion_title',
        'max_marks',
        'marks_awarded',
        'weighted_contribution',
        'comment',
        'is_flagged',
    ];

    protected function casts(): array
    {
        return [
            'max_marks'             => 'decimal:2',
            'marks_awarded'         => 'decimal:2',
            'weighted_contribution' => 'decimal:4',
            'is_flagged'            => 'boolean',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(RubricComponent::class, 'rubric_component_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    public function percent(): float
    {
        if ((float) $this->max_marks <= 0) {
            return 0.0;
        }

        return round(((float) $this->marks_awarded / (float) $this->max_marks) * 100, 2);
    }

    public function descriptor(): string
    {
        return RubricCriterion::descriptorFor($this->percent());
    }

    /** Set the mark and recompute the weighted contribution in one step. */
    public function award(float $marks, float $criterionWeightPercent): self
    {
        $this->marks_awarded = $marks;

        $component = $this->component;
        $componentWeight = $component ? (float) $component->weight_percent : 0.0;

        // Contribution to the overall rubric total
        $this->weighted_contribution = $marks
            * ($componentWeight / 100)
            * ($criterionWeightPercent / 100);

        return $this;
    }
}
