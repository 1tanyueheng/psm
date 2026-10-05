<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Models\AcademicSemester;
use App\Models\FinalGrade;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 5 — Reporting & analytics.
 *
 * Read-only. Every endpoint is gated on cohort-analytics permission, which
 * only coordinators and admins hold.
 *
 * Every endpoint accepts the same three filters — `semester_id`, `psm_part` and
 * the legacy cohort-year `batch` — because the requirement is that PSM 1 and
 * PSM 2 run concurrently in one term and every figure has to be attributable to
 * a term, a batch, or both. `semester_id` defaults to the active term; leaving
 * it off should mean "the term I am working in", never "every term since the
 * system was installed".
 */
class ReportController extends ApiController
{
    public function __construct(
        protected ReportingService $reports,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /api/reports/dashboard
     *
     * The single call the coordinator dashboard makes on load. Returns the
     * figures for the selected batch plus a `by_part` rollup so the PSM 1 /
     * PSM 2 segmented control can render both tabs from one response.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        return $this->ok($this->reports->dashboardSummary($batch, $psmPart, $semesterId));
    }

    /** GET /api/reports/cohort-progress */
    public function cohortProgress(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        return $this->ok($this->reports->cohortProgress($batch, $psmPart, $semesterId));
    }

    /**
     * GET /api/reports/at-risk
     *
     * Now batch-filterable as well as term-filterable: "how many PSM 1 students
     * are at risk" and "how many PSM 2 students are at risk" are different
     * questions with different remedies, and this endpoint previously could not
     * answer either one on its own.
     */
    public function atRisk(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        $validated = $request->validate([
            'batch'      => ['nullable', 'string', 'max:32'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:200'],
            'semester_id'=> ['nullable', 'integer', 'exists:academic_semesters,id'],
            'psm_part'   => ['nullable', 'in:PSM1,PSM2'],
        ]);

        return $this->ok(
            $this->reports->atRiskStudents(
                $validated['batch'] ?? null,
                $validated['limit'] ?? 25,
                $validated['psm_part'] ?? null,
                AcademicSemester::resolveFilterId($validated['semester_id'] ?? null),
            )
        );
    }

    /** GET /api/reports/supervisor-workload */
    public function supervisorWorkload(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        return $this->ok($this->reports->supervisorWorkload($batch, $psmPart, $semesterId));
    }

    /** GET /api/reports/examiner-workload */
    public function examinerWorkload(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        return $this->ok($this->reports->examinerWorkload($batch, $psmPart, $semesterId));
    }

    /** GET /api/reports/mark-distribution */
    public function markDistribution(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        return $this->ok($this->reports->markDistribution($batch, $psmPart, $semesterId));
    }

    /**
     * GET /api/reports/milestone-breakdown
     *
     * Per-milestone completion across the cohort — which stage is the
     * bottleneck. Built as a raw join rather than through Eloquent because it
     * groups on milestone columns, and the term filter is a join condition
     * rather than a `whereHas`.
     */
    public function milestoneBreakdown(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        $rows = DB::table('milestones')
            ->join('projects', 'projects.id', '=', 'milestones.project_id')
            ->when($semesterId !== null, fn ($q) => $q->where('projects.academic_semester_id', $semesterId))
            ->when($batch, fn ($q) => $q->where('projects.batch', $batch))
            // Null part means both batches, which is what the raw join needs:
            // the predicate is only added when a batch was actually asked for.
            ->when($psmPart !== null, fn ($q) => $q->where('projects.psm_part', $psmPart))
            ->select(
                'milestones.code',
                'milestones.title',
                'milestones.sequence',
                'milestones.weight_percent',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN milestones.status = 'approved' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN milestones.status = 'submitted' THEN 1 ELSE 0 END) as submitted"),
                DB::raw("SUM(CASE WHEN milestones.status IN ('overdue','rejected') THEN 1 ELSE 0 END) as problem"),
                DB::raw("SUM(CASE WHEN milestones.status = 'pending' THEN 1 ELSE 0 END) as not_started")
            )
            ->groupBy('milestones.code', 'milestones.title', 'milestones.sequence', 'milestones.weight_percent')
            ->orderBy('milestones.sequence')
            ->get()
            ->map(fn ($r) => [
                'code'     => $r->code,
                'title'    => $r->title,
                'sequence' => (int) $r->sequence,
                'weight'   => (float) $r->weight_percent,
                'total'    => (int) $r->total,
                'approved' => (int) $r->approved,
                'submitted'=> (int) $r->submitted,
                'problem'  => (int) $r->problem,
                'not_started' => (int) $r->not_started,
                'completion_percent' => $r->total > 0
                    ? round(($r->approved / $r->total) * 100, 1)
                    : 0.0,
            ]);

        return $this->ok($rows);
    }

    // -----------------------------------------------------------------
    // CSV exports
    // -----------------------------------------------------------------

