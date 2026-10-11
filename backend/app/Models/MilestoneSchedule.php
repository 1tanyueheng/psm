<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The faculty's milestone calendar for one batch of one term.
 *
 * A row here says "Chapter 1 is due 15 November" for *every* student in that
 * batch — not for one project. That is the point: the faculty publishes a
 * calendar, and dates were previously derived per project from each student's
 * own registration date, so two students who registered a fortnight apart had
 * deadlines a fortnight apart and nothing could state the real date.
 *
 * `milestones.due_at` is still what the system enforces; this table is what a
 * coordinator edits and what that column is derived from. Keeping the calendar
 * separate from the milestone rows means the term's dates survive a re-seed of
 * any one project and can be shown even for a batch with no projects yet.
 */
class MilestoneSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_semester_id',
        'psm_part',
        'code',
        'opens_at',
        'due_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'date',
            'due_at'   => 'date',
        ];
    }

    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForSemesterPart(Builder $query, int|AcademicSemester|null $semester, ?string $psmPart): Builder
    {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $query
            ->when($id !== null, fn ($q) => $q->where('academic_semester_id', $id))
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart));
    }
}
