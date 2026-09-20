<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 4 — A weighted component of a rubric (e.g. "Final Report", 40%).
 */
class RubricComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'rubric_template_id',
        'code',
        'title',
        'description',
        'weight_percent',
        'sequence',
        'requires_comment_below',
        'comment_threshold_percent',
    ];

    protected function casts(): array
    {
        return [
            'weight_percent'            => 'decimal:2',
            'comment_threshold_percent' => 'decimal:2',
            'requires_comment_below'    => 'boolean',
            'sequence'                  => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RubricTemplate::class, 'rubric_template_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('sequence');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    /** Criteria weights must sum to 100 inside every component. */
    public function criteriaWeightsBalance(): bool
    {
        return abs((float) $this->criteria()->sum('weight_percent') - 100.0) < 0.01;
    }

    /**
     * Marks this component is worth in absolute terms, derived from the
     * template total and this component's share.
     */
    public function absoluteMaxMarks(): float
    {
        $total = (float) ($this->template?->total_marks ?? 100.0);

        return round($total * ((float) $this->weight_percent / 100), 2);
    }
}
