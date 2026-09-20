<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\ReminderDispatch;
use App\Models\User;
use App\Notifications\PsmNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Module 6 — The single fan-out point for all notifications.
 *
 * Responsibilities:
 *   1. Decide *who* gets a notification (respecting role + preferences)
 *   2. Decide *through which channels* (mail / in-app)
 *   3. De-duplicate reminder waves so a retried job cannot double-send
 *
 * Nothing else in the app calls Notification::send() directly.
 */
class NotificationDispatcher
{
    /**
     * Send a notification to one or more users.
     *
     * @param  iterable<User>        $recipients
     * @param  NotificationType      $type
     * @param  array{title:string, body:string, action_url?:string, urgent?:bool, meta?:array}  $payload
     */
    public function notify(
        iterable $recipients,
        NotificationType $type,
        array $payload,
        ?Model $subject = null,
    ): int {
        $sent = 0;

        foreach ($this->normalise($recipients) as $user) {
            if (! $this->shouldReceive($user, $type)) {
                continue;
            }

            $channels = $user->channelsFor($type);

            if ($channels === []) {
                continue;
            }

            try {
                // Suppress framework exceptions so one bad address cannot stop
                // a whole reminder wave. Failures are logged for follow-up.
                $user->notifyNow(new PsmNotification($type, $payload, $subject, $channels));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Notification dispatch failed', [
                    'user_id'  => $user->id,
                    'type'     => $type->value,
                    'channels' => $channels,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Send a messaging-level notification to many users in one batch.
     * Prefer this for class-wide announcements; it issues a single mail BCC
     * rather than N separate sends.
     */
    public function notifyMany(
        iterable $recipients,
        NotificationType $type,
        array $payload,
        ?Model $subject = null,
    ): int {
        $eligible = $this->normalise($recipients)
            ->filter(fn (User $u) => $this->shouldReceive($u, $type) && $u->channelsFor($type) !== []);

        if ($eligible->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($eligible, new PsmNotification($type, $payload, $subject));
        } catch (Throwable $e) {
            Log::warning('Bulk notification failed', [
                'type'  => $type->value,
                'count' => $eligible->count(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        return $eligible->count();
    }

    /**
     * Send a deadline reminder, recording it in the ledger so the same wave
     * cannot be sent twice even if the scheduled command is retried.
     */
    public function sendReminder(
        User $user,
        Model $subject,
        int $daysBefore,
        NotificationType $type,
        array $payload,
    ): bool {
        $subjectId = $subject->getKey();

        if (ReminderDispatch::alreadySent($subjectId, $user->id, $daysBefore, $type->value)) {
            return false;
        }

        $sent = $this->notify([$user], $type, $payload, $subject);

        if ($sent > 0) {
            ReminderDispatch::create([
                'milestone_id'      => $subjectId,
                'user_id'           => $user->id,
                'days_before'       => $daysBefore,
                'notification_type' => $type->value,
                'channel'           => implode(',', $user->channelsFor($type)),
                'sent_at'           => now(),
            ]);
        }

        return $sent > 0;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * A user should receive a notification if:
     *   - their account is active (never wake a suspended account's mailbox)
     *   - they have not opted out of this type
     */
    protected function shouldReceive(User $user, NotificationType $type): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->wantsNotification($type);
    }

    /** @return EloquentCollection<int, User> */
    protected function normalise(iterable $recipients): EloquentCollection
    {
        if ($recipients instanceof EloquentCollection) {
            return $recipients->filter()->unique('id')->values();
        }

        $users = collect($recipients)
            ->filter(fn ($u) => $u instanceof User)
            ->unique('id')
            ->values();

        return new EloquentCollection($users->all());
    }
}
