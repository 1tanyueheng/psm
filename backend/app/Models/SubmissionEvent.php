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
        $who = $this->actor?->name ?? 'النظام';

        return match ($this->event) {
            self::EVENT_UPLOADED         => "{$who} رفع تسليمًا",
            self::EVENT_REPLACED         => "{$who} استبدل ملف التسليم",
            self::EVENT_REVIEWED         => "{$who} راجع التسليم",
            self::EVENT_APPROVED         => "{$who} اعتمد هذا المعلم",
            self::EVENT_REJECTED         => "{$who} طلب مراجعة",
            self::EVENT_DEADLINE_CHANGED => "{$who} غيّر الموعد النهائي",
            self::EVENT_OPENED           => 'فتح المعلم للتسليم',
            self::EVENT_COMMENTED        => "{$who} أضاف تعليقًا",
            default                      => "{$who} حدّث المعلم",
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
