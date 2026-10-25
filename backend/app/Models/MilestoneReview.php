<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 3 — one round of Lampiran R1 review against a milestone submission.
 *
 * The student submits a chapter; the supervisor responds on the official R1
 * form; if improvement is asked for, the student revises and the cycle repeats,
 * each turn being a row here. See the create migration for why this is not a
 * milestone of its own.
 *
 * Reading a row:
 *
 *   ack_received   the supervisor has received the item and is satisfied with
 *                  it — "menerima item projek pelajar ... untuk semakan"
 *   ack_reviewed   the supervisor has reviewed it and wants improvement —
 *                  "menyemak item projek pelajar ... untuk penambahbaikan"
 *
 * Both ticked reads as *improvement*: asking for improvement necessarily
 * implies having received the work, so `verdict` records the stronger of the
 * two rather than trusting the reader to notice.
 *
 * The scores are evidential. They are stored and printed, and they are
 * deliberately absent from every grade calculation — see `isEvidenceOnly()`.
 */
class MilestoneReview extends Model
{
    use HasFactory;

    /** Received, and nothing further owed: the milestone settles. */
    public const VERDICT_APPROVED = 'approved';

    /** Reviewed for improvement: the student revises and the round repeats. */
    public const VERDICT_IMPROVEMENT = 'improvement';

    protected $fillable = [
        'milestone_id',
        'round_no',
        'revision_no',
        'reviewer_id',
        'reviewer_name',
        'reviewer_role',
        'ack_received',
        'ack_reviewed',
        'verdict',
        'declaration',
        'scores',
        'total_score',
        'max_score',
        'comments',
        'signature_path',
        'signature_disk',
        'signature_type',
        'signature_mime_type',
        'signature_size_bytes',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'ack_received'          => 'boolean',
            'ack_reviewed'          => 'boolean',
            'declaration'           => 'array',
            'scores'                => 'array',
            'total_score'           => 'decimal:2',
            'max_score'             => 'decimal:2',
            'signed_at'             => 'datetime',
            'round_no'              => 'integer',
            'revision_no'           => 'integer',
            'signature_size_bytes'  => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    // -----------------------------------------------------------------
    // The verdict
    // -----------------------------------------------------------------

    /**
     * The round's outcome, derived from the two printed checkboxes.
     *
     * Returns null when neither box is ticked — an R1 that declares nothing is
     * not a verdict, and the service refuses to record one. Kept as the single
     * place the rule lives: the controller validates against it, the service
     * stores it, and the screen reads it back.
     */
    public static function verdictFor(bool $ackReceived, bool $ackReviewed): ?string
    {
        // Improvement is the stronger claim: a supervisor cannot ask for
        // improvement without having received the work, so the tick stands on
        // its own and wins even when both are ticked.
        if ($ackReviewed) {
            return self::VERDICT_IMPROVEMENT;
        }

        if ($ackReceived) {
            return self::VERDICT_APPROVED;
        }

        return null;
    }

    public function isApproved(): bool
    {
        return $this->verdict === self::VERDICT_APPROVED;
    }

    public function isImprovement(): bool
    {
        return $this->verdict === self::VERDICT_IMPROVEMENT;
    }

    /** Does this round send the milestone back to the student? */
    public function repeats(): bool
    {
        return $this->isImprovement();
    }

    /** The verdict in the words the screen shows. */
    public function verdictLabel(): ?string
    {
        return match ($this->verdict) {
            self::VERDICT_APPROVED    => 'Received — settled',
            self::VERDICT_IMPROVEMENT => 'Improvement requested',
            default                   => null,
        };
    }

    /**
     * These scores never enter a grade.
     *
     * Stated as code rather than only as a comment so that a future change which
     * reaches for `MilestoneReview::scores` while computing `FinalGrade` has
     * something to trip over. The graded forms are E, G, H, I and J; a chapter
     * reviewed five times must not out-weigh one reviewed once.
     */
    public function isEvidenceOnly(): bool
    {
        return true;
    }

    public function hasSignature(): bool
    {
        return filled($this->signature_path);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Newest round first, which is the order the screen reads them in. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('round_no')->orderByDesc('id');
    }

    public function scopeForMilestone(Builder $query, int $milestoneId): Builder
    {
        return $query->where('milestone_id', $milestoneId);
    }
}
