<?php

namespace App\Models;

use App\Enums\ProjectCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 3 — Versioned milestone template, selected by project category.
 *
 * Versioning matters academically: a change to next semester's milestone
 * structure must not rewrite the milestones of an in-flight cohort.
 */
class MilestoneTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'psm_part',
        'version',
        'is_active',
        'default_duration_days',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'category'              => ProjectCategory::class,
            'is_active'             => 'boolean',
            'version'               => 'integer',
            'default_duration_days' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MilestoneTemplateItem::class)->orderBy('sequence');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sum of item weights — must be 100 for a well-formed template. */
    public function totalWeight(): float
    {
        return (float) $this->items()->sum('weight_percent');
    }

    public function weightsBalance(): bool
    {
        return abs($this->totalWeight() - 100.0) < 0.01;
    }

    /**
     * The template a new project of this category should use.
     * Falls back from a part-specific template to a BOTH-part one.
     *
     * The ORDER BY prefers the part-specific row over the BOTH row. Written
     * as a CASE rather than MySQL's FIELD(): the whereIn above already
     * restricts psm_part to exactly these two values, so "is it the specific
     * one" is the whole question, and CASE is standard SQL. FIELD() would
     * have made template resolution MySQL-only.
     */
    public static function resolveFor(ProjectCategory|string $category, string $psmPart): ?self
    {
        $value = $category instanceof ProjectCategory ? $category->value : $category;

        return static::query()
            ->where('category', $value)
            ->where('is_active', true)
            ->whereIn('psm_part', [$psmPart, 'BOTH'])
            ->orderByRaw('CASE WHEN psm_part = ? THEN 0 ELSE 1 END', [$psmPart])
            ->orderByDesc('version')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory($query, ProjectCategory|string $category)
    {
        return $query->where(
            'category',
            $category instanceof ProjectCategory ? $category->value : $category
        );
    }
}
