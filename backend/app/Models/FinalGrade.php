<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 / 8 — The computed, frozen final grade for one student.
 *
 * Written by EvaluationService::computeFinalGrade() and thereafter treated as
 * authoritative: the leaderboard (Module 8) ranks on this value, not on a
 * live recomputation, so published rankings cannot drift.
 */
class FinalGrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'student_profile_id',
        'psm_part',
        'supervisor_score',
        'examiner_score',
        'coordinator_score',
        'aggregate_percent',
        'milestone_score',
        'final_mark',
        'grade_letter',
        'grade_point',
        'is_pass',
        'assessor_count',
        'computation_breakdown',
        'status',
        'computed_at',
        'released_at',
        'released_by',
        'is_publishable',
    ];

    protected function casts(): array
    {
        return [
            'supervisor_score'  => 'decimal:2',
            'examiner_score'    => 'decimal:2',
            'coordinator_score' => 'decimal:2',
            'aggregate_percent' => 'decimal:2',
            'milestone_score'   => 'decimal:2',
            'final_mark'        => 'decimal:2',
            'grade_point'       => 'decimal:2',
            'computation_breakdown' => 'array',
            'is_pass'           => 'boolean',
            'is_publishable'    => 'boolean',
            'assessor_count'    => 'integer',
            'computed_at'       => 'datetime',
            'released_at'       => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }

    /** Module 4 — apply the configured grade bands to a percentage. */
    public static function bandFor(float $percent): array
    {
        foreach (config('psm.grade_bands') as $band) {
            if ($percent >= $band['min']) {
                return $band;
            }
        }

        return ['min' => 0, 'grade' => 'F', 'point' => 0.0, 'label' => 'Fail'];
    }

    public function gradeLabel(): string
    {
        foreach (config('psm.grade_bands') as $band) {
            if ($band['grade'] === $this->grade_letter) {
                return $band['label'];
            }
        }

        return '—';
    }

    /**
     * Module 8 — may this result be displayed publicly?
     * Requires release, consent, and enough assessors behind the number.
     */
    public function qualifiesForPublication(int $minAssessors = 2): bool
    {
        $project = $this->project;

        return $this->isReleased()
            && $this->is_publishable
            && $project !== null
            && ! $project->leaderboard_opt_out
            && $this->assessor_count >= $minAssessors
            && $this->final_mark !== null;
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', 'released');
    }

    public function scopePublishable(Builder $query): Builder
    {
        return $query->where('status', 'released')->where('is_publishable', true);
    }

    /** Module 5 — grade distribution report. */
    public function scopeForBatch(Builder $query, string $batch): Builder
    {
        return $query->whereHas('studentProfile', fn ($q) => $q->where('batch', $batch));
    }
}
