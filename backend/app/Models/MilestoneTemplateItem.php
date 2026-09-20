<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 3 — One milestone definition inside a template.
 */
class MilestoneTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_template_id',
        'code',
        'title',
        'description',
        'deliverable_expectation',
        'sequence',
        'offset_days',
        'duration_days',
        'weight_percent',
        'allowed_file_types',
        'requires_supervisor_approval',
        'max_files',
    ];

    protected function casts(): array
    {
        return [
            'allowed_file_types' => 'array',
            'requires_supervisor_approval' => 'boolean',
            'weight_percent'     => 'decimal:2',
            'sequence'           => 'integer',
            'offset_days'        => 'integer',
            'duration_days'      => 'integer',
            'max_files'          => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MilestoneTemplate::class, 'milestone_template_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }
}
