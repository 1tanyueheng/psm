<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 3 — A milestone instance on a real project.
 *
 * Deadlines are stored as dates (not datetimes) because academic deadlines are
 * "end of Friday", and the exact cut-off hour is a policy decision applied by
 * MilestoneService rather than a per-row value.
 */
class Milestone extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'milestone_template_item_id',
        'code',
        'title',
        'description',
        'sequence',
        'weight_percent',
        'status',
        'opens_at',
        'due_at',
        'allow_late_submission',
        'late_window_days',
        'extended_until',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'review_comment',
        'revision_count',
        'deadline_overridden_by',
        'deadline_override_reason',
        'allowed_file_types',
        'max_files',
        'requires_supervisor_approval',
    ];

    protected function casts(): array
    {
        return [
            'status'                    => MilestoneStatus::class,
            'allow_late_submission'     => 'boolean',
            'requires_supervisor_approval' => 'boolean',
            'allowed_file_types'        => 'array',
            'weight_percent'            => 'decimal:2',
            'opens_at'                  => 'date',
            'due_at'                    => 'date',
            'extended_until'            => 'datetime',
            'submitted_at'              => 'datetime',
            'reviewed_at'               => 'datetime',
            'approved_at'               => 'datetime',
            'sequence'                  => 'integer',
            'late_window_days'          => 'integer',
            'max_files'                 => 'integer',
            'revision_count'            => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(MilestoneTemplateItem::class, 'milestone_template_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class)->orderByDesc('revision_no');
    }

    /** Files that currently represent the submission (not superseded). */
    public function currentFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class)->where('is_current', true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubmissionEvent::class)->orderBy('created_at');
    }

    // -----------------------------------------------------------------
    // Deadline logic — the core of Module 6
    // -----------------------------------------------------------------

    /** The deadline actually in force, honouring any granted extension. */
    public function effectiveDueAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->extended_until) {
            return $this->extended_until->copy()->endOfDay();
        }

        return $this->due_at?->copy()->endOfDay();
    }

    public function isOverdue(): bool
    {
        $due = $this->effectiveDueAt();

        return $due !== null
            && $due->isPast()
            && ! $this->status->isProgressed()
            && $this->status !== MilestoneStatus::Approved;
    }

    /** Whole days remaining; negative when overdue. */
    public function daysUntilDue(): ?int
    {
        $due = $this->effectiveDueAt();

        return $due === null ? null : (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);
    }

    public function isDueWithinDays(int $days): bool
    {
        $remaining = $this->daysUntilDue();

        return $remaining !== null && $remaining >= 0 && $remaining <= $days;
    }

    /** Whether this milestone sits inside its late-submission window. */
    public function isWithinLateWindow(): bool
    {
        if (! $this->allow_late_submission || $this->due_at === null) {
            return false;
        }

        $windowEnd = $this->due_at->copy()->addDays($this->late_window_days)->endOfDay();

        return now()->isAfter($this->due_at->copy()->endOfDay())
            && now()->isBefore($windowEnd);
    }

    /** Can the student upload right now? */
    public function acceptsSubmission(): bool
    {
        if (! $this->status->isSubmittable()) {
            return false;
        }

        if ($this->isWithinLateWindow()) {
            return true;
        }

        $due = $this->effectiveDueAt();

        return $due === null || now()->isBefore($due);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeOpenForSubmission(Builder $query): Builder
    {
        return $query->whereIn('status', [
            MilestoneStatus::Open->value,
            MilestoneStatus::Rejected->value,
            MilestoneStatus::Overdue->value,
        ]);
    }

    /** Milestones whose deadline falls on a specific calendar day. */
    public function scopeDueOn(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('due_at', $date);
    }

    /**
     * Milestones due exactly N days from now — the wave the reminder job
     * targets. Uses COALESCE so a granted extension is respected.
     *
     * CAST(... AS date) rather than DATE(...): MySQL accepts both, but
     * PostgreSQL has no DATE() cast function, so DATE() would make the whole
     * reminder pipeline MySQL-only. The explicit CAST is standard SQL and
     * behaves identically on both.
     */
    public function scopeDueInDays(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotIn('status', [
                MilestoneStatus::Approved->value,
                MilestoneStatus::Submitted->value,
                MilestoneStatus::Reviewed->value,
            ])
            ->whereRaw(
                'CAST(COALESCE(extended_until, due_at) AS date) = ?',
                [now()->addDays($days)->toDateString()]
            );
    }

    /** Milestones that should now be flagged overdue. */
    public function scopeShouldBeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                MilestoneStatus::Open->value,
                MilestoneStatus::Pending->value,
            ])
            ->whereRaw(
                'CAST(COALESCE(extended_until, due_at) AS date) < ?',
                [now()->toDateString()]
            );
    }

    public function weightOrZero(): float
    {
        return (float) $this->weight_percent;
    }
}
