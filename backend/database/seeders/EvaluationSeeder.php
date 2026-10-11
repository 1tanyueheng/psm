<?php

namespace Database\Seeders;

use App\Enums\AssessorType;
use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Models\AcademicSemester;
use App\Models\AssessmentWindow;
use App\Models\Evaluation;
use App\Models\ExaminerAssignment;
use App\Models\FinalGrade;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\MarkSubmissionService;
use App\Services\MarkVisibilityService;
use App\Services\SemesterService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Module 4 — Evaluations and computed grades.
 *
 * The rule this seeder follows: marks are never invented directly on an
 * `evaluations` row. They are typed into criteria through
 * EvaluationService::saveMarks() and then submitted — the same path an
 * assessor takes in the UI. Only then is the aggregate computed by the real
 * algorithm. If a seeded mark could not have come out of the running system,
 * it does not belong in the database.
 *
 * Grading spread, by progress profile:
 *
 *   completed      supervisor + examiner, both submitted, grade released
 *   near_complete  supervisor submitted only (examiner not yet due)
 *   mid_project    supervisor draft in progress (student sees "not yet marked")
 *   early          no evaluation yet
 *   at_risk        examiner submitted, supervisor still outstanding
 */
class EvaluationSeeder extends Seeder
{
    /**
     * Per-profile marking behaviour.
     *
     * `band` is the target quality, expressed as the fraction of available
     * marks a criterion should receive. A small per-criterion wobble is
     * applied so the resulting form does not look machine-generated.
     *
     * @var array<string, array{supervisor:?array<string,mixed>, examiner:?array<string,mixed>}>
     */
    protected array $plan = [
        'completed' => [
            'supervisor' => ['band' => 0.86, 'submit' => true],
            'examiner'   => ['band' => 0.79, 'submit' => true],
        ],
        'near_complete' => [
            'supervisor' => ['band' => 0.80, 'submit' => true],
            'examiner'   => null,
        ],
        'mid_project' => [
            'supervisor' => ['band' => 0.72, 'submit' => false],
            'examiner'   => null,
        ],
        'early' => [
            'supervisor' => null,
            'examiner'   => null,
        ],
        'at_risk' => [
            'supervisor' => null,
            'examiner'   => ['band' => 0.58, 'submit' => true],
        ],
    ];

public function __construct(
    protected EvaluationService $evaluations,
    protected SemesterService $semesters,
    protected MarkSubmissionService $submissions,
    ) {
    }

