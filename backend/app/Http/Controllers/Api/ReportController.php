<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\ProjectCategory;
use App\Http\Controllers\ApiController;
use App\Models\Project;
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
     * The single call the coordinator dashboard makes on load.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        $validated = $request->validate([
            'batch'    => ['nullable', 'string', 'max:32'],
            'psm_part' => ['nullable', 'in:PSM1,PSM2'],
        ]);

        $batch   = $validated['batch'] ?? null;
        $psmPart = $validated['psm_part'] ?? 'PSM2';

        return $this->ok($this->reports->dashboardSummary($batch, $psmPart));
    }

    /**
     * GET /api/reports/cohort-progress
     */
    public function cohortProgress(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        $validated = $request->validate([
            'batch'    => ['nullable', 'string', 'max:32'],
            'psm_part' => ['nullable', 'in:PSM1,PSM2'],
        ]);

        return $this->ok(
            $this->reports->cohortProgress(
                $validated['batch'] ?? null,
                $validated['psm_part'] ?? 'PSM2'
            )
        );
    }

    /**
     * GET /api/reports/at-risk
     */
    public function atRisk(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        $validated = $request->validate([
            'batch' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->ok(
            $this->reports->atRiskStudents(
                $validated['batch'] ?? null,
                $validated['limit'] ?? 25
            )
        );
    }

    /**
     * GET /api/reports/supervisor-workload
     */
    public function supervisorWorkload(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        return $this->ok(
            $this->reports->supervisorWorkload($request->input('batch'))
        );
    }

    /**
     * GET /api/reports/examiner-workload
     */
    public function examinerWorkload(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        return $this->ok(
            $this->reports->examinerWorkload($request->input('batch'))
        );
    }

    /**
     * GET /api/reports/grade-distribution
     */
    public function gradeDistribution(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        $validated = $request->validate([
            'batch'    => ['nullable', 'string', 'max:32'],
            'psm_part' => ['nullable', 'in:PSM1,PSM2'],
        ]);

        return $this->ok(
            $this->reports->gradeDistribution(
                $validated['batch'] ?? null,
                $validated['psm_part'] ?? 'PSM2'
            )
        );
    }

    /**
     * GET /api/reports/milestone-breakdown
     *
     * Per-milestone completion across the cohort — which stage is the
     * bottleneck.
     */
    public function milestoneBreakdown(Request $request): JsonResponse
    {
        $this->authorize('viewAnalytics', \App\Models\User::class);

        $validated = $request->validate([
            'batch'    => ['nullable', 'string', 'max:32'],
            'psm_part' => ['nullable', 'in:PSM1,PSM2'],
        ]);

        $batch   = $validated['batch'] ?? null;
        $psmPart = $validated['psm_part'] ?? 'PSM2';

        $rows = DB::table('milestones')
            ->join('projects', 'projects.id', '=', 'milestones.project_id')
            ->when($batch, fn ($q) => $q->where('projects.batch', $batch))
            ->where('projects.psm_part', $psmPart)
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
    public function exportGrades(Request $request): StreamedResponse
    {
        $this->authorize('export', \App\Models\FinalGrade::class);

        $batch   = $request->input('batch');
        $psmPart = $request->input('psm_part', 'PSM2');

        $this->audit->log(
            action: AuditAction::ReportExported,
            description: "Grade report exported (batch: ".($batch ?: 'all').", {$psmPart})",
        );

        $filename = 'psm-grades-'.($batch ?: 'all').'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($batch, $psmPart) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Project Code', 'Title', 'Category', 'Student ID', 'Student Name',
                'Program', 'Batch', 'Supervisor Score', 'Examiner Score',
                'Aggregate %', 'Milestone %', 'Final Mark', 'Grade', 'Grade Point',
                'Assessors', 'Status', 'Released At',
            ]);

            \App\Models\FinalGrade::query()
                ->with(['project', 'studentProfile.user'])
                ->where('psm_part', $psmPart)
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
                            $g->supervisor_score,
                            $g->examiner_score,
                            $g->aggregate_percent,
                            $g->milestone_score,
                            $g->final_mark,
                            $g->grade_letter,
                            $g->grade_point,
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

    /**
     * GET /api/reports/export/projects.csv
     */
    public function exportProjects(Request $request): StreamedResponse
    {
        $this->authorize('export', \App\Models\User::class);

        $batch   = $request->input('batch');
        $psmPart = $request->input('psm_part', 'PSM2');

        $this->audit->log(
            action: AuditAction::ReportExported,
            description: "Project report exported (batch: ".($batch ?: 'all').", {$psmPart})",
        );

        $filename = 'psm-projects-'.($batch ?: 'all').'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($batch, $psmPart) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Project Code', 'Title', 'Category', 'PSM Part', 'Batch', 'Program',
                'Status', 'Students', 'Supervisors', 'Progress %', 'Current Stage',
                'Next Deadline', 'Submitted At', 'Approved At',
            ]);

            Project::query()
                ->with(['students.user', 'students.activeSupervisions.supervisorProfile.user', 'milestones'])
                ->where('psm_part', $psmPart)
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
}
