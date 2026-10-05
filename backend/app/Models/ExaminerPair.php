<?php

namespace App\Models;

use App\Enums\PsmPart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Module 2/4 — a fixed pair of examiners.
 *
 * The faculty groups examiners into standing pairs and assigns batches of
 * students to a pair, so every student in a batch is assessed by the same two
 * people — for the proposal review and the final evaluation alike. The pair is
 * therefore created once and reused, not chosen per project.
 */
class ExaminerPair extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'psm_part',
        'academic_semester_id',
        'examiner_1_id',
        'examiner_2_id',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * The term this panel is registered for.
     *
     * Requirement §3.7. The same two examiners form a *different* pair in
     * different terms, because a panel is a term's allocation decision, not a
     * permanent property of two people. Scoping on the term is what lets the
     * faculty reuse a panel next semester and what stops this term's
     * allocations leaking into the next term's roster.
     */
    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function examiner1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_1_id');
    }

    public function examiner2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_2_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every allocation made on behalf of this pair. */
    public function assignments(): HasMany
    {
        return $this->hasMany(ExaminerAssignment::class);
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * The two examiners, in slot order.
     *
     * Slot order is what the faculty sheet's "Insert Panel Name (1)" and "(2)"
     * columns read, so it is preserved rather than sorted.
     *
     * @return array<int, User>
     */
    public function members(): array
    {
        return array_values(array_filter([
            $this->examiner1,
            $this->examiner2,
        ]));
    }

    /** @return array<int, string> */
    public function memberNames(): array
    {
        return array_values(array_filter(array_map(
            fn (User $u) => $u->displayName(),
            $this->members()
        )));
    }

    public function containsUser(int $userId): bool
    {
        return $this->examiner_1_id === $userId || $this->examiner_2_id === $userId;
    }

    /**
     * How many students this pair currently covers.
     *
     * The unit is the *student*, not the project: a pair is allocated against
     * the student from Lampiran A onward, before any project exists, and the
     * same pair reviews the proposal and gives the final evaluation. Counting
     * projects would read zero for every allocation made before registration.
     *
     * Only *active* allocations count. A re-seated student keeps their old row
     * with `is_active = false` so the audit trail survives, and counting those
     * would make a pair look permanently full after one correction — every later
     * run would skip it as "at capacity" and open a needless new panel. The
     * distinct() is because a pair seats two examiners per student.
     *
     * Legacy rows created before the student anchor existed have only a project
     * id, so they are counted separately rather than silently dropped.
     */
    public function projectCount(): int
    {
        $byStudent = $this->assignments()->active()
            ->whereNotNull('student_profile_id')
            ->distinct()
            ->count('student_profile_id');

        $byProject = $this->assignments()->active()
            ->whereNull('student_profile_id')
            ->distinct()
            ->count('project_id');

        return (int) $byStudent + (int) $byProject;
    }

    /** The same number, under the name that says what it counts. */
    public function studentCount(): int
    {
        return $this->projectCount();
    }

    /**
     * The ceiling on students per pair per term, from config/psm.php.
     *
     * A pair is a standing allocation, so it needs one: without a cap a single
     * panel silently ends up examining an entire cohort while the others sit
     * idle, and the coordinator has no signal that it happened.
     */
    public function capacityForPart(?string $psmPart = null): int
    {
        $part = PsmPart::tryParse($psmPart ?? $this->psm_part, PsmPart::Psm2);

        return (int) config("psm.examiner_capacity.{$part->value}", 10);
    }

    public function remainingCapacity(?string $psmPart = null): int
    {
        return max(0, $this->capacityForPart($psmPart) - $this->projectCount());
    }

    public function isFull(?string $psmPart = null): bool
    {
        return $this->remainingCapacity($psmPart) === 0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Panels available within one term.
     *
     * Passing null means "no term filter" — the unfiltered list view, where
     * showing every term's panels is the point.
     *
     * Passing an id is strict: a panel with no term attached is *excluded*.
     * Handing a term-less panel to this term's students is precisely the
     * cross-term leak the term column exists to prevent, and a term-less panel
     * is always visible and fixable on the unfiltered list, so there is no
     * reason to let one reach a real allocation run.
     */
    public function scopeForSemester(Builder $query, int|AcademicSemester|null $semester): Builder
    {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $id === null
            ? $query
            : $query->where('academic_semester_id', $id);
    }

    public function scopeForPart(Builder $query, ?string $psmPart): Builder
    {
        return $psmPart === null
            ? $query
            : $query->where('psm_part', $psmPart);
    }

    /** Pairs not yet at their ceiling — the pool auto-assign draws from. */
    public function scopeWithRoom(Builder $query, ?string $psmPart = null): Builder
    {
        $capacity = (int) config('psm.examiner_capacity.' . (
            PsmPart::tryParse($psmPart, PsmPart::Psm2)->value
        ), 10);

        return $query->withCount([
            'assignments as assignment_count' => fn ($q) => $q->active()->select(
                DB::raw('count(distinct coalesce(student_profile_id, project_id))')
            ),
        ])->havingRaw('assignment_count < ?', [$capacity]);
    }
}
