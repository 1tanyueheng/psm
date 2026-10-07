<?php

namespace App\Services;

use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Enums\PsmPart;
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
 * Every report is scoped by two things before it is scoped by anything else:
 *
 *   $semesterId — which academic term. PSM 1 and PSM 2 run concurrently in one
 *                 term, so a report that does not name a term cannot say
 *                 whether it is describing this cohort or last year's.
 *   $psmPart    — which batch. Null means both, which is what a term-level
 *                 report wants; naming one is what the coordinator's PSM 1 /
 *                 PSM 2 tabs want.
 *
 * Both default to null rather than to a hardcoded batch. The previous signature
 * defaulted `$psmPart` to `'PSM2'`, which meant a coordinator who opened the
 * reports screen with no filters was silently looking at PSM 2 only — and the
 * PSM 1 half of the cohort was simply absent from every figure with nothing on
 * screen to say so.
 *
 * Expensive results are cached with the TTLs from config/cache.php, keyed on
 * both filters so one batch's numbers can never be served for the other.
 */
class ReportingService
{
    // -----------------------------------------------------------------
    // Cohort progress
    // -----------------------------------------------------------------

    /**
     * How many students sit at each milestone stage.
     *
     * Returns the total plus a `by_part` breakdown so PSM 1 and PSM 2 can be
     * read side by side (acceptance criterion #8) without the caller issuing
     * two requests and stitching them together.
     *
     * @return array{
     *   total_students:int, total_projects:int, stages:array, by_batch:array,
     *   by_part:array
     * }
     */
    public function cohortProgress(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): array {
        $cacheKey = sprintf(
            'report:cohort_progress:%s:%s:%s',
            $batch ?? 'all',
            $psmPart ?? 'both',
            $semesterId ?? 'all'
        );

        return Cache::remember($cacheKey, config('cache.ttl.cohort_progress', 120), function () use ($batch, $psmPart, $semesterId) {
            $projectQuery = Project::query()
                ->forSemester($semesterId)
                ->when($psmPart, fn ($q) => $q->forPart($psmPart))
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
                ->forSemester($semesterId)
                ->when($psmPart, fn ($q) => $q->forPart($psmPart))
                ->when($batch, fn ($q) => $q->where('batch', $batch))
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

                // One row per batch, always keyed by both PSM1 and PSM2 even at
                // zero, so the report renders both columns without a null check
                // and an empty batch reads as an explicit 0 rather than a gap.
                'by_part'        => $this->cohortByPart($batch, $semesterId),
            ];
        });
    }

    /**
     * The same rollup, one row per PSM batch.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function cohortByPart(?string $batch, ?int $semesterId): array
    {
        $rows = Project::query()
            ->forSemester($semesterId)
            ->when($batch, fn ($q) => $q->where('batch', $batch))
            ->select(
                'psm_part',
                DB::raw('COUNT(*) as projects'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            )
            ->groupBy('psm_part')
            ->get()
            ->keyBy('psm_part');

        $out = [];

        foreach (PsmPart::deliverables() as $part) {
            $row = $rows->get($part->value);
            $projects = (int) ($row->projects ?? 0);

            $out[$part->value] = [
                'label'             => $part->label(),
                'projects'          => $projects,
                'completed'         => (int) ($row->completed ?? 0),
                'in_progress'       => (int) ($row->in_progress ?? 0),
                'completion_percent'=> $projects > 0
                    ? round(((int) $row->completed / $projects) * 100, 1)
                    : 0.0,
            ];
        }

        return $out;
    }

    /**
     * The students most at risk: overdue or unsubmitted milestones, ordered by
     * how badly they are behind. This is the list a coordinator actually acts on.
     *
     * Filterable by term and batch for the same reason as everything else here:
     * "how many PSM 2 students are at risk" and "how many PSM 1 students are at
     * risk" are different questions with different remedies, and this method
     * previously could not tell them apart.
     */
    public function atRiskStudents(
        ?string $batch = null,
        int $limit = 25,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): Collection {
        $overdue = Milestone::query()
            ->whereIn('status', [
                MilestoneStatus::Overdue->value,
                MilestoneStatus::Rejected->value,
            ])
            ->whereHas('project', function ($p) use ($batch, $psmPart, $semesterId) {
                $p->when($semesterId !== null, fn ($q) => $q->forSemester($semesterId))
                    ->when($psmPart !== null, fn ($q) => $q->forPart($psmPart))
                    ->when($batch, fn ($q) => $q->forBatch($batch));
            })
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
                    'psm_part'      => $project?->psm_part,
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
     * `supervising`, `capacity` and `capacity_by_part` all respect the term
     * filter, because a supervisor carrying five PSM 1 students this term and
     * five last term is not carrying ten: last term's students are done. The
     * per-part figures are what requirement §7.1 asks a coordinator to read —
     * "2/5 PSM 1, 3/5 PSM 2" — rather than a single flat number that hides which
     * batch is full.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function supervisorWorkload(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): Collection {
        return SupervisorProfile::query()
            ->with('user')
            ->get()
            ->map(function (SupervisorProfile $supervisor) use ($batch, $psmPart, $semesterId) {
                $assignmentIds = $supervisor->activeSupervisions()
                    ->when($batch || $semesterId !== null, fn ($q) => $q->whereHas(
                        'studentProfile',
                        function ($s) use ($batch, $semesterId) {
                            // Term is reached through the student's live project,
                            // since a supervision row carries no term of its own.
                            $s->when($semesterId !== null, fn ($sq) => $sq->whereHas(
                                'projects',
                                fn ($pq) => $pq->forSemester($semesterId)
                            ));
                            if ($batch !== null) {
                                $s->where('batch', $batch);
                            }
                        }
                    ))
                    ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
                    ->pluck('id');

                $studentIds = $supervisor->activeSupervisions()
                    ->when($batch || $semesterId !== null, fn ($q) => $q->whereHas(
                        'studentProfile',
                        function ($s) use ($batch, $semesterId) {
                            $s->when($semesterId !== null, fn ($sq) => $sq->whereHas(
                                'projects',
                                fn ($pq) => $pq->forSemester($semesterId)
                            ));
                            if ($batch !== null) {
                                $s->where('batch', $batch);
                            }
                        }
                    ))
                    ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
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
                        ->when($semesterId !== null, fn ($q) => $q->whereHas(
                            'project',
                            fn ($p) => $p->forSemester($semesterId)
                        ))
                        ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart))
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
                    'capacity'       => $semesterId === null
                        ? $supervisor->max_supervisees
                        : array_sum($supervisor->capacityByPart()),
                    'capacity_by_part' => $supervisor->capacityByPart(),
                    'load_by_part'     => $semesterId === null
                        ? $supervisor->currentLoadByPart()
                        : $supervisor->currentLoadByPartInSemester($semesterId),
                    'utilisation'    => $semesterId === null
                        ? $supervisor->utilisationPercent()
                        : $supervisor->utilisationPercentInSemester($semesterId),
                    'overloaded'     => $semesterId === null
                        ? $supervisor->isOverloaded()
                        : $supervisor->isOverloadedInSemester($semesterId),
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

    /**
     * Panel workload: allocations versus completed assessments.
     *
     * Covers **academic staff** — there is no examiner role, and the pool a
     * panel is drawn from is the same people who supervise. This is the
     * examining counterpart to `supervisorWorkload()`: one lists supervision
     * load, the other panel load, and a person appears in both.
     */
    public function examinerWorkload(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): Collection {
        return User::query()
            ->withRole(Role::Supervisor)
            ->active()
            ->get()
            ->map(function (User $examiner) use ($batch, $psmPart, $semesterId) {
                $allocations = $examiner->examinerAssignments()
                    ->active()
                    ->when($batch || $semesterId !== null || $psmPart !== null, fn ($q) => $q->whereHas(
                        'project',
                        function ($p) use ($batch, $semesterId, $psmPart) {
                            $p->when($semesterId !== null, fn ($sq) => $sq->forSemester($semesterId))
                                ->when($psmPart !== null, fn ($sq) => $sq->forPart($psmPart))
                                ->when($batch !== null, fn ($sq) => $sq->forBatch($batch));
                        }
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
    // Mark distribution
    // -----------------------------------------------------------------

    /**
     * Distribution of released marks for a cohort, plus the stats a report
     * chapter needs.
     *
     * Buckets are fixed mark ranges rather than letter grades. The system
     * releases a mark and that mark is not on a 0-100 scale, so banding it into
     * A/B/C would invent a grade. Ranges are descriptive, not evaluative.
     *
     * @return array{distribution:array<string,int>, by_range:array, stats:array, total:int}
     */
    public function markDistribution(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): array {
        $grades = FinalGrade::query()
            ->forSemesterPart($semesterId, $psmPart)
            ->where('status', 'released')
            ->when($batch, fn ($q) => $q->forBatch($batch))
            ->get();

        $ranges = [
            ['key' => '80+',   'label' => '80 and above', 'min' => 80, 'max' => null],
            ['key' => '70-79', 'label' => '70 - 79',      'min' => 70, 'max' => 79.99],
            ['key' => '60-69', 'label' => '60 - 69',      'min' => 60, 'max' => 69.99],
            ['key' => '50-59', 'label' => '50 - 59',      'min' => 50, 'max' => 59.99],
            ['key' => '40-49', 'label' => '40 - 49',      'min' => 40, 'max' => 49.99],
            ['key' => '0-39',  'label' => 'Below 40',     'min' => 0,  'max' => 39.99],
        ];

        $distribution = collect($ranges)->mapWithKeys(fn ($r) => [$r['key'] => 0])->all();

        foreach ($grades as $grade) {
            if ($grade->final_mark === null) {
                continue;
            }

            $mark = (float) $grade->final_mark;

            foreach ($ranges as $range) {
                if ($mark >= $range['min'] && ($range['max'] === null || $mark <= $range['max'])) {
                    $distribution[$range['key']]++;
                    break;
                }
            }
        }

        $marks = $grades->pluck('final_mark')->filter(fn ($m) => $m !== null)->map(fn ($m) => (float) $m);

        $stats = [
            'count'    => $marks->count(),
            'mean'     => $marks->isNotEmpty() ? round($marks->avg(), 2) : null,
            'median'   => $marks->isNotEmpty() ? round($this->median($marks), 2) : null,
            'min'      => $marks->isNotEmpty() ? round($marks->min(), 2) : null,
            'max'      => $marks->isNotEmpty() ? round($marks->max(), 2) : null,
            'std_dev'  => $marks->count() > 1 ? round($this->stdDev($marks), 2) : null,
        ];

        $total = $grades->count();

        $byRange = collect($ranges)
            ->map(fn (array $range) => [
                'range'   => $range['key'],
                'label'   => $range['label'],
                'min'     => $range['min'],
                'count'   => $distribution[$range['key']] ?? 0,
                'percent' => $total > 0
                    ? round((($distribution[$range['key']] ?? 0) / $total) * 100, 1)
                    : 0.0,
            ])
            ->all();

        return [
            'distribution' => $distribution,
            'by_range'     => $byRange,
            'stats'        => $stats,
            'total'        => $total,
        ];
    }

    // -----------------------------------------------------------------
    // Category & programme breakdowns
    // -----------------------------------------------------------------

    public function categoryBreakdown(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): array {
        return Project::query()
            ->forSemester($semesterId)
            ->when($psmPart, fn ($q) => $q->where('psm_part', $psmPart))
            ->when($batch, fn ($q) => $q->where('batch', $batch))
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

    /**
     * A single coordinator-facing summary used by the dashboard header.
     *
     * Requirement §4.2 asks the dashboard to split its stats by batch. The split
     * is computed here rather than left to the caller so the totals and the
     * per-batch rows can never disagree — both come from the same queries.
     */
    public function dashboardSummary(
        ?string $batch = null,
        ?string $psmPart = null,
        ?int $semesterId = null
    ): array {
        $progress = $this->cohortProgress($batch, $psmPart, $semesterId);

        $submitted = Milestone::query()
            ->whereIn('project_id', Project::query()
                ->forSemester($semesterId)
                ->when($psmPart, fn ($q) => $q->where('psm_part', $psmPart))
                ->when($batch, fn ($q) => $q->where('batch', $batch))
                ->pluck('id'))
            ->where('status', MilestoneStatus::Submitted->value)
            ->count();

        return [
            'cohort'            => $progress,
            'awaiting_review'   => $submitted,
            'at_risk'           => $this->atRiskStudents($batch, 5, $psmPart, $semesterId)->count(),
            'marks'             => $this->markDistribution($batch, $psmPart, $semesterId),
            'categories'        => $this->categoryBreakdown($batch, $psmPart, $semesterId),

            // The per-batch rollup the coordinator's segmented control reads.
            'by_part'           => $this->dashboardByPart($batch, $semesterId),
        ];
    }

    /**
     * Dashboard figures for both batches of a term, always both keys.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function dashboardByPart(?string $batch, ?int $semesterId): array
    {
        $out = [];

        foreach (PsmPart::deliverables() as $part) {
            $out[$part->value] = [
                'label'   => $part->label(),
                'cohort'  => $this->cohortProgress($batch, $part->value, $semesterId),
                'marks'   => $this->markDistribution($batch, $part->value, $semesterId),
                'at_risk' => $this->atRiskStudents($batch, 5, $part->value, $semesterId)->count(),
            ];
        }

        return $out;
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