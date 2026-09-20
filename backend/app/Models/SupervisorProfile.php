<?php

namespace App\Models;

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
        'academic_title',
        'office_location',
        'max_supervisees',
        'is_accepting_students',
        'workload_release_percent',
        'bio',
        'can_examine',
    ];

    protected function casts(): array
    {
        return [
            'max_supervisees'         => 'integer',
            'is_accepting_students'   => 'boolean',
            'can_examine'             => 'boolean',
            'workload_release_percent'=> 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** How many students this supervisor currently has. */
    public function currentLoad(): int
    {
        return $this->activeSupervisions()->count();
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->max_supervisees - $this->currentLoad());
    }

    /** Module 2 — the check the coordinator screen runs before allowing a pairing. */
    public function hasCapacity(int $adding = 1): bool
    {
        return $this->remainingCapacity() >= $adding;
    }

    public function isFull(): bool
    {
        return $this->remainingCapacity() === 0;
    }

    /**
     * Load ratio as a percentage of capacity. Module 5 uses this to flag
     * overloaded supervisors on the workload report.
     */
    public function utilisationPercent(): float
    {
        if ($this->max_supervisees <= 0) {
            return 0.0;
        }

        return round(($this->currentLoad() / $this->max_supervisees) * 100, 2);
    }

    public function isOverloaded(): bool
    {
        return $this->currentLoad() > $this->max_supervisees;
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
}
