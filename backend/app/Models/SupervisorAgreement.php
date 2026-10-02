<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lampiran A — Supervisor Agreement (Form A).
 *
 * The starting point of the registration flow: a student proposes a supervisor
 * and up to three titles; the supervisor acknowledges; JKPSM approves; the
 * system then registers the pairing and this row points at it.
 */
class SupervisorAgreement extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Lifecycle states, in order. */
    public const STATUS_PENDING_SUPERVISOR = 'pending_supervisor';
    public const STATUS_PENDING_JKPSM      = 'pending_jkpsm';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_CANCELLED          = 'cancelled';

    protected $fillable = [
        'student_profile_id',
        'supervisor_profile_id',
        'session',
        'psm_part',
        'proposed_title_1',
        'proposed_title_2',
        'proposed_title_3',
        'agreed_title',
        'english_report',
        'status',
        'student_signed_at',
        'supervisor_acknowledged_at',
        'jkpsm_received_at',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'supervision_assignment_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'english_report'             => 'boolean',
            'student_signed_at'          => 'datetime',
            'supervisor_acknowledged_at' => 'datetime',
            'jkpsm_received_at'          => 'datetime',
            'decided_at'                 => 'datetime',
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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The pairing this agreement produced, once approved. */
    public function supervisionAssignment(): BelongsTo
    {
        return $this->belongsTo(SupervisionAssignment::class);
    }

    // -----------------------------------------------------------------
    // Convenience
    // -----------------------------------------------------------------

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Part B — every title the student proposed, in order, blanks removed. */
    public function proposedTitles(): array
    {
        return array_values(array_filter([
            $this->proposed_title_1,
            $this->proposed_title_2,
            $this->proposed_title_3,
        ], fn ($t) => filled($t)));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_SUPERVISOR,
            self::STATUS_PENDING_JKPSM,
        ]);
    }
}
