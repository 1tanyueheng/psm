<?php

namespace Database\Seeders;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentProfile;
use App\Models\SubmissionEvent;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Services\MilestoneService;
use Illuminate\Database\Seeder;

/**
 * Module 3 — Projects, memberships, and instantiated milestones.
 *
 * Almost nothing here writes milestone rows by hand. The chain is created by
 * MilestoneService::instantiateFor(), exactly as the API does it at
 * registration, and every status change goes through transitionTo(). If the
 * seeder took a shortcut, it would produce data that the real state machine
 * could never have produced — and the demo would be lying.
 *
 * The cohort is deliberately spread across five progress profiles so that
 * Module 5's analytics and Module 8's leaderboard have something to show:
 *
 *   completed      — every milestone approved, ready to grade and rank
 *   near_complete  — final report in review
 *   mid_project    — implementation underway
 *   early           — still on the proposal
 *   at_risk        — overdue milestones, the intervention list
 */
class ProjectSeeder extends Seeder
{
    /** Academic session label used throughout the demo cohort. */
    protected const SESSION = '2025/2026';
    protected const BATCH   = '2026';

    public function __construct(
        protected MilestoneService $milestones,
    ) {
    }

    public function run(): void
    {
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();
        $supervisorId = User::where('email', 'supervisor@psm.test')->value('id');

        // Only students with a supervisor can carry a project; the two left
        // unassigned by UserSeeder form the coordinator's pairing queue.
        $students = StudentProfile::query()
            ->with(['user', 'activeSupervisions.supervisorProfile.user'])
            ->whereHas('activeSupervisions')
            ->orderBy('id')
            ->get();

        $profiles = $this->progressProfiles();

        foreach ($students as $index => $student) {
            $profileName = array_keys($profiles)[$index % count($profiles)];
            $profile     = $profiles[$profileName];

            $category = $index % 3 === 0
                ? ProjectCategory::Research
                : ProjectCategory::System;

            $project = $this->createProject($student, $category, $coordinator, $profileName);

            // Module 3 — the real instantiation path
            $this->milestones->instantiateFor($project, now()->subDays($profile['started_days_ago']));

            $this->advance($project, $profile);
            $this->attachSupervisorNote($project, $supervisorId);
        }

        $this->command?->info(sprintf(
            '  Projects: %d, milestones: %d, submission files: %d, events: %d.',
            Project::count(),
            Milestone::count(),
            SubmissionFile::count(),
            SubmissionEvent::count()
        ));
    }

    // -----------------------------------------------------------------
    // Project creation
    // -----------------------------------------------------------------

    protected function createProject(
        StudentProfile $student,
        ProjectCategory $category,
        User $coordinator,
        string $profileName,
    ): Project {
        // The thesis title lives on the student profile from Module 2
        $title = $student->thesis_title
            ?? "PSM Project — {$student->student_id}";

        // Completed projects carry a raw code; in-flight ones use the sequence
        $code = Project::nextCode('PSM2', self::SESSION, $student->program_code);

        $project = Project::updateOrCreate(
            ['code' => $code],
            [
                'title'            => $title,
                'abstract'         => $student->thesis_abstract,
                'objectives'       => $this->objectivesFor($title),
                'scope'            => $this->scopeFor($category),
                'category'         => $category,
                'psm_part'         => 'PSM2',
                'academic_session' => self::SESSION,
                'batch'            => self::BATCH,
                'program'          => $student->program,
                'status'           => $profileName === 'completed' ? 'completed' : 'in_progress',
                'created_by'       => $student->user_id,
                'approved_by'      => $coordinator->id,
                'submitted_at'     => now()->subMonths(6),
                'approved_at'      => now()->subMonths(6)->addDays(5),
                // One student withholds consent, so the Module 8 opt-out path
                // is exercised by real data rather than only by a unit test.
                'leaderboard_opt_out' => $student->id % 11 === 0,
                'metadata'         => [
                    'progress_profile' => $profileName,
                    'seeded'           => true,
                ],
            ]
        );

        ProjectMember::updateOrCreate(
            ['project_id' => $project->id, 'student_profile_id' => $student->id],
            ['is_leader' => true, 'contribution_percent' => 100.00]
        );

        return $project;
    }

    // -----------------------------------------------------------------
    // Progress simulation
    // -----------------------------------------------------------------