    public function run(): void
    {
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();

        /**
         * The panel pool is the academic staff who supervise.
         *
         * This used to be `where('role', 'examiner')`. When that role was merged
         * into `supervisor`, the query started returning **nothing** — and
         * because the call site below guards on `isNotEmpty()`, it failed
         * silently rather than loudly: no examiner was ever assigned, so every
         * seeded project lost its panel. Nothing errored; the panels simply were
         * not there, which is exactly the kind of hole a guard like that hides.
         */
        $examiners = User::query()
            ->where('role', 'supervisor')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $projects = Project::query()
            ->with(['students.activeSupervisions.supervisorProfile.user'])
            ->get();

        /**
         * Marking must be open before any mark can be filed.
         *
         * Marking is fail-closed: a batch whose assessment window is not
         * accepting marks refuses `saveMarks()`, so seeding evaluations without
         * opening the window made the whole seeder fail. The windows are
         * provisioned by AcademicSemesterSeeder (closed, as they are in real
         * life); this opens the ones the demo cohort actually marks into, so the
         * seeded state matches what a coordinator would have set up.
         */
        $this->openMarkingWindows($projects);

        $evaluated = 0;

        foreach ($projects as $index => $project) {
            $profileName = $project->metadata['progress_profile'] ?? 'mid_project';
            $plan        = $this->plan[$profileName] ?? $this->plan['mid_project'];

            /**
             * The panel comes first, and **outside** the markable gate.
             *
             * A panel is seated for every project, because it is the panel that
             * decides the proposal milestone — so every registered student has
             * one, whatever stage they are at. Seating it only for markable
             * projects left eight `approved` proposals with nobody appointed to
             * have approved them, which is a contradiction the milestone screen
             * showed as an empty panel.
             *
             * What varies by stage is the *evaluation*, not the panel: an
             * external reader is only invited to mark once the project is far
             * enough along.
             */
            $panel = $this->pickPanel($examiners, $project, $index);

            foreach ($panel as $seat => $examiner) {
                $this->assignExaminer($project, $examiner, $coordinator, $seat === 0 ? 'chair' : 'member');
            }

            // Marking only makes sense once there is something to mark
            if (! $this->isMarkable($project)) {
                continue;
            }

            /**
             * Open the mark submission first, through the real service.
             *
             * Everything downstream depends on this row existing. Without it the
             * forms are allocated but belong to no submission: `MarkVisibility`
             * reads the components through `submissionFor()` and finds nothing,
             * so a student with three submitted forms sees no mark at all; the
             * examiner queue resolves through the same relation and shows
             * nothing to file; and `readiness()` cannot report on a set it
             * cannot see.
             *
             * PSM 2 made it visible because it is where the form set grew — the
             * seeder marked G, H1, H2 and J by hand while no submission existed
             * to hold them, so nine PSM 2 projects ended up with marks that the
             * application could not display.
             *
             * Opening here means the seeded state is reachable through the
             * running system, which is the rule this seeder holds everywhere
             * else. Allocation is idempotent, so the marking below re-uses the
             * forms this creates rather than making a second set.
             */
            foreach ($project->students as $student) {
                try {
                    $this->submissions->open($project, $student->id, $coordinator);
                } catch (\InvalidArgumentException $e) {
                    $this->command?->warn(
                        "  Could not open a mark submission for {$project->code}/{$student->student_id}: "
                        .$e->getMessage()
                    );
                }
            }

            // --- Supervisor -------------------------------------------------
            if ($plan['supervisor'] !== null) {
                $supervisor = $this->supervisorUserFor($project);

                if ($supervisor !== null) {
                    $this->markAndMaybeSubmit(
                        $project,
                        $supervisor,
                        AssessorType::Supervisor,
                        $plan['supervisor'],
                    );
                    $evaluated++;

                    /**
                     * PSM 2 adds the two progress reports, each carrying its own
                     * 5% as a distinct form (H1 and H2).
                     *
                     * Seeded through the same path as Lampiran G, because a mark
                     * that could not have come out of the running system does not
                     * belong in the database. They are taken only where the main
                     * supervisor form is, so a project that is not yet being
                     * marked does not end up with progress reports and no
                     * evaluation.
                     *
                     * The two bands differ slightly so the reports do not read as
                     * copies of one another — a supervisor filing the same marks
                     * twice is exactly the artefact worth avoiding in demo data.
                     */
                    if ($project->psm_part === 'PSM2') {
                        foreach ([1 => -0.04, 2 => 0.03] as $laporanNum => $shift) {
                            $this->markAndMaybeSubmit(
                                $project,
                                $supervisor,
                                AssessorType::Supervisor,
                                [
                                    'band'   => max(0.0, min(1.0, (float) $plan['supervisor']['band'] + $shift)),
                                    'submit' => $plan['supervisor']['submit'],
                                ],
                                $laporanNum,
                            );
                            $evaluated++;
                        }
                    }
                }
            }

            // --- Examiner evaluations ---------------------------------------
            // The panel is already seated above; this is only the marking, and
            // only where the plan invites an external reader.
            if ($plan['examiner'] !== null && $this->isExaminable($project)) {
                foreach ($panel as $examiner) {
                    $this->markAndMaybeSubmit(
                        $project,
                        $examiner,
                        AssessorType::Examiner,
                        $plan['examiner'],
                    );
                    $evaluated++;
                }
            }
        }

        // --- Release -------------------------------------------------------
        // Completed projects are released so Module 8 has rankable grades.
        $released = $this->releaseCompletedGrades($coordinator);

        $this->command?->info(sprintf(
            '  Evaluations: %d, scores: %d, final grades: %d (%d released).',
            Evaluation::count(),
            \App\Models\EvaluationScore::count(),
            FinalGrade::count(),
            $released
        ));
    }

    // -----------------------------------------------------------------
    // Marking
    // -----------------------------------------------------------------

