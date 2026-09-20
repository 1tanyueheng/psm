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
        'batch',
        'program',
        'status',
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

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            StudentProfile::class,
            'project_members',
            'project_id',
            'student_profile_id'
        )->withPivot(['is_leader', 'contribution_percent'])->withTimestamps();
    }

    public function leader(): ?StudentProfile
    {
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

        $earned = $milestones->sum(fn (Milestone $m) => match ($m->status) {
            MilestoneStatus::Approved  => (float) $m->weight_percent,
            // Partial credit: submitted/reviewed work is underway
            MilestoneStatus::Reviewed  => (float) $m->weight_percent * 0.75,
            MilestoneStatus::Submitted => (float) $m->weight_percent * 0.5,
            default                    => 0.0,
        });

        return round(($earned / $totalWeight) * 100, 2);
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

    public function scopeForPart(Builder $query, string $psmPart): Builder
    {
        return $query->where('psm_part', $psmPart);
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

            return $query->whereHas(
                'members.studentProfile.activeSupervisions',
                fn ($q) => $q->where('supervisor_profile_id', $supervisorId)
            );
        }

        if ($user->isExaminer()) {
            return $query->whereHas(
                'examinerAssignments',
                fn ($q) => $q->where('examiner_id', $user->id)->where('is_active', true)
            );
        }

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
