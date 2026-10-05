<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 3 — one academic term, which owns one or both PSM batches.
 *
 * A term is not a cohort. "2025/2026 Semester I" contains the PSM 1 students
 * and, concurrently, the PSM 2 students who started a term earlier — the same
 * supervisors, the same coordinators, the same examiner pool. Everything that
 * used to be scoped by `academic_session` is now scoped by this record, because
 * a session holds two terms and a term holds two batches.
 *
 * The three flags are the term's administrative state, and each gates one part
 * of the process:
 *
 *   is_active            — the term is running. At most one per faculty is
 *                          active; see AcademicSemester::current().
 *   is_registration_open — students may submit Lampiran A. Per-term because
 *                          the faculty opens PSM 2 registration before PSM 1
 *                          re-registration in the same term.
 *   is_marks_released   — results are visible to students. Per-term because
 *                          releasing one batch must not publish the other.
 */
class AcademicSemester extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'academic_session',
        'semester_number',
        'starts_at',
        'ends_at',
        'is_active',
        'is_registration_open',
        'is_marks_released',
        'marks_released_at',
        'registration_opened_at',
        'closed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'semester_number'       => 'integer',
            'starts_at'             => 'date',
            'ends_at'               => 'date',
            'is_active'             => 'boolean',
            'is_registration_open'  => 'boolean',
            'is_marks_released'    => 'boolean',
            'marks_released_at'    => 'datetime',
            'registration_opened_at'=> 'datetime',
            'closed_at'             => 'datetime',
            'metadata'              => 'array',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function examinerPairs(): HasMany
    {
        return $this->hasMany(ExaminerPair::class);
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    /**
     * Is this term accepting Lampiran A submissions right now?
     *
     * Both flags must hold, and the answer is checked on every registration
     * attempt rather than cached in the UI, because a term can be closed by
     * another coordinator mid-session and a stale button would then fail with
     * a confusing validation error instead of being disabled.
     */
    public function isRegistrationOpen(): bool
    {
        return $this->is_registration_open && $this->is_active;
    }

    /**
     * Has this term finished?
     *
     * `ends_at` is advisory: a term can run late. `closed_at` is the
     * authoritative answer, which is why it is checked first.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null || ! $this->is_active;
    }

    /** "Semester I" / "Semester II" — used in labels and the code generator. */
    public function semesterLabel(): string
    {
        return 'Semester ' . ($this->semester_number === 2 ? 'II' : 'I');
    }

    /** The term's own label, without the session prefix. */
    public function shortName(): string
    {
        return $this->semesterLabel();
    }

    public function coordinatorId(): ?int
    {
        $id = $this->metadata['coordinator_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Has this term passed its end date? Drives the "should you close this?"
     * hint on the semester screen, never the gate itself.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfSession(Builder $query, string $session): Builder
    {
        return $query->where('academic_session', $session);
    }

    public function scopeOfNumber(Builder $query, int $number): Builder
    {
        return $query->where('semester_number', $number);
    }

    /**
     * Newest first, with the term number as the tiebreak.
     *
     * `starts_at` is nullable (the faculty does not always publish dates), and
     * a NULL sorts first in MySQL's ascending order and last descending — which
     * happens to be the right answer here: an undated term is the least
     * trustworthy candidate for "current", so it is ordered after dated ones and
     * loses the `id` tiebreak below.
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderByDesc('starts_at')->orderByDesc('semester_number')->orderByDesc('id');
    }

    // -----------------------------------------------------------------
    // Static lookups
    // -----------------------------------------------------------------

    /**
     * The term a coordinator is currently working in.
     *
     * Requirement §7.3: the active term is the one with the most recent
     * `starts_at` that is still active. Returns null rather than falling back to
     * "the newest of any kind" — if no term is active, every default in the UI
     * has to stay empty so a coordinator is asked which term they mean instead
     * of silently editing the wrong cohort's grades.
     */
    public static function current(): ?self
    {
        return static::query()->active()->chronological()->first();
    }

    /** Resolve a caller-supplied id, tolerating an absent/blank/invalid value. */
    public static function resolve(mixed $id): ?self
    {
        if ($id instanceof self) {
            return $id;
        }

        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return static::find((int) $id);
    }

    /**
     * Resolve a filter value to a term id.
     *
     * Accepts `'current'`, a numeric id, or a session string ("2025/2026",
     * matching the active term). Falls back to the active term so that a screen
     * loaded without a filter still shows the term being worked on rather than
     * every term at once.
     */
    public static function resolveFilterId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'current') {
            return static::current()?->id;
        }

        if (is_numeric($value)) {
            return static::query()
                ->whereKey((int) $value)
                ->value('id');
        }

        return static::query()
            ->where('academic_session', (string) $value)
            ->active()
            ->chronological()
            ->value('id');
    }
}