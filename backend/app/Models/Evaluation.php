<?php

namespace App\Models;

use App\Enums\AssessorType;
use App\Enums\EvaluationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Module 4 — One assessor's completed assessment of one project.
 *
 * `rubric_snapshot` freezes the rubric at marking time. This is the single
 * most important integrity decision in the grading engine: without it, editing
 * a rubric next semester would silently reinterpret every historical mark.
 */
class Evaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'assessor_id',
        'rubric_template_id',
        'rubric_snapshot',
        'assessor_type',
        'psm_part',
        'status',
        'raw_score',
        'max_score',
        'score_percent',
        'final_score',
        'moderation_delta',
        'comment',
        'strengths',
        'improvements',
        'is_late_assessment',
        'submitted_at',
        'released_at',
        'moderated_by',
        'moderation_reason',
        'moderated_at',
        'coi_declaration',
    ];

    protected function casts(): array
    {
        return [
            'status'             => EvaluationStatus::class,
            'assessor_type'      => AssessorType::class,
            'rubric_snapshot'    => 'array',
            'raw_score'          => 'decimal:2',
            'max_score'          => 'decimal:2',
            'score_percent'      => 'decimal:2',
            'final_score'        => 'decimal:2',
            'moderation_delta'   => 'decimal:2',
            'is_late_assessment' => 'boolean',
            'submitted_at'       => 'datetime',
            'released_at'        => 'datetime',
            'moderated_at'       => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function rubricTemplate(): BelongsTo
    {
        return $this->belongsTo(RubricTemplate::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    // -----------------------------------------------------------------
    // Scoring
    // -----------------------------------------------------------------

    /**
     * Recompute raw_score / score_percent / max_score from the criterion marks.
     *
     * Sums the per-criterion `weighted_contribution` values, which already
     * carry the component and criterion weights. Falls back to a plain sum of
     * raw marks if contributions were never populated (e.g. legacy rows).
     */
    public function recalculateTotals(): self
    {
        $scores = $this->relationLoaded('scores') ? $this->scores : $this->scores()->get();

        $maxMarks = (float) ($this->rubric_snapshot['total_marks'] ?? $this->max_score ?? 100.0);

        $weighted = (float) $scores->sum('weighted_contribution');

        // If weighting was not applied, fall back to the raw sum scaled to the
        // rubric total so the figure stays comparable.
        if ($weighted <= 0.0 && $scores->sum('marks_awarded') > 0) {
            $rawSum = (float) $scores->sum('marks_awarded');
            $rawMax = (float) $scores->sum('max_marks');

            $weighted = $rawMax > 0 ? $rawSum * ($maxMarks / $rawMax) : 0.0;
        }

        $this->raw_score    = round($weighted, 2);
        $this->max_score    = $maxMarks;
        $this->score_percent = $maxMarks > 0
            ? round(($weighted / $maxMarks) * 100, 2)
            : 0.0;

        return $this;
    }

    /**
     * Roll the per-criterion marks up to a component subtotal.
     * Returns [component_code => ['obtained' => x, 'max' => y, 'percent' => z]]
     */
    public function componentBreakdown(): array
    {
        $snapshot = $this->rubric_snapshot ?? [];

        return collect($snapshot['components'] ?? [])
            ->mapWithKeys(function (array $component) {
                $obtained = (float) $this->scores
                    ->filter(fn (EvaluationScore $s) => $s->component_code === $component['code'])
                    ->sum('marks_awarded');

                $max = (float) $this->scores
                    ->filter(fn (EvaluationScore $s) => $s->component_code === $component['code'])
                    ->sum('max_marks');

                return [$component['code'] => [
                    'title'    => $component['title'] ?? $component['code'],
                    'weight'   => (float) ($component['weight_percent'] ?? 0),
                    'obtained' => round($obtained, 2),
                    'max'      => round($max, 2),
                    'percent'  => $max > 0 ? round(($obtained / $max) * 100, 2) : 0.0,
                ]];
            })
            ->all();
    }

    /**
     * The percentage that feeds the aggregate. Prefers the moderated figure
     * when a coordinator has adjusted the mark.
     */
    public function effectivePercent(): float
    {
        if ($this->final_score !== null) {
            return round(((float) $this->final_score / (float) $this->max_score) * 100, 2);
        }

        return (float) ($this->score_percent ?? 0.0);
    }

    /** The assessor's mark on the 0–100 scale used everywhere downstream. */
    public function effectiveMark(): float
    {
        return $this->final_score !== null
            ? (float) $this->final_score
            : (float) ($this->raw_score ?? 0.0);
    }

    public function hasBeenModerated(): bool
    {
        return $this->moderation_delta !== null && (float) $this->moderation_delta !== 0.0;
    }

    /**
     * Validate the entered marks before submission.
     * Returns a list of human-readable problems; empty means valid.
     */
    public function validationErrors(): array
    {
        $errors = [];
        $snapshot = $this->rubric_snapshot ?? [];

        $expected = collect($snapshot['components'] ?? [])
            ->flatMap(fn (array $c) => collect($c['criteria'] ?? [])
                ->map(fn (array $cr) => "{$c['code']}.{$cr['code']}"))
            ->all();

        $provided = $this->scores->map(fn (EvaluationScore $s) => "{$s->component_code}.{$s->criterion_code}")->all();

        foreach (array_diff($expected, $provided) as $missing) {
            $errors[] = "Missing mark for {$missing}.";
        }

        foreach ($this->scores as $score) {
            if ((float) $score->marks_awarded < 0) {
                $errors[] = "Negative mark for {$score->criterion_code}.";
            }
            if ((float) $score->marks_awarded > (float) $score->max_marks) {
                $errors[] = "Mark for {$score->criterion_code} exceeds the maximum of {$score->max_marks}.";
            }
        }

        // Comments are mandatory where the rubric says so
        foreach ($snapshot['components'] ?? [] as $component) {
            if (! ($component['requires_comment_below'] ?? false)) {
                continue;
            }

            $threshold = (float) ($component['comment_threshold_percent'] ?? 0);
            $obtained  = (float) $this->scores
                ->filter(fn (EvaluationScore $s) => $s->component_code === $component['code'])
                ->sum('marks_awarded');
            $max = (float) $this->scores
                ->filter(fn (EvaluationScore $s) => $s->component_code === $component['code'])
                ->sum('max_marks');

            $percent = $max > 0 ? ($obtained / $max) * 100 : 0;

            $hasComment = $this->scores
                ->filter(fn (EvaluationScore $s) => $s->component_code === $component['code'])
                ->contains(fn (EvaluationScore $s) => filled($s->comment));

            if ($percent < $threshold && ! $hasComment) {
                $errors[] = "A comment is required for '{$component['title']}' because the mark is below {$threshold}%.";
            }
        }

        return $errors;
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EvaluationStatus::Submitted->value,
            EvaluationStatus::Moderated->value,
            EvaluationStatus::Released->value,
        ]);
    }

    /** Forms the given user may still edit. */
    public function scopeEditableBy(Builder $query, User $user): Builder
    {
        return $query->where('assessor_id', $user->id)
                     ->where('status', EvaluationStatus::Draft->value);
    }

    /** Module 5 — outstanding assessment workload for an assessor. */
    public function scopeOutstandingFor(Builder $query, User $user): Builder
    {
        return $query->where('assessor_id', $user->id)
                     ->whereIn('status', [
                         EvaluationStatus::Draft->value,
                         EvaluationStatus::Submitted->value,
                     ]);
    }
}