    /**
     * Progress profiles. `milestones` maps a milestone code to the status it
     * should reach; anything not listed stays Pending.
     *
     * @return array<string, array{started_days_ago:int, milestones:array<string,string>}>
     */
    protected function progressProfiles(): array
    {
        return [
            // Finished — the students Module 8 will rank
            'completed' => [
                'started_days_ago' => 150,
                'milestones' => [
                    'proposal'  => 'approved',
                    'design'    => 'approved',
                    'litreview' => 'approved',
                    'methods'   => 'approved',
                    'implement' => 'approved',
                    'analysis'  => 'approved',
                    'testing'   => 'approved',
                    'report'    => 'approved',
                ],
            ],

            // Final report submitted, awaiting the examiner
            'near_complete' => [
                'started_days_ago' => 138,
                'milestones' => [
                    'proposal'  => 'approved',
                    'design'    => 'approved',
                    'litreview' => 'approved',
                    'methods'   => 'approved',
                    'implement' => 'approved',
                    'analysis'  => 'approved',
                    'testing'   => 'approved',
                    'report'    => 'submitted',
                ],
            ],

            // Mid-flight: the biggest group, so the workload report is realistic
            'mid_project' => [
                'started_days_ago' => 96,
                'milestones' => [
                    'proposal'  => 'approved',
                    'design'    => 'approved',
                    'litreview' => 'approved',
                    'methods'   => 'approved',
                    'implement' => 'submitted',
                ],
            ],

            // Early: still validating the proposal
            'early' => [
                'started_days_ago' => 34,
                'milestones' => [
                    'proposal' => 'approved',
                    'design'   => 'open',
                ],
            ],

            // At risk: one rejection and one overdue milestone, so the
            // "students needing intervention" list is populated
            'at_risk' => [
                'started_days_ago' => 120,
                'milestones' => [
                    'proposal'  => 'approved',
                    'design'    => 'approved',
                    'litreview' => 'approved',
                    'methods'   => 'rejected',
                    'implement' => 'overdue',
                ],
            ],
        ];
    }

    /**
     * Drive a project to its target profile through the real state machine.
     */
    protected function advance(Project $project, array $profile): void
    {
        $supervisorUser = $this->supervisorFor($project);

        foreach ($profile['milestones'] as $code => $targetStatus) {
            $milestone = $project->milestones()->where('code', $code)->first();

            if ($milestone === null) {
                // e.g. `design` does not exist on a research-category template
                continue;
            }

            $this->driveTo($milestone, MilestoneStatus::from($targetStatus), $supervisorUser);
        }
    }

    /**
     * Walk a milestone to the requested status along legal transitions only.
     *
     * The route matters: you cannot approve something that was never
     * submitted, so every intermediate hop is made explicitly. This is what
     * guarantees the seeded data is consistent with the state machine.
     */
    protected function driveTo(Milestone $milestone, MilestoneStatus $target, ?User $supervisor): void
    {
        if ($milestone->status === $target) {
            return;
        }

        // Route each destination through the states that must precede it
        $route = match ($target) {
            MilestoneStatus::Open      => [MilestoneStatus::Open],
            MilestoneStatus::Overdue   => [MilestoneStatus::Overdue],
            MilestoneStatus::Submitted => [MilestoneStatus::Open, MilestoneStatus::Submitted],
            MilestoneStatus::Reviewed  => [MilestoneStatus::Open, MilestoneStatus::Submitted, MilestoneStatus::Reviewed],
            MilestoneStatus::Approved  => [MilestoneStatus::Open, MilestoneStatus::Submitted, MilestoneStatus::Approved],
            MilestoneStatus::Rejected  => [MilestoneStatus::Open, MilestoneStatus::Submitted, MilestoneStatus::Rejected],
            MilestoneStatus::Pending   => [],
        };

        foreach ($route as $step) {
            // Re-read: approving one milestone unlocks the next, and the
            // service writes timestamps we do not want to fight.
            $current = $milestone->fresh();

            if ($current->status === $step) {
                continue;
            }

            // Once approved a milestone is terminal — stop rather than throw
            if ($current->status === MilestoneStatus::Approved) {
                return;
            }

            if (! $current->status->canTransitionTo($step)) {
                // The route asked for an impossible hop; skip quietly so a
                // template difference (design vs litreview) cannot break seeding
                continue;
            }

            $this->milestones->transitionTo(
                $current,
                $step,
                $supervisor,
                $this->commentFor($step, $current),
            );

            if ($step === MilestoneStatus::Submitted) {
                $this->fakeSubmissionFiles($current->fresh());
            }
        }
    }

