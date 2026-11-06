<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 3 — one Lampiran R1 review against a milestone submission.
 *
 * The student submits a chapter; the supervisor files the official R1 form
 * **once**, ticking both declarations, and the milestone settles. One form per
 * chapter is the faculty's practice, so the normal case is a single row here.
 * `VERDICT_IMPROVEMENT` is retained for the stored history of rounds recorded
 * before the both-ticked rule was corrected, and for the send-back the
 * supervisor's own review route still supports; it is no longer reachable from
 * the R1 form.
 *
 * Reading a row:
 *
 *   ack_received   "menerima item projek pelajar ... untuk semakan"
 *   ack_reviewed   "menyemak item projek pelajar ... untuk penambahbaikan"
 *
 * Both ticked is the endorsement and reads as *approved*. A row with only one
 * box ticked cannot be created by the current service — see `verdictFor()`.
 *
 * The scores are evidential. They are stored and printed, and they are
 * deliberately absent from every grade calculation — see `isEvidenceOnly()`.
 */
class MilestoneReview extends Model
{
    use HasFactory;

    /** Received, and nothing further owed: the milestone settles. */
    public const VERDICT_APPROVED = 'approved';

    /**
     * Reviewed for improvement: the student revises and the cycle repeats.
     *
     * Not produced by the R1 form any more — both boxes now mean approval. Kept
     * because rows already stored carry this value, and because the separate
     * review route can still send work back.
     */
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
     * **Both boxes are ticked, or the form is not a verdict.** The faculty's
     * practice is that a supervisor files Lampiran R1 **once** per chapter and
     * endorses it by ticking *both* declarations — "menerima … untuk semakan"
     * and "menyemak … untuk penambahbaikan" are the two halves of one signed
     * endorsement, not a choice between accept and reject. There is no
     * revision round in this use case: a chapter is filed, endorsed once, and
     * settled.
     *
     * This reverses the earlier rule, where `ack_reviewed` won outright so
     * that ticking both sent the chapter **back for improvement** — the exact
     * opposite of what the supervisor meant, and the reason chapters could sit
     * in a revision loop that the faculty does not run.
     *
     * A single tick returns null and is refused, rather than being read as half
     * an approval: the screen shows a red prompt asking for both boxes, because
     * an R1 half-filled is an endorsement nobody signed.
     */
    public static function verdictFor(bool $ackReceived, bool $ackReviewed): ?string
    {
        if ($ackReceived && $ackReviewed) {
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
            self::VERDICT_APPROVED    => 'Received and reviewed — endorsed',
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
