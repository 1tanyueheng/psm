<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Module 3 — membership of a project.
 *
 * PSM projects are single-member: the registrant is the sole student, so
 * contribution is always 100%. The rule is enforced here rather than in each
 * controller so no code path — API, seeder or a future import — can quietly
 * create a second member.
 */
class ProjectMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'student_profile_id',
        'is_leader',
        'contribution_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_leader'            => 'boolean',
            'contribution_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            $taken = static::query()
                ->where('project_id', $member->project_id)
                ->exists();

            if ($taken) {
                throw new LogicException(
                    'A PSM project has exactly one member, and this project already has one.'
                );
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
