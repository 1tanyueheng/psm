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
        // Lampiran letter (E/I/G/H/J). Required so G and H — both PSM2 /
        // supervisor — stay distinct; without it they collide on the template
        // unique key.
        'form_code',
        'version',
        'is_active',
        'is_published',
        'total_marks',
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
     * The official Lampiran form that applies to a PSM part and assessor role.
     *
     * PSM1 -> supervisor E, examiner I. PSM2 -> supervisor G, examiner J.
     * Lampiran H (PSM2 progress report) is deliberately absent: it is a separate
     * submission taken twice per student, so it is requested explicitly via
     * EvaluationService::createProgressReportForm() rather than resolved from
     * context. Without this mapping a PSM2 supervisor evaluation matched both G
     * and H and `resolveFor()` returned whichever row came first.
     *
     * @var array<string, array<string, string>>
     */
    private const CONTEXT_FORMS = [
        'PSM1' => ['supervisor' => 'E', 'examiner' => 'I'],
        'PSM2' => ['supervisor' => 'G', 'examiner' => 'J'],
    ];

    /** Lampiran letter for a PSM part + assessor role, or null if not mapped. */
    public static function formCodeFor(string $psmPart, AssessorType|string $assessorType): ?string
    {
        $type = $assessorType instanceof AssessorType ? $assessorType->value : $assessorType;

        return self::CONTEXT_FORMS[$psmPart][$type] ?? null;
    }

    /**
     * Resolve the rubric to use for a given assessment context.
     *
     * Four of the five official forms print a different set of items depending
     * on the project category — Lampiran E carries C(i) Prototype *or* C(ii)
     * Research Framework, I carries B(i) *or* B(ii), and G and J likewise — so
     * E/I/G/J are seeded once per category and the matching row wins. Lampiran
     * H has no variant and stays category-agnostic (`category IS NULL`).
     *
     * The category match is therefore ordered *before* the shared NULL row. That
     * is the opposite of the old preference, which assumed every form served
     * every category and so put the shared row first; leaving it that way would
     * hand a research project the Development items.
     *
     * Pass $formCode to pin an official form. Callers should always pass it:
     * without it the part-specific / BOTH-part fallback below applies, which is
     * how a non-official rubric would be reached at all.
     */
    public static function resolveFor(
        ProjectCategory|string $category,
        string $psmPart,
        AssessorType|string $assessorType,
        ?string $formCode = null
    ): ?self {
        $cat = $category instanceof ProjectCategory ? $category->value : $category;
        $ast = $assessorType instanceof AssessorType ? $assessorType->value : $assessorType;

        $query = static::query()
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', $cat))
            ->where('assessor_type', $ast)
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereIn('psm_part', [$psmPart, 'BOTH']);

        if ($formCode !== null) {
            $query->where('form_code', $formCode);
        }

        return $query
            // Category-specific row first, then the shared one. CASE rather than
            // MySQL's FIELD() so this resolves on PostgreSQL too.
            ->orderByRaw('CASE WHEN category IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN category = ? THEN 0 ELSE 1 END', [$cat])
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
            'components'   => $this->components()->with('criteria')->get()->map(fn (RubricComponent $c) => [
                'code'          => $c->code,
                // Carried into the snapshot so a form created now can still be
                // matched to its chapters after the template is reworked.
                'milestone_code'=> $c->milestone_code,
                'title'         => $c->title,
                'description'   => $c->description,
                'weight_percent'=> (float) $c->weight_percent,
                // The stakeholder's "explain a mark below 50%" rule. Frozen into
                // the snapshot because Evaluation::validationErrors() reads it
                // from here, so omitting it silently disables the requirement.
                'requires_comment_below'     => (bool) $c->requires_comment_below,
                'comment_threshold_percent'  => (float) $c->comment_threshold_percent,
                // What an excellent answer looks like, per criterion. Frozen
                // with the rest of the rubric so an assessor marking an old
                // form is still shown the guidance that applied at the time.
                'criteria'      => $c->criteria->map(fn (RubricCriterion $cr) => [
                    'code'          => $cr->code,
                    'title'         => $cr->title,
                    'description'   => $cr->description,
                    'guidance'      => $cr->guidance,
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

    /**
     * Only the official marking forms: Lampiran E, G, H, I, J.
     *
     * A rubric with no `form_code` corresponds to no form the faculty issues, so
     * it must never reach an assessor. This scope is what makes that a property
     * of the interface rather than a convention — a future seeder cannot quietly
     * reintroduce an invented rubric without it being filtered out here. The
     * legacy chapter rubrics were exactly that failure.
     */
    public function scopeOfficialForms($query)
    {
        return $query->whereNotNull('form_code');
    }

    public function scopeForAssessor($query, AssessorType|string $type)
    {
        return $query->where(
            'assessor_type',
            $type instanceof AssessorType ? $type->value : $type
        );
    }

    /**
     * Templates that apply to a category: the category-specific ones plus any
     * shared (`category IS NULL`) form, which is Lampiran H — it carries the
     * same three items whether the project is a system build or a study.
     */
    public function scopeForCategory($query, ProjectCategory|string $category)
    {
        $cat = $category instanceof ProjectCategory ? $category->value : $category;

        return $query->where(fn ($q) => $q->whereNull('category')->orWhere('category', $cat));
    }
}
