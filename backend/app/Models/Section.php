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
 * A section is a *teaching* group and is independent of a student's PSM
 * project: the same student has one section and, separately, one project with a
 * supervisor. The two are different relationships and the system keeps them
 * apart.
 *
 * **One row per section per term, and the row is the appointment.** `S1` is not
 * a thing the faculty owns for ever — it is what this term's first class is
 * called, and the next term has its own `S1` with its own students and its own
 * teacher. So the row carries the term (`academic_semester_id`) and the teacher
 * (`lecturer_id`), `code` is unique only *within* a term (`2026_10_19_000001`),
 * and a person may teach `S1` this term and `S2` the next. That is also why
 * there is no `section_lecturers` pivot or history table: the appointment *is*
 * this row, and a term's appointments are its rows.
 *
 * A corollary worth stating: existing rows are never re-pointed at a new term.
 * Provisioning a term means *creating* its sections — by hand or by copying the
 * term before it — because a student's placement and a keyed mark both point at
 * a particular term's row, and moving that row would silently move their
 * grades.
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

    /**
     * The lecturer appointed to this section — for this term only.
     *
     * Nullable on purpose: a section with nobody appointed is a real state (the
     * students are placed before the faculty decides), and while it is null
     * *nobody* may key that section's marks. See `User::lecturerSection()` for
     * the other side of the relationship, and note that the appointment usually
     * belongs to a supervisor who teaches as well as supervises.
     */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    /** The term this section belongs to. */
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
