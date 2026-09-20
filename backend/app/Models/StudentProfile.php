<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    /** The most recent project for a given PSM part. */
    public function currentProject(string $psmPart = 'PSM2'): ?Project
    {
        return $this->projects()
            ->where('psm_part', $psmPart)
            ->orderByDesc('projects.created_at')
            ->first();
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
