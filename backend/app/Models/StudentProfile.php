<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 2 — Student profile.
 */
class StudentProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'student_id',
        'program',
        'program_code',
        'batch',
        'faculty',
        'current_semester',
        'academic_semester_id',
        'phone_emergency',
        'thesis_title',
        'thesis_abstract',
        'max_supervisors',
        'is_active_cohort',
    ];

    protected function casts(): array
    {
        return [
            'is_active_cohort' => 'boolean',
            'current_semester' => 'integer',
            'max_supervisors'  => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -----------------------------------------------------------------
    // Module 3 — term enrolment
    // -----------------------------------------------------------------

    /**
     * The term this student is currently enrolled in.
     *
     * Requirement §3.3 proposes a second profile row per term, and the profile
     * table cannot carry that: `user_id` and `student_id` are both unique, so a
     * student has exactly one profile for the whole programme. Duplicating the
     * row would break every relation that treats a profile as a person —
     * supervision capacity counts profiles, `project_members` is unique per
     * profile, and `user->studentProfile` is read directly all over the auth
     * layer.
     *
     * So the profile stays the person's identity and this FK moves forward: it
     * points at whichever term they are enrolled in *now*, and each term's work
     * is retained on the project rows themselves (requirement §6.2 — a PSM 1
     * project is archived, not deleted, when the student progresses). A student
     * who did PSM 1 in Semester I and PSM 2 in Semester II therefore has one
     * profile pointing at Semester II and two archived-or-live projects, which
     * answers "what did this student do last term" without a duplicate profile.
     */
    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    // -----------------------------------------------------------------
    // Module 2 — supervision
    // -----------------------------------------------------------------

    public function supervisionAssignments(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class);
    }

    /** Only the currently effective pairings. */
    public function activeSupervisions(): HasMany
    {
        return $this->supervisionAssignments()->where('is_active', true);
    }

    /** All supervisors, active or not (for history views). */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(
            SupervisorProfile::class,
            'supervision_assignments',
            'student_profile_id',
            'supervisor_profile_id'
        )->withPivot(['role', 'psm_part', 'responsibility_percent', 'is_active', 'assigned_by'])
         ->withTimestamps();
    }

    // -----------------------------------------------------------------
    // Module 3 — projects
    // -----------------------------------------------------------------

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'project_members',
            'student_profile_id',
            'project_id'
        )->withPivot(['is_leader', 'contribution_percent'])->withTimestamps();
    }

    /**
     * Restrict to students who hold a live project in one term and batch.
     *
     * This is a scope rather than a `currentProject` relation on purpose. A
     * student's projects are reached through the `project_members` pivot, not a
     * `leader_id` foreign key, so Eloquent has no hasOne to hang a "current
     * project" off — and the coordinator screens that need this filter are
     * paginated queries where a per-student lookup would cost one extra round
     * trip per row.
     *
     * Null `$psmPart` means "either batch", which is the honest default for a
     * term-level filter: a term holds both batches and neither is wrong.
     */
    public function scopeInSemesterPart(
        Builder $query,
        int|AcademicSemester|null $semester,
        ?string $psmPart = null
    ): Builder {
        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        if ($semesterId === null && $psmPart === null) {
            return $query;
        }

        return $query->whereHas('projects', function (Builder $p) use ($semesterId, $psmPart) {
            $p->forSemester($semesterId);

            if ($psmPart !== null) {
                $p->forPart($psmPart);
            }

            $p->live();
        });
    }

    /**
     * The student's most recent project, optionally for one PSM part.
     *
     * Returns archived rows too, because the historical view needs them. Pass
     * the part when the caller cares which batch: a PSM 1 student must not be
     * read as having no project just because their newest row is PSM 2.
     */
    public function projectForPart(?string $psmPart = null): ?Project
    {
        return $this->projects()
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
            ->orderByDesc('projects.created_at')
            ->first();
    }

    /** The student's newest live project, whichever batch. */
    public function currentProject(): ?Project
    {
        return $this->projects()->live()->orderByDesc('projects.created_at')->first();
    }

    /**
     * Does this student already hold a live project in the given term?
     *
     * Requirement §6.2's integrity rule: one active project per student per
     * term. Checked here rather than as a database constraint because the
     * constraint would have to span `project_members` (a many-to-many) and
     * `archived_at`, which no single unique index can express — a constraint
     * that could not also be dropped would trap a coordinator who archived a
     * duplicate by hand.
     */
    public function hasActiveProjectInSemester(
        int|AcademicSemester|null $semester,
        ?string $psmPart = null
    ): bool {
        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        if ($semesterId === null) {
            return false;
        }

        return $this->projects()
            ->where('academic_semester_id', $semesterId)
            ->whereNull('archived_at')
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
            ->exists();
    }

    /** Every project of this student's, newest term first. */
    public function projectsInOrder(): BelongsToMany
    {
        return $this->projects()
            ->orderByDesc('projects.academic_semester_id')
            ->orderByDesc('projects.created_at');
    }

    public function finalGrades(): HasMany
    {
        return $this->hasMany(FinalGrade::class);
    }

    // -----------------------------------------------------------------
    // Convenience
    // -----------------------------------------------------------------

    /** "S12345 — Aisyah binti Rahman (CS230)" */
    public function label(): string
    {
        $name = $this->user?->name ?? 'Unknown';

        return "{$this->student_id} — {$name} ({$this->program_code})";
    }

    /**
     * Module 2 — how many more supervisors this student may be paired with.
     * The coordinator UI hides the "add supervisor" button at zero.
     */
    public function remainingSupervisorSlots(): int
    {
        $used = $this->activeSupervisions()->count();

        return max(0, $this->max_supervisors - $used);
    }

    public function scopeInBatch($query, string $batch)
    {
        return $query->where('batch', $batch);
    }

    public function scopeActiveCohort($query)
    {
        return $query->where('is_active_cohort', true);
    }
}
