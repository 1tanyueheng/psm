<?php

namespace Database\Seeders;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use App\Models\AcademicSemester;
use App\Models\Milestone;
use App\Models\MilestoneReview;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentProfile;
use App\Models\SubmissionEvent;
use App\Models\SubmissionFile;
use App\Models\SupervisionAssignment;
use App\Models\User;
use App\Services\MilestoneScheduleService;
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
 *   early          — still on the proposal
 *   at_risk        — overdue milestones, the intervention list
 *
 * Every project is stamped with the active academic semester, and the cohort is
 * split across PSM 1 and PSM 2 *within that one term*. Both matter: the whole
 * point of the concurrent-batches requirement is that the two parts coexist in
 * a single term, and a demo cohort where everything is PSM 2 in no term at all
 * cannot show any of it — the coordinator's segmented overview, the per-part
 * milestone chains, and the side-by-side report would all be empty.
 */
class ProjectSeeder extends Seeder
{
    /** Academic session label used throughout the demo cohort. */
    protected const SESSION = '2025/2026';
    protected const BATCH   = '2026';

    public function __construct(
        protected MilestoneService $milestones,
        protected MilestoneScheduleService $schedules,
    ) {
    }

    public function run(): void
    {
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();
        $supervisorId = User::where('email', 'supervisor@psm.test')->value('id');

        // The term the cohort is registered into. Every semester-scoped screen
        // resolves a missing filter to the active term, so seeding without one
        // leaves the whole application looking empty.
        $semester = AcademicSemester::current()
            ?? AcademicSemester::query()->chronological()->firstOrFail();

        // Only students with a supervisor can carry a project; the two left
        // unassigned by UserSeeder form the coordinator's pairing queue.
        $students = StudentProfile::query()
            ->with(['user', 'activeSupervisions.supervisorProfile.user'])
            ->whereHas('activeSupervisions')
            ->orderBy('id')
            ->get();

        /**
         * Publish the term's milestone calendar before any project is created,
         * so every project in the batch is instantiated against the same dates.
         *
         * That is the point of the feature: the faculty sets Chapter 1 once and
         * the whole cohort follows, rather than each project deriving its own
         * dates from its own registration date.
         *
         * Anchored near today rather than on the term's official `starts_at`,
         * because the seeded terms are historical — the active one ran January
         * to May, and anchoring there would put every deadline months in the
         * past and make the whole demo look stale. The demo is synthetic data;
         * the coordinator's real calendar is set from the screen.
         */
        $this->seedMilestoneCalendar($semester, $coordinator);

        foreach ($students as $index => $student) {
            // Interleave the two parts rather than assigning a contiguous block, so
            // each progress profile is represented in both batches and switching the
            // segmented control never lands on an empty tab.
            $parts = ['PSM1', 'PSM2'];
            $psmPart = $parts[$index % count($parts)];

            // Resolved per part: the two milestone chains share only two codes.
            $profiles    = $this->progressProfiles($psmPart);
            $profileName = array_keys($profiles)[$index % count($profiles)];
            $profile     = $profiles[$profileName];

            $category = $index % 3 === 0
                ? ProjectCategory::Research
                : ProjectCategory::System;

            // The enrolment follows the project: a student belongs to the term
            // they are registered in.
            if ($student->academic_semester_id !== $semester->id) {
                $student->forceFill(['academic_semester_id' => $semester->id])->save();
            }

            $project = $this->createProject($student, $category, $coordinator, $profileName, $psmPart, $semester);

            /**
             * Module 3 — the real instantiation path, **once per project**.
             *
             * `instantiateFor()` inserts the chain unconditionally, and
             * `milestone_project_code_attempt_unique` refuses a second
             * `(project_id, code, attempt)` — so re-running the seed against an
             * existing cohort threw immediately. A project that already carries
             * its chain is stepped over; `driveTo()` still tops up the statuses
             * and the R1 rows below, which is what a re-seed is actually for.
             */
            if ($project->milestones()->doesntExist()) {
                $this->milestones->instantiateFor($project, now()->subDays($profile['started_days_ago']));
            }

            $this->advance($project, $profile);
            $this->attachSupervisorNote($project, $supervisorId);
        }

        // Reconcile SupervisionAssignment psm_part to the part(s) each student
        // actually holds in this term. The UserSeeder created them as 'BOTH'
        // because projects didn't exist yet; now we know the truth. This avoids
        // the double-counting that made a supervisor with 2 students show
        // 2/2 PSM 1 and 2/2 PSM 2 — masking the per-part capacity feature.
        // If a student holds both batches (rare), the pairing stays BOTH.
        $this->reconcileSupervisionParts($semester);

        /**
         * Final sweep: file Lampiran R1 behind **every** approved chapter.
         *
         * The loop above only reaches students who hold an active supervision,
         * and `driveTo()` only files R1 for a milestone it is asked to drive.
         * Between them, two whole classes of chapter were left with an empty R1
         * card: projects whose owner sits outside the loop (the two students the
         * UserSeeder leaves unassigned), and every project that already existed
         * before this seeding was added. An approved chapter with no R1 behind it
         * is exactly the inconsistency being fixed, so the check is done over the
         * table rather than over whatever the loop happened to touch.
         */
        $this->fileMissingR1();

        $this->command?->info(sprintf(
            '  Projects: %d (PSM 1: %d, PSM 2: %d), milestones: %d, submission files: %d, events: %d.',
            Project::count(),
            Project::where('psm_part', 'PSM1')->count(),
            Project::where('psm_part', 'PSM2')->count(),
            Milestone::count(),
            SubmissionFile::count(),
            SubmissionEvent::count()
        ));
    }

