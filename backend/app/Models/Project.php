<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 3 — A registered PSM project (one per student/group per PSM part).
 */
class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'title',
        'abstract',
        'objectives',
        'scope',
        'category',
        'psm_part',
        'academic_session',
            'academic_semester_id',
            'batch',
            'program',
            'status',
            'agreement_id',
            'created_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'rejection_reason',
        'archived_at',
        'leaderboard_opt_out',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'category'            => ProjectCategory::class,
            'leaderboard_opt_out' => 'boolean',
            'metadata'            => 'array',
            'submitted_at'        => 'datetime',
            'approved_at'         => 'datetime',
            'archived_at'         => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * The term this project belongs to — the scoping unit for every cohort
     * query.
     *
     * Reachable through here rather than through `academic_session` because a
     * session holds two terms and each term holds two batches. The FK is
     * nullable so pre-semester data keeps working; callers that genuinely need a
     * term (the cohort views, the reporting split) must decide what a null means
     * rather than inherit it.
     */
    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            StudentProfile::class,
            'project_members',
            'project_id',
            'student_profile_id'
        )->withPivot(['is_leader', 'contribution_percent'])->withTimestamps();
    }

    /**
     * The project's lead student, falling back to the first member.
     *
     * Uses the loaded `students` relation when there is one. It previously
     * always called `$this->students()`, which issues a **fresh query** each
     * time and ignores anything the caller already eager-loaded — so a page
     * that rendered this once per row paid a query per row for data it already
     * had. On a hosted database each of those is a network round trip, which
     * turned a screen that should be instant into one that took seconds.
     *
     * Falls back to querying when the relation is not loaded, so callers that
     * have not eager-loaded still get a correct answer.
     */
    public function leader(): ?StudentProfile
    {
        if ($this->relationLoaded('students')) {
            return $this->students->firstWhere('pivot.is_leader', true)
                ?? $this->students->first();
        }

        return $this->students()->wherePivot('is_leader', true)->first()
            ?? $this->students()->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The Lampiran A agreement this project was created from (if it came via
     * the Lampiran B flow). Null if the project predates that flow.
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(SupervisorAgreement::class, 'agreement_id');
    }

    /** Module 3 — instantiated milestones, in order. */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sequence');
    }

    /** Module 2/4 — examiners allocated to this project. */
    public function examinerAssignments(): HasMany
    {
        return $this->hasMany(ExaminerAssignment::class);
    }

    public function examiners(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'examiner_assignments',
            'project_id',
            'examiner_id'
        )->withPivot(['psm_part', 'panel_role', 'is_active'])->withTimestamps();
    }

    /** Module 4 — all evaluation forms on this project. */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function gradeScheme(): HasOne
    {
        return $this->hasOne(GradeScheme::class);
    }

    public function finalGrades(): HasMany
    {
        return $this->hasMany(FinalGrade::class);
    }

    /** Module 8 — the aggregate grade used for ranking. */
    public function primaryGrade(): ?FinalGrade
    {
        return $this->finalGrades()
            ->orderByDesc('aggregate_percent')
            ->first();
    }

    /** Module 7 — the archive record once this project is retired. */
    public function archivedRecord(): HasOne
    {
        return $this->hasOne(ArchivedProject::class);
    }

    public function leaderboardEntries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class);
    }

    // -----------------------------------------------------------------
    // Module 3 — progress
    // -----------------------------------------------------------------

    /**
     * Overall milestone completion, 0–100, using each milestone's
     * weight_percent. This is the number Module 5 shows on the cohort
     * progress bar and Module 8 can optionally rank by.
     *
     * Each chapter contributes its own weight in proportion to how far it has
     * got, so approving a 15%-weight chapter moves this figure by 15. The
     * per-status factors come from MilestoneStatus::progressFactor(), the
     * same source Milestone::completionPercent() uses, which is what
     * guarantees the chapter breakdown shown to a supervisor sums to this
     * total rather than merely resembling it.
     */
    public function milestoneProgressPercent(): float
    {
        $milestones = $this->relationLoaded('milestones')
            ? $this->milestones
            : $this->milestones()->get();

        if ($milestones->isEmpty()) {
            return 0.0;
        }

        $totalWeight = (float) $milestones->sum('weight_percent');

        if ($totalWeight <= 0) {
            // Fall back to a plain count when a template omitted weights
            $approved = $milestones->where('status', MilestoneStatus::Approved)->count();

            return round(($approved / $milestones->count()) * 100, 2);
        }

        $earned = $milestones->sum(
            fn (Milestone $m) => (float) $m->weight_percent * $m->status->progressFactor()
        );

        return round(($earned / $totalWeight) * 100, 2);
    }

    /**
     * Has every milestone reached the terminal `approved` state?
     *
     * This is the gate the PSM requirement puts on the supervisor's Lampiran E
     * evaluation: "Once all milestones are marked as completed, the supervisor
     * evaluates the student." Completion means approved, not merely submitted —
     * a chapter sitting in review is not finished work, and the final mark
     * should not be assessable while part of the project is still moving.
     *
     * Deliberately a separate query rather than `status === 'completed'`: that
     * status is set as a side effect of approving the last milestone
     * (MilestoneService::activateNext), so relying on it would make this rule
     * depend on an unrelated write succeeding.
     */
    public function allMilestonesApproved(): bool
    {
        $milestones = $this->relationLoaded('milestones')
            ? $this->milestones
            : $this->milestones()->get();

        // A project with no milestones has nothing to have completed, so it
        // must not be treated as finished.
        if ($milestones->isEmpty()) {
            return false;
        }

        return $milestones->every(
            fn (Milestone $m) => $m->status === MilestoneStatus::Approved
        );
    }

    /** The next milestone the student should be working on. */
    public function currentMilestone(): ?Milestone
    {
        return $this->milestones()
            ->whereIn('status', [
                MilestoneStatus::Open->value,
                MilestoneStatus::Rejected->value,
                MilestoneStatus::Overdue->value,
                MilestoneStatus::Pending->value,
            ])
            ->orderBy('sequence')
            ->first();
    }

    public function currentStageLabel(): string
    {
        return $this->currentMilestone()?->title ?? 'Completed';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['in_progress', 'approved'], true);
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    /** Module 8 — may this project ever be publicly displayed? */
    public function isEligibleForLeaderboard(): bool
    {
        return ! $this->leaderboard_opt_out
            && $this->isComplete();
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForBatch(Builder $query, string $batch): Builder
    {
        return $query->where('batch', $batch);
    }

    /**
     * Restrict to one academic term.
     *
     * A null id means "no filter" rather than "no rows": screens that must show
     * legacy projects predating semesters pass null through deliberately, and a
     * silent empty list there would look like data loss.
     */
    public function scopeForSemester(Builder $query, int|AcademicSemester|null $semester): Builder
    {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $id === null ? $query : $query->where('academic_semester_id', $id);
    }

    public function scopeForPart(Builder $query, string $psmPart): Builder
    {
        return $query->where('psm_part', $psmPart);
    }

    /**
     * One batch of one term — the combination every cohort view needs.
     *
     * Both parts of a term together is just `scopeForSemester`; naming the pair
     * separately keeps the call sites from each hand-rolling both filters and
     * forgetting one, which is how a roster ends up mixing batches.
     */
    public function scopeForSemesterPart(
        Builder $query,
        int|AcademicSemester|null $semester,
        string $psmPart
    ): Builder {
        return $query->forSemester($semester)->forPart($psmPart);
    }

    /**
     * Projects this user **supervises**, and only those.
     *
     * `visibleTo()` deliberately returns supervised *and* examined projects,
     * because being seated on a panel is a reason to read a project. But a
     * roster is a different question from a permission: "my supervisees" must
     * not include the students someone merely examines, or the supervisor's
     * PSM 1 window lists other people's students under this supervisor's name.
     */
    public function scopeSupervisedBy(Builder $query, User $user): Builder
    {
        $supervisorId = $user->supervisorProfile?->id;

        if ($supervisorId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'members.studentProfile.activeSupervisions',
            fn ($sub) => $sub->where('supervisor_profile_id', $supervisorId)
        );
    }

    /** Projects this user is seated on as an examiner, and only those. */
    public function scopeExaminedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'examinerAssignments',
            fn ($sub) => $sub->where('examiner_id', $user->id)->where('is_active', true)
        );
    }

    /**
     * Projects that are not archived.
     *
     * `archived_at` is the retirement marker, but the soft-delete column is
     * also in play — an archived project is usually soft-deleted, and either
     * one alone is enough to retire it. Checking both keeps a project that was
     * archived by the coordinator but not by the deletion cascade out of every
     * live roster.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOfCategory(Builder $query, ProjectCategory|string $category): Builder
    {
        return $query->where(
            'category',
            $category instanceof ProjectCategory ? $category->value : $category
        );
    }

    /** Only projects the given user is entitled to see. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isCoordinator()) {
            $scopes = $user->coordinatorScopes;

            if ($scopes->isEmpty()) {
                return $query;
            }

            return $query->where(function ($q) use ($scopes) {
                foreach ($scopes as $scope) {
                    $q->orWhere(function ($sub) use ($scope) {
                        if ($scope->batch) {
                            $sub->where('batch', $scope->batch);
                        }
                        if ($scope->program) {
                            $sub->where('program', $scope->program);
                        }
                    });
                }
            });
        }

        if ($user->isStudent()) {
            $studentId = $user->studentProfile?->id;

            return $query->whereHas('members', fn ($q) => $q->where('student_profile_id', $studentId));
        }

        if ($user->isSupervisor()) {
            $supervisorId = $user->supervisorProfile?->id;

            /**
             * Projects they supervise, **or** projects they are allocated to as
             * a panel examiner.
             *
             * Being an examiner is a seating, not a role — the same academic
             * supervises their own students and may sit on someone else's panel,
             * so a supervisor must see the project they were appointed to
             * examine. Otherwise they cannot read, or decide the proposal
             * milestone of, the work they were given.
             *
             * This mirrors `ProjectPolicy::view()`; the two must agree or a list
             * and a single-record check would disagree about the same row.
             */
            return $query->where(function ($q) use ($supervisorId, $user) {
                $q->whereHas(
                    'members.studentProfile.activeSupervisions',
                    fn ($sub) => $sub->where('supervisor_profile_id', $supervisorId)
                )->orWhereHas(
                    'examinerAssignments',
                    fn ($sub) => $sub->where('examiner_id', $user->id)->where('is_active', true)
                );
            });        }

        return $query->whereRaw('1 = 0');
    }

    /** Generate the next project code, e.g. PSM2-2026-CS-014. */
    public static function nextCode(string $psmPart, string $session, ?string $programCode = null): string
    {
        $year  = explode('/', $session)[0] ?: date('Y');
        $prog  = $programCode ? strtoupper(substr($programCode, 0, 3)) : 'GEN';

        $count = static::withTrashed()
            ->where('psm_part', $psmPart)
            ->where('academic_session', $session)
            ->count();

        return sprintf('%s-%s-%s-%03d', $psmPart, $year, $prog, $count + 1);
    }
}
