<?php

namespace App\Models;

use App\Enums\EnrolmentStatus;
use App\Enums\PsmPart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's attempt at one PSM part in one semester.
 *
 * This is the record the admin works with at the start of a term: "register
 * A123456 into PSM 2 for 2026/2027 Semester I". The project follows from
 * registration and attaches itself here afterwards.
 *
 * Why it exists is described in the migration. In short, the term used to live
 * only on the project, which made three things impossible or fragile: asking
 * "what is this student enrolled in" before a project existed, placing a repeat
 * in a term that definitely exists, and counting how many attempts a student has
 * had.
 *
 * `attempt_no` counts per (student, part) and is never reused. A withdrawn
 * attempt still consumes its number — the record is of what happened, and
 * renumbering after a withdrawal would silently rewrite history.
 */
class StudentEnrolment extends Model
{
    protected $table = 'student_enrolments';

    protected $fillable = [
        'student_profile_id',
        'academic_semester_id',
        'psm_part',
        'attempt_no',
        'status',
        'project_id',
        'repeats_enrolment_id',
        'enrolled_at',
        'settled_at',
        'note',
        'enrolled_by',
    ];

    protected function casts(): array
    {
        return [
            'psm_part'    => PsmPart::class,
            'status'      => EnrolmentStatus::class,
            'attempt_no'  => 'integer',
            'enrolled_at' => 'datetime',
            'settled_at'  => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The attempt this one is repeating, when it is a repeat. */
    public function repeatsEnrolment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeats_enrolment_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Attempts still in play — enrolling or active. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', ['enrolling', 'active']);
    }

    /** Attempts that are over, whichever way they went. */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', ['completed', 'failed', 'withdrawn']);
    }

    public function scopeForSemester(Builder $query, ?int $semesterId): Builder
    {
        return $semesterId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('academic_semester_id', $semesterId);
    }

    public function scopeForPart(Builder $query, ?string $part): Builder
    {
        return $part === null || $part === ''
            ? $query
            : $query->where('psm_part', $part);
    }

    // -----------------------------------------------------------------
    // Predicates
    // -----------------------------------------------------------------

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** A live attempt that already has its project attached. */
    public function hasProject(): bool
    {
        return $this->project_id !== null;
    }

    /**
     * Whether a new attempt may be created for the same student and part.
     *
     * Only a settled attempt frees the student to enrol again, and only when
     * the attempt before it actually failed or was withdrawn — a completed part
     * is done, and there is nothing to repeat.
     */
    public function allowsAnotherAttempt(): bool
    {
        return $this->status->isSettled() && $this->status !== EnrolmentStatus::Completed;
    }

    /** A human label: "PSM 2 · 2025/2026 Semester II · attempt 2". */
    public function label(): string
    {
        return sprintf(
            '%s · %s · attempt %d',
            $this->psm_part instanceof PsmPart ? $this->psm_part->label() : 'PSM',
            $this->semester?->name ?? 'unknown term',
            $this->attempt_no,
        );
    }
}
