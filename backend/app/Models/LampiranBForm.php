<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One filing of Lampiran B — the title proposal form.
 *
 * A project may have several: a refused title is re-presented by filing a new
 * form, and each carries its own title and its own supervisor signature. That
 * is the whole reason this is a table rather than a column — see
 * `thesis/PSM-LIFECYCLE-SPEC.md` §2 and §3.2.
 *
 * `projects.title` is **not** written by this model. It changes only when a
 * panel accepts a form, so a proposed title can never be mistaken for an agreed
 * one (R3).
 */
class LampiranBForm extends Model
{
    protected $table = 'lampiran_b_forms';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ENDORSED  = 'endorsed';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';

    protected $fillable = [
        'project_id',
        'revision_no',
        'title',
        'project_type',
        'field_study',
        'req_software',
        'req_hardware',
        'req_technology',
        'origin',
        'supervisor_signature_path',
        'supervisor_signature_disk',
        'supervisor_signed_at',
        'supervisor_profile_id',
        'signature_type',
        'status',
        'rejected_reason',
        'rejected_at',
        'rejected_by',
        'supersedes_id',
        'superseded_by_id',
        'milestone_id',
    ];

    protected function casts(): array
    {
        return [
            'revision_no'           => 'integer',
            'supervisor_signed_at'  => 'datetime',
            'rejected_at'           => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supervisorProfile(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** The filing this one replaces, when it is a re-presentation. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** The filing that replaced this one, once it has been superseded. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** The milestone this filing is recorded against, when it has one. */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** The form currently in play — the one nothing has replaced. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    // -----------------------------------------------------------------
    // Predicates
    // -----------------------------------------------------------------

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isSigned(): bool
    {
        return $this->supervisor_signed_at !== null;
    }

    /**
     * Is this a demo signature rather than a real approval?
     *
     * The seeded images carry a small DEMO mark, and this column says the same
     * in the database. Anything that prints a signature should be able to ask.
     */
    public function isSeededSignature(): bool
    {
        return $this->signature_type === 'seeded';
    }

    /** A label for the chain: "Lampiran B (2nd filing)". */
    public function label(): string
    {
        $suffix = match ($this->revision_no) {
            1 => '1st filing',
            2 => '2nd filing',
            3 => '3rd filing',
            default => "{$this->revision_no}th filing",
        };

        return "Lampiran B ({$suffix})";
    }

    /**
     * The next revision number for a project.
     *
     * Computed rather than stored as a counter so a deleted or withdrawn filing
     * cannot leave a gap that suggests one is missing.
     */
    public static function nextRevisionFor(int $projectId): int
    {
        return (int) static::forProject($projectId)->max('revision_no') + 1;
    }
}
