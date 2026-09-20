<?php

namespace Database\Seeders;

use App\Enums\AssessorType;
use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Models\Evaluation;
use App\Models\ExaminerAssignment;
use App\Models\FinalGrade;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Services\EvaluationService;
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
    ) {
    }

    public function run(): void
    {
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();
        $examiners   = User::where('role', 'examiner')->orderBy('id')->get();

        $projects = Project::query()
            ->with(['students.activeSupervisions.supervisorProfile.user'])
            ->get();

        $evaluated = 0;

        foreach ($projects as $index => $project) {
            $profileName = $project->metadata['progress_profile'] ?? 'mid_project';
            $plan        = $this->plan[$profileName] ?? $this->plan['mid_project'];

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

            // --- Examiner ---------------------------------------------------
            // Only assign an examiner once the project is far enough along
            // that an external reader would realistically be invited.
            if ($plan['examiner'] !== null && $examiners->isNotEmpty() && $this->isExaminable($project)) {
                $examiner = $examiners[$index % $examiners->count()];

                $this->assignExaminer($project, $examiner, $coordinator);

                $this->markAndMaybeSubmit(
                    $project,
                    $examiner,
                    AssessorType::Examiner,
                    $plan['examiner'],
                );
                $evaluated++;
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

            // Keep marks to a sensible granularity for a 0-100 rubric
            $value = round($value * 2) / 2;
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

    protected function assignExaminer(Project $project, User $examiner, User $coordinator): void
    {
        ExaminerAssignment::updateOrCreate(
            [
                'project_id'  => $project->id,
                'examiner_id' => $examiner->id,
                'psm_part'    => $project->psm_part,
            ],
            [
                'panel_role'  => 'member',
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
     */
    protected function releaseCompletedGrades(User $coordinator): int
    {
        $count = 0;

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

    /** A project can be marked once its first milestone has been approved. */
    protected function isMarkable(Project $project): bool
    {
        return $project->milestones()
            ->where('status', MilestoneStatus::Approved->value)
            ->exists();
    }

    /**
     * An examiner is meaningful once there is a submission to examine —
     * implementation submitted, or the final report in.
     */
    protected function isExaminable(Project $project): bool
    {
        return $project->milestones()
            ->whereIn('code', ['implement', 'testing', 'report'])
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
