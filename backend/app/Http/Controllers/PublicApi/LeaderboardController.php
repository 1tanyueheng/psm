<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\PublicApiController;
use App\Services\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 8 — The public, no-login recognition page.
 *
 * IMPORTANT: this controller is reachable without authentication. It reads
 * only from published leaderboard snapshots, through an explicit whitelist in
 * LeaderboardService::publicPayload(). It must never:
 *   - touch a live project, user, or grade row directly
 *   - return an email address, phone number, or student ID beyond the public copy
 *   - expose a draft or unpublished board
 *
 * The route is registered outside the auth middleware group and rate-limited
 * in routes/api.php.
 */
class LeaderboardController extends PublicApiController
{
    public function __construct(
        protected LeaderboardService $leaderboards,
    ) {
    }

    /**
     * GET /api/public/leaderboard
     *
     * The current published board. Returns 404 when nothing is published, so
     * the SPA can render a neutral "results not yet announced" state.
     */
    public function current(Request $request): JsonResponse
    {
        $payload = $this->leaderboards->publicPayload();

        if ($payload === null) {
            return $this->fail('No results have been published yet.', 404);
        }

        return $this->ok($payload);
    }

    /**
     * GET /api/public/leaderboard/{slug}
     *
     * A specific published board — the stable URL a winner can be linked to
     * after the ceremony, and which keeps working next semester.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $payload = $this->leaderboards->publicPayload($slug);

        if ($payload === null) {
            return $this->fail('That leaderboard could not be found or is no longer published.', 404);
        }

        return $this->ok($payload);
    }

    /**
     * GET /api/public/leaderboard/archive
     *
     * List of past published boards, so the public page can offer a history
     * view ("PSM2 Showcase 2024/25", "2023/24", ...).
     */
    public function archive(Request $request): JsonResponse
    {
        $boards = \App\Models\Leaderboard::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['slug', 'title', 'subtitle', 'batch', 'academic_session', 'psm_part', 'published_at'])
            ->map(fn ($b) => [
                'slug'         => $b->slug,
                'title'        => $b->title,
                'subtitle'     => $b->subtitle,
                'batch'        => $b->batch,
                'session'      => $b->academic_session,
                'psm_part'     => $b->psm_part,
                'published_at' => $b->published_at?->toIso8601String(),
            ]);

        return $this->ok($boards);
    }

    /**
     * GET /api/public/leaderboard/{slug}/entry/{rank}
     *
     * A single entry, for a shareable "winner card" link.
     */
    public function entry(Request $request, string $slug, int $rank): JsonResponse
    {
        $leaderboard = \App\Models\Leaderboard::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        if ($leaderboard === null) {
            return $this->fail('That leaderboard could not be found.', 404);
        }

        $entry = $leaderboard->visibleEntries()->where('rank', $rank)->first();

        if ($entry === null) {
            return $this->fail('No entry at that rank.', 404);
        }

        return $this->ok([
            'rank'       => $entry->rank,
            'rank_label' => $entry->rank.$entry->rankSuffix(),
            'medal'      => $entry->medal(),
            'title'      => $entry->project_title,
            'code'       => $leaderboard->show_scores ? $entry->project_code : null,
            'abstract'   => $leaderboard->show_abstract ? $entry->project_abstract : null,
            'category'   => $entry->category?->label(),
            'program'    => $leaderboard->show_program ? $entry->program : null,
            'students'   => $leaderboard->show_student_names ? $entry->publicStudents() : [],
            'supervisors'=> $entry->publicSupervisors(),
            'score'      => $leaderboard->show_scores ? (float) $entry->display_score : null,
            'score_label'=> $entry->score_label,
            'award'      => $entry->award_title,
            'citation'   => $entry->citation,
            'poster_path'=> $entry->poster_path,
            'leaderboard'=> [
                'title'        => $leaderboard->title,
                'slug'         => $leaderboard->slug,
                'batch'        => $leaderboard->batch,
                'session'      => $leaderboard->academic_session,
                'published_at' => $leaderboard->published_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/public/leaderboard/status
     *
     * Whether the module is live. Lets the public page avoid rendering a
     * broken shell when the module has been switched off.
     */
    public function status(): JsonResponse
    {
        $enabled = \App\Models\LeaderboardSetting::isPubliclyAvailable();

        return $this->ok([
            'module_enabled' => $enabled,
            'has_published'  => $enabled
                ? \App\Models\Leaderboard::published()->exists()
                : false,
        ]);
    }
}
