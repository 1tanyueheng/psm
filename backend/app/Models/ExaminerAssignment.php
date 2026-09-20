<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 2 / 4 — Explicit examiner allocation to a project.
 */
class ExaminerAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'examiner_id',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
}
