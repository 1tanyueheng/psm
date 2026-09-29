<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 4 — The finest grading unit: one criterion an assessor scores.
 */
class RubricCriterion extends Model
{
    use HasFactory;

    protected $fillable = [
        'rubric_component_id',
        'code',
        'title',
        'description',
        'guidance',
        'weight_percent',
        'max_marks',
        'sequence',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'weight_percent' => 'decimal:2',
            'max_marks'      => 'decimal:2',
            'is_required'    => 'boolean',
            'sequence'       => 'integer',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(RubricComponent::class, 'rubric_component_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    /** Marks as a percentage of the maximum, for banding the descriptor. */
    public function percentageOf(float $marks): float
    {
        if ((float) $this->max_marks <= 0) {
            return 0.0;
        }

        return round(($marks / (float) $this->max_marks) * 100, 2);
    }

    /**
     * Qualitative descriptor for a given percentage — shown live in the
     * assessor's form so marking is calibrated across examiners.
     */
    public static function descriptorFor(float $percent): string
    {
        return match (true) {
            $percent >= 85 => 'متميز',
            $percent >= 75 => 'ممتاز',
            $percent >= 65 => 'جيد',
            $percent >= 55 => 'مرضي',
            $percent >= 50 => 'ناجح',
            $percent >= 40 => 'ضعيف',
            default        => 'غير كافٍ',
        };
    }
}