    /**
     * Publish the demo term's milestone calendar for both batches.
     *
     * Dates are laid out from a single anchor so the chain reads like a real
     * semester: the proposal first, then the chapters in order, the final report
     * last, each opening when the previous one is due. Deriving them from the
     * template's own offsets keeps the seeded calendar consistent with what the
     * "re-seed from template" action would produce.
     *
     * `seedDefaultsFor` is idempotent, so a re-seed does not fight dates a
     * coordinator has since changed.
     */
    protected function seedMilestoneCalendar(AcademicSemester $semester, User $coordinator): void
    {
        // Anchored a few weeks back so the earlier milestones have already
        // opened and the later ones are still ahead — the spread the demo needs
        // to show both an overdue chapter and an upcoming one.
        $anchor = now()->subDays(21)->startOfDay();

        $seeded = 0;

        foreach (['PSM1', 'PSM2'] as $psmPart) {
            $seeded += $this->schedules->seedDefaultsFor(
                $semester->id,
                $psmPart,
                $anchor,
                $coordinator,
            );
        }

        $this->command?->line(sprintf(
            '  <info>Milestone calendar</info> %d date(s) published for %s.',
            $seeded,
            $semester->name,
        ));
    }

    /**
     * Reconcile supervision pairings to match the batches each student holds.
     *
     * The UserSeeder creates pairings as 'BOTH' because projects don't exist
     * yet. Now that we know each student's batch(es), we update the pairing to
     * the exact part, or BOTH if the student genuinely holds both. This keeps
     * the per-part load honest: a supervisor's PSM 1 bar reflects only PSM 1
     * students, not everyone they supervise.
     */
    protected function reconcileSupervisionParts(AcademicSemester $semester): void
    {
        // Map each student to the set of parts they have projects in this term.
        $studentParts = Project::query()
            ->forSemester($semester)
            ->join('project_members', 'project_members.project_id', '=', 'projects.id')
            ->select('project_members.student_profile_id', 'projects.psm_part')
            ->get()
            ->groupBy('student_profile_id')
            ->map(fn ($rows) => collect($rows->pluck('psm_part')->unique()));

        foreach ($studentParts as $studentId => $parts) {
            $part = $parts->count() === 2 ? 'BOTH' : $parts->first();

            SupervisionAssignment::query()
                ->where('student_profile_id', $studentId)
                ->where('is_active', true)
                ->update(['psm_part' => $part]);
        }

        $changed = SupervisionAssignment::query()
            ->where('is_active', true)
            ->whereHas('studentProfile.projects', fn ($q) => $q->forSemester($semester))
            ->count();

        $this->command?->line("  Supervision pairings reconciled to real batches: {$changed}");
    }

    // -----------------------------------------------------------------
    // Project creation
    // -----------------------------------------------------------------

