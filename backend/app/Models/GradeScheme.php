<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 — Per-project definition of how assessor marks combine.
 *
 * Stored per project so that changing the faculty default next semester does
 * not retroactively alter an already-released grade.
 */
class GradeScheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'weights',
        'aggregation',
        'trim_extremes',
        'is_locked',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weights'       => 'array',
            'trim_extremes' => 'boolean',
            'is_locked'     => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Weight for one official form (a Lampiran code), or 0 if it carries none.
     *
     * Weights are keyed by form rather than by assessor role because Lampiran G
     * and Lampiran H are both supervisor forms with different shares (50 and 5)
     * — grouping by role would merge them into one 55% bucket.
     */
    public function weightForForm(string $formCode): float
    {
        foreach ($this->weights ?? [] as $entry) {
            if (($entry['form_code'] ?? null) === $formCode) {
                return (float) ($entry['weight'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * The form codes this scheme weights, in configured order.
     *
     * @return array<int, string>
     */
    public function weightedForms(): array
    {
        return collect($this->weights ?? [])
            ->pluck('form_code')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Does the scheme total the weight expected for this project's PSM part?
     *
     * Not 100, and that is intentional: the system holds only part of the
     * official weighting (65 for PSM 1, 95 for PSM 2) because the remainder is
     * marked outside it. Comparing against 100 would report every scheme as
     * broken.
     */
    public function weightsBalance(): bool
    {
        $sum = collect($this->weights ?? [])->sum(fn ($w) => (float) ($w['weight'] ?? 0));

        return abs($sum - static::expectedWeightTotal($this->project?->psm_part)) < 0.01;
    }

    /** The weight this system is responsible for, for a given PSM part. */
    public static function expectedWeightTotal(?string $psmPart): float
    {
        return (float) array_sum(static::configuredWeights($psmPart));
    }

    /**
     * The configured form weights for a PSM part, from `psm.assessment_weights`.
     *
     * @return array<string, float>  form code => weight
     */
    public static function configuredWeights(?string $psmPart): array
    {
        $configured = (array) config('psm.assessment_weights', []);

        // 'BOTH' has no weighting of its own; fall back to PSM 2, which is where
        // a combined project is finally assessed.
        return (array) ($configured[$psmPart] ?? $configured['PSM2'] ?? []);
    }

    /** Default scheme weights for a project, as the scheme's JSON shape. */
    public static function defaultsFor(?string $psmPart): array
    {
        return collect(static::configuredWeights($psmPart))
            ->map(fn ($weight, $formCode) => [
                'form_code' => (string) $formCode,
                'weight'    => (float) $weight,
            ])
            ->values()
            ->all();
    }

    /**
     * Create the scheme for a project if one does not yet exist, so the
     * aggregate is always computable.
     */
    public static function ensureFor(Project $project): self
    {
        return static::firstOrCreate(
            ['project_id' => $project->id],
            [
                'weights'    => self::defaultsFor($project->psm_part),
                'aggregation'=> 'mean',
                'created_by' => $project->created_by,
            ]
        );
    }
}
