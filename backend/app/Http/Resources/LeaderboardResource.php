<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 8 — Leaderboard management payload (staff-only view).
 *
 * The *public* payload is built separately in LeaderboardService::publicPayload()
 * with an explicit whitelist, because this resource intentionally exposes
 * draft state and internal ids that must never reach the open internet.
 *
 * @mixin \App\Models\Leaderboard
 */
class LeaderboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'title'  => $this->title,
            'slug'   => $this->slug,
            'subtitle' => $this->subtitle,
            'description' => $this->description,

            'psm_part'         => $this->psm_part,
            'batch'            => $this->batch,
            'academic_session' => $this->academic_session,

            'top_n'          => $this->top_n,
            'min_assessors'  => $this->min_assessors,
            'ranking_basis'  => $this->ranking_basis,
            'tie_breaker'    => $this->tie_breaker,

            'status'         => $this->status,
            'is_published'   => $this->isPublished(),
            'published_at'   => $this->published_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),

            // Where the public page lives
            'public_path' => $this->publicPath(),
            'public_url'  => $this->publicUrl(),

            // Display options
            'show_abstract'      => (bool) $this->show_abstract,
            'show_scores'        => (bool) $this->show_scores,
            'show_student_names' => (bool) $this->show_student_names,
            'show_program'       => (bool) $this->show_program,
            'theme'              => $this->theme,

            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($e) => [
                'id'          => $e->id,
                'rank'        => $e->rank,
                'is_winner'   => (bool) $e->is_winner,
                'is_top_n'    => (bool) $e->is_top_n,
                'is_hidden'   => (bool) $e->is_hidden,
                'title'       => $e->project_title,
                'code'        => $e->project_code,
                'category'    => $e->category?->value,
                'program'     => $e->program,
                'students'    => $e->students,
                'supervisors' => $e->supervisors,
                'display_score' => $e->display_score !== null ? (float) $e->display_score : null,
                'score_label' => $e->score_label,
                'assessor_count' => $e->assessor_count,
                'award_title' => $e->award_title,
            ])),

            'entry_count' => $this->whenLoaded('entries', fn () => $this->entries->count()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
