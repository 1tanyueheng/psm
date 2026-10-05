<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 — the assessment window.
 *
 * The coordinator's open/close control over marking. While a window is open,
 * the supervisor and the panel may file Lampiran E and I (PSM 1) or G, H and J
 * (PSM 2) for any student in the batch; once it closes, they may read what they
 * filed but not change it.
 *
 * Two-part gate — the coordinator's `status` plus the published time window — so
 * the coordinator controls it deliberately and the published dates still bind.
 *
 * Scoped by (term, psm_part). A term holds both batches and their forms differ,
 * so one window cannot cover both.
 */
class AssessmentWindow extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_OPEN      = 'open';
    public const STATUS_CLOSED    = 'closed';

    protected $fillable = [
        'name',
        'academic_session',
        'academic_semester_id',
        'psm_part',
        'scheduled_start_at',
        'scheduled_end_at',
        'status',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at'   => 'datetime',
            'opened_at'          => 'datetime',
            'closed_at'          => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * May marks be filed right now?
     *
     * Open *and* inside the published window. The coordinator controls `status`;
     * the window is what the faculty published. A window left open past its end
     * stops accepting marks rather than silently running on, but this only gates
     * writes and never rewrites the status.
     */
    public function acceptsMarks(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if ($this->scheduled_end_at !== null && now()->isAfter($this->scheduled_end_at)) {
            return false;
        }

        return true;
    }

    /**
     * A short human description of where the window stands.
     *
     * Distinguishes "open but out of time" from "closed", because the two look
     * identical to a reader and mean different things to whoever has to fix it.
     */
    public function stateLabel(): string
    {
        if ($this->isClosed()) {
            return 'Closed';
        }

        if (! $this->isOpen()) {
            return 'Not started';
        }

        return $this->acceptsMarks() ? 'Open for marking' : 'Open — past its end time';
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** The window governing one batch of one term, if there is one. */
    public function scopeForSemesterPart(
        Builder $query,
        int|AcademicSemester|null $semester,
        ?string $psmPart
    ): Builder {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $query
            ->when($id !== null, fn ($q) => $q->where('academic_semester_id', $id))
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The window that governs a project, or null when marking is ungated.
     *
     * Null is a real answer: a batch that has never had a window keeps the old
     * behaviour rather than being frozen out. Once a window exists it is
     * authoritative.
     */
    public static function governing(Project $project): ?self
    {
        if ($project->academic_semester_id === null) {
            return null;
        }

        return static::query()
            ->forSemesterPart($project->academic_semester_id, $project->psm_part)
            ->first();
    }
}
