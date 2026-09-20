<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Module 2 — One supervisor↔student pairing, with its own lifecycle.
 *
 * Activation and deactivation are audited rather than deleted, so the
 * coordinator can always answer "who supervised whom, and when".
 */
class SupervisionAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'supervisor_profile_id',
        'psm_part',
        'role',
        'responsibility_percent',
        'is_active',
        'assigned_by',
        'assignment_note',
        'effective_from',
        'effective_until',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active'              => 'boolean',
            'responsibility_percent' => 'decimal:2',
            'effective_from'         => 'date',
            'effective_until'        => 'date',
            'ended_at'               => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function supervisorProfile(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // -----------------------------------------------------------------

    /** End this pairing without destroying the record (Module 7 trail). */
    public function end(string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'ended_at'  => now(),
            'end_reason'=> $reason,
        ]);
    }

    public function isPrimary(): bool
    {
        return $this->role === 'primary';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPart($query, string $psmPart)
    {
        return $query->whereIn('psm_part', [$psmPart, 'BOTH']);
    }

    /** Static helper so callers do not have to remember the audit column. */
    public static function currentActorId(): ?int
    {
        return Auth::id();
    }
}
