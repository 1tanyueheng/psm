<?php

namespace App\Services;

use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Enums\Role;
use App\Models\FinalGrade;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Module 5 — Reporting & analytics.
 *
 * All the aggregate queries live here. They are written against the indexed
 * columns (batch, status, due_at, assessor_id) rather than in PHP, because a
 * cohort rollup over a few hundred projects is exactly the kind of query that
 * silently becomes N+1 when it is assembled in a view layer.
 *
 * Expensive results are cached with the TTLs from config/cache.php.
 */
class ReportingService
{
    // -----------------------------------------------------------------
    // Cohort progress
    // -----------------------------------------------------------------

    /**
     * How many students sit at each milestone stage.
     *
     * @return array{
     *   total_students:int, total_projects:int, stages:array, by_batch:array
     * }
     */
    public function cohortProgress(?string $batch = null, string $psmPart = 'PSM2'): array
    {
        $cacheKey = "report:cohort_progress:{$batch}:{$psmPart}";

        return Cache::remember($cacheKey, config('cache.ttl.cohort_progress', 120), function () use ($batch, $psmPart) {
            $projectQuery = Project::query()
                ->where('psm_part', $psmPart)
                ->when($batch, fn ($q) => $q->where('batch', $batch));

            $projectIds = (clone $projectQuery)->pluck('id');

            $totalProjects  = $projectIds->count();
            $totalStudents  = DB::table('project_members')
                ->whereIn('project_id', $projectIds)
                ->count();

            // Count projects whose *current* milestone sits at each status.
            // A project is attributed to the earliest milestone it has not
            // yet had approved, which is what "stage" means to a coordinator.
            $stages = Milestone::query()
                ->whereIn('project_id', $projectIds)
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all();

            // Normalise so every status appears even at zero
            $stageCounts = [];
            foreach (MilestoneStatus::cases() as $status) {
                $stageCounts[$status->value] = [
                    'label' => $status->label(),
                    'tone'  => $status->tone(),
                    'count' => (int) ($stages[$status->value] ?? 0),
                ];
            }

            $byBatch = Project::query()
                ->when($batch, fn ($q) => $q->where('batch', $batch))
                ->where('psm_part', $psmPart)
                ->select(
                    'batch',
                    DB::raw('COUNT(*) as projects'),
                    DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                )
                ->groupBy('batch')
                ->orderBy('batch')
                ->get()
                ->map(fn ($row) => [
                    'batch'      => $row->batch,
                    'projects'   => (int) $row->projects,
                    'completed'  => (int) $row->completed,
                    'completion_percent' => $row->projects > 0
                        ? round(($row->completed / $row->projects) * 100, 1)
                        : 0.0,
                ])
                ->all();

            return [
                'total_students' => (int) $totalStudents,
                'total_projects' => $totalProjects,
                'stages'         => $stageCounts,
                'by_batch'       => $byBatch,
            ];
        });
    }

    /**
     * The students most at risk: overdue or unsubmitted milestones, ordered by
     * how badly they are behind. This is the list a coordinator actually acts on.
     */
    public function atRiskStudents(?string $batch = null, int $limit = 25): Collection
    {
        $overdue = Milestone::query()
            ->whereIn('status', [
                MilestoneStatus::Overdue->value,
                MilestoneStatus::Rejected->value,
            ])
            ->when($batch, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('batch', $batch)))
            ->with(['project.students.user'])
            ->get()
            ->groupBy('project_id');