    /**
     * Open the assessment windows this demo cohort files marks into.
     *
     * One window per Lampiran, so this opens exactly the forms the seeder
     * actually marks. Every form in the batch is opened, because the seeded
     * cohort is a finished demo rather than a batch mid-assessment — the
     * *closed* case is still exercised, by the terms that carry no markable
     * projects.
     *
     * Set directly rather than through `AssessmentWindowService::open()`, which
     * also allocates every student's forms. The seeder already decides which
     * forms exist (via `createForm` in `markAndMaybeSubmit`), so letting the
     * service allocate a second, competing set would duplicate work and make
     * the seeded state depend on allocation order.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    protected function openMarkingWindows(Collection $projects): void
    {
        $pairs = $projects
            ->filter(fn (Project $p) => $this->isMarkable($p))
            ->map(fn (Project $p) => [
                'semester' => $p->academic_semester_id,
                'part'     => $p->psm_part,
            ])
            ->unique(fn (array $p) => $p['semester'].':'.$p['part']);

        foreach ($pairs as $pair) {
            if ($pair['semester'] === null) {
                continue;
            }

            AssessmentWindow::query()
                ->where('academic_semester_id', $pair['semester'])
                ->where('psm_part', $pair['part'])
                ->update([
                    'status'    => AssessmentWindow::STATUS_OPEN,
                    'opened_at' => now(),
                ]);
        }
    }

    /**
     * Open a form, enter marks for every criterion, optionally submit.
     *
     * `$laporanNum` selects a PSM 2 progress report (Lampiran H1 or H2) instead
     * of the assessor's context form. Those are not resolvable from
     * (part, role) — the supervisor has three forms in PSM 2 — so they are
     * requested by number, exactly as the coordinator's endpoint does it.
     */
    protected function markAndMaybeSubmit(
        Project $project,
        User $assessor,
        AssessorType $type,
        array $plan,
        ?int $laporanNum = null,
    ): Evaluation {
        $evaluation = $laporanNum === null
            ? $this->evaluations->createForm($project, $assessor, $type)
            : $this->evaluations->createProgressReportForm($project, $assessor, $laporanNum);

        if (! $evaluation->status->isEditable()) {
            // Already submitted on a previous seed run — leave it alone
            return $evaluation;
        }

        $band = (float) $plan['band'];

        $this->evaluations->saveMarks(
            $evaluation,
            $this->marksFor($evaluation, $band),
            $assessor,
        );

        if ($plan['submit'] === true) {
            $evaluation = $this->evaluations->submit($evaluation->fresh(['scores']), $assessor);
        }

        return $evaluation;
    }

    /**
     * Generate criterion marks around a target band.
     *
     * The wobble is derived from the criterion code rather than from a random
     * number generator, so re-seeding produces the same marks. Reproducibility
     * matters here: a demo that changes its grades every time it is rebuilt is
     * impossible to talk over.
     *
     * @return array<int, array{criterion_code:string, marks:float, comment:?string}>
     */
    protected function marksFor(Evaluation $evaluation, float $band): array
    {
        $marks = [];

        foreach ($evaluation->scores as $score) {
            $max = (float) $score->max_marks;

            // Deterministic offset in roughly -0.06..+0.06 of the criterion max
            $seed   = crc32($evaluation->id.':'.$score->criterion_code);
            $offset = (($seed % 121) - 60) / 1000;

            $fraction = max(0.0, min(1.0, $band + $offset));
            $value    = round($max * $fraction, 2);

            // Granularity follows the criterion. Half a mark reads naturally on a
            // 0-100 chapter rubric, but an official form's small items (Lampiran
            // H is 1.65 / 1.65 / 1.70) need a finer step or an entire band
            // collapses onto a single value.
            $step = $max >= 10.0 ? 0.5 : 0.05;

            $value = round(round($value / $step) * $step, 2);
            $value = max(0.0, min($max, $value));

            $marks[] = [
                'criterion_code' => $score->criterion_code,
                'marks'          => $value,
                'comment'        => $this->commentFor($score->criterion_code, $fraction),
            ];
        }

        return $marks;
    }

