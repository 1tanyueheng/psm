<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 6 — In-app notification.
 *
 * @mixin \App\Models\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'type'  => $this->typeKey(),

            'title'      => $this->title(),
            'body'       => $this->body(),
            'action_url' => $this->actionUrl(),
            'icon'       => $this->icon(),
            'severity'   => $this->severity(),
            'urgent'     => $this->isUrgent(),

            'is_read'  => ! $this->isUnread(),
            'read_at'  => $this->read_at?->toIso8601String(),

            'meta'       => $this->data['meta'] ?? null,
            'subject'    => $this->data['subject'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