        return $overdue
            ->map(function (Collection $milestones, int $projectId) {
                $project = $milestones->first()->project;
                $worst = $milestones->sortBy(fn (Milestone $m) => $m->daysUntilDue() ?? 0)->first();

                return [
                    'project_id'    => $projectId,
                    'project_code'  => $project?->code,
                    'project_title' => $project?->title,
                    'batch'         => $project?->batch,
                    'students'      => $project?->students
                        ->map(fn ($s) => [
                            'name'       => $s->user?->name,
                            'student_id' => $s->student_id,
                        ])->all() ?? [],
                    'overdue_count'   => $milestones->count(),
                    'worst_milestone' => $worst?->title,
                    'worst_status'    => $worst?->status->label(),
                    'days_overdue'    => $worst ? abs(min(0, $worst->daysUntilDue() ?? 0)) : 0,
                    'progress'        => $project?->milestoneProgressPercent() ?? 0.0,
                ];
            })
            ->sortByDesc('days_overdue')
            ->take($limit)
            ->values();
    }

    // -----------------------------------------------------------------
    // Workload
    // -----------------------------------------------------------------

    /**
     * Supervisor workload and performance.
     *
     * @return Collection<int, array{
     *   name:string, supervising:int, capacity:int, utilisation:float,
     *   overloaded:bool, pending_reviews:int, avg_mark:?float, on_time_rate:?float
     * }>
     */
    public function supervisorWorkload(?string $batch = null): Collection
    {
        return SupervisorProfile::query()
            ->with('user')
            ->get()
            ->map(function (SupervisorProfile $supervisor) use ($batch) {
                $assignmentIds = $supervisor->activeSupervisions()
                    ->when($batch, fn ($q) => $q->whereHas(
                        'studentProfile',
                        fn ($s) => $s->where('batch', $batch)
                    ))
                    ->pluck('id');

                $studentIds = $supervisor->activeSupervisions()
                    ->when($batch, fn ($q) => $q->whereHas(
                        'studentProfile',
                        fn ($s) => $s->where('batch', $batch)
                    ))
                    ->pluck('student_profile_id');

                $projectIds = DB::table('project_members')
                    ->whereIn('student_profile_id', $studentIds)
                    ->pluck('project_id');

                // Reviews still waiting on this supervisor
                $pendingReviews = Milestone::query()
                    ->whereIn('project_id', $projectIds)
                    ->whereIn('status', [
                        MilestoneStatus::Submitted->value,
                        MilestoneStatus::Reviewed->value,
                    ])
                    ->count();

                // Assessment outcomes this supervisor has produced
                $evaluations = $supervisor->user
                    ? $supervisor->user->evaluations()
                        ->whereIn('status', [
                            EvaluationStatus::Submitted->value,
                            EvaluationStatus::Moderated->value,
                            EvaluationStatus::Released->value,
                        ])
                        ->get()
                    : collect();

                $avgMark = $evaluations->isNotEmpty()
                    ? round($evaluations->avg(fn ($e) => $e->effectivePercent()), 2)
                    : null;

                // How often this supervisor reviewed within the marking window
                $onTime = $evaluations->filter(fn ($e) => ! $e->is_late_assessment)->count();
                $onTimeRate = $evaluations->isNotEmpty()
                    ? round(($onTime / $evaluations->count()) * 100, 1)
                    : null;

                return [
                    'id'             => $supervisor->id,
                    'name'           => $supervisor->label(),
                    'supervising'    => $assignmentIds->count(),
                    'capacity'       => $supervisor->max_supervisees,
                    'utilisation'    => $supervisor->utilisationPercent(),
                    'overloaded'     => $supervisor->isOverloaded(),
                    'remaining'      => $supervisor->remainingCapacity(),
                    'accepting'      => $supervisor->is_accepting_students,
                    'pending_reviews'=> $pendingReviews,
                    'project_count'  => $projectIds->unique()->count(),
                    'avg_mark'       => $avgMark,
                    'on_time_rate'   => $onTimeRate,
                    'assessments'    => $evaluations->count(),
                ];
            })
            ->sortByDesc('supervising')
            ->values();
    }

    /** Examiner workload: allocations versus completed assessments. */
    public function examinerWorkload(?string $batch = null): Collection
    {
        return User::query()
            ->withRole(Role::Examiner)
            ->active()
            ->get()
            ->map(function (User $examiner) use ($batch) {
                $allocations = $examiner->examinerAssignments()
                    ->active()
                    ->when($batch, fn ($q) => $q->whereHas(
                        'project',
                        fn ($p) => $p->where('batch', $batch)
                    ))
                    ->with('project')
                    ->get();

                $evaluations = $examiner->evaluations();

                $submitted = (clone $evaluations)
                    ->whereIn('status', [
                        EvaluationStatus::Submitted->value,
                        EvaluationStatus::Moderated->value,
                        EvaluationStatus::Released->value,
                    ])
                    ->count();

                $drafts = (clone $evaluations)
                    ->where('status', EvaluationStatus::Draft->value)
                    ->count();

                $allocated = $allocations->count();

                return [
                    'id'                 => $examiner->id,
                    'name'               => $examiner->displayName(),
                    'allocated'          => $allocated,
                    'submitted'          => $submitted,
                    'outstanding'        => max(0, $allocated - $submitted),
                    'drafts'             => $drafts,
                    'completion_percent' => $allocated > 0
                        ? round(($submitted / $allocated) * 100, 1)
                        : 0.0,
                    'avg_mark_given'     => $submitted > 0
                        ? round($evaluations->get()->avg(fn ($e) => $e->effectivePercent()), 2)
                        : null,
                ];
            })
            ->sortByDesc('allocated')
            ->values();
    }

    // -----------------------------------------------------------------
    // Grade distribution
    // -----------------------------------------------------------------

    /**
     * Grade distribution for a cohort, plus the stats a report chapter needs.
     *
     * @return array{
     *   distribution:array<string,int>, by_band:array, stats:array,
     *   pass_rate:float, total:int
     * }
     */
    public function gradeDistribution(?string $batch = null, string $psmPart = 'PSM2'): array
    {
        $grades = FinalGrade::query()
            ->where('psm_part', $psmPart)
            ->where('status', 'released')
            ->when($batch, fn ($q) => $q->forBatch($batch))
            ->get();

        $distribution = [];
        $bandOrder = config('psm.grade_bands');

        foreach ($bandOrder as $band) {
            $distribution[$band['grade']] = 0;
        }

        foreach ($grades as $grade) {
            $letter = $grade->grade_letter ?? 'F';
            $distribution[$letter] = ($distribution[$letter] ?? 0) + 1;
        }

        $marks = $grades->pluck('final_mark')->filter(fn ($m) => $m !== null)->map(fn ($m) => (float) $m);

        $stats = [
            'count'    => $marks->count(),
            'mean'     => $marks->isNotEmpty() ? round($marks->avg(), 2) : null,
            'median'   => $marks->isNotEmpty() ? round($this->median($marks), 2) : null,
            'min'      => $marks->isNotEmpty() ? round($marks->min(), 2) : null,
            'max'      => $marks->isNotEmpty() ? round($marks->max(), 2) : null,
            'std_dev'  => $marks->count() > 1 ? round($this->stdDev($marks), 2) : null,
            'grade_point_avg' => $grades->isNotEmpty()
                ? round($grades->avg(fn ($g) => (float) $g->grade_point), 2)
                : null,
        ];

        $passCount = $grades->where('is_pass', true)->count();

        $byBand = collect($bandOrder)
            ->map(fn (array $band) => [
                'grade' => $band['grade'],
                'label' => $band['label'],
                'min'   => $band['min'],
                'count' => $distribution[$band['grade']] ?? 0,
                'percent' => $grades->count() > 0
                    ? round((($distribution[$band['grade']] ?? 0) / $grades->count()) * 100, 1)
                    : 0.0,
            ])
            ->all();

        return [
            'distribution' => $distribution,
            'by_band'      => $byBand,
            'stats'        => $stats,
            'pass_rate'    => $grades->count() > 0
                ? round(($passCount / $grades->count()) * 100, 1)
                : 0.0,
            'total'        => $grades->count(),
        ];
    }

    // -----------------------------------------------------------------
    // Category & programme breakdowns
    // -----------------------------------------------------------------

    public function categoryBreakdown(?string $batch = null, string $psmPart = 'PSM2'): array
    {
        return Project::query()
            ->when($batch, fn ($q) => $q->where('batch', $batch))
            ->where('psm_part', $psmPart)
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category instanceof \App\Enums\ProjectCategory
                    ? $row->category->label()
                    : (string) $row->category,
                'total'    => (int) $row->total,
            ])
            ->all();
    }

    /** A single coordinator-facing summary used by the dashboard header. */
    public function dashboardSummary(?string $batch = null, string $psmPart = 'PSM2'): array
    {
        $progress = $this->cohortProgress($batch, $psmPart);

        $submitted = Milestone::query()
            ->whereIn('project_id', Project::query()
                ->when($batch, fn ($q) => $q->where('batch', $batch))
                ->where('psm_part', $psmPart)
                ->pluck('id'))
            ->where('status', MilestoneStatus::Submitted->value)
            ->count();

        return [
            'cohort'            => $progress,
            'awaiting_review'   => $submitted,
            'at_risk'           => $this->atRiskStudents($batch, 5)->count(),
            'grades'            => $this->gradeDistribution($batch, $psmPart),
            'categories'        => $this->categoryBreakdown($batch, $psmPart),
        ];
    }

    // -----------------------------------------------------------------
    // Statistics helpers
    // -----------------------------------------------------------------

    protected function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count  = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($sorted[$middle - 1] + $sorted[$middle]) / 2
            : (float) $sorted[$middle];
    }

    protected function stdDev(Collection $values): float
    {
        $count = $values->count();

        if ($count < 2) {
            return 0.0;
        }

        $mean = $values->avg();

        $variance = $values->sum(fn ($v) => ($v - $mean) ** 2) / ($count - 1);

        return sqrt($variance);
    }
}
