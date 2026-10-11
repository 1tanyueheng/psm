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
        'deadline_is_fixed',
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

        // Lampiran C — the corrections a conditional title approval requires.
        // Only ever set on the proposal milestone.
        'lampiran_c_title',
        'lampiran_c_actions',
        'lampiran_c_at',
        'lampiran_c_by',
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
            'deadline_is_fixed'         => 'boolean',
            'extended_until'            => 'datetime',
            'submitted_at'              => 'datetime',
            'reviewed_at'               => 'datetime',
            'approved_at'               => 'datetime',
            'sequence'                  => 'integer',
            'late_window_days'          => 'integer',
            'max_files'                 => 'integer',
            'revision_count'            => 'integer',
            'lampiran_c_actions'        => 'array',
            'lampiran_c_at'             => 'datetime',
        ];
    }

    /** The code the proposal milestone carries in every template. */
    public const CODE_PROPOSAL = 'proposal';

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

    /** The student who filed the Lampiran C form, on a conditional approval. */
    public function lampiranCBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lampiran_c_by');
    }

    /**
     * Is this the proposal milestone — the one that decides the title?
     *
     * The proposal is sequence 1 in every chain and is the only milestone whose
     * verdict is the panel's rather than the supervisor's; see
     * ProposalReviewService. Matched on `code` rather than `sequence` because
     * the code is what the template names it.
     */
    public function isProposal(): bool
    {
        return $this->code === self::CODE_PROPOSAL;
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

    /**
     * How long this milestone is meant to stay open, in days.
     *
     * Read from the template item, which is where the faculty's intent lives.
     * Null when the item is gone or recorded no duration — the caller then
     * leaves the deadline alone rather than inventing a window.
     */
    public function plannedDurationDays(): ?int
    {
        $duration = $this->templateItem?->duration_days;

        return $duration === null ? null : (int) $duration;
    }

    /**
     * The deadline this milestone should carry, given when it actually opened.
     *
     * Dates are computed once, at registration, from the template's planned
     * offsets — `due_at` is "planned open + duration". But the chain is
     * sequential: a milestone opens when its predecessor is approved, and if
     * that runs late the milestone opens late while keeping the due date it was
     * born with. The result was a milestone that opened already overdue — 49 of
     * 134 were in exactly that state.
     *
     * The same defect has a milder form: a milestone that opens late but still
     * before its planned deadline keeps that deadline, so its window is
     * silently truncated — 28 days becomes 14. The student is given less time
     * than the template promises, and nothing on any screen says so.
     *
     * The repair preserves the *window* rather than the calendar date, because
     * the window is what the template promises and what the student is assessed
     * against: due = actual open + duration. That one rule answers both forms.
     *
     * Deliberately conservative in four ways:
     *
     *   - Never shortens. A milestone opened ahead of plan keeps its original
     *     (later) deadline; the student is not punished for a predecessor being
     *     approved early, and re-running this is a no-op.
     *   - Never overrides a coordinator. `deadline_overridden_by` means a human
     *     set this date deliberately, with a recorded reason, and an automatic
     *     recomputation must not quietly undo it.
     *   - Never overrides an extension. `extended_until` is a granted
     *     concession, and it wins over any arithmetic here.
     *   - Never moves a **fixed** deadline. When the faculty has published a
     *     calendar for the term, `deadline_is_fixed` is set and the date is not
     *     relative to anything — pushing it out because a predecessor was
     *     approved late would silently overrule the faculty. This is the one
     *     case where an already-passed deadline is the correct answer, and the
     *     coordinator moves it deliberately if that is what the faculty decides.
     */
    public function repairedDueAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->opens_at === null || $this->due_at === null) {
            return $this->due_at;
        }

        if ($this->deadline_is_fixed
            || $this->deadline_overridden_by !== null
            || $this->extended_until !== null) {
            return $this->due_at;
        }

        $duration = $this->plannedDurationDays();

        if ($duration === null) {
            return $this->due_at;
        }

        $windowEnd = $this->opens_at->copy()->addDays($duration);

        // Only ever push a stale deadline out. An equal or earlier window end
        // means the milestone opened on time or early, so the original stands.
        return $windowEnd->isAfter($this->due_at) ? $windowEnd : $this->due_at;
    }

    public function isOverdue(): bool
    {
        $due = $this->effectiveDueAt();

        return $due !== null
            && $due->isPast()
            && ! $this->status->isProgressed()
            && ! $this->status->isSettled();
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
    // Upload constraints — one definition, shared by the API and the SPA
    // -----------------------------------------------------------------

    /**
     * The platform-wide allowlist, from config.
     *
     * Normalised to bare lowercase extensions so `PDF`, `.pdf` and `pdf` in
     * config all behave the same and can be compared against a milestone's
     * own list without surprises.
     *
     * @return array<int, string>
     */
    public static function platformAllowedExtensions(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($extension) => strtolower(ltrim(trim((string) $extension), '.')),
            (array) config('psm.submission.allowed_extensions', []),
        ))));
    }

    /**
     * The file types this milestone actually accepts.
     *
     * A milestone may *narrow* what the platform permits but never widen it,
     * so the effective rule is the intersection. The per-milestone
     * `allowed_file_types` used to be advisory only — the UI honoured it and
     * the API ignored it, so a `.zip` could be posted to a chapter that only
     * accepts documents. Enforcing it here closes that gap, and having one
     * method means the rule the client displays is the rule the server applies.
     *
     * @return array<int, string>
     */
    public function effectiveAllowedExtensions(): array
    {
        $platform = static::platformAllowedExtensions();

        $own = array_values(array_unique(array_filter(array_map(
            static fn ($extension) => strtolower(ltrim(trim((string) $extension), '.')),
            (array) ($this->allowed_file_types ?? []),
        ))));

        if ($own === []) {
            return $platform;
        }

        $allowed = array_values(array_intersect($own, $platform));

        // A milestone whose list names nothing the platform allows would
        // otherwise reject every upload with an error listing an empty set.
        // Falling back to the platform list keeps the milestone usable.
        return $allowed === [] ? $platform : $allowed;
    }

    /**
     * How many files one submission may carry.
     *
     * `max_files` is nullable in the schema, and the previous
     * `max(1, $milestone->max_files)` silently turned "no limit recorded" into
     * "exactly one file" — while the UI, reading the same null as falsy, said
     * "unlimited". The column default is 3, so this only bites on rows written
     * outside the normal path, but the two ends should agree either way.
     */
    public function effectiveMaxFiles(): int
    {
        return (int) ($this->max_files ?: config('psm.submission.default_max_files', 3));
    }

    /** Per-file ceiling in megabytes, as enforced by validation. */
    public function maxFileMegabytes(): int
    {
        return (int) config('psm.submission.max_mb', 25);
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
                MilestoneStatus::Conditional->value,
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

    // -----------------------------------------------------------------
    // Module 3 — progress contribution
    // -----------------------------------------------------------------

    /**
     * How complete this chapter is on its own terms, 0–100.
     *
     * Driven entirely by status: approved is complete, submitted and reviewed
     * are work-in-progress. A supervisor looking at Chapter 3 sees one figure
     * that answers "how far through is this chapter", independent of how much
     * of the project it represents.
     */
    public function completionPercent(): float
    {
        return round($this->status->progressFactor() * 100, 2);
    }

    /**
     * The percentage points this chapter contributes to the project's overall
     * progress figure.
     *
     * Summing these across a project's milestones reproduces
     * Project::milestoneProgressPercent() exactly, which is what lets the UI
     * show a chapter breakdown that adds up to the headline number.
     */
    public function progressContribution(): float
    {
        return round($this->weightOrZero() * $this->status->progressFactor(), 2);
    }
}