    protected function commentFor(MilestoneStatus $step, Milestone $milestone): ?string
    {
        return match ($step) {
            MilestoneStatus::Approved => match ($milestone->code) {
                'proposal'  => 'Clear problem statement and achievable scope. Proceed.',
                'design'    => 'Architecture is sound; consider caching for the report list.',
                'litreview' => 'Good synthesis. Strengthen the gap statement in section 2.4.',
                'methods'   => 'Methodology is appropriate. Sampling justification is adequate.',
                'implement' => 'Core features demonstrably working. Well structured code.',
                'analysis'  => 'Analysis is correctly applied and honestly reported.',
                'testing'   => 'Coverage is reasonable and defects are logged properly.',
                'report'    => 'Meets the faculty template. Approved for examination.',
                default     => 'Approved.',
            },
            MilestoneStatus::Rejected => $milestone->code === 'methods'
                ? 'The sampling strategy is not justified for the population size. '
                  .'Please revise section 3.2 and resubmit with a power calculation.'
                : 'Insufficient detail. Please revise and resubmit.',
            MilestoneStatus::Submitted => 'Submitted for review.',
            default => null,
        };
    }

    // -----------------------------------------------------------------
    // Submission artefacts
    // -----------------------------------------------------------------

    /**
     * Give a submitted milestone a plausible file, so the download and
     * revision-history UI has something real to render.
     *
     * Note the disk path is synthetic: these files are not on disk, so the
     * demo must not attempt an actual download for seeded rows.
     */
    protected function fakeSubmissionFiles(Milestone $milestone): void
    {
        if ($milestone->files()->exists()) {
            return;
        }

        $extension = 'pdf';
        $baseName  = sprintf(
            '%s_%s_v1.pdf',
            $milestone->project->code ?? 'PSM',
            $milestone->code
        );

        // `uploaded_by` is a required foreign key on submission_files, and
        // omitting it fails the insert with:
        //
        //   SQLSTATE[HY000]: General error: 1364
        //   Field 'uploaded_by' doesn't have a default value
        //
        // The student leading the project is the honest answer. leader()
        // already falls back to the first member, and created_by covers the
        // case of a project with no members attached yet.
        $uploaderId = $milestone->project->leader()?->user_id
            ?? $milestone->project->created_by;

        SubmissionFile::create([
            'milestone_id'   => $milestone->id,
            'uploaded_by'    => $uploaderId,
            'disk'           => 'local',
            'path'           => sprintf(
                'submissions/%d/%s',
                $milestone->project_id,
                $baseName
            ),
            'original_name'  => $baseName,
            'mime_type'      => 'application/pdf',
            'size_bytes'     => random_int(420_000, 3_800_000),
            'revision_no'    => 1,
            'is_current'     => true,
            'checksum_sha256'=> hash('sha256', $baseName.$milestone->id),
        ]);
    }

    /** Leave a supervisor-visible note on the project's latest event. */
    protected function attachSupervisorNote(Project $project, ?int $supervisorId): void
    {
        if ($supervisorId === null) {
            return;
        }

        $approved = $project->milestones()
            ->where('status', MilestoneStatus::Approved->value)
            ->orderByDesc('sequence')
            ->first();

        if ($approved === null) {
            return;
        }

        SubmissionEvent::create([
            'milestone_id' => $approved->id,
            'actor_id'     => $supervisorId,
            'event'        => SubmissionEvent::EVENT_COMMENTED,
            'from_status'  => $approved->status->value,
            'to_status'    => $approved->status->value,
            'comment'      => 'Supervision meeting recorded: progress on track, next steps agreed.',
            'payload'      => ['meeting_no' => random_int(4, 12), 'duration_minutes' => 30],
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    protected function supervisorFor(Project $project): ?User
    {
        return $project->students
            ->flatMap(fn (StudentProfile $s) => $s->activeSupervisions->pluck('supervisor_profile_id'))
            ->unique()
            ->pipe(fn ($ids) => \App\Models\SupervisorProfile::query()
                ->whereIn('id', $ids)
                ->with('user')
                ->first()
                ?->user);
    }

    protected function objectivesFor(string $title): string
    {
        return "1. To investigate the current limitations addressed by \"{$title}\".\n"
             ."2. To design and implement a solution that meets the identified requirements.\n"
             ."3. To evaluate the solution against established criteria and report the findings.";
    }

    protected function scopeFor(ProjectCategory $category): string
    {
        return $category === ProjectCategory::System
            ? 'Covers requirements analysis, design, implementation and testing of the proposed system. '
              .'Excludes production deployment and long-term maintenance.'
            : 'Covers literature review, methodology design, data collection and analysis for the stated '
              .'research questions. Excludes longitudinal follow-up beyond the project period.';
    }
}
