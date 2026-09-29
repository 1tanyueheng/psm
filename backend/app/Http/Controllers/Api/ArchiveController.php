<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\ArchivedProjectResource;
use App\Http\Resources\AuditLogResource;
use App\Models\ArchivedProject;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 7 — Archive retrieval and audit trail browsing.
 *
 * Both are read-only here. Archiving happens through ProjectController; the
 * audit trail is never writable through the API.
 */
class ArchiveController extends ApiController
{
    public function __construct(
        protected ArchiveService $archive,
    ) {
    }

    // -----------------------------------------------------------------
    // Archive
    // -----------------------------------------------------------------

    /**
     * GET /api/archive
     *
     * Search past projects. Public records are visible to everyone who can
     * reach the archive; non-public ones require archive access rights.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ArchivedProject::class);

        $user = $request->user();
        $canSeeAll = $user->canAccessArchive();

        $term = $request->input('search');

        $paginator = ArchivedProject::query()
            ->when(! $canSeeAll, fn ($q) => $q->where('is_public', true))
            ->when($term, fn ($q) => $q->search($term))
            ->when($request->filled('session'), fn ($q) => $q->forSession($request->input('session')))
            ->when($request->filled('batch'), fn ($q) => $q->forBatch($request->input('batch')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->filled('psm_part'), fn ($q) => $q->where('psm_part', $request->input('psm_part')))
            ->orderByDesc('archived_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(
            $paginator,
            fn (ArchivedProject $r) => (new ArchivedProjectResource($r))->resolve($request)
        );
    }

    /**
     * GET /api/archive/{archived}
     */
    public function show(Request $request, ArchivedProject $archived): JsonResponse
    {
        $this->authorize('view', $archived);

        return $this->ok(new ArchivedProjectResource($archived));
    }

    /**
     * GET /api/archive/filters
     *
     * Available sessions and batches, so the search UI is driven by real data.
     */
    public function filters(): JsonResponse
    {
        $this->authorize('viewAny', ArchivedProject::class);

        return $this->ok([
            'sessions' => $this->archive->availableSessions(),
            'batches'  => ArchivedProject::query()
                ->select('batch')->distinct()->orderByDesc('batch')->pluck('batch'),
            'categories' => [
                ['value' => 'system',   'label' => 'System Development'],
                ['value' => 'research', 'label' => 'Research'],
            ],
        ]);
    }

    /**
     * POST /api/archive/{archived}/restore
     */
    public function restore(Request $request, ArchivedProject $archived): JsonResponse
    {
        $this->authorize('restore', ArchivedProject::class);

        try {
            $project = $this->archive->restore($archived, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created([
            'project_id' => $project->id,
            'code'       => $project->code,
        ], 'Project restored from the archive.');
    }

    /**
     * GET /api/archive/export.csv
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', ArchivedProject::class);

        $term = $request->input('search');

        return response()->streamDownload(function () use ($term) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Code', 'Title', 'Category', 'PSM Part', 'Session', 'Batch',
                'Students', 'Supervisors', 'Final Mark', 'Grade',
                'Documents', 'Public', 'Archived At',
            ]);

            ArchivedProject::query()
                ->when($term, fn ($q) => $q->search($term))
                ->orderByDesc('archived_at')
                ->chunk(200, function ($records) use ($out) {
                    foreach ($records as $r) {
                        fputcsv($out, [
                            $r->code,
                            $r->title,
                            $r->category,
                            $r->psm_part,
                            $r->academic_session,
                            $r->batch,
                            $r->studentList(),
                            $r->supervisorList(),
                            $r->final_mark,
                            $r->grade_letter,
                            $r->documentCount(),
                            $r->is_public ? 'yes' : 'no',
                            $r->archived_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, 'psm-archive-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -----------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------

    /**
     * GET /api/audit-logs
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorize('viewAuditLog', User::class);

        $paginator = AuditLog::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->input('search')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('category'), fn ($q) => $q->category($request->input('category')))
            ->when($request->filled('severity'), fn ($q) => $q->severity($request->input('severity')))
            ->when($request->filled('actor_id'), fn ($q) => $q->forActor($request->integer('actor_id')))
            ->when($request->boolean('suspicious_only'), fn ($q) => $q->suspicious())
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return $this->paginated(
            $paginator,
            fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request)
        );
    }

    /**
     * GET /api/audit-logs/filters
     */
    public function auditFilters(): JsonResponse
    {
        $this->authorize('viewAuditLog', User::class);

        return $this->ok([
            'actions'    => \App\Enums\AuditAction::options(),
            'categories' => [
                'Authentication', 'Accounts & Profiles', 'Projects & Milestones',
                'Evaluation & Grading', 'Reporting', 'Recognitions', 'Archive', 'Other',
            ],
            'severities' => ['info', 'warning', 'critical'],
        ]);
    }

    /**
     * GET /api/audit-logs/for/{type}/{id}
     *
     * The full history of one record — the "who touched this?" view.
     */
    public function auditForSubject(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorize('viewAuditLog', User::class);

        // Map a short type name to a model class, so the route stays readable
        $map = [
            'project'    => \App\Models\Project::class,
            'milestone'  => \App\Models\Milestone::class,
            'evaluation' => \App\Models\Evaluation::class,
            'grade'      => \App\Models\FinalGrade::class,
            'user'       => \App\Models\User::class,
            'archive'    => \App\Models\ArchivedProject::class,
            'leaderboard'=> \App\Models\Leaderboard::class,
        ];

        $class = $map[strtolower($type)] ?? null;

        if ($class === null) {
            return $this->fail('Unknown subject type.', 422);
        }

        $paginator = AuditLog::query()
            ->where('auditable_type', $class)
            ->where('auditable_id', $id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return $this->paginated(
            $paginator,
            fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request)
        );
    }

    /**
     * GET /api/audit-logs/me
     *
     * A user's own action history.
     */
    public function myAuditLog(Request $request): JsonResponse
    {
        $paginator = AuditLog::query()
            ->forActor($request->user())
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return $this->paginated(
            $paginator,
            fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request)
        );
    }
}
