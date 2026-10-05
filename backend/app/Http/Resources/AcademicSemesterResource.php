<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 — Academic semester payload.
 *
 * Carries enough for the filter dropdown (id, name, whether it is the active
 * term) without a second round trip, and exposes the flags so a student can be
 * told *why* registration is closed rather than just that it is.
 *
 * The per-batch figures are attached only when `stats` has been loaded, since
 * counting a whole cohort is not something every caller of the term list should
 * pay for — see SemesterService::stats() and SemesterPolicy::viewStats().
 *
 * @mixin \App\Models\AcademicSemester
 */
class AcademicSemesterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isActive = $this->resource->is_active;

        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'academic_session'      => $this->academic_session,
            'semester_number'       => $this->semester_number,
            'semester_label'        => $this->semesterLabel(),
            'short_name'            => $this->shortName(),

            'starts_at'             => $this->starts_at?->toDateString(),
            'ends_at'               => $this->ends_at?->toDateString(),

            'is_active'             => $isActive,
            'is_registration_open'  => (bool) $this->is_registration_open,

            /**
             * Derived, not stored.
             *
             * The `is_marks_released` column still exists but nothing writes or
             * reads it: a mark publishes itself the moment its supervisor form
             * arrives, so there is no term-level release left to record. The
             * flag is echoed here so existing screens keep rendering, and it now
             * means "some mark in this term is readable", read from the grades
             * themselves.
             *
             * Null when stats were not loaded — the caller then has no
             * permission to see cohort figures, and this is a cohort figure.
             */
            'is_marks_released'     => $this->relationLoaded('stats')
                ? (bool) ($this->stats['marks']['marks_released'] ?? false)
                : null,
            'marks'                 => $this->when(
                $this->relationLoaded('stats'),
                fn () => $this->stats['marks'] ?? null
            ),

            // The gate, resolved. A student hitting a closed registration window
            // needs the reason, not a boolean they cannot act on.
            'registration_open'     => $this->resource->isRegistrationOpen(),
            'is_closed'             => $this->resource->isClosed(),
            'has_ended'             => $this->resource->hasEnded(),

            'marks_released_at'    => $this->marks_released_at?->toIso8601String(),
            'registration_opened_at'=> $this->registration_opened_at?->toIso8601String(),
            'closed_at'             => $this->closed_at?->toIso8601String(),
            'coordinator_id'        => $this->resource->coordinatorId(),
            'metadata'              => $this->metadata,

            'stats' => $this->when(
                $this->relationLoaded('stats'),
                fn () => $this->stats
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}