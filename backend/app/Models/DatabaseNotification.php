<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * Module 6 — In-app notification with the domain conveniences the
 * notification centre needs.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    /** The NotificationType value stored in the payload, if any. */
    public function typeKey(): ?string
    {
        return $this->data['type_key'] ?? null;
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? 'Notification');
    }

    public function body(): string
    {
        return (string) ($this->data['body'] ?? '');
    }

    /** Where clicking the notification should navigate in the SPA. */
    public function actionUrl(): ?string
    {
        return $this->data['action_url'] ?? null;
    }

    public function icon(): string
    {
        return (string) ($this->data['icon'] ?? 'bell');
    }

    public function severity(): string
    {
        return (string) ($this->data['severity'] ?? 'info');
    }

    public function isUrgent(): bool
    {
        return (bool) ($this->data['urgent'] ?? false);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfType($query, string $typeKey)
    {
        return $query->where('data->type_key', $typeKey);
    }
}
