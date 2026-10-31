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
        'section_id',
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

    /**
     * The teaching section this student is placed in.
     *
     * Independent of their PSM project and of their supervision: a section is a
     * group on the service course that runs alongside PSM 1, and an admin sets
     * it. A student's lecturer and their PSM supervisor are therefore different
     * people in different roles, which is why this is a second relation rather
     * than a flag on the supervision.
     *
     * Nullable: a student not on that course has none, and a section's roster
     * simply omits them.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
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
     * Every PSM attempt this student has been enrolled into, oldest first.
     *
     * This is the relation the project-centric model could not have. A student's
     * term used to be reachable only through their project, so a student with no
     * project yet — the normal state before registration — had no history to
     * show. Enrolments exist first, so this is complete from the moment the
     * admin registers them.
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class)->orderBy('psm_part')->orderBy('attempt_no');
    }

    /** The attempts currently in play — enrolling or active. */
    public function liveEnrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class)
            ->whereIn('status', ['enrolling', 'active'])
            ->orderBy('psm_part')
            ->orderBy('attempt_no');
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
     *
     * **Active, not merely unarchived.** A project that has been resolved —
     * passed or failed — is finished work, and a student who failed is entitled
     * to a new one. Counting a finished project here would refuse the retake,
     * which is the same mistake `livePsm2For()` made.
     */
    public function hasActiveProjectInSemester(
        int|AcademicSemester|null $semester,
        ?string $psmPart = null
    ): bool {
        $semesterId = $semester instanceof AcademicSemester ? $semester->id : $semester;

        if ($semesterId === null) {
            return false;
        }

        /**
         * **This stays a project query, and that is deliberate.**
         *
         * The name says "active project" and the callers mean it: this is asked
         * when deciding whether a student may *register* a project, and the
         * answer it must give is "is there already a running project here".
         * A student who is enrolled but has not registered has no project, so
         * this correctly says no and registration proceeds — which is exactly
         * what the enrolment row is for.
         *
         * `hasLiveEnrolment()` is the other question, and the one to reach for
         * when asking whether a student already holds a place.
         */
        return $this->projects()
            ->where('academic_semester_id', $semesterId)
            ->active()
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
            ->exists();
    }

    /**
     * Whether this student holds a live place in a part, in any term.
     *
     * The enrolment-level question, and the one registration and repeat actually
     * need: a student who is enrolled but has not registered still occupies the
     * place, and enrolling them again would double-count them.
     */
    public function hasLiveEnrolment(?string $psmPart = null): bool
    {
        return $this->enrolments()
            ->whereIn('status', ['enrolling', 'active'])
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
     *
     * Reads the eager-loaded relation when there is one. It used to call
     * `activeSupervisions()->count()` unconditionally — correct, but one query
     * per student, and `StudentProfileResource` renders this on every row of the
     * admin user list. With 29 students that alone was 1.9 seconds of the 3.3
     * the screen took, on a page that had already been fixed once for the same
     * N+1 on the supervisor side.
     *
     * This is a display count, so the loaded relation is the right source. The
     * allocation gate is a different question: `AssignmentService` reads the
     * live rows, so a stale eager load can never authorise a pairing the
     * capacity rule would refuse.
     */
    public function remainingSupervisorSlots(): int
    {
        $used = $this->relationLoaded('activeSupervisions')
            ? $this->activeSupervisions->count()
            : $this->activeSupervisions()->count();

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

    /**
     * Students whose account still exists and can still act.
     *
     * **A soft-deleted user leaves its student profile behind**, and that
     * profile keeps `is_active_cohort = true`, so the student went on appearing
     * in the enrolment and supervision pickers with a blank name — the name
     * lives on `users`, which `StudentProfileResource` can no longer load.
     * Four such rows from 2026-10-26 (`AI907087`, `AI907727`, `AI907747`,
     * `AI907843`, all named "… Probe") sat in the enrolment dialog for six days
     * before anyone noticed, because the row looked like a student with an
     * unfilled name rather than like a deleted account.
     *
     * `whereHas('user')` alone is not enough: `User` is soft-deletable, so the
     * relation still resolves a trashed row unless the scope says otherwise —
     * hence the explicit `withTrashed()`-free `whereHas`, which applies the
     * model's default scope and therefore excludes trashed users. The status
     * test is separate because a *deactivated* account is equally unable to
     * take a place in a term.
     *
     * Use this in every candidate list. A picker that offers a deleted account
     * is a row the admin cannot act on and cannot debug.
     */
    public function scopeWithActiveAccount(Builder $query): Builder
    {
        // `active()` is `status = 'active'`; the default SoftDeletes scope on
        // User already excludes trashed rows from this subquery.
        return $query->whereHas('user', fn ($q) => $q->active());
    }
}
