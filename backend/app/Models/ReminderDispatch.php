<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 6 — Ledger of reminders already sent.
 *
 * The unique index (milestone, user, days_before, type) is what makes the
 * nightly reminder scan idempotent: re-running it cannot double-send, which
 * is why the job can safely be retried.
 */
class ReminderDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'user_id',
        'days_before',
        'notification_type',
        'channel',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'days_before' => 'integer',
            'sent_at'     => 'datetime',
        ];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Has this exact reminder already gone out? */
    public static function alreadySent(
        int $milestoneId,
        int $userId,
        int $daysBefore,
        string $type
    ): bool {
        return static::where('milestone_id', $milestoneId)
            ->where('user_id', $userId)
            ->where('days_before', $daysBefore)
            ->where('notification_type', $type)
            ->exists();
    }
}
