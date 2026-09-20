<?php

namespace App\Models;

use App\Enums\AssessorType;
use App\Enums\ProjectCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Module 4 — A versioned rubric, scoped to category + PSM part + assessor type.
 */
class RubricTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'psm_part',
        'assessor_type',
        'version',
        'is_active',
        'is_published',
        'total_marks',
        'pass_mark',
        'description',
        'grading_guide',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category'     => ProjectCategory::class,
            'assessor_type'=> AssessorType::class,
            'is_active'    => 'boolean',
            'is_published' => 'boolean',
            'total_marks'  => 'decimal:2',
            'pass_mark'    => 'decimal:2',
            'version'      => 'integer',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(RubricComponent::class)->orderBy('sequence');
    }

    /** All criteria across all components, via the component table. */
    public function criteria(): HasManyThrough
    {
        return $this->hasManyThrough(
            RubricCriterion::class,
            RubricComponent::class,
            'rubric_template_id',   // FK on components
            'rubric_component_id',  // FK on criteria
            'id',
            'id'
        );
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------

    /** Flattened criteria across all components — used for weight validation. */
    public function allCriteria()
    {
        return $this->components()
            ->with('criteria')
            ->get()
            ->flatMap(fn (RubricComponent $c) => $c->criteria);
    }

    public function totalComponentWeight(): float
    {
        return (float) $this->components()->sum('weight_percent');
    }

    /** A rubric may only be used for marking once its components sum to 100. */
    public function weightsBalance(): bool
    {
        return abs($this->totalComponentWeight() - 100.0) < 0.01;
    }

    public function maxMarksFor(string $componentCode, string $criterionCode): ?float
    {
        $criterion = $this->components()
            ->where('code', $componentCode)
            ->first()
            ?->criteria()
            ->where('code', $criterionCode)
            ->first();

        return $criterion?->max_marks !== null ? (float) $criterion->max_marks : null;
    }

    /**
     * Resolve the rubric to use for a given assessment context.
     * Prefers a part-specific published rubric, then a BOTH-part one.
     */
    public static function resolveFor(
        ProjectCategory|string $category,
        string $psmPart,
        AssessorType|string $assessorType
    ): ?self {
        $cat = $category instanceof ProjectCategory ? $category->value : $category;
        $ast = $assessorType instanceof AssessorType ? $assessorType->value : $assessorType;

        return static::query()
            ->where('category', $cat)
            ->where('assessor_type', $ast)
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereIn('psm_part', [$psmPart, 'BOTH'])
            // See MilestoneTemplate::resolveFor — CASE instead of MySQL's
            // FIELD() so this resolves on PostgreSQL too.
            ->orderByRaw('CASE WHEN psm_part = ? THEN 0 ELSE 1 END', [$psmPart])
            ->orderByDesc('version')
            ->first();
    }

    /**
     * A self-contained copy of this rubric, stored on an evaluation so that
     * historical marks stay interpretable after the template changes.
     */
    public function snapshot(): array
    {
        return [
            'template_id'  => $this->id,
            'name'         => $this->name,
            'version'      => $this->version,
            'total_marks'  => (float) $this->total_marks,
            'pass_mark'    => (float) $this->pass_mark,
            'components'   => $this->components()->with('criteria')->get()->map(fn (RubricComponent $c) => [
                'code'          => $c->code,
                'title'         => $c->title,
                'weight_percent'=> (float) $c->weight_percent,
                'criteria'      => $c->criteria->map(fn (RubricCriterion $cr) => [
                    'code'          => $cr->code,
                    'title'         => $cr->title,
                    'weight_percent'=> (float) $cr->weight_percent,
                    'max_marks'     => (float) $cr->max_marks,
                ])->all(),
            ])->all(),
        ];
    }

    public function scopePublished($query)
    {
        return $query->where('is_active', true)->where('is_published', true);
    }

    public function scopeForAssessor($query, AssessorType|string $type)
    {
        return $query->where(
            'assessor_type',
            $type instanceof AssessorType ? $type->value : $type
        );
    }
}
