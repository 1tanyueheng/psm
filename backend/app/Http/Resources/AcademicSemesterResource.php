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
             * means "marking for this term has finished" — read from the
             * submissions, which is what the mark list's Release button used to
             * gate on and what a filter badge actually wants to say.
             *
             * Always a boolean. The list endpoint counts locked submissions per
             * row so this is answerable without loading full cohort stats, which
             * only coordinators may see — a student's filter bar was reading a
             * null here and concluding the term had been withheld.
             */
            'is_marks_released'     => $this->marksAreComplete(),
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

    /**
     * Has this term's marking finished?
     *
     * Two sources, in order of preference:
     *
     *  1. the per-row `locked_submissions_count` the list endpoint attaches, or
     *     the full `stats.marks` figures when a coordinator loaded them — both
     *     answer it without a query per row;
     *  2. the stored column, kept only as a fallback for a term whose
     *     submissions were never counted. New terms never set it, so this is
     *     really for legacy rows.
     *
     * "Complete" means at least one submission is locked and every one of them
     * is. A term with no submissions at all reports false: nothing has been
     * marked, which is not the same as marking having finished.
     */
    protected function marksAreComplete(): bool
    {
        if ($this->resource->relationLoaded('stats')) {
            $marks = $this->stats['marks'] ?? null;

            if ($marks !== null) {
                return ($marks['total'] ?? 0) > 0 && ($marks['outstanding'] ?? 1) === 0;
            }
        }

        $locked = $this->resource->locked_submissions_count ?? null;
        $total  = $this->resource->mark_submissions_count ?? null;

        if ($locked !== null && $total !== null) {
            return (int) $total > 0 && (int) $locked === (int) $total;
        }

        return (bool) $this->is_marks_released;
    }
}