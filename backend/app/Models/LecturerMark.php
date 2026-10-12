<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lecturer's coursework marks for one student in one term.
 *
 * Four fixed components — Quiz 5, Assignment-C 12, Assignment-P 9, Assignment-A
 * 9 — summing to the `CW` weight in `psm.assessment_weights`. They reach the
 * PSM 1 aggregate as a single weighted **component**, the same way a Lampiran
 * does, but they are not an evaluation: there is no rubric, no assessor type and
 * no per-criterion breakdown, because the lecturer marks a mark sheet rather
 * than a form.
 *
 * The raw marks are stored in their own units and converted to a percentage in
 * exactly one place (`percent()`), so the student's page, the lecturer's entry
 * form and the aggregate cannot each do the arithmetic slightly differently.
 */
class LecturerMark extends Model
{
    use HasFactory;

    /**
     * The components and their maxima, read from `config/psm.php`.
     *
     * Declared once so the entry form, the validation rules, the student's
     * breakdown and the aggregate all take their names and maxima from the same
     * place. The maxima are fixed by faculty decision and sum to the `CW`
     * weight; `PsmConfigTest`-style assertions are unnecessary because the sum
     * is checked by `weightsBalance()` against the same config.
     *
     * @return array<string, array{label: string, max: float}>
     */
    public static function components(): array
    {
        return (array) config('psm.coursework.components', []);
    }

    /** The form code this component is weighted under in the grade scheme. */
    public static function formCode(): string
    {
        return (string) config('psm.coursework.form_code', 'CW');
    }

    /** The maximum total, i.e. what the four components sum to. */
    public static function maxTotal(): float
    {
        return (float) collect(static::components())->sum('max');
    }

    protected $fillable = [
        'student_profile_id',
        'section_id',
        'academic_semester_id',
        'quiz',
        'assignment_c',
        'assignment_p',
        'assignment_a',
        'entered_by',
        'entered_at',
    ];

    protected function casts(): array
    {
        return [
            'quiz'         => 'float',
            'assignment_c' => 'float',
            'assignment_p' => 'float',
            'assignment_a' => 'float',
            'entered_at'   => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class);
    }

    /** Who typed the marks — not always the section's lecturer. */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    // -----------------------------------------------------------------
    // Reading the marks
    // -----------------------------------------------------------------

    /**
     * Is every component keyed?
     *
     * A partly-keyed set is *not* a mark. Four components become one weighted
     * component in the aggregate, so a row missing the quiz would otherwise
     * contribute a total that looks like a student scored nothing on it —
     * failing them for a mark nobody has entered yet.
     */
    public function isComplete(): bool
    {
        foreach (array_keys(static::components()) as $key) {
            if ($this->{$key} === null) {
                return false;
            }
        }

        return true;
    }

    /** The raw total, in component units: out of `maxTotal()`. */
    public function total(): ?float
    {
        if (! $this->isComplete()) {
            return null;
        }

        $sum = 0.0;

        foreach (array_keys(static::components()) as $key) {
            $sum += (float) $this->{$key};
        }

        return round($sum, 2);
    }

    /**
     * The coursework component as a percentage of its maximum.
     *
     * This is the single conversion the whole feature depends on. The PSM
     * aggregate works in percentages per form — `computeFinalGrade()` weights
     * each form's subtotal by its share — so coursework must arrive in the same
     * shape as a Lampiran subtotal or it would be weighted on a different scale
     * from everything else.
     *
     * Null when incomplete, for the reason on `isComplete()`.
     */
    public function percent(): ?float
    {
        $total = $this->total();
        $max = static::maxTotal();

        if ($total === null || $max <= 0.0) {
            return null;
        }

        return round(($total / $max) * 100, 2);
    }

    /**
     * The per-component breakdown, in the shape the student's page renders.
     *
     * Mirrors the Lampiran breakdown (`forms` → component rows) so the
     * coursework appears in the same list as E and I rather than as a special
     * case the UI has to know about.
     *
     * @return array<int, array{key: string, label: string, marks: float|null, max: float}>
     */
    public function breakdown(): array
    {
        $rows = [];

        foreach (static::components() as $key => $spec) {
            $rows[] = [
                'key'   => $key,
                'label' => $spec['label'],
                'marks' => $this->{$key} !== null ? (float) $this->{$key} : null,
                'max'   => (float) $spec['max'],
            ];
        }

        return $rows;
    }

    /** The marks row for a student in a term, if one exists. */
    public static function forStudent(int $studentProfileId, ?int $semesterId): ?self
    {
        return static::query()
            ->where('student_profile_id', $studentProfileId)
            ->when($semesterId !== null,
                fn ($q) => $q->where('academic_semester_id', $semesterId),
                fn ($q) => $q->whereNull('academic_semester_id'))
            ->first();
    }
}
