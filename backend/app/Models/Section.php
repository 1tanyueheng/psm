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
        'course_code',
        'lecturer_id',
        'academic_semester_id',
    ];

    /** The attribute default, so a section created in code needs no course. */
    protected $attributes = [
        'course_code' => self::COURSE_BIC,
    ];

    /**
     * The two offerings of the service course.
     *
     * They are different courses, not two names for one: `BIC31602` serves BIP,
     * BIW, BIS and BIM together, and `BIT34002` serves BIT alone. A student is
     * placed into the course their programme belongs to, and a lecturer teaches
     * one course — so a section states its course and nobody crosses over.
     *
     * Declared once here and read everywhere (the section list, the placement
     * rules, the admin's form), because a second list of course codes is a
     * second thing that can drift.
     */
    public const COURSE_BIC = 'BIC31602';

    public const COURSE_BIT = 'BIT34002';

    /** @return array<string, array{code: string, label: string, title: string, programmes: array<int, string>}> */
    public static function courses(): array
    {
        return [
            self::COURSE_BIC => [
                'code'       => self::COURSE_BIC,
                'label'      => 'BIC31602',
                'title'      => 'Projek Sarjana Muda I',
                'programmes' => ['BIP', 'BIW', 'BIS', 'BIM'],
            ],
            self::COURSE_BIT => [
                'code'       => self::COURSE_BIT,
                'label'      => 'BIT34002',
                'title'      => 'Projek Sarjana Muda I',
                'programmes' => ['BIT'],
            ],
        ];
    }

    /** The course a programme is taught under. Unknown programmes take the BIC course. */
    public static function courseForProgramme(?string $programme): string
    {
        $programme = strtoupper(trim((string) $programme));

        foreach (static::courses() as $course) {
            if (in_array($programme, $course['programmes'], true)) {
                return $course['code'];
            }
        }

        return self::COURSE_BIC;
    }

    /** "BIC31602 — Projek Sarjana Muda I" */
    public function courseLabel(): string
    {
        $course = static::courses()[$this->course_code] ?? null;

        return $course === null
            ? (string) $this->course_code
            : "{$course['label']} — {$course['title']}";
    }

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

    /**
     * "BIT34002 · S1 — Section 1", or just the section when there is one course.
     *
     * The course is part of the name only when it has to be: with two offerings
     * of the same course title in one term, "S1" alone is ambiguous and a
     * coordinator looking at a roster needs to know which class they are in.
     */
    public function qualifiedLabel(): string
    {
        return "{$this->course_code} · {$this->label()}";
    }
}
