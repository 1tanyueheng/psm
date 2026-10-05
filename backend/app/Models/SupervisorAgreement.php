<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lampiran A — Supervisor Agreement (Form A).
 *
 * The starting point of the registration flow: a student names a supervisor and
 * proposes up to three candidate titles; the supervisor acknowledges Part C and
 * picks the agreed title. The pairing is registered at acknowledgement, and
 * Lampiran B then registers the agreed title as a project.
 *
 * **This row no longer carries the panel's review.** The title is decided at the
 * proposal milestone, by the panel seated on the student — see
 * `ProposalReviewService`. It used to be reviewed here (and before that, at a
 * separate title defence with its own sitting and roster), which put the decision
 * *between* Lampiran A and Lampiran B and meant the project could not exist until
 * the panel had ruled.
 */
class SupervisorAgreement extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Lifecycle states. */
    public const STATUS_PENDING_SUPERVISOR = 'pending_supervisor';
    public const STATUS_APPROVED           = 'approved';
    /** Legacy: a JKPSM rejection, from before that step was removed. */
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_CANCELLED          = 'cancelled';

    protected $fillable = [
        'student_profile_id',
        'supervisor_profile_id',
        'session',
        'academic_semester_id',
        'psm_part',
        'proposed_title_1',
        'proposed_title_2',
        'proposed_title_3',
        'agreed_title',
        'english_report',
        'status',
        'student_signed_at',
        'supervisor_acknowledged_at',
        'supervision_assignment_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'english_report'             => 'boolean',
            'student_signed_at'          => 'datetime',
            'supervisor_acknowledged_at' => 'datetime',
            'metadata'                   => 'array',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function supervisorProfile(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class);
    }

    /**
     * The term the agreement was filed for.
     *
     * Stored rather than derived: `session` is only the session string
     * ("2025/2026"), and the faculty runs two terms per session, so it cannot
     * identify the term on its own. Lampiran B copies this onto the project it
     * registers, which is what lets every term-scoped screen find it.
     */
    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    /** The pairing this agreement produced, registered at acknowledgement. */
    public function supervisionAssignment(): BelongsTo
    {
        return $this->belongsTo(SupervisionAssignment::class);
    }

    /**
     * The project this agreement produced, if Lampiran B has been submitted.
     *
     * Reverse of Project::agreement(); at most one project per agreement
     * because one Lampiran A is one proposed title under one supervisor.
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'agreement_id');
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isAwaitingSupervisor(): bool
    {
        return $this->status === self::STATUS_PENDING_SUPERVISOR;
    }

    /**
     * The title Lampiran B registers.
     *
     * The supervisor's agreed title: it is picked at acknowledgement and the
     * project is created with it. If the panel later refuses it at the proposal
     * milestone, the student changes the *project* title — this row is not
     * rewritten, because the agreement records what was agreed at registration.
     */
    public function titleForRegistration(): ?string
    {
        return $this->agreed_title;
    }

    /**
     * May Lampiran B be filed against this agreement?
     *
     * The supervisor's acknowledgement is the only gate: it registers the pairing
     * and fixes the agreed title, so the project can be created straight away.
     * The title is judged afterwards, at the proposal milestone.
     */
    public function canSubmitLampiranB(): bool
    {
        return $this->isApproved() && filled($this->titleForRegistration());
    }

    /**
     * Why Lampiran B is refused, in the wording the registration screen shows.
     *
     * Kept here rather than in the resource so the gate and its explanation
     * cannot drift apart; `RegistrationService::confirmedTitleFor()` enforces
     * the same rules and reads the same statuses.
     */
    public function lampiranBBlockedReason(): ?string
    {
        if ($this->isApproved()) {
            return filled($this->titleForRegistration())
                ? null
                : 'No agreed title is recorded, so there is nothing for Lampiran B to register.';
        }

        return match ($this->status) {
            self::STATUS_PENDING_SUPERVISOR =>
                'Lampiran A is still awaiting your supervisor\'s acknowledgement.',
            self::STATUS_CANCELLED =>
                'This registration was cancelled.',
            default =>
                'Lampiran A must be acknowledged by your supervisor before Lampiran B can be submitted.',
        };
    }

    /** Part B — every title the student proposed, in order, blanks removed. */
    public function proposedTitles(): array
    {
        return array_values(array_filter([
            $this->proposed_title_1,
            $this->proposed_title_2,
            $this->proposed_title_3,
        ], fn ($title) => filled($title)));
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Agreements still owed an acknowledgement. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_SUPERVISOR);
    }
}
