<?php

namespace App\Models;

use App\Enums\Programme;
use App\Enums\PsmPart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 2 — Supervisor profile, including the capacity constraint that
 * Module 2's assignment screen enforces.
 */
class SupervisorProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'staff_no',
        'programme',
        'academic_title',
        'office_location',
        'max_supervisees',
        'max_supervisees_psm1',
        'max_supervisees_psm2',
        'is_accepting_students',
        'workload_release_percent',
        'bio',
    ];

    protected function casts(): array
    {
        return [
            'max_supervisees'         => 'integer',
            'max_supervisees_psm1'    => 'integer',
            'max_supervisees_psm2'    => 'integer',
            'is_accepting_students'   => 'boolean',
            'workload_release_percent'=> 'decimal:2',
            'programme'               => Programme::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -----------------------------------------------------------------
    // Programme ownership
    // -----------------------------------------------------------------

    /**
     * May this supervisor take students from the given programme?
     *
     * FSKTM's rule: a supervisor supervises — and an examiner examines — only
     * students from their own programme. Enforced through one method so the
     * allocation gate, the panel gate and the suggestion lists cannot drift
     * apart, which is how the capacity rule drifted before it.
     *
     * **An unknown programme on either side permits the pairing.** That is a
     * deliberate fail-open, and the reasoning is worth stating because it is the
     * opposite of the usual instinct: this rule is additive and new, most
     * existing staff rows have no programme recorded, and failing closed would
     * refuse every allocation in the faculty until somebody filled in a field
     * that did not exist yesterday. The rule bites as soon as the data is there,
     * and a null is visibly "not yet recorded" rather than silently "matches".
     *
     * Compare `hasCapacityForInSemester()`, which fails *closed* — there, a null
     * means capacity zero and permitting would over-allocate a real person.
     */
    public function canSuperviseProgramme(mixed $programme): bool
    {
        $student = Programme::tryParse($programme);

        if ($student === null || $this->programme === null) {
            return true;
        }

        return $this->programme === $student;
    }

    /**
     * The refusal message, or null when the pairing is allowed.
     *
     * Returned rather than thrown so the caller can compose it with the capacity
     * refusal — a coordinator needs to know *which* rule blocked them, and
     * "no capacity" would be misleading when the real reason is the programme.
     */
    public function programmeRefusal(mixed $programme, ?string $studentLabel = null): ?string
    {
        if ($this->canSuperviseProgramme($programme)) {
            return null;
        }

        $student = Programme::tryParse($programme);
        $who = $studentLabel !== null ? $studentLabel : 'This student';

        return sprintf(
            '%s is on %s, but %s supervises %s. FSKTM pairs staff with students from their '
            .'own programme only.',
            $who,
            $student?->label() ?? (string) $programme,
            $this->user?->name ?? 'this supervisor',
            $this->programme?->label() ?? 'a different programme',
        );
    }

    // -----------------------------------------------------------------
    // Module 2 — expertise
    // -----------------------------------------------------------------

    public function expertiseAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            ExpertiseArea::class,
            'supervisor_expertise',
            'supervisor_profile_id',
            'expertise_area_id'
        )->withPivot('proficiency')->withTimestamps();
    }

    /** Best-match expertise, highest proficiency first — used by auto-suggest. */
    public function topExpertise(int $limit = 3)
    {
        return $this->expertiseAreas()
            ->orderByDesc('supervisor_expertise.proficiency')
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------
    // Module 2 — workload
    // -----------------------------------------------------------------

    /**
     * Workload is budgeted PER PSM PART, not as one shared number.
     *
     * Requirement §7.1. The faculty runs both batches out of one supervisor pool,
     * so a supervisor carrying 4 PSM 1 students and 5 PSM 2 students is carrying
     * nine — inside both caps and outside neither. A single flat ceiling cannot
     * express that: at 5 + 5 the flat total of 8 would refuse the fifth PSM 1
     * student even though PSM 1 was nowhere near its cap, and a coordinator
     * reading "8/8" had no way to see that one batch was full and the other
     * had room.
     *
     * The per-part columns are nullable so a coordinator can override one part
     * without touching the other; null falls back to config/psm.php.
     * `max_supervisees` survives as the aggregate ceiling — see
     * currentLoad() for why it is still checked, and why it is not the gate.
     */
    public function supervisionAssignments(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class);
    }

    public function activeSupervisions(): HasMany
    {
        return $this->supervisionAssignments()->where('is_active', true);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            StudentProfile::class,
            'supervision_assignments',
            'supervisor_profile_id',
            'student_profile_id'
        )->withPivot(['role', 'psm_part', 'responsibility_percent', 'is_active'])
         ->withTimestamps();
    }

    /**
     * How many students this supervisor currently has, in every part.
     *
     * This is the *total*, and it is kept as the aggregate the Module 5 workload
     * report has always shown. It is deliberately not the number the allocation
     * screen gates on — see hasCapacityFor().
     */
    public function currentLoad(): int
    {
        return $this->activeSupervisions()->count();
    }

    /**
     * Active supervisions, reusing the eager-loaded relation when there is one.
     *
     * capacityReport() asks for both parts in a row; without this the second call
     * would re-run the same query, and the coordinator's supervisor list calls it
     * once per supervisor.
     */
    public function activeSupervisionsLoaded()
    {
        return $this->relationLoaded('activeSupervisions')
            ? $this->activeSupervisions
            : $this->activeSupervisions()->get();
    }

    /**
     * Active supervisions that count against one part's cap.
     *
     * A `BOTH` pairing counts in both parts: the supervisor is carrying that
     * student in both batches, which is exactly what makes it consume two slots.
     * PsmPart::covers() owns that rule so this method, hasCapacityFor() and the
     * workload report cannot disagree about it.
     *
     * Reads the eager-loaded relation when there is one — this is a *display*
     * count. Never gate an allocation on it: see currentLoadForPartFromDatabase().
     */
    public function currentLoadForPart(string|PsmPart $part): int
    {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        return $this->activeSupervisionsLoaded()
            ->filter(fn (SupervisionAssignment $a) => $part->covers($a->psm_part))
            ->count();
    }

    /**
     * The same count, read straight from the database. The gate uses this one.
     *
     * The eager-loaded count goes stale the moment a row is inserted, and the
     * stale value is what let a coordinator's batch allocation walk straight
     * past a cap: allocating five students in one request reused the same
     * in-memory snapshot five times, so the sixth check still saw "3 of 5" and
     * the per-part ceiling was only ever enforced by the aggregate, silently.
     * A display count may be a cached snapshot; a capacity decision may not.
     */
    public function currentLoadForPartFromDatabase(string|PsmPart $part): int
    {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        return $this->activeSupervisions()
            ->where(function ($query) use ($part) {
                // `Both` is covered by every part, so it means "everything" —
                // the same answer covers() gives.
                if ($part === PsmPart::Both) {
                    return;
                }

                $query->where('psm_part', $part->value)
                    ->orWhere('psm_part', PsmPart::Both->value);
            })
            ->count();
    }

    /**
     * Load for both deliverable parts, keyed by the raw column value.
     *
     * @return array<string, int>
     */
    public function currentLoadByPart(): array
    {
        $loads = [];

        foreach (PsmPart::deliverables() as $part) {
            $loads[$part->value] = $this->currentLoadForPart($part);
        }

        return $loads;
    }

    /**
     * The same two counts, restricted to one academic term.
     *
     * Requirement §7.1 measures capacity against *this term's* students. A
     * supervisor who carried five PSM 1 students last term and is allocated
     * three this term is carrying three, not eight — last term's students are
     * finished and their supervision rows are still `is_active`, so the global
     * count would silently push the supervisor over their per-part cap and
     * refuse every further allocation in either batch.
     *
     * Reached through the student's live project, because a supervision row
     * carries no term of its own. Archived projects are excluded for the same
     * reason the allocation gate excludes them: a retired project is not
     * current supervision.
     *
     * @return array<string, int>
     */
    public function currentLoadByPartInSemester(int|AcademicSemester|null $semester): array
    {
        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        if ($semesterId === null) {
            return $this->currentLoadByPart();
        }

        $inTerm = $this->activeSupervisions()
            ->whereHas('studentProfile.projects', fn ($p) => $p->forSemester($semesterId)->live())
            ->get();

        $loads = [];

        foreach (PsmPart::deliverables() as $part) {
            $loads[$part->value] = $inTerm
                ->filter(fn (SupervisionAssignment $a) => $part->covers($a->psm_part))
                ->count();
        }

        return $loads;
    }

    /**
     * Has this supervisor room, counting only this term's students?
     *
     * The gate the allocation screen runs when a term is in play. Falls back to
     * the all-terms count when no term is given, so callers that have not been
     * taught about semesters keep their existing behaviour rather than
     * accidentally gating on an unfiltered count.
     */
    public function hasCapacityForInSemester(
        string|PsmPart $part,
        int|AcademicSemester|null $semester,
        int $adding = 1
    ): bool {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        if ($semesterId === null) {
            return $this->hasCapacityFor($part, $adding);
        }

        $load = $this->currentLoadByPartInSemester($semesterId)[$part->value] ?? 0;

        if ($load + $adding > $this->capacityForPart($part)) {
            return false;
        }

        // The aggregate backstop still applies, but measured against this term's
        // students so a finished cohort does not consume it - and against
        // effectiveTotalCapacity() so the legacy single-total column cannot
        // refuse an allocation that is inside both part caps.
        $aggregate = array_sum($this->currentLoadByPartInSemester($semesterId));

        return ($aggregate + $adding) <= $this->effectiveTotalCapacity();
    }

    /**
     * The effective ceiling for one part: this supervisor's override if set,
     * otherwise the faculty-wide default from config/psm.php.
     *
     * Reading the column is not enough on its own. A NULL here means "no
     * override", which is different from "capacity zero" — treating it as zero
     * would silently lock every legacy supervisor out of new allocations.
     */
    public function capacityForPart(string|PsmPart $part): int
    {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        if ($part === PsmPart::Both) {
            return 0;
        }

        $column = $part === PsmPart::Psm1 ? 'max_supervisees_psm1' : 'max_supervisees_psm2';

        $override = $this->{$column};

        return $override !== null
            ? (int) $override
            : (int) config("psm.supervisor_capacity.{$part->value}", 5);
    }

    /** Both effective ceilings, keyed by the raw column value. */
    public function capacityByPart(): array
    {
        $capacities = [];

        foreach (PsmPart::deliverables() as $part) {
            $capacities[$part->value] = $this->capacityForPart($part);
        }

        return $capacities;
    }

    /**
     * The aggregate ceiling, for use as a backstop behind the per-part checks.
     *
     * This is `max(max_supervisees, sum of the effective per-part caps)`, and
     * the `max` matters.
     *
     * `max_supervisees` is a legacy single-total column: the per-part capacity
     * migration backfills it to the *sum* of the two part caps, and it still
     * ships defaulting to 8. Reading it as the aggregate on its own therefore
     * contradicts the two independent part caps it was derived from - a
     * supervisor left at the default 8 could be refused a fifth PSM 1 student
     * purely because the legacy total had not been re-saved, even though that
     * part's real ceiling is 5 and they were carrying 4. Acceptance criterion #4
     * asks for capacity to be enforced *per part, not globally*, so the legacy
     * total is allowed to be more generous than the parts, never less.
     *
     * A coordinator who deliberately sets `max_supervisees` above the sum of the
     * parts (say 12 for a 5+5 pair, to leave headroom) still gets that headroom
     * honoured; the per-part checks remain the primary rule either way.
     */
    public function effectiveTotalCapacity(): int
    {
        return max(
            (int) $this->max_supervisees,
            (int) array_sum($this->capacityByPart()),
        );
    }

    public function remainingCapacityForPart(string|PsmPart $part): int
    {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        return max(0, $this->capacityForPart($part) - $this->currentLoadForPart($part));
    }

    /**
     * Free slots in one part, counting only this term's students.
     *
     * The term-scoped twin of remainingCapacityForPart(). The allocation screen
     * ranks candidates on headroom, so measuring against all terms would rank a
     * supervisor carrying a finished cohort as though they had no room and
     * quietly drop them from a shortlist they are in fact eligible for.
     */
    public function remainingCapacityForPartInSemester(
        string|PsmPart $part,
        int|AcademicSemester|null $semester = null,
    ): int {
        $part = $part instanceof PsmPart
            ? $part
            : (PsmPart::tryParse($part) ?? PsmPart::Psm2);

        if ($part === PsmPart::Both) {
            // A BOTH pairing needs a slot in each part, so the binding
            // constraint is whichever part has less room.
            return min(
                $this->remainingCapacityForPartInSemester(PsmPart::Psm1, $semester),
                $this->remainingCapacityForPartInSemester(PsmPart::Psm2, $semester),
            );
        }

        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        $load = $semesterId === null
            ? $this->currentLoadForPart($part)
            : ($this->currentLoadByPartInSemester($semesterId)[$part->value] ?? 0);

        return max(0, $this->capacityForPart($part) - $load);
    }

    /**
     * Is this supervisor full in *any* part, counting only this term's students?
     *
     * `isFull()` answers that question across every term, which made the
     * suggestion list and the allocation filter treat a supervisor who finished
     * last term's cohort as unavailable for this one.
     */
    public function isFullInSemester(int|AcademicSemester|null $semester = null): bool
    {
        foreach (PsmPart::deliverables() as $part) {
            if ($this->remainingCapacityForPartInSemester($part, $semester) <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Module 2 — the check the allocation screen runs before pairing a student.
     *
     * Two conditions, both required:
     *
     *   1. room in the part being allocated, and
     *   2. room against the aggregate ceiling.
     *
     * The per-part check is the real rule (AC#4). The aggregate is a backstop
     * that keeps a coordinator's deliberately-generous `max_supervisees` from
     * being ignored entirely; because effectiveTotalCapacity() never falls
     * below the sum of the part caps, it cannot refuse an allocation that is
     * within both caps, and with the default 5 + 5 it can never bind at all.
     */
    public function hasCapacityFor(string|PsmPart $part, int $adding = 1): bool
    {
        // The database count, not the eager-loaded one — see
        // currentLoadForPartFromDatabase(). An allocation made earlier in this
        // same request has to count against the next one, or the cap is not a cap.
        if ($this->currentLoadForPartFromDatabase($part) + $adding > $this->capacityForPart($part)) {
            return false;
        }

        return $this->remainingCapacity() >= $adding;
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->effectiveTotalCapacity() - $this->currentLoad());
    }

    /** Module 2 — the flat check, retained for callers that predate per-part caps. */
    public function hasCapacity(int $adding = 1): bool
    {
        return $this->remainingCapacity() >= $adding;
    }

    /**
     * Is this supervisor full — in *any* part?
     *
     * The allocation screen disables the action when this is true. It is a
     * single boolean only because the screen shows one button; the reason is
     * reported per part by capacityReport() so the coordinator is told which
     * batch is full rather than being told "full" and left to guess.
     */
    public function isFull(): bool
    {
        foreach (PsmPart::deliverables() as $part) {
            if ($this->remainingCapacityForPart($part) <= 0) {
                return true;
            }
        }

        return false;
    }

    /** The per-part picture the coordinator's capacity bar renders. */
    public function capacityReport(): array
    {
        $report = [];

        foreach (PsmPart::deliverables() as $part) {
            $capacity = $this->capacityForPart($part);
            $load = $this->currentLoadForPart($part);

            $report[$part->value] = [
                'label'           => $part->label(),
                'load'            => $load,
                'capacity'        => $capacity,
                'remaining'       => max(0, $capacity - $load),
                'utilisation'     => $capacity > 0 ? round(($load / $capacity) * 100, 2) : 0.0,
                'is_full'         => $load >= $capacity,
                'is_over_capacity'=> $load > $capacity,
            ];
        }

        return $report;
    }

    /**
     * Load ratio as a percentage of capacity. Module 5 uses this to flag
     * overloaded supervisors on the workload report.
     *
     * Measured against effectiveTotalCapacity() — the sum of the two per-part
     * ceilings, or the legacy total where that is more generous. Comparing
     * against the larger *single* part ceiling instead would call a supervisor
     * carrying a full 5 + 5 load "100% utilised" against a denominator of 5,
     * which is exactly the reading that made the flat number useless.
     */
    public function utilisationPercent(): float
    {
        $capacity = $this->effectiveTotalCapacity();

        if ($capacity <= 0) {
            return 0.0;
        }

        return round(($this->currentLoad() / $capacity) * 100, 2);
    }

    public function isOverloaded(): bool
    {
        foreach (PsmPart::deliverables() as $part) {
            if ($this->currentLoadForPart($part) > $this->capacityForPart($part)) {
                return true;
            }
        }

        return $this->currentLoad() > $this->effectiveTotalCapacity();
    }

    public function label(): string
    {
        $name = $this->user?->name ?? 'Unknown';

        return $this->academic_title
            ? "{$this->academic_title} {$name}"
            : $name;
    }

    public function scopeAccepting($query)
    {
        return $query->where('is_accepting_students', true);
    }

    /**
     * Supervisors with room in one specific part — the pool the auto-assign and
     * suggestion screens rank.
     *
     * Filtering in SQL on the part would need the `BOTH` rows counted in both
     * buckets, which no simple predicate expresses, so the cap is applied after
     * counting. The candidate set is a faculty-sized list, not a table scan of
     * projects, so the row-by-row load count is acceptable here and is the same
     * cost the per-row `currentLoadForPart()` already pays elsewhere.
     */
    public function scopeWithCapacityFor($query, string|PsmPart $part, int $adding = 1)
    {
        return $query->accepting()->get()->filter(
            fn (self $supervisor) => $supervisor->hasCapacityFor($part, $adding)
        );
    }
}
