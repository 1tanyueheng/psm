<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Models\FinalGrade;
use App\Models\Leaderboard;
use App\Models\LeaderboardEntry;
use App\Models\LeaderboardSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 8 — Builds and publishes public recognition leaderboards.
 *
 * Two-phase by design:
 *   build()     — ranks eligible projects into a DRAFT snapshot (previewable)
 *   publish()   — flips the snapshot live and notifies participants
 *
 * The public endpoint only ever reads a published snapshot, so nothing on the
 * no-login page touches live grade rows or student contact details.
 */
class LeaderboardService
{
    public function __construct(
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // Building
    // -----------------------------------------------------------------

    /**
     * Rank projects and populate the entries of a draft leaderboard.
     *
     * @param  array{psm_part?:string, batch?:string, academic_session?:string}  $filters
     */
    public function build(Leaderboard $leaderboard, array $filters = []): Leaderboard
    {
        if ($leaderboard->isPublished()) {
            throw new InvalidArgumentException(
                'Unpublish this leaderboard before rebuilding it, so a live page never changes underneath visitors.'
            );
        }

        $settings = LeaderboardSetting::current();

        $grades = $this->eligibleGrades($leaderboard, $filters, $settings->default_min_assessors);

        return DB::transaction(function () use ($leaderboard, $grades) {
            // Rebuild cleanly from scratch
            $leaderboard->entries()->delete();

            $rank = 0;

            foreach ($grades as $grade) {
                $rank++;
                $project = $grade->project;

                if ($project === null) {
                    continue;
                }

                LeaderboardEntry::create([
                    'leaderboard_id'   => $leaderboard->id,
                    'project_id'       => $project->id,
                    'final_grade_id'   => $grade->id,
                    'rank'             => $rank,
                    'is_winner'        => $rank === 1,
                    'is_top_n'         => $rank <= $leaderboard->top_n,
                    'project_title'    => $project->title,
                    'project_abstract' => $project->abstract,
                    'project_code'     => $project->code,
                    'category'         => $project->category,
                    'program'          => $project->program,
                    // Frozen roster: name + student id only, never contact data
                    'students'         => $this->studentRoster($project),
                    'supervisors'      => $this->supervisorRoster($project),
                    'display_score'    => $grade->final_mark,
                    'score_label'      => $this->scoreLabel($leaderboard->ranking_basis),
                    'assessor_count'   => $grade->assessor_count,
                ]);
            }

            return $leaderboard->fresh(['entries']);
        });
    }

    /**
     * All grades eligible for public display, ranked.
     */
    protected function eligibleGrades(
        Leaderboard $leaderboard,
        array $filters,
        int $minAssessors,
    ): Collection {
        $query = FinalGrade::query()
            ->with(['project.students.user', 'project.members.studentProfile.user'])
            ->where('status', 'released')
            ->where('is_publishable', true)
            ->where('assessor_count', '>=', $minAssessors)
            ->whereNotNull('final_mark')
            ->whereHas('project', function ($q) use ($leaderboard, $filters) {
                // Consent: never list a project that opted out
                $q->where('leaderboard_opt_out', false);

                if ($leaderboard->psm_part !== 'BOTH') {
                    $q->where('psm_part', $leaderboard->psm_part);
                }

                if ($batch = ($filters['batch'] ?? $leaderboard->batch)) {
                    $q->where('batch', $batch);
                }

                if ($session = ($filters['academic_session'] ?? $leaderboard->academic_session)) {
                    $q->where('academic_session', $session);
                }
            });

        // Apply the ranking basis, then the configured tie-breaker
        $query->orderByDesc(match ($leaderboard->ranking_basis) {
            'aggregate_percent' => 'aggregate_percent',
            'milestone_score'   => 'milestone_score',
            default             => 'final_mark',
        });

        match ($leaderboard->tie_breaker) {
            'supervisor_score'  => $query->orderByDesc('supervisor_score'),
            'submission_time'   => $query->orderBy('computed_at'),
            default             => $query->orderByDesc('assessor_count'),
        };

        return $query->limit(max($leaderboard->top_n * 5, 50))->get();
    }

    // -----------------------------------------------------------------
    // Publication
    // -----------------------------------------------------------------

    public function publish(Leaderboard $leaderboard, User $actor): Leaderboard
    {
        $settings = LeaderboardSetting::current();

        if (! $settings->module_enabled) {
            throw new InvalidArgumentException('The recognition module is currently disabled.');
        }

        if ($leaderboard->entries()->where('is_hidden', false)->count() === 0) {
            throw new InvalidArgumentException(
                'This leaderboard has no visible entries — build it before publishing.'
            );
        }

        if ($settings->require_approval && ! $actor->hasRole('admin', 'coordinator')) {
            throw new InvalidArgumentException('Publishing this board requires coordinator approval.');
        }

        $leaderboard->publish($actor);

        $this->audit->log(
            action: AuditAction::LeaderboardPublished,
            description: "Published '{$leaderboard->title}' with TOP {$leaderboard->top_n}",
            subject: $leaderboard,
            actor: $actor,
        );

        // Notify the showcased students
        $students = $leaderboard->entries()
            ->where('is_top_n', true)
            ->where('is_hidden', false)
            ->get()
            ->flatMap(fn (LeaderboardEntry $e) => $e->project?->students->pluck('user') ?? collect())
            ->filter()
            ->unique('id');

        $this->notifications->notify(
            $students,
            NotificationType::LeaderboardPublished,
            [
                'title'      => 'You are on the PSM showcase!',
                'body'       => "{$leaderboard->title} has been published.",
                'action_url' => $leaderboard->publicPath(),
            ],
            $leaderboard,
        );

        return $leaderboard->fresh(['entries']);
    }

    public function unpublish(Leaderboard $leaderboard, User $actor, ?string $reason = null): Leaderboard
    {
        $leaderboard->unpublish($reason);

        $this->audit->log(
            action: AuditAction::LeaderboardUnpublished,
            description: $reason ?? "Unpublished '{$leaderboard->title}'",
            subject: $leaderboard,
            actor: $actor,
        );

        return $leaderboard->fresh();
    }

    /** Convenience: build and publish in one call. */
    public function buildAndPublish(Leaderboard $leaderboard, User $actor, array $filters = []): Leaderboard
    {
        $this->build($leaderboard, $filters);

        return $this->publish($leaderboard, $actor);
    }

    // -----------------------------------------------------------------
    // Public read
    // -----------------------------------------------------------------

    /**
     * The payload served to the no-login public route.
     * Returns null when nothing is published or the module is off.
     */
    public function publicPayload(?string $slug = null): ?array
    {
        if (! LeaderboardSetting::isPubliclyAvailable()) {
            return null;
        }

        $leaderboard = $slug
            ? Leaderboard::published()->where('slug', $slug)->first()
            : Leaderboard::currentPublic();

        if ($leaderboard === null) {
            return null;
        }

        $entries = $leaderboard->visibleEntries()->get();

        return [
            'title'       => $leaderboard->title,
            'slug'        => $leaderboard->slug,
            'subtitle'    => $leaderboard->subtitle,
            'description' => $leaderboard->description,
            'batch'       => $leaderboard->batch,
            'session'     => $leaderboard->academic_session,
            'psm_part'    => $leaderboard->psm_part,
            'published_at'=> $leaderboard->published_at?->toIso8601String(),
            'theme'       => $leaderboard->theme,
            'show_scores' => $leaderboard->show_scores,
            'show_abstract' => $leaderboard->show_abstract,
            'podium'      => $entries->where('rank', '<=', 3)->map(fn ($e) => $this->publicEntry($e, $leaderboard))->values(),
            'runners_up'  => $entries->where('rank', '>', 3)->map(fn ($e) => $this->publicEntry($e, $leaderboard))->values(),
        ];
    }

    /**
     * Shape one entry for public consumption.
     * Every field is explicitly whitelisted — no model serialisation.
     */
    protected function publicEntry(LeaderboardEntry $entry, Leaderboard $leaderboard): array
    {
        return [
            'rank'        => $entry->rank,
            'rank_label'  => $entry->rank.$entry->rankSuffix(),
            'medal'       => $entry->medal(),
            'title'       => $entry->project_title,
            'code'        => $leaderboard->show_scores ? $entry->project_code : null,
            'abstract'    => $leaderboard->show_abstract ? $entry->shortAbstract() : null,
            'category'    => $entry->category?->label(),
            'program'     => $leaderboard->show_program ? $entry->program : null,
            'students'    => $leaderboard->show_student_names ? $entry->publicStudents() : [],
            'supervisors' => $entry->publicSupervisors(),
            'score'       => $leaderboard->show_scores ? (float) $entry->display_score : null,
            'score_label' => $entry->score_label,
            'award'       => $entry->award_title,
            'poster_path' => $entry->poster_path,
        ];
    }

    // -----------------------------------------------------------------
    // Rosters
    // -----------------------------------------------------------------

    /** Name + student ID only — the public page must never carry more. */
    protected function studentRoster(Project $project): array
    {
        return $project->students->map(fn ($student) => [
            'name'       => $student->user?->name,
            'student_id' => $student->student_id,
        ])->filter(fn ($s) => $s['name'] !== null)->values()->all();
    }

    protected function supervisorRoster(Project $project): array
    {
        $supervisorIds = $project->students
            ->flatMap(fn ($student) => $student->activeSupervisions->pluck('supervisor_profile_id'))
            ->unique();

        return \App\Models\SupervisorProfile::query()
            ->with('user')
            ->whereIn('id', $supervisorIds)
            ->get()
            ->map(fn ($s) => ['name' => $s->label()])
            ->values()
            ->all();
    }

    protected function scoreLabel(string $basis): string
    {
        return match ($basis) {
            'aggregate_percent' => 'Assessor aggregate',
            'milestone_score'   => 'Milestone completion',
            default             => 'Final mark',
        };
    }
}
