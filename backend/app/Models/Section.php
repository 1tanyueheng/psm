<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teaching section for the service course that runs alongside PSM 1.
 *
 * Four exist, each with one lecturer who is appointed permanently. A section is
 * a *teaching* group and is independent of a student's PSM project: the same
 * student has one section and, separately, one project with a supervisor. The
 * two are different relationships and the system keeps them apart.
 *
 * See the create migration for why there is no term history here.
 */
class Section extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'lecturer_id',
        'academic_semester_id',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /** The lecturer who owns this section. One person, permanently. */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class);
    }

    /** The students placed in this section. */
    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(LecturerMark::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Sections in a term, or every section when the term is unknown. */
    public function scopeForSemester(Builder $query, int|AcademicSemester|null $semester): Builder
    {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $query->when($id !== null, fn (Builder $q) => $q->where('academic_semester_id', $id));
    }

    /** The section a given lecturer owns, if any. */
    public function scopeForLecturer(Builder $query, int|User $lecturer): Builder
    {
        $id = $lecturer instanceof User ? $lecturer->id : $lecturer;

        return $query->where('lecturer_id', $id);
    }

    /** "S1 — Section 1" */
    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
