<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Module 6 — One notification class for every domain event.
 *
 * Rather than a class per event (which would be ~17 near-identical files),
 * the NotificationType enum carries the presentation metadata and this class
 * adapts it to the mail and database channels.
 */
class PsmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{title:string, body:string, action_url?:string, urgent?:bool, meta?:array}  $payload
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public NotificationType $type,
        public array $payload,
        public ?Model $subject = null,
        public array $channels = [],
    ) {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if ($this->channels !== []) {
            return $this->channels;
        }

        return $this->type->defaultChannels();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actionUrl = $this->absoluteActionUrl();

        $mail = (new MailMessage())
            ->subject($this->payload['title'] ?? $this->type->label())
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($this->payload['body'] ?? '');

        if ($this->payload['urgent'] ?? $this->type->isUrgent()) {
            $mail->line('This needs your attention soon.');
        }

        if ($actionUrl) {
            $mail->action('Open in PSM System', $actionUrl);
        }

        return $mail->salutation('— PSM Management System');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type_key'   => $this->type->value,
            'title'      => $this->payload['title'] ?? $this->type->label(),
            'body'       => $this->payload['body'] ?? '',
            'action_url' => $this->payload['action_url'] ?? null,
            'icon'       => $this->type->icon(),
            'severity'   => $this->severity(),
            'urgent'     => (bool) ($this->payload['urgent'] ?? $this->type->isUrgent()),
            'subject'    => $this->subject ? [
                'type' => class_basename($this->subject),
                'id'   => $this->subject->getKey(),
            ] : null,
            'meta'       => $this->payload['meta'] ?? null,
        ];
    }

    public function severity(): string
    {
        if ($this->payload['urgent'] ?? $this->type->isUrgent()) {
            return 'critical';
        }

        return match ($this->type) {
            NotificationType::DeadlineMissed,
            NotificationType::RevisionRequested,
            NotificationType::CapacityWarning => 'warning',
            default => 'info',
        };
    }

    /**
     * The SPA lives on a different origin from the API, so relative paths
     * from the API must be prefixed before they reach an inbox.
     */
    protected function absoluteActionUrl(): ?string
    {
        $path = $this->payload['action_url'] ?? null;

        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