    /**
     * Only write a comment where it carries information. A comment on every
     * criterion is noise, and assessors are asked for one only when a mark is
     * low — so the seeded data follows the same convention.
     */
    protected function commentFor(string $criterionCode, float $fraction): ?string
    {
        if ($fraction >= 0.7) {
            return null;
        }

        return match ($criterionCode) {
            'planning'      => 'Timeline slipped in the middle of the semester; a revised plan was requested.',
            'meetings'      => 'Attendance was irregular during the implementation phase.',
            'documentation' => 'Working notes were thin; decisions were often only explained verbally.',
            'independence'  => 'Needed more direction than expected on routine technical decisions.',
            'functionality' => 'Several promised features remain incomplete at submission.',
            'code_quality'  => 'Some duplicated logic and inconsistent naming across modules.',
            'robustness'    => 'Invalid input is not consistently handled; unhandled exceptions observed.',
            'testing'       => 'Test coverage is limited mainly to happy paths.',
            'criticality'   => 'Sources are summarised individually rather than synthesised into an argument.',
            'gap'           => 'The research gap is asserted but not clearly demonstrated from the literature.',
            'sampling'      => 'Sampling strategy is not justified against the target population.',
            'validity'      => 'Threats to validity are acknowledged only briefly.',
            'clarity'       => 'Several sections would benefit from tighter editing.',
            'evidence'      => 'Some claims are not supported by the presented results.',
            'defence_qa'    => 'Answers to methodology questions were hesitant.',
            'reproducibility' => 'The analysis cannot be fully reproduced from the report alone.',
            default         => 'Below the expected standard for this criterion.',
        };
    }

    // -----------------------------------------------------------------
    // Examiner assignment
    // -----------------------------------------------------------------

    /**
     * A full panel of academics who do not supervise this project.
     *
     * Round-robin over the pool, skipping anyone who supervises one of the
     * project's students. This is the same conflict-of-interest rule
     * `AssignmentService` enforces, applied here because a seeded panel that
     * broke it would show up as a contradiction the moment a coordinator opened
     * the panel screen — and the demo data is supposed to be the thing that
     * looks right.
     *
     * Returns as many seats as the pool allows (up to `psm.examiner_panel_size`),
     * so a caller that gets an empty collection skips the project rather than
     * seating an invalid panel.
     *
     * @return Collection<int, User>
     */
    protected function pickPanel(Collection $pool, Project $project, int $offset): Collection
    {
        $supervisorUserIds = $project->students
            ->flatMap(fn (StudentProfile $s) => $s->activeSupervisions
                ->map(fn ($a) => $a->supervisorProfile?->user_id))
            ->filter()
            ->all();

        /**
         * A panel is drawn from the student's **own programme**, for the same
         * reason supervision is.
         *
         * `AssignmentService::assignExaminer()` refuses a cross-programme seat,
         * so a panel picked without this filter would be one the coordinator
         * could never have seated through the application — and the demo would
         * show examiners marking students the rule says they must not touch.
         *
         * The conflict check below is separate and still applies: it stops this
         * student's own supervisor from examining them, which the programme
         * filter does not cover.
         */
        $studentProgramme = $project->students
            ->map(fn (StudentProfile $s) => $s->program_code)
            ->filter()
            ->first();

        $eligible = $pool
            ->reject(fn (User $u) => in_array($u->id, $supervisorUserIds, true))
            // Unknown programme on either side permits the pairing, matching
            // `SupervisorProfile::canSuperviseProgramme()` exactly — the seeder
            // must not be stricter than the rule it is demonstrating.
            ->filter(fn (User $u) => $u->supervisorProfile?->canSuperviseProgramme($studentProgramme) ?? true)
            ->values();

        if ($eligible->isEmpty()) {
            return collect();
        }

        $size = (int) config('psm.examiner_panel_size', 2);

        return collect(range(0, $size - 1))
            ->map(fn (int $seat) => $eligible[($offset + $seat) % $eligible->count()])
            ->unique('id')
            ->values();
    }

