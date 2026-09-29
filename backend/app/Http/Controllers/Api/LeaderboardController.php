<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\ProjectCategory;
use App\Http\Controllers\ApiController;
use App\Http\Resources\LeaderboardResource;
use App\Models\Leaderboard;
use App\Models\LeaderboardEntry;
use App\Models\LeaderboardSetting;
use App\Services\AuditLogger;
use App\Services\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 8 — Staff-facing leaderboard management.
 *
 * The public/read-only counterpart lives in PublicApi\LeaderboardController.
 */
class LeaderboardController extends ApiController
{
    public function __construct(
        protected LeaderboardService $leaderboards,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/leaderboards
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Leaderboard::class);

        $paginator = Leaderboard::query()
            ->withCount('entries')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('psm_part'), fn ($q) => $q->forPart($request->input('psm_part')))
            ->when($request->filled('batch'), fn ($q) => $q->where('batch', $request->input('batch')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(
            $paginator,
            fn (Leaderboard $l) => (new LeaderboardResource($l))->resolve($request)
        );
    }

    /**
     * GET /api/leaderboards/{leaderboard}
     */
    public function show(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('view', $leaderboard);

        return $this->ok(
            new LeaderboardResource($leaderboard->load(['entries.project', 'createdBy', 'publishedBy']))
        );
    }

    /**
     * POST /api/leaderboards
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Leaderboard::class);

        $settings = LeaderboardSetting::current();

        $validated = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'subtitle'         => ['nullable', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:3000'],
            'psm_part'         => ['sometimes', Rule::in(['PSM1', 'PSM2', 'BOTH'])],
            'batch'            => ['nullable', 'string', 'max:32'],
            'academic_session' => ['nullable', 'string', 'max:32'],
            'top_n'            => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_assessors'    => ['sometimes', 'integer', 'min:1', 'max:10'],
            'ranking_basis'    => ['sometimes', Rule::in(['final_mark', 'aggregate_percent', 'milestone_score'])],
            'tie_breaker'      => ['sometimes', Rule::in(['assessor_count', 'supervisor_score', 'submission_time'])],
            'show_abstract'    => ['sometimes', 'boolean'],
            'show_scores'      => ['sometimes', 'boolean'],
            'show_student_names' => ['sometimes', 'boolean'],
            'show_program'     => ['sometimes', 'boolean'],
            'theme'            => ['nullable', 'string', 'max:32'],
        ]);

        $leaderboard = Leaderboard::create($validated + [
            'top_n'          => $validated['top_n'] ?? $settings->default_top_n,
            'min_assessors'  => $validated['min_assessors'] ?? $settings->default_min_assessors,
            'ranking_basis'  => $validated['ranking_basis'] ?? $settings->default_ranking_basis,
            'status'         => 'draft',
            'created_by'     => $request->user()->id,
        ]);

        $this->audit->log(
            action: AuditAction::LeaderboardConfigChanged,
            description: "Created leaderboard '{$leaderboard->title}'",
            subject: $leaderboard,
            actor: $request->user(),
        );

        return $this->created(new LeaderboardResource($leaderboard), 'Leaderboard created as a draft.');
    }

    /**
     * PATCH /api/leaderboards/{leaderboard}
     */
    public function update(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('update', $leaderboard);

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'subtitle'    => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'top_n'       => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_assessors' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'ranking_basis' => ['sometimes', Rule::in(['final_mark', 'aggregate_percent', 'milestone_score'])],
            'tie_breaker'   => ['sometimes', Rule::in(['assessor_count', 'supervisor_score', 'submission_time'])],
            'batch'         => ['nullable', 'string', 'max:32'],
            'academic_session' => ['nullable', 'string', 'max:32'],
            'show_abstract' => ['sometimes', 'boolean'],
            'show_scores'   => ['sometimes', 'boolean'],
            'show_student_names' => ['sometimes', 'boolean'],
            'show_program'  => ['sometimes', 'boolean'],
            'theme'         => ['nullable', 'string', 'max:32'],
        ]);

        $before = $leaderboard->getAttributes();

        $leaderboard->update($validated);

        $this->audit->log(
            action: AuditAction::LeaderboardConfigChanged,
            description: 'Leaderboard settings updated',
            subject: $leaderboard,
            before: $before,
            after: $leaderboard->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok(new LeaderboardResource($leaderboard->fresh()), 'Leaderboard updated.');
    }

    /**
     * POST /api/leaderboards/{leaderboard}/build
     *
     * Ranks eligible projects into the draft. Previewable before publishing.
     */
    public function build(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('build', $leaderboard);

        try {
            $leaderboard = $this->leaderboards->build($leaderboard, $request->only([
                'batch', 'academic_session',
            ]));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new LeaderboardResource($leaderboard->load('entries')),
            $leaderboard->entries->count().' entries ranked. Review them, then publish.'
        );
    }

    /**
     * POST /api/leaderboards/{leaderboard}/publish
     */
    public function publish(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('publish', $leaderboard);

        try {
            $published = $this->leaderboards->publish($leaderboard, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok([
            'leaderboard' => new LeaderboardResource($published->load('entries')),
            'public_url'  => $published->publicUrl(),
        ], 'Leaderboard published. It is now publicly accessible.');
    }

    /**
     * POST /api/leaderboards/{leaderboard}/unpublish
     */
    public function unpublish(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('unpublish', $leaderboard);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $unpublished = $this->leaderboards->unpublish(
            $leaderboard,
            $request->user(),
            $validated['reason'] ?? null
        );

        return $this->ok(
            new LeaderboardResource($unpublished),
            'Leaderboard withdrawn from public view.'
        );
    }

    /**
     * PATCH /api/leaderboards/{leaderboard}/entries/{entry}
     *
     * Per-entry presentation: award title, citation, or hide the entry.
     */
    public function updateEntry(Request $request, Leaderboard $leaderboard, LeaderboardEntry $entry): JsonResponse
    {
        $this->authorize('manageEntries', $leaderboard);

        if ($entry->leaderboard_id !== $leaderboard->id) {
            return $this->fail('That entry does not belong to this leaderboard.', 422);
        }

        $validated = $request->validate([
            'award_title' => ['nullable', 'string', 'max:128'],
            'citation'    => ['nullable', 'string', 'max:2000'],
            'poster_path' => ['nullable', 'string', 'max:255'],
            'is_hidden'   => ['sometimes', 'boolean'],
            'is_top_n'    => ['sometimes', 'boolean'],
        ]);

        $entry->update($validated);

        return $this->ok([
            'id'          => $entry->id,
            'rank'        => $entry->rank,
            'award_title' => $entry->award_title,
            'is_hidden'   => (bool) $entry->is_hidden,
            'is_top_n'    => (bool) $entry->is_top_n,
        ], 'Entry updated.');
    }

    /**
     * GET /api/leaderboards/preview/{slug}
     *
     * Renders exactly what the public page will show, but for a draft — so a
     * coordinator can check names and abstracts before going live.
     */
    public function preview(Request $request, string $slug): JsonResponse
    {
        $leaderboard = Leaderboard::where('slug', $slug)->firstOrFail();

        $this->authorize('view', $leaderboard);

        return $this->ok([
            'leaderboard' => new LeaderboardResource($leaderboard->load('entries')),
            // The exact payload the public endpoint will serve
            'public_payload' => $this->leaderboards->publicPayload($slug),
        ]);
    }

    /**
     * DELETE /api/leaderboards/{leaderboard}
     */
    public function destroy(Request $request, Leaderboard $leaderboard): JsonResponse
    {
        $this->authorize('delete', $leaderboard);

        $title = $leaderboard->title;
        $leaderboard->delete();

        $this->audit->log(
            action: AuditAction::LeaderboardUnpublished,
            description: "Deleted leaderboard '{$title}'",
        );

        return $this->ok(null, 'Leaderboard deleted.');
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    /**
     * GET /api/leaderboards/settings
     */
    public function settings(): JsonResponse
    {
        return $this->ok(LeaderboardSetting::current());
    }

    /**
     * PUT /api/leaderboards/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', Leaderboard::class);

        $validated = $request->validate([
            'default_top_n'          => ['sometimes', 'integer', 'min:1', 'max:50'],
            'default_min_assessors'  => ['sometimes', 'integer', 'min:1', 'max:10'],
            'default_ranking_basis'  => ['sometimes', Rule::in(['final_mark', 'aggregate_percent', 'milestone_score'])],
            'module_enabled'         => ['sometimes', 'boolean'],
            'require_approval'       => ['sometimes', 'boolean'],
            'honour_opt_out'         => ['sometimes', 'boolean'],
        ]);

        $settings = LeaderboardSetting::current();
        $before = $settings->getAttributes();

        $settings->update($validated + ['updated_by' => $request->user()->id]);

        $this->audit->log(
            action: AuditAction::LeaderboardConfigChanged,
            description: 'Recognition module settings updated',
            subject: $settings,
            before: $before,
            after: $settings->fresh()->getAttributes(),
            actor: $request->user(),
        );

        return $this->ok($settings->fresh(), 'Settings saved.');
    }

    /**
     * GET /api/leaderboards/eligible
     *
     * Which projects would qualify right now, and why others would not — the
     * diagnostic a coordinator needs before publishing.
     */
    public function eligible(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Leaderboard::class);

        $minAssessors = (int) $request->input('min_assessors', config('psm.leaderboard.min_assessors', 2));

        $grades = \App\Models\FinalGrade::query()
            ->with(['project', 'studentProfile.user'])
            ->where('status', 'released')
            ->orderByDesc('final_mark')
            ->get();

        $eligible = [];
        $excluded = [];

        foreach ($grades as $grade) {
            $reasons = [];

            if ($grade->project === null) {
                $reasons[] = 'Project record missing';
            } else {
                if (! $grade->is_publishable) {
                    $reasons[] = 'Grade is not marked publishable';
                }
                if ($grade->project->leaderboard_opt_out) {
                    $reasons[] = 'Student opted out';
                }
                if ($grade->project->status !== 'completed') {
                    $reasons[] = 'Project is not completed';
                }
            }

            if ($grade->assessor_count < $minAssessors) {
                $reasons[] = "Only {$grade->assessor_count} assessor(s), {$minAssessors} required";
            }

            $row = [
                'grade_id'     => $grade->id,
                'project_code' => $grade->project?->code,
                'title'        => $grade->project?->title,
                'student'      => $grade->studentProfile?->user?->name,
                'final_mark'   => (float) $grade->final_mark,
                'assessor_count' => $grade->assessor_count,
                'reasons'      => $reasons,
            ];

            if ($reasons === []) {
                $eligible[] = $row;
            } else {
                $excluded[] = $row;
            }
        }

        return $this->ok([
            'eligible_count' => count($eligible),
            'eligible'       => $eligible,
            'excluded_count' => count($excluded),
            'excluded'       => $excluded,
        ]);
    }
}
