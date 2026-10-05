<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 2 / 4 — Explicit examiner allocation to a student, and to the project
 * once one exists.
 *
 * The pair is allocated to the *student* from Lampiran A onward, because the
 * same two examiners review the proposal (which happens before registration)
 * and give the final mark (which happens after). So `student_profile_id` is the
 * durable anchor and `project_id` is stamped on once Lampiran B creates the
 * project — which is what keeps the evaluation path, which reads `project_id`,
 * working unchanged.
 */
class ExaminerAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'student_profile_id',
        'examiner_id',
        'examiner_pair_id',
        'psm_part',
        'panel_role',
        'is_active',
        'assigned_by',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    /** The fixed pair this allocation was made on behalf of, if any. */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(ExaminerPair::class, 'examiner_pair_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The student this examiner is seated on. Set from the moment the pair is
     * allocated, before the project exists.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Allocations seated on one student — the lookup the panel-allocation roster
     * and the panel policy use, since the project may not exist yet.
     */
    public function scopeForStudent($query, int|StudentProfile $student)
    {
        $id = $student instanceof StudentProfile ? $student->id : $student;

        return $query->where('student_profile_id', $id);
    }
}