    protected function createProject(
        StudentProfile $student,
        ProjectCategory $category,
        User $coordinator,
        string $profileName,
        string $psmPart = 'PSM2',
        ?AcademicSemester $semester = null,
    ): Project {
        // The thesis title lives on the student profile from Module 2
        $title = $student->thesis_title
            ?? "PSM Project — {$student->student_id}";

        /**
         * Re-seed the project this student already holds, rather than minting a
         * new one.
         *
         * `nextCode()` numbers by *count* (`count + 1`), so on a second run the
         * codes it produces no longer match the existing rows, `updateOrCreate`
         * misses on the code, and the whole cohort is duplicated: 53 projects
         * became 78, then 157, each with a full chain of 401+ milestones. A
         * student holds one project per part per term, so that is the key the
         * seeder should have used all along.
         *
         * **Oldest first.** A database that was already double-seeded holds two
         * PSM 1 projects for the same student — one from the original run and a
         * duplicate the bug created later. `->first()` with no order returned
         * whichever the planner offered (id 7's student picked up `PSM2-…-015`
         * and never reached their own PSM 1 project), so half the cohort was
         * re-seeded and half silently skipped. The oldest row is the one the
         * demo has been showing all along.
         */
        $existing = Project::query()
            ->where('psm_part', $psmPart)
            ->where('academic_session', self::SESSION)
            ->whereHas('members', fn ($q) => $q->where('student_profile_id', $student->id))
            ->orderBy('id')
            ->first();

        // Completed projects carry a raw code; in-flight ones use the sequence.
        // The part goes into the code so PSM 1 and PSM 2 projects in the same
        // term never collide on the same sequence.
        $code = $existing?->code
            ?? Project::nextCode($psmPart, self::SESSION, $student->program_code);

        $project = Project::updateOrCreate(
            ['code' => $code],
            [
                'title'            => $title,
                'abstract'         => $student->thesis_abstract,
                'objectives'       => $this->objectivesFor($title),
                'scope'            => $this->scopeFor($category),
                'category'         => $category,
                'psm_part'         => $psmPart,
                'academic_session' => self::SESSION,
                // The term, not just the session string. Nullable for legacy
                // rows, but new rows must supply it.
                'academic_semester_id' => $semester?->id,
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
     * Profiles are defined PER PART, because the two chains share only two
     * codes (`chapter_4` and `final_report`) and are otherwise disjoint:
     *
     *   PSM 1  proposal, chapter_1..chapter_4, final_report   (6 items)
     *   PSM 2  chapter_4, chapter_5, chapter_6, chapter_7, final_report (5)
     *
     * A single PSM 2-shaped profile map leaves a PSM 1 project with only those
     * two codes resolvable, so it stalls at 2 of 6 approved and is then skipped
     * by the evaluation seeder — which is how a cohort split across both parts
     * ends up with no PSM 1 grades at all.
     *
     * `started_days_ago` is tuned per chain against the template offsets
     * (PSM 1's final report sits at day 140, PSM 2's at day 126).
     *
     * A code that does not exist on the project's own template is skipped by
     * advance(), so these stay safe against a template change.
     *
     * @return array<string, array{started_days_ago:int, milestones:array<string,string>}>
     */
    protected function progressProfiles(string $psmPart = 'PSM2'): array
    {
        return $psmPart === 'PSM1'
            ? $this->psm1Profiles()
            : $this->psm2Profiles();
    }

    /**
     * PSM 1 — proposal through Chapter 4 and the consolidated report.
     *
     * @return array<string, array{started_days_ago:int, milestones:array<string,string>}>
     */
    protected function psm1Profiles(): array
    {
        return [
            // Finished — Lampiran E is open, so these are the PSM 1 projects
            // that carry a grade into Module 8.
            'completed' => [
                'started_days_ago' => 175,
                'milestones' => [
                    'proposal'     => 'approved',
                    'chapter_1'    => 'approved',
                    'chapter_2'    => 'approved',
                    'chapter_3'    => 'approved',
                    'chapter_4'    => 'approved',
                    'final_report' => 'approved',
                ],
            ],

            // Final report submitted, awaiting the examiner
            'near_complete' => [
                'started_days_ago' => 155,
                'milestones' => [
                    'proposal'     => 'approved',
                    'chapter_1'    => 'approved',
                    'chapter_2'    => 'approved',
                    'chapter_3'    => 'approved',
                    'chapter_4'    => 'approved',
                    'final_report' => 'submitted',
                ],
            ],

            // Mid-flight: proposal and two chapters in, third under review
            'mid_project' => [
                'started_days_ago' => 95,
                'milestones' => [
                    'proposal'  => 'approved',
                    'chapter_1' => 'approved',
                    'chapter_2' => 'approved',
                    'chapter_3' => 'submitted',
                ],
            ],

            // Early: the proposal is in and Chapter 1 has just opened
            'early' => [
                'started_days_ago' => 45,
                'milestones' => [
                    'proposal'  => 'approved',
                    'chapter_1' => 'open',
                ],
            ],

            // At risk: a rejected chapter and an overdue one
            'at_risk' => [
                'started_days_ago' => 125,
                'milestones' => [
                    'proposal'  => 'approved',
                    'chapter_1' => 'approved',
                    'chapter_2' => 'approved',
                    'chapter_3' => 'rejected',
                    'chapter_4' => 'overdue',
                ],
            ],
        ];
    }

    /**
     * PSM 2 — Chapters 4 to 7 and the final report.
     *
     * @return array<string, array{started_days_ago:int, milestones:array<string,string>}>
     */
    protected function psm2Profiles(): array
    {
        return [
            // Finished — the students Module 8 will rank
            'completed' => [
                'started_days_ago' => 160,
                'milestones' => [
                    'chapter_4'    => 'approved',
                    'chapter_5'    => 'approved',
                    'chapter_6'    => 'approved',
                    'chapter_7'    => 'approved',
                    'final_report' => 'approved',
                ],
            ],

            // Final report submitted, awaiting the examiner
            'near_complete' => [
                'started_days_ago' => 140,
                'milestones' => [
                    'chapter_4'    => 'approved',
                    'chapter_5'    => 'approved',
                    'chapter_6'    => 'approved',
                    'chapter_7'    => 'approved',
                    'final_report' => 'submitted',
                ],
            ],

            // Mid-flight: the biggest group, so the workload report is realistic
            'mid_project' => [
                'started_days_ago' => 80,
                'milestones' => [
                    'chapter_4' => 'approved',
                    'chapter_5' => 'approved',
                    'chapter_6' => 'submitted',
                ],
            ],

            // Early: implementation just opened
            'early' => [
                'started_days_ago' => 34,
                'milestones' => [
                    'chapter_4' => 'open',
                ],
            ],

            // At risk: one rejection and one overdue chapter, so the
            // "students needing intervention" list is populated
            'at_risk' => [
                'started_days_ago' => 110,
                'milestones' => [
                    'chapter_4' => 'approved',
                    'chapter_5' => 'approved',
                    'chapter_6' => 'rejected',
                    'chapter_7' => 'overdue',
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
            /**
             * Already where the profile wants it — but an approved chapter may
             * still be missing its Lampiran R1. Re-seeding a demo database that
             * predates the R1 seeding hits exactly this path: every milestone
             * reports "already approved", the loop below never runs, and the R1
             * cards stay empty for ever. Filing here makes the seed idempotent
             * in the direction that matters.
             */
            if ($target === MilestoneStatus::Approved) {
                $this->fileR1($milestone, $supervisor);
            }

            return;
        }

        /**
         * Route each destination through the states that must precede it.
         *
         * `Approved` routes through **`Reviewed`**, not straight from
         * `Submitted`. The state machine deliberately has no
         * `submitted -> approved` hop: `Reviewed` is the supervisor's endorsement
         * and the panel's gate, and the seeder used to ask for the illegal hop
         * and then swallow the refusal in the `continue` below — so every seeded
         * approval silently did nothing, chapters sat at `submitted`, and the
         * R1 rows had to be written by hand afterwards. A milestone that is
         * genuinely approved has always passed through `Reviewed`.
         */
        $route = match ($target) {
            MilestoneStatus::Open      => [MilestoneStatus::Open],
            MilestoneStatus::Overdue   => [MilestoneStatus::Overdue],
            MilestoneStatus::Submitted => [MilestoneStatus::Open, MilestoneStatus::Submitted],
            MilestoneStatus::Reviewed  => [MilestoneStatus::Open, MilestoneStatus::Submitted, MilestoneStatus::Reviewed],
            MilestoneStatus::Approved  => [
                MilestoneStatus::Open,
                MilestoneStatus::Submitted,
                MilestoneStatus::Reviewed,
                MilestoneStatus::Approved,
            ],
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
                // The route asked for a hop this status does not allow. Skip
                // quietly: a demo profile that does not suit the milestone it
                // lands on must not break the whole seed run.
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

            // Lampiran R1 is the endorsement of a chapter: a chapter that the
            // demo shows as approved must carry the form behind it, or its
            // milestone page opens an empty R1 card. Written here rather than in
            // the profile tables so it cannot drift from the statuses.
            if ($step === MilestoneStatus::Approved) {
                $this->fileR1($current->fresh(), $supervisor);
            }
        }
    }

    /**
     * File Lampiran R1 for every approved chapter that has none.
     *
     * Table-driven on purpose: the seeding loop is scoped to students with an
     * active supervision, and the demo cohort contains projects outside that set
     * (the two students the UserSeeder leaves for the coordinator's pairing
     * queue). Asking "which approved chapter lacks an R1?" over `milestones`
     * catches those, plus anything a previous seed created before this rule
     * existed, and is the same question a reader of the demo would ask.
     */
    protected function fileMissingR1(): void
    {
        Milestone::query()
            ->whereIn('code', Milestone::R1_CODES)
            ->where('status', MilestoneStatus::Approved->value)
            ->whereDoesntHave('reviews')
            ->with('project')
            ->chunkById(100, function ($milestones) {
                foreach ($milestones as $milestone) {
                    $this->fileR1($milestone, $this->supervisorFor($milestone->project));
                }
            });
    }

    /**
     * Record the Lampiran R1 form behind an approved chapter.
     *
     * Mirrors what the R1 screen does — **both** declarations ticked, which is
     * the endorsement and the approval — and the scores the supervisor typed
     * against the four printed review items. Written with `MilestoneReview::create`
     * rather than through `MilestoneReviewService::record()` because the service
     * drives the state machine, and `driveTo()` has already walked it.
     *
     * Only PSM 1's chapters 1-4 and the system demo are covered (see
     * `Milestone::R1_CODES`); every other milestone is endorsed on a different
     * form, so nothing is written for it.
     */
    protected function fileR1(Milestone $milestone, ?User $supervisor): void
    {
        if (! $milestone->usesR1() || $milestone->reviews()->exists()) {
            return;
        }

        /**
         * The demo has no submission to point at, and needs none — it is a
         * face-to-face session. A chapter is only ever reviewed after the student
         * uploaded, so a chapter with no current file is skipped rather than
         * given an R1 against a document that does not exist.
         */
        if ($milestone->code !== Milestone::CODE_DEMO
            && $milestone->currentFiles()->count() === 0) {
            return;
        }

        $comments = $this->commentFor(MilestoneStatus::Approved, $milestone);

        // Deterministic per milestone, so re-running the seed does not shuffle
        // which chapter scored what.
        $scores = match ($milestone->code) {
            'chapter_1' => ['report_content' => 4, 'report_format' => 4],
            'chapter_2' => ['report_content' => 5, 'report_format' => 4],
            'chapter_3' => ['report_content' => 4, 'report_format' => 4, 'prototype_analysis' => 4],
            'chapter_4' => ['report_content' => 4, 'report_format' => 4, 'prototype_analysis' => 5, 'prototype_ui' => 4],
            'demo'      => ['prototype_analysis' => 5, 'prototype_ui' => 5],
            default     => ['report_content' => 4, 'report_format' => 4],
        };

        MilestoneReview::create([
            'milestone_id'  => $milestone->id,
            'round_no'      => 1,
            'revision_no'   => (int) ($milestone->currentFiles()->max('revision_no') ?: 1),
            'reviewer_id'   => $supervisor?->id,
            'reviewer_name' => $supervisor?->name ?? 'Supervisor',
            'reviewer_role' => $supervisor?->role?->value,
            'ack_received'  => true,
            'ack_reviewed'  => true,
            'verdict'       => MilestoneReview::VERDICT_APPROVED,
            'declaration'   => [
                'student_name'  => $milestone->project?->leader()?->user?->name,
                'matric'        => $milestone->project?->leader()?->student_id,
                'programme'     => $milestone->project?->program,
                'project_title' => $milestone->project?->title,
                'session'       => $milestone->project?->academic_session,
            ],
            'scores'        => $scores,
            'total_score'   => array_sum($scores),
            'max_score'     => count($scores) * 5,
            'comments'      => $comments,
        ]);
    }

    protected function commentFor(MilestoneStatus $step, Milestone $milestone): ?string
    {
        return match ($step) {
            MilestoneStatus::Approved => match ($milestone->code) {
                'proposal'     => 'Clear problem statement and achievable scope. Proceed.',
                'chapter_1'    => 'Introduction is well scoped and the contribution is stated clearly.',
                'chapter_2'    => 'Requirements are testable and traceable. Good comparison of prior systems.',
                'chapter_3'    => 'Design is justified and the alternatives you rejected are explained.',
                'chapter_4'    => 'Core features demonstrably working. Well structured code.',
                'chapter_5'    => 'Coverage is reasonable and defects are logged properly.',
                'chapter_6'    => 'Results are tied back to the objectives, and the shortfalls are reported honestly.',
                'chapter_7'    => 'Conclusions follow from the evidence and the limitations are acknowledged.',
                'final_report' => 'Meets the faculty template. Approved for examination.',
                default        => 'Approved.',
            },
            // Keyed on chapter_6 because that is the chapter the `at_risk`
            // profile rejects; every other rejection gets the generic wording.
            MilestoneStatus::Rejected => $milestone->code === 'chapter_6'
                ? 'The evaluation does not yet support the claims made. '
                  .'Please report the negative results, revisit the analysis and resubmit.'
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
