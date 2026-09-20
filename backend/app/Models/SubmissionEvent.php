<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 3 / 7 — Append-only narrative history of a milestone.
 *
 * Where `audit_logs` answers "what did this *user* do", this answers
 * "what happened to this *milestone*" — the timeline the student and
 * supervisor both see on the milestone detail page.
 */
class SubmissionEvent extends Model
{
    use HasFactory;

    public const EVENT_UPLOADED        = 'uploaded';
    public const EVENT_REPLACED        = 'replaced';
    public const EVENT_REVIEWED        = 'reviewed';
    public const EVENT_APPROVED        = 'approved';
    public const EVENT_REJECTED        = 'rejected';
    public const EVENT_DEADLINE_CHANGED= 'deadline_changed';
    public const EVENT_OPENED          = 'opened';
    public const EVENT_COMMENTED       = 'commented';

    protected $fillable = [
        'milestone_id',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'comment',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** A sentence the timeline can render without further logic. */
    public function describe(): string
    {
        $who = $this->actor?->name ?? 'System';

        return match ($this->event) {
            self::EVENT_UPLOADED         => "{$who} uploaded a submission",
            self::EVENT_REPLACED         => "{$who} replaced the submission file",
            self::EVENT_REVIEWED         => "{$who} reviewed the submission",
            self::EVENT_APPROVED         => "{$who} approved this milestone",
            self::EVENT_REJECTED         => "{$who} requested a revision",
            self::EVENT_DEADLINE_CHANGED => "{$who} changed the deadline",
            self::EVENT_OPENED           => 'Milestone opened for submission',
            self::EVENT_COMMENTED        => "{$who} added a comment",
            default                      => "{$who} updated the milestone",
        };
    }

    public function iconName(): string
    {
        return match ($this->event) {
            self::EVENT_UPLOADED, self::EVENT_REPLACED => 'upload',
            self::EVENT_REVIEWED  => 'eye',
            self::EVENT_APPROVED  => 'check-circle',
            self::EVENT_REJECTED  => 'alert-triangle',
            self::EVENT_DEADLINE_CHANGED => 'calendar',
            self::EVENT_OPENED    => 'play-circle',
            default               => 'message-circle',
        };
    }
}