    protected function assignExaminer(
        Project $project,
        User $examiner,
        User $coordinator,
        string $panelRole = 'member',
    ): void {
        ExaminerAssignment::updateOrCreate(
            [
                'project_id'  => $project->id,
                'examiner_id' => $examiner->id,
                'psm_part'    => $project->psm_part,
            ],
            [
                // The student anchor. The panel belongs to the student — it
                // decides their proposal and gives their final mark — and every
                // lookup outside the evaluation path goes through this column.
                'student_profile_id' => $project->leader()?->id,
                'panel_role'  => $panelRole,
                'is_active'   => true,
                'assigned_by' => $coordinator->id,
                'notified_at' => now()->subDays(30),
            ]
        );
    }

    // -----------------------------------------------------------------
    // Release
    // -----------------------------------------------------------------

    /**
     * Release grades for projects that are genuinely finished, so that
     * Module 8 has a pool of publishable results to rank.
     *
     * Released grades are mirrored onto the evaluations by the service, which
     * is why this must run after all marking has been submitted.
     *
     * Releasing is a per-term decision, and the term must be opened for release
     * first: EvaluationService::releaseGrade() refuses to publish an individual
     * grade while its semester has results withheld, precisely so that a
     * coordinator cannot publish one result from a cohort the faculty decided
     * to hold. This seeder performs the same two steps in the same order a
     * coordinator would — open the term, then release the grades inside it.
     */
    protected function releaseCompletedGrades(User $coordinator): int
    {
        $count = 0;

        // Marks publish themselves now, so there is no term-level release to
        // flip and no per-grade button to press. What the demo data needs is for
        // every grade's visibility to be reconciled against the forms that back
        // it — which is exactly what `MarkVisibilityService` does, and it is the
        // only path the running application has. Driving the seeder through it
        // keeps the invariant the project holds everywhere else: a demo must
        // never display a state the app could not have produced.
        //
        // A grade whose forms are all in also auto-locks here, so the demo shows
        // the same "complete" picture a real term would.
        $visibility = app(MarkVisibilityService::class);

        $published = FinalGrade::query()
            ->with('project.students')
            ->whereIn('status', ['provisional', 'released', 'locked'])
            ->get();

        foreach ($published as $grade) {
            $project = $grade->project;

            if ($project === null || $project->status !== 'completed') {
                continue;
            }

            // Reconcile as the system, not as the coordinator: nobody attests
            // an automatic publication, and passing the coordinator here would
            // write their name onto a decision they did not make.
            $result = $visibility->syncFor($project, $grade->student_profile_id);

            if ($result['visible']) {
                $count++;
            }
        }

        return $count;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A project can be marked once there is something to mark.
     *
     * The readiness bar differs by part, because the forms differ. A PSM 1
     * supervisor form is Lampiran E — the *final* PSM 1 evaluation — which
     * EvaluationService only opens once every milestone is approved. A PSM 2
     * supervisor form is Lampiran G, the mid-project report, which is markable
     * as soon as anything has been approved.
     *
     * Applying the looser PSM 2 bar to a PSM 1 project used to abort the whole
     * seed the moment the cohort was split across both parts, which is exactly
     * what made the concurrent-batches requirement undemonstrable.
     */
    protected function isMarkable(Project $project): bool
    {
        if ($project->psm_part === 'PSM1') {
            return $project->allMilestonesApproved();
        }

        return $project->milestones()
            ->where('status', MilestoneStatus::Approved->value)
            ->exists();
    }

    /**
     * An examiner is meaningful once there is something final to examine —
     * the implementation chapters under review, or the final report in.
     */
    protected function isExaminable(Project $project): bool
    {
        return $project->milestones()
            ->whereIn('code', ['chapter_4', 'chapter_5', 'final_report'])
            ->whereIn('status', [
                MilestoneStatus::Submitted->value,
                MilestoneStatus::Reviewed->value,
                MilestoneStatus::Approved->value,
            ])
            ->exists();
    }

    protected function supervisorUserFor(Project $project): ?User
    {
        // Each student carries one primary supervision; take the first
        // primary supervisor found across the project's members.
        foreach ($project->students as $student) {
            $assignment = $student->activeSupervisions
                ->sortBy(fn ($a) => $a->role === 'primary' ? 0 : 1)
                ->first();

            if ($assignment?->supervisorProfile?->user !== null) {
                return $assignment->supervisorProfile->user;
            }
        }

        return null;
    }
}
