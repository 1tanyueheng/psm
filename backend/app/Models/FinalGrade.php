<?php

namespace App\Models;

use App\Models\AcademicSemester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 / 8 — The computed, frozen final mark for one student.
 *
 * Written by EvaluationService::computeFinalGrade() and thereafter treated as
 * authoritative: the leaderboard (Module 8) ranks on this value, not on a
 * live recomputation, so published rankings cannot drift.
 *
 * There is deliberately no letter grade or grade point here. The released mark
 * is the weighted sum of the forms that count towards the system's share of
 * the assessment — that share is not 100% (the remainder is marked outside the
 * system), so mapping it onto A/B/C bands would be inventing a grade the
 * faculty never awarded. The system releases a mark.
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
            'computation_breakdown' => 'array',
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

    /** Module 5 — mark distribution report. */
    public function scopeForBatch(Builder $query, string $batch): Builder
    {
        return $query->whereHas('studentProfile', fn ($q) => $q->where('batch', $batch));
    }

    /**
     * Module 3/5 — restrict to one academic term.
     *
     * A grade reaches its term through its *project*, not through a column of its
     * own: the project is what was enrolled in the term, and a student's own
     * enrolment moves on when they progress from PSM 1 to PSM 2 while their
     * PSM 1 grade stays where it was filed. Filtering on the student's enrolment
     * instead would drop the PSM 1 results of every student who has since
     * progressed — which is exactly the population a term-level release targets.
     *
     * Null means "no filter", matching Project::scopeForSemester, so the
     * legacy-term reports keep working.
     */
    public function scopeForSemester(Builder $query, int|AcademicSemester|null $semester): Builder
    {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $id === null
            ? $query
            : $query->whereHas('project', fn ($p) => $p->where('academic_semester_id', $id));
    }

    /** One batch of one term — the pair every grade screen filters on. */
    public function scopeForSemesterPart(
        Builder $query,
        int|AcademicSemester|null $semester,
        ?string $psmPart
    ): Builder {
        $query = $this->scopeForSemester($query, $semester);

        if ($psmPart !== null) {
            $query->where('psm_part', $psmPart);
        }

        return $query;
    }

    /**
     * Module 4 — free-text search behind the mark list's search box.
     *
     * Matches the student and the project, since a coordinator looking for a
     * particular result is usually typing one of those two.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereHas('project', fn ($p) => $p->where('title', 'like', $like)
                ->orWhere('code', 'like', $like))
              ->orWhereHas('studentProfile', fn ($s) => $s->where('student_id', 'like', $like)
                ->orWhere('program', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
        });
    }
}