    /**
     * GET /api/reports/export/grades.csv
     *
     * Streamed rather than buffered: a full cohort export can run to
     * thousands of rows and should not be held in memory.
     */
    public function exportMarks(Request $request): StreamedResponse
    {
        $this->authorize('export', FinalGrade::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        $this->audit->log(
            action: AuditAction::ReportExported,
            description: sprintf(
                'Grade report exported (term: %s, batch: %s, part: %s)',
                $semesterId ?? 'all',
                $batch ?: 'all',
                $psmPart ?? 'both'
            ),
        );

        $filename = sprintf(
            'psm-marks-%s-%s-%s.csv',
            $semesterId ?? 'all',
            $psmPart ?? 'both',
            now()->format('Ymd-His')
        );

        return response()->streamDownload(function () use ($batch, $psmPart, $semesterId) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Project Code', 'Title', 'Category', 'Student ID', 'Student Name',
                'Program', 'Batch', 'PSM Part', 'Supervisor Score', 'Examiner Score',
                'Aggregate %', 'Milestone %', 'Final Mark',
                'Assessors', 'Status', 'Released At',
            ]);

            FinalGrade::query()
                ->with(['project', 'studentProfile.user'])
                ->forSemesterPart($semesterId, $psmPart)
                ->when($batch, fn ($q) => $q->forBatch($batch))
                ->orderByDesc('final_mark')
                ->chunk(200, function ($grades) use ($out) {
                    foreach ($grades as $g) {
                        fputcsv($out, [
                            $g->project?->code,
                            $g->project?->title,
                            $g->project?->category?->label(),
                            $g->studentProfile?->student_id,
                            $g->studentProfile?->user?->name,
                            $g->studentProfile?->program,
                            $g->project?->batch,
                            $g->psm_part,
                            $g->supervisor_score,
                            $g->examiner_score,
                            $g->aggregate_percent,
                            $g->milestone_score,
                            $g->final_mark,
                            $g->assessor_count,
                            $g->status,
                            $g->released_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** GET /api/reports/export/projects.csv */
    public function exportProjects(Request $request): StreamedResponse
    {
        $this->authorize('export', User::class);

        [$batch, $psmPart, $semesterId] = $this->filters($request);

        $this->audit->log(
            action: AuditAction::ReportExported,
            description: sprintf(
                'Project report exported (term: %s, batch: %s, part: %s)',
                $semesterId ?? 'all',
                $batch ?: 'all',
                $psmPart ?? 'both'
            ),
        );

        $filename = sprintf(
            'psm-projects-%s-%s-%s.csv',
            $semesterId ?? 'all',
            $psmPart ?? 'both',
            now()->format('Ymd-His')
        );

        return response()->streamDownload(function () use ($batch, $psmPart, $semesterId) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Project Code', 'Title', 'Category', 'PSM Part', 'Batch', 'Program',
                'Status', 'Students', 'Supervisors', 'Progress %', 'Current Stage',
                'Next Deadline', 'Submitted At', 'Approved At',
            ]);

            Project::query()
                ->with(['students.user', 'students.activeSupervisions.supervisorProfile.user', 'milestones'])
                ->forSemesterPart($semesterId, $psmPart)
                ->when($batch, fn ($q) => $q->forBatch($batch))
                ->orderBy('code')
                ->chunk(200, function ($projects) use ($out) {
                    foreach ($projects as $p) {
                        fputcsv($out, [
                            $p->code,
                            $p->title,
                            $p->category->label(),
                            $p->psm_part,
                            $p->batch,
                            $p->program,
                            $p->status,
                            $p->students->map(fn ($s) => $s->user?->name.' ('.$s->student_id.')')->implode('; '),
                            $p->students->flatMap(fn ($s) => $s->activeSupervisions)
                                ->map(fn ($a) => $a->supervisorProfile?->label())
                                ->filter()->unique()->implode('; '),
                            $p->milestoneProgressPercent(),
                            $p->currentStageLabel(),
                            $p->currentMilestone()?->due_at?->toDateString(),
                            $p->submitted_at?->toDateTimeString(),
                            $p->approved_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Validate and resolve the three report filters, once.
     *
     * `psm_part` deliberately has no default. It previously defaulted to
     * `'PSM2'`, so a coordinator who opened the reports screen with no filters
     * was shown PSM 2 figures with nothing on screen indicating that the PSM 1
     * half of the cohort had been silently dropped. Null means "both batches",
     * which is the honest reading of an unfiltered report.
     *
     * @return array{0: ?string, 1: ?string, 2: ?int} batch, psm_part, semester_id
     */
    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'batch'       => ['nullable', 'string', 'max:32'],
            'psm_part'    => ['nullable', 'in:PSM1,PSM2'],
            'semester_id' => ['nullable', 'integer', 'exists:academic_semesters,id'],
        ]);

        return [
            $validated['batch'] ?? null,
            $validated['psm_part'] ?? null,
            AcademicSemester::resolveFilterId($validated['semester_id'] ?? null),
        ];
    }
}