<?php

namespace App\Models;

use App\Enums\AssessorType;
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
        'pass_mark',
        'is_locked',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weights'       => 'array',
            'trim_extremes' => 'boolean',
            'is_locked'     => 'boolean',
            'pass_mark'     => 'decimal:2',
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

    public function weightFor(AssessorType|string $type): float
    {
        $key = $type instanceof AssessorType ? $type->value : $type;

        foreach ($this->weights ?? [] as $entry) {
            if (($entry['assessor_type'] ?? null) === $key) {
                return (float) ($entry['weight'] ?? 0);
            }
        }

        return 0.0;
    }

    /** Total must be 100 for a usable scheme. */
    public function weightsBalance(): bool
    {
        $sum = collect($this->weights ?? [])->sum(fn ($w) => (float) ($w['weight'] ?? 0));

        return abs($sum - 100.0) < 0.01;
    }

    /** Sensible default scheme for a new project. */
    public static function defaults(): array
    {
        return [
            ['assessor_type' => AssessorType::Supervisor->value,  'weight' => 60],
            ['assessor_type' => AssessorType::Examiner->value,    'weight' => 40],
            ['assessor_type' => AssessorType::Coordinator->value, 'weight' => 0],
        ];
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
                'weights'    => self::defaults(),
                'aggregation'=> 'mean',
                'pass_mark'  => 50.00,
                'created_by' => $project->created_by,
            ]
        );
    }
}
