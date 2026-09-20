<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 / 7 — One entry on a milestone's timeline.
 *
 * Ships a pre-rendered `description` and `icon` so the React timeline is a
 * pure presentation component with no domain logic of its own.
 *
 * @mixin \App\Models\SubmissionEvent
 */
class SubmissionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'event'   => $this->event,

            // Presentation, decided server-side
            'description' => $this->describe(),
            'icon'        => $this->iconName(),

            'from_status' => $this->from_status,
            'to_status'   => $this->to_status,
            'comment'     => $this->comment,

            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id'   => $this->actor->id,
                'name' => $this->actor->name,
                'role' => $this->actor->role->value,
            ] : null),

            'payload'    => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
