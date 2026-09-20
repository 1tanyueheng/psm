<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 7 — Audit trail entry.
 *
 * @mixin \App\Models\AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Pre-rendered sentence, so the UI needs no action lookup table
            'sentence' => $this->sentence(),

            'action'        => $this->action instanceof \App\Enums\AuditAction
                ? $this->action->value
                : (string) $this->action,
            'action_label'  => $this->action instanceof \App\Enums\AuditAction
                ? $this->action->label()
                : null,
            'category' => $this->category,
            'severity' => $this->severity,

            'actor' => [
                'id'    => $this->user_id,
                'name'  => $this->actor_name,
                'role'  => $this->actor_role,
            ],

            'subject' => $this->auditable_type ? [
                'type'  => class_basename($this->auditable_type),
                'id'    => $this->auditable_id,
            ] : null,

            'description' => $this->description,

            // Field-level diff for the detail drawer
            'changes' => $this->when(
                $request->boolean('with_changes'),
                fn () => $this->changedFields()
            ),

            'context' => [
                'ip_address'     => $this->ip_address,
                'request_method' => $this->request_method,
                'request_url'    => $this->request_url,
                'user_agent'     => $this->user_agent,
            ],

            'is_suspicious' => (bool) $this->is_suspicious,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
