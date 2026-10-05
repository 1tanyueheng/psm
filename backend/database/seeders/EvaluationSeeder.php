<?php

namespace Database\Seeders;

use App\Enums\AssessorType;
use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Models\AcademicSemester;
use App\Models\Evaluation;
use App\Models\ExaminerAssignment;
use App\Models\FinalGrade;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\EvaluationService;
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
     * Open a form, enter marks for every criterion, optionally submit.
     */
    protected function markAndMaybeSubmit(
        Project $project,
        User $assessor,
        AssessorType $type,
        array $plan,
    ): Evaluation {
        $evaluation = $this->evaluations->createForm($project, $assessor, $type);

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

        $eligible = $pool
            ->reject(fn (User $u) => in_array($u->id, $supervisorUserIds, true))
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

        // Which terms actually have a releasable grade? Opening a term that has
        // nothing to release would leave a needless "results released" flag
        // lying around on the demo data.
        //
        // Driven from `projects` rather than a join off `final_grades`: both
        // tables carry a `status` column, so a hand-rolled join makes the
        // where clauses ambiguous and MySQL rejects the query outright.
        $termIds = Project::query()
            ->where('status', 'completed')
            ->whereNotNull('academic_semester_id')
            ->whereHas('finalGrades', fn ($q) => $q
                ->where('status', 'provisional')
                ->whereNotNull('final_mark'))
            ->pluck('academic_semester_id')
            ->unique();

        foreach ($termIds as $termId) {
            $semester = AcademicSemester::find($termId);

            if ($semester === null || $semester->is_marks_released) {
                continue;
            }

            $this->semesters->setMarkRelease($semester, true, $coordinator);
        }

        FinalGrade::query()
            ->with('project')
            ->where('status', 'provisional')
            ->get()
            ->each(function (FinalGrade $grade) use (&$count, $coordinator) {
                $project = $grade->project;

                if ($project === null) {
                    return;
                }

                // Only release when the course is over and enough assessors
                // have reported — the same condition the real workflow uses.
                if ($project->status !== 'completed') {
                    return;
                }

                if ($grade->assessor_count < 2) {
                    return;
                }

                if ($grade->final_mark === null) {
                    return;
                }

                $this->evaluations->releaseGrade($grade, $coordinator);
                $count++;
            });

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
