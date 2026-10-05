<?php

namespace App\Services;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Enums\EvaluationStatus;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Models\AssessmentWindow;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\ExaminerAssignment;
use App\Models\FinalGrade;
use App\Models\GradeScheme;
use App\Models\MarkSubmission;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RubricTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 4 — The assessment engine.
 *
 * Owns three things:
 *   1. Creating evaluation forms (with a frozen rubric snapshot)
 *   2. Recording and validating marks
 *   3. Computing the aggregate final grade from multiple assessors
 *
 * The aggregate algorithm is deliberately explicit and its full arithmetic is
 * persisted on the FinalGrade row, so a result can always be explained to an
 * examiner or appealed by a student.
 */
class EvaluationService
{
    public function __construct(
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // Form creation
    // -----------------------------------------------------------------

    /**
     * Create a draft evaluation form for an assessor on a project.
     * Idempotent: calling twice returns the existing form.
     *
     * The rubric is the official Lampiran form for the project's PSM part and
     * the assessor's role (PSM1 -> E / I, PSM2 -> G / J), so the form an
     * assessor fills is determined by the project rather than chosen from a
     * list of every published rubric.
     */
    public function createForm(Project $project, User $assessor, AssessorType $assessorType): Evaluation
    {
        return $this->createEvaluation(
            project: $project,
            assessor: $assessor,
            assessorType: $assessorType,
            formCode: RubricTemplate::formCodeFor($project->psm_part, $assessorType),
            formInstance: 'default',
        );
    }

    /**
     * Lampiran H — the PSM 2 progress report, taken once for Laporan Kemajuan 1
     * and again for Laporan Kemajuan 2, each by the supervisor.
     *
     * Kept apart from createForm() because H shares (PSM2, supervisor) with
     * Lampiran G and is never the form a supervisor fills by default.
     */
    public function createProgressReportForm(Project $project, User $supervisor, int $laporanNum): Evaluation
    {
        if (! in_array($laporanNum, [1, 2], true)) {
            throw new InvalidArgumentException('Laporan Kemajuan must be 1 or 2.');
        }

        return $this->createEvaluation(
            project: $project,
            assessor: $supervisor,
            assessorType: AssessorType::Supervisor,
            formCode: 'H',
            formInstance: 'laporan_'.$laporanNum,
        );
    }

    protected function createEvaluation(
        Project $project,
        User $assessor,
        AssessorType $assessorType,
        ?string $formCode,
        string $formInstance,
    ): Evaluation {
        // No milestone check here on purpose. A form may be *allocated* ahead of
        // the milestones — that is what lets the coordinator open an assessment
        // window for the whole batch at once, and what lets a form sit in an
        // assessor's queue as a draft. The milestone rule is enforced at
        // submission (see submit()), which is where it actually bites: Lampiran
        // E still cannot be *completed* until every milestone is approved.
        //
        // Checking here as well made opening a window allocate nothing for any
        // student still mid-project, which is most of them.
        $rubric = RubricTemplate::resolveFor(
            $project->category,
            $project->psm_part,
            $assessorType,
            $formCode
        );

        if ($rubric === null) {
            throw new InvalidArgumentException(
                "No published rubric for category [{$project->category->value}], "
                ."part [{$project->psm_part}], assessor [{$assessorType->value}]"
                .($formCode !== null ? ", form [{$formCode}]" : '')
                .'.'
            );
        }

        if (! $rubric->weightsBalance()) {
            throw new InvalidArgumentException(
                "Rubric [{$rubric->name}] has component weights totalling "
                .$rubric->totalComponentWeight().'%, expected 100%.'
            );
        }

        return DB::transaction(function () use ($project, $assessor, $assessorType, $rubric, $formInstance) {
            $evaluation = Evaluation::firstOrCreate(
                [
                    'project_id'         => $project->id,
                    'assessor_id'        => $assessor->id,
                    'rubric_template_id' => $rubric->id,
                    'form_instance'      => $formInstance,
                ],
                [
                    'assessor_type'   => $assessorType,
                    'psm_part'        => $project->psm_part,
                    'status'          => EvaluationStatus::Draft,
                    // Freeze the rubric now: later template edits cannot
                    // reinterpret this assessment.
                    'rubric_snapshot' => $rubric->snapshot(),
                    'max_score'       => $rubric->total_marks,
                ]
            );

            // Pre-create the score rows so the form can be saved incrementally
            if ($evaluation->wasRecentlyCreated) {
                foreach ($rubric->components()->with('criteria')->get() as $component) {
                    foreach ($component->criteria as $criterion) {
                        EvaluationScore::create([
                            'evaluation_id'        => $evaluation->id,
                            'rubric_component_id'  => $component->id,
                            'rubric_criterion_id'  => $criterion->id,
                            'component_code'       => $component->code,
                            'criterion_code'       => $criterion->code,
                            'criterion_title'      => $criterion->title,
                            'max_marks'            => $criterion->max_marks,
                            'marks_awarded'        => 0,
                        ]);
                    }
                }

                GradeScheme::ensureFor($project);

                $this->audit->log(
                    action: AuditAction::EvaluationCreated,
                    description: "Evaluation form created for {$assessor->name} on {$project->code}",
                    subject: $evaluation,
                    actor: $assessor->id === auth()->id() ? $assessor : auth()->user(),
                );

                $this->notifications->notify(
                    [$assessor],
                    NotificationType::EvaluationAssigned,
                    [
                        'title'      => 'Evaluation assigned',
                        'body'       => "You have been asked to assess {$project->title}.",
                        'action_url' => "/evaluations/{$evaluation->id}",
                    ],
                    $evaluation,
                );
            }

            return $evaluation->load('scores');
        });
    }

    // -----------------------------------------------------------------
    // Marking
    // -----------------------------------------------------------------

    /**
     * Apply a batch of criterion marks to a draft evaluation.
     *
     * @param  array<int, array{criterion_code:string, marks:float, comment?:string}>  $marks
     */
    public function saveMarks(Evaluation $evaluation, array $marks, ?User $actor = null): Evaluation
    {
        if (! $evaluation->status->isEditable()) {
            throw new InvalidArgumentException(
                "Evaluation [{$evaluation->id}] is {$evaluation->status->value} and can no longer be edited."
            );
        }

        $this->assertMarkingOpen($evaluation->project, $evaluation->psm_part);

        return DB::transaction(function () use ($evaluation, $marks, $actor) {
            $snapshot = $evaluation->rubric_snapshot ?? [];
            $criteriaWeights = $this->criteriaWeightMap($snapshot);

            /**
             * Two kinds of rubric live side by side, and they total differently.
             *
             * A rubric that totals 100 is percentage-driven (the chapter
             * rubrics): a criterion's mark is out of its own max, and the
             * component/criterion percentages carry it onto the 100-point
             * total.
             *
             * The official forms total their own raw marks — E 35, I 30, G 50,
             * H 5, J 40 — and their weight percentages are *derived* from those
             * marks (1.65 of 5 is 33%). Applying the percentages again would
             * count the weight twice and report roughly a tenth of the mark
             * actually earned, so there the mark is already its own
             * contribution.
             */
            $maxMarks = (float) ($snapshot['total_marks'] ?? $evaluation->max_score ?? 100.0);
            $percentageDriven = abs($maxMarks - 100.0) < 0.001;

            $markLookup = collect($marks)->keyBy('criterion_code');

            foreach ($evaluation->scores as $score) {
                $incoming = $markLookup->get($score->criterion_code);

                if ($incoming === null) {
                    continue;
                }

                $value = (float) $incoming['marks'];
                $max   = (float) $score->max_marks;

                if ($value < 0 || $value > $max) {
                    throw new InvalidArgumentException(
                        "Mark for '{$score->criterion_code}' must be between 0 and {$max}."
                    );
                }

                $score->marks_awarded = $value;
                $score->comment       = $incoming['comment'] ?? $score->comment;
                $score->is_flagged    = $value <= ($max * 0.4);

                // Contribution to the rubric total, for transparent totalling
                $weight = $criteriaWeights[$score->criterion_code] ?? ['component' => 0, 'criterion' => 0];

                $score->weighted_contribution = $percentageDriven
                    ? round($value * ($weight['component'] / 100) * ($weight['criterion'] / 100), 4)
                    : round($value, 4);

                $score->save();
            }

            $evaluation->recalculateTotals();
            $evaluation->save();

            $this->audit->log(
                action: AuditAction::EvaluationUpdated,
                description: "Marks saved ({$evaluation->score_percent}%)",
                subject: $evaluation,
                actor: $actor,
            );

            return $evaluation->fresh(['scores']);
        });
    }

    /**
     * Submit a draft: validate completeness, lock it, and trigger recomputation.
     */
    public function submit(Evaluation $evaluation, User $actor): Evaluation
    {
        if (! $evaluation->status->isEditable()) {
            throw new InvalidArgumentException(
                "Only a draft evaluation can be submitted (current: {$evaluation->status->value})."
            );
        }

        $this->assertMarkingOpen($evaluation->project, $evaluation->psm_part);

        $fresh = $evaluation->fresh(['scores', 'rubricTemplate', 'project']);

        // Re-checked at submission as well as at allocation: a coordinator may
        // allocate the form ahead of the milestones (that is allowed — it just
        // cannot be completed), and a milestone can be reopened by a revision
        // request after the form was drafted.
        $this->assertMilestonesCompleteFor($fresh->rubricTemplate?->form_code, $fresh->project);

        $errors = $fresh->validationErrors();

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        return DB::transaction(function () use ($fresh, $actor) {
            $before = $fresh->getAttributes();

            $fresh->recalculateTotals();

            $fresh->update([
                'status'             => EvaluationStatus::Submitted,
                'submitted_at'       => now(),
                'is_late_assessment' => $this->isLate($fresh),
            ]);

            $this->audit->log(
                action: AuditAction::EvaluationSubmitted,
                description: "Marks submitted: {$fresh->score_percent}%",
                subject: $fresh,
                before: $before,
                after: $fresh->getAttributes(),
                actor: $actor,
            );

            // Recompute the project's aggregate with the new input
            foreach ($fresh->project->students as $student) {
                $this->computeFinalGrade($fresh->project, $student->id);
            }

            // Tell the coordinator that moderation is now possible
            $coordinators = User::query()
                ->withRole(\App\Enums\Role::Coordinator)
                ->active()
                ->get();

            $this->notifications->notify(
                $coordinators,
                NotificationType::EvaluationSubmitted,
                [
                    'title'      => 'Marks submitted',
                    'body'       => "{$actor->name} submitted marks for {$fresh->project->title}.",
                    'action_url' => "/reports/projects/{$fresh->project_id}",
                ],
                $fresh,
            );

            return $fresh->fresh();
        });
    }

    /**
     * Coordinator moderation: apply a delta with a mandatory reason.
     * The original assessor's mark is preserved on `raw_score`.
     */
    /*
     * `moderate()` used to live here — a coordinator could overwrite a locked
     * mark with a reason and the delta was recorded.
     *
     * Removed: a coordinator does not award or alter marks. The aggregate reads
     * each assessor's mark exactly as submitted. The `moderated_*` columns and
     * `EvaluationStatus::Moderated` remain so historical rows still resolve;
     * nothing can produce a new one.
     */

    // -----------------------------------------------------------------
    // The aggregate
    // -----------------------------------------------------------------

    /**
     * Compute (or recompute) the final grade for one student on a project.
     *
     * Algorithm
     * ---------
     *  1. Collect every evaluation whose status counts toward the aggregate
     *  2. Group them by assessor type, and within each type combine the
     *     individual percentages using the scheme's `aggregation` rule
     *  3. Weight each type's subtotal by the scheme's per-type weight
     *  4. Sum to an aggregate percentage
     *  5. Blend with the milestone-completion score to get the final mark
     *  6. Apply the grade band and persist the full breakdown
     */
    /**
     * Compute (or recompute) the final grade for one student on a project.
     *
     * When $expectedPanelSize is provided (from a locked MarkSubmission), the
     * examiner component is NULL until every panel member has submitted. This
     * implements the "Final_Examiner_Mark = (P1+P2)/2, NULL until both in"
     * rule. Without the panel size the method computes with whatever forms are
     * submitted — used for the per-assessor recompute after each submission.
     */
    public function computeFinalGrade(
        Project $project,
        int $studentProfileId,
        ?int $expectedPanelSize = null
    ): FinalGrade {
        $scheme = GradeScheme::ensureFor($project);

        $evaluations = $project->evaluations()
            ->with(['scores', 'assessor', 'rubricTemplate'])
            ->whereIn('status', [
                EvaluationStatus::Submitted->value,
                EvaluationStatus::Moderated->value,
                EvaluationStatus::Released->value,
            ])
            ->where('psm_part', $project->psm_part)
            ->get();

        // Only the current panel's forms count.
        //
        // Retiring an examiner deactivates their allocation rather than deleting
        // it, so a form they already marked is still sitting in the table. If it
        // counted, a re-paired project would either average three Lampiran I
        // forms where the panel is two, or keep a mark from someone who is no
        // longer on the panel. The panel is authoritative — a student always has
        // exactly two examiners.
        //
        // Applied whenever an active panel exists, not only when a caller passed
        // a size: the size governs whether the examiner component is written at
        // all, not who is allowed to contribute to it.
        $panelExaminerIds = ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->active()
            ->pluck('examiner_id')
            ->all();

        if ($panelExaminerIds !== []) {
            $evaluations = $evaluations->filter(function (Evaluation $e) use ($panelExaminerIds) {
                if ($e->assessor_type?->value !== 'examiner') {
                    return true;
                }

                return in_array($e->assessor_id, $panelExaminerIds, true);
            });
        }

        // --- 2. Per-form combination ---------------------------------
        //
        // Grouped by the official form (Lampiran code), not by assessor role.
        // The weights are per form: PSM 2 splits 55% of supervisor weighting
        // into Lampiran G 50 and Lampiran H 5, which a per-role grouping would
        // merge. Each form's subtotal is the mean of its evaluations — so the
        // two Lampiran H progress reports average into one 5% component, and
        // the two examiners on Lampiran I or J average into one component,
        // which is the "sum both examiner scores and average" rule.
        $perForm = [];

        foreach ($evaluations->groupBy(fn (Evaluation $e) => $e->rubricTemplate?->form_code ?? 'unmapped') as $formCode => $group) {
            $weight = $scheme->weightForForm((string) $formCode);

            $perForm[$formCode] = [
                'subtotal'       => $this->combineGroup($group, $scheme),
                'evaluation_count' => $group->count(),
                'weight'         => $weight,
                'assessor_type'  => $group->first()->assessor_type->value,
                'individual'     => $group->map(fn (Evaluation $e) => [
                    'assessor_id'   => $e->assessor_id,
                    'assessor_name' => $e->assessor?->name,
                    'percent'       => $e->effectivePercent(),
                    'moderated'     => $e->hasBeenModerated(),
                ])->values()->all(),
            ];
        }

        // --- 3. Per-assessor-type rollup (for the persisted columns) --
        //
        // Weighted by each form's share, so "supervisor score" means the
        // supervisor's contribution and not a flat mean across G and H.
        $perType = [];

        foreach (AssessorType::cases() as $type) {
            $forms = collect($perForm)->filter(fn ($data) => $data['assessor_type'] === $type->value);

            if ($forms->isEmpty()) {
                continue;
            }

            $weightSum = $forms->sum('weight');

            $perType[$type->value] = [
                'subtotal'       => $weightSum > 0
                    ? round($forms->sum(fn ($d) => $d['subtotal'] * $d['weight']) / $weightSum, 2)
                    : round($forms->avg('subtotal'), 2),
                'assessor_count' => $forms->sum('evaluation_count'),
                'forms'          => $forms->keys()->values()->all(),
                'individual'     => $forms->pluck('individual')->flatten(1)->values()->all(),
            ];
        }

        // --- 4. Weighted sum over forms ------------------------------
        $weightedTotal = 0.0;
        $weightSum     = 0.0;

        foreach ($perForm as $data) {
            $weightedTotal += $data['subtotal'] * ($data['weight'] / 100);
            $weightSum     += $data['weight'];
        }

        /**
         * Rescale to 0-100 over the weight actually present.
         *
         * Two different situations both land here, and the same rescale is
         * right for each:
         *
         *   - Only some forms are marked yet (say Lampiran G is in and H is
         *     not). Without rescaling, a project would appear to score out of
         *     55 rather than 95 and look like it was failing.
         *   - The scheme's weights total less than 100 by design, because the
         *     rest of the official weighting is marked outside this system.
         *
         * The result is therefore the weighted mean of what the system holds —
         * a mark, not a final grade. The official total is only reachable once
         * the external components are combined.
         */
        $aggregate = $weightSum > 0
            ? ($weightedTotal / $weightSum) * 100
            : 0.0;

        $aggregate = round($aggregate, 2);

        // --- 5. Blend with milestone completion ----------------------
        // 0 by default: milestones gate the assessment (Lampiran E cannot be
        // completed until every milestone is approved) rather than diluting it.
        $milestoneScore = $project->milestoneProgressPercent();

        $milestoneBlendPercent = (float) config('psm.milestone_blend_percent', 0);
        $finalMark = round(
            ($aggregate * ((100 - $milestoneBlendPercent) / 100))
            + ($milestoneScore * ($milestoneBlendPercent / 100)),
            2
        );

        // Nothing counted, so there is no mark — not a mark of zero.
        //
        // This happens when the panel was re-paired after the only submitted
        // form was retired: no current panel member has marked yet. Persisting
        // 0.00 would show the student a failing result, and would satisfy
        // `qualifiesForPublication()` (which only checks `final_mark !== null`),
        // putting a zero on the public leaderboard.
        if ($evaluations->isEmpty()) {
            $finalMark = null;
            $aggregate = null;
        }

        // --- 6. Persist ----------------------------------------------
        // No letter grade is derived: the system's share of the assessment is
        // not 100%, so banding the mark would invent a grade.
        $breakdown = [
            'algorithm'            => 'weighted_form_subtotals',
            'aggregation_rule'     => $scheme->aggregation,
            'trim_extremes'        => $scheme->trim_extremes,
            'weighting_source'     => 'psm.assessment_weights',
            // Weight of the forms actually marked — less than configured while
            // a form is still outstanding, which is why the aggregate rescales.
            'weights_present'      => round($weightSum, 2),
            'weights_configured'   => round((float) collect($scheme->weights ?? [])->sum('weight'), 2),
            'weights_expected'     => GradeScheme::expectedWeightTotal($project->psm_part),
            'weights_balance'      => $scheme->weightsBalance(),
            'forms'                => $perForm,
            'assessor_types'       => $perType,
            'aggregate_percent'    => $aggregate,
            'milestone_percent'    => $milestoneScore,
            'milestone_blend_percent' => $milestoneBlendPercent,
            'final_mark'           => $finalMark,
            'computed_at'          => now()->toIso8601String(),
        ];

        $grade = FinalGrade::updateOrCreate(
            [
                'project_id'         => $project->id,
                'student_profile_id' => $studentProfileId,
                'psm_part'           => $project->psm_part,
            ],
            [
                'supervisor_score'  => $perType[AssessorType::Supervisor->value]['subtotal'] ?? null,
                'examiner_score'    => $this->resolveExaminerScore(
                    $perType,
                    $expectedPanelSize,
                    $evaluations
                ),
                'examiner_panel_average' => $this->resolveExaminerPanelAverage(
                    $perType,
                    $expectedPanelSize,
                    $evaluations
                ),
                'coordinator_score' => $perType[AssessorType::Coordinator->value]['subtotal'] ?? null,
                'aggregate_percent' => $aggregate,
                'milestone_score'   => $milestoneScore,
                'final_mark'        => $finalMark,
                'assessor_count'    => $evaluations->count(),
                'computation_breakdown' => $breakdown,
                'computed_at'       => now(),
            ]
        );

        $this->audit->log(
            action: AuditAction::GradeRecalculated,
            description: "Final mark {$finalMark} from {$evaluations->count()} assessor(s)",
            subject: $grade,
        );

        return $grade;
    }

    /**
     * Release a grade so the student can see it and Module 8 may rank it.
     *
     * Gated on the academic term's own release flag (requirement §4.2,
     * acceptance criterion #7). Releasing a single grade used to be an entirely
     * independent path from `POST /semesters/{id}/release-marks`, which left
     * two ways to publish results and one way to retract them: a coordinator
     * could withhold a term's results at the term level and still have a
     * per-grade release sitting in the UI — and pressing it would publish a
     * result the faculty had decided not to publish.
     *
     * Withholding the term is therefore authoritative, and the per-grade button
     * only works once the term is open for release. Projects belonging to no
     * term (legacy rows) are released the old way, so pre-semester records
     * remain administrable.
     *
     * @throws InvalidArgumentException
     */
    public function releaseGrade(FinalGrade $grade, User $actor): FinalGrade
    {
        $this->assertTermAllowsRelease($grade);

        $before = $grade->getAttributes();

        $minAssessors = (int) config('psm.leaderboard.min_assessors', 2);

        $grade->update([
            'status'         => 'released',
            'released_at'    => now(),
            'released_by'    => $actor->id,
            // Publishability is decided here, once, rather than at render time
            'is_publishable' => $grade->qualifiesForPublication($minAssessors),
        ]);

        // Mirror the release onto the underlying evaluations
        $grade->project->evaluations()
            ->whereIn('status', [EvaluationStatus::Submitted->value, EvaluationStatus::Moderated->value])
            ->update(['status' => EvaluationStatus::Released->value, 'released_at' => now()]);

        $this->audit->log(
            action: AuditAction::GradeReleased,
            description: "Released mark {$grade->final_mark}",
            subject: $grade,
            before: $before,
            after: $grade->getAttributes(),
            actor: $actor,
        );

        $this->notifications->notify(
            $grade->project->students->pluck('user')->filter(),
            NotificationType::GradeReleased,
            [
                'title'      => 'Mark released',
                'body'       => "Your mark for {$grade->project->title} is now available.",
                'action_url' => "/projects/{$grade->project_id}/result",
            ],
            $grade,
        );

        return $grade->fresh();
    }

    /**
     * One student's mark, broken down by form and then by component.
     *
     * The aggregate is a single number; this is the same mark at the level it
     * was actually awarded, so a student can see which part of the work earned
     * what — and which Lampiran is still outstanding.
     *
     * The form list comes from the configured weights for the part (PSM 1:
     * E, I — PSM 2: G, H, J), not from the evaluations that happen to exist, so
     * a Lampiran nobody has returned yet still appears rather than vanishing.
     * Any filed form the configuration does not mention is added too.
     *
     * The marked set is exactly what `computeFinalGrade` aggregates: submitted
     * and released evaluations for the project's PSM part. Driving it from the
     * mark submission's *current* expected assessors was wrong — when a panel is
     * re-paired, a form already marked by a stood-down examiner still counts
     * towards the released mark but dropped out of the breakdown.
     *
     * Panel examiners share a form, so their component marks are averaged — the
     * same rule the aggregate applies. Draft forms contribute headings but no
     * marks, and are excluded from that average. Projects here are
     * single-member, so every form belongs to this student.
     *
     * @return array<int, array{
     *   form_code:string, assessor_type:?string, title:?string, status:string,
     *   evaluations:int, marked:int, marks:?float, max:float,
     *   components:array<int, array{code:string, title:string, weight_percent:float, marks:?float, max:float}>
     * }>
     */
    public function componentBreakdown(Project $project): array
    {
        // Same panel rule as computeFinalGrade: only the current panel's
        // examiner forms count, so the breakdown can never show a form the
        // released mark excluded.
        $panelExaminerIds = ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->active()
            ->pluck('examiner_id')
            ->all();

        $evaluations = Evaluation::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->with(['rubricTemplate', 'scores'])
            ->get()
            ->filter(function (Evaluation $e) use ($panelExaminerIds) {
                if ($panelExaminerIds === [] || $e->assessor_type?->value !== 'examiner') {
                    return true;
                }

                return in_array($e->assessor_id, $panelExaminerIds, true);
            })
            ->groupBy(fn (Evaluation $e) => $e->rubricTemplate?->form_code ?? '—');

        $formCodes = array_keys((array) config("psm.assessment_weights.{$project->psm_part}", []));

        // A filed form the configuration does not mention still belongs here.
        foreach ($evaluations->keys() as $code) {
            if (! in_array($code, $formCodes, true)) {
                $formCodes[] = $code;
            }
        }

        sort($formCodes);

        $forms = [];

        foreach ($formCodes as $formCode) {
            $form = $this->formBreakdown($project, $formCode, $evaluations->get($formCode) ?? collect());

            if ($form !== null) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * One Lampiran, folded into component rows.
     *
     * @param  Collection<int, Evaluation>  $evaluations
     */
    private function formBreakdown(Project $project, string $formCode, Collection $evaluations): ?array
    {
        $template = $evaluations->first()?->rubricTemplate ?? $this->templateFor($project, $formCode);
        $components = $this->componentShape($evaluations, $template);

        if ($components === []) {
            return null;
        }

        // Only forms an assessor has actually returned carry marks.
        $marked = $evaluations->filter(fn (Evaluation $e) => in_array(
            $e->status?->value,
            [EvaluationStatus::Submitted->value, EvaluationStatus::Released->value],
            true
        ));

        foreach ($marked as $evaluation) {
            $perComponent = [];

            foreach ($evaluation->scores as $score) {
                $code = $score->component_code ?: '—';
                $perComponent[$code] = ($perComponent[$code] ?? 0.0) + (float) $score->marks_awarded;
            }

            foreach ($perComponent as $code => $marks) {
                if (isset($components[$code])) {
                    $components[$code]['marks'][] = $marks;
                }
            }
        }

        $rows = collect($components)
            ->map(fn (array $component, string $code): array => [
                'code'           => $code,
                'title'          => $component['title'],
                'weight_percent' => $component['weight_percent'],
                // Averaged across the form's *marked* assessors only, so a
                // draft panel member cannot drag the mark down.
                'marks'          => $component['marks'] === []
                    ? null
                    : round(array_sum($component['marks']) / count($component['marks']), 2),
                'max'            => round((float) $component['max'], 2),
            ])
            ->values()
            ->all();

        $awarded = array_values(array_filter(
            array_column($rows, 'marks'),
            fn ($mark) => $mark !== null
        ));

        return [
            'form_code'     => $formCode,
            'assessor_type' => ($evaluations->first()?->assessor_type ?? $template?->assessor_type)?->value,
            'title'         => $template?->name,
            'evaluations'   => $evaluations->count(),
            'marked'        => $marked->count(),
            // 'pending' means no assessor has returned this form yet.
            'status'        => $marked->isEmpty() ? 'pending' : 'marked',
            // Raw marks in the Lampiran's own units, never rescaled to 100.
            'marks'         => $awarded === [] ? null : round(array_sum($awarded), 2),
            'max'           => round(array_sum(array_column($rows, 'max')), 2),
            'components'    => $rows,
        ];
    }

    /**
     * Component headings and maxima for a form.
     *
     * Prefers the frozen snapshot of a form that was actually filed, so a
     * historical mark reads the way it was entered; falls back to the live
     * template so an outstanding Lampiran still shows its headings.
     *
     * @param  Collection<int, Evaluation>  $evaluations
     * @return array<string, array{title:string, weight_percent:float, max:float, marks:array<int, float>}>
     */
    private function componentShape(Collection $evaluations, ?RubricTemplate $template): array
    {
        $snapshot = $evaluations->first()?->rubric_snapshot;
        $shape = [];

        if (is_array($snapshot) && ($snapshot['components'] ?? []) !== []) {
            foreach ($snapshot['components'] as $component) {
                $max = 0.0;
                foreach (($component['criteria'] ?? []) as $criterion) {
                    $max += (float) ($criterion['max_marks'] ?? 0);
                }

                $shape[$component['code']] = [
                    'title'          => $component['title'] ?? $component['code'],
                    'weight_percent' => (float) ($component['weight_percent'] ?? 0),
                    'max'            => $max,
                    'marks'          => [],
                ];
            }

            return $shape;
        }

        if ($template === null) {
            return [];
        }

        foreach ($template->components()->with('criteria')->orderBy('sequence')->get() as $component) {
            $max = 0.0;
            foreach ($component->criteria as $criterion) {
                $max += (float) $criterion->max_marks;
            }

            $shape[$component->code] = [
                'title'          => $component->title,
                'weight_percent' => (float) $component->weight_percent,
                'max'            => $max,
                'marks'          => [],
            ];
        }

        return $shape;
    }

    /** The published rubric for one form of one part, preferring the category match. */
    private function templateFor(Project $project, string $formCode): ?RubricTemplate
    {
        return RubricTemplate::query()
            ->where('form_code', $formCode)
            ->where('psm_part', $project->psm_part)
            ->where('is_active', true)
            ->where('is_published', true)
            ->when($project->category !== null, fn ($q) => $q->where(
                fn ($sub) => $sub->whereNull('category')->orWhere('category', $project->category->value)
            ))
            // A category-specific rubric beats a category-agnostic one.
            ->orderByRaw('CASE WHEN category IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Refuse a write when the coordinator's assessment window is closed.
     *
     * A window, once it exists, is authoritative for its batch — that is the
     * point of the coordinator opening one. A batch that has never had a window
     * keeps the old behaviour rather than being frozen out, so this gates only
     * where a window has actually been declared.
     *
     * @throws InvalidArgumentException
     */
    protected function assertMarkingOpen(?Project $project, ?string $psmPart): void
    {
        if ($project === null) {
            return;
        }

        $window = AssessmentWindow::governing($project);

        if ($window === null || $window->acceptsMarks()) {
            return;
        }

        throw new InvalidArgumentException(
            $window->isClosed()
                ? "Marking for {$psmPart} is closed. Ask the coordinator to reopen the assessment window."
                : "Marking for {$psmPart} has not been opened yet. Ask the coordinator to start the assessment window."
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Refuse a per-grade release when the term has not released its results.
     *
     * Reads the term through the project, which is where a grade's enrolment
     * lives — see FinalGrade::scopeForSemester for why it is not the student's
     * own enrolment.
     *
     * @throws InvalidArgumentException
     */
    protected function assertTermAllowsRelease(FinalGrade $grade): void
    {
        $semester = $grade->project?->academicSemester;

        if ($semester === null || $semester->is_marks_released) {
            return;
        }

        throw new InvalidArgumentException(
            "Results for {$semester->name} have not been released yet. "
            .'Release the semester from Manage Semesters, then release individual grades.'
        );
    }

    /**
     * Combine several evaluations of the same assessor type into one subtotal.
     */
    /**
     * Lampiran E — the supervisor's end-of-project evaluation — is gated on the
     * project's milestones.
     *
     * The requirement is explicit: the supervisor evaluates "once all
     * milestones are marked as completed". The check lives in the service
     * rather than a controller because two paths must apply it — allocation and
     * submission — and because a milestone can be sent back for revision after
     * a draft form already exists, so passing at allocation is not enough.
     *
     * Only E is gated. G, H, I and J are seminar and progress assessments that
     * are supposed to happen while the project is still running.
     */
    protected function assertMilestonesCompleteFor(?string $formCode, ?Project $project): void
    {
        if ($formCode !== 'E' || $project === null) {
            return;
        }

        if ($project->allMilestonesApproved()) {
            return;
        }

        $milestones = $project->relationLoaded('milestones')
            ? $project->milestones
            : $project->milestones()->get();

        $outstanding = $milestones
            ->reject(fn (Milestone $m) => $m->status === MilestoneStatus::Approved)
            ->count();

        throw new InvalidArgumentException(
            'Lampiran E is the final PSM 1 evaluation and opens only once every milestone '
            ."has been approved. {$outstanding} of {$milestones->count()} milestone(s) are still outstanding."
        );
    }

    protected function combineGroup(Collection $group, GradeScheme $scheme): float
    {
        $percentages = $group->map(fn (Evaluation $e) => $e->effectivePercent())->sort()->values();

        if ($percentages->isEmpty()) {
            return 0.0;
        }

        // Optionally discard extremes when a panel is large enough for it to be
        // meaningful (needs at least 3 marks, else nothing would remain).
        if ($scheme->trim_extremes && $percentages->count() >= 3) {
            $percentages = $percentages->slice(1, $percentages->count() - 2)->values();

            if ($percentages->isEmpty()) {
                return 0.0;
            }
        }

        return match ($scheme->aggregation) {
            'max' => round((float) $percentages->max(), 2),
            'min' => round((float) $percentages->min(), 2),
            /**
             * 'weighted_mean' is still accepted so an existing scheme keeps
             * working, but it now resolves to the mean.
             *
             * A group is one official form, so every evaluation in it carries
             * the same assessor role and therefore the same weight — weighting
             * them would divide out. The old implementation weighted by the
             * role's default share (supervisor 60 / examiner 40), which no
             * longer exists now that weights belong to forms.
             */
            default => round((float) $percentages->avg(), 2),
        };
    }

    /**
     * Gate the examiner component: if a panel size was declared, the mean is
     * written only when ALL panel members have submitted. Otherwise NULL.
     *
     * This implements: IF Panel_1 AND Panel_2 SUBMITTED THEN mean ELSE NULL.
     */
    private function resolveExaminerScore(
        array $perType,
        ?int $expectedPanelSize,
        Collection $evaluations
    ): ?float {
        if ($expectedPanelSize === null) {
            return $perType[AssessorType::Examiner->value]['subtotal'] ?? null;
        }

        $examinerCount = $evaluations
            ->where('assessor_type', AssessorType::Examiner->value)
            ->count();

        if ($examinerCount < $expectedPanelSize) {
            return null;
        }

        return $perType[AssessorType::Examiner->value]['subtotal'] ?? null;
    }

    /**
     * The raw panel average (unweighted mean of examiner percentages), stored
     * separately so the arithmetic is reproducible.
     */
    private function resolveExaminerPanelAverage(
        array $perType,
        ?int $expectedPanelSize,
        Collection $evaluations
    ): ?float {
        if ($expectedPanelSize === null) {
            return $perType[AssessorType::Examiner->value]['subtotal'] ?? null;
        }

        $examinerCount = $evaluations
            ->where('assessor_type', AssessorType::Examiner->value)
            ->count();

        if ($examinerCount < $expectedPanelSize) {
            return null;
        }

        return $perType[AssessorType::Examiner->value]['subtotal'] ?? null;
    }

    /** Flatten the snapshot into ['criterion_code' => ['component'=>..,'criterion'=>..]]. */
    protected function criteriaWeightMap(array $snapshot): array
    {
        $map = [];

        foreach ($snapshot['components'] ?? [] as $component) {
            foreach ($component['criteria'] ?? [] as $criterion) {
                $map[$criterion['code']] = [
                    'component' => (float) ($component['weight_percent'] ?? 0),
                    'criterion' => (float) ($criterion['weight_percent'] ?? 0),
                ];
            }
        }

        return $map;
    }

    /** Was this assessment submitted after the project's marking window closed? */
    protected function isLate(Evaluation $evaluation): bool
    {
        $finalMilestone = $evaluation->project->milestones()
            ->orderByDesc('sequence')
            ->first();

        if ($finalMilestone?->due_at === null) {
            return false;
        }

        // A two-week grace after the final milestone before marking is "late"
        return now()->isAfter($finalMilestone->due_at->copy()->addDays(14));
    }
}
