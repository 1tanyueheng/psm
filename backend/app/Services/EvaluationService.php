<?php

namespace App\Services;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Enums\EvaluationStatus;
use App\Enums\NotificationType;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalGrade;
use App\Models\GradeScheme;
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
     */
    public function createForm(Project $project, User $assessor, AssessorType $assessorType): Evaluation
    {
        $rubric = RubricTemplate::resolveFor($project->category, $project->psm_part, $assessorType);

        if ($rubric === null) {
            throw new InvalidArgumentException(
                "No published rubric for category [{$project->category->value}], "
                ."part [{$project->psm_part}], assessor [{$assessorType->value}]."
            );
        }

        if (! $rubric->weightsBalance()) {
            throw new InvalidArgumentException(
                "Rubric [{$rubric->name}] has component weights totalling "
                .$rubric->totalComponentWeight().'%, expected 100%.'
            );
        }

        return DB::transaction(function () use ($project, $assessor, $assessorType, $rubric) {
            $evaluation = Evaluation::firstOrCreate(
                [
                    'project_id'         => $project->id,
                    'assessor_id'        => $assessor->id,
                    'rubric_template_id' => $rubric->id,
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

        return DB::transaction(function () use ($evaluation, $marks, $actor) {
            $snapshot = $evaluation->rubric_snapshot ?? [];
            $criteriaWeights = $this->criteriaWeightMap($snapshot);

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
                $score->weighted_contribution = round(
                    $value * ($weight['component'] / 100) * ($weight['criterion'] / 100),
                    4
                );

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

        $fresh = $evaluation->fresh(['scores', 'rubricTemplate']);

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
    public function moderate(
        Evaluation $evaluation,
        float $newPercent,
        string $reason,
        User $actor,
    ): Evaluation {
        if (! $evaluation->status->isLocked()) {
            throw new InvalidArgumentException('Only a submitted evaluation can be moderated.');
        }

        if ($newPercent < 0 || $newPercent > 100) {
            throw new InvalidArgumentException('A moderated percentage must be between 0 and 100.');
        }

        return DB::transaction(function () use ($evaluation, $newPercent, $reason, $actor) {
            $before = $evaluation->getAttributes();

            $originalPercent = $evaluation->effectivePercent();
            $max = (float) $evaluation->max_score;

            $evaluation->update([
                'status'           => EvaluationStatus::Moderated,
                'final_score'      => round($max * ($newPercent / 100), 2),
                'moderation_delta' => round($newPercent - $originalPercent, 2),
                'moderation_reason'=> $reason,
                'moderated_by'     => $actor->id,
                'moderated_at'     => now(),
            ]);

            $this->audit->log(
                action: AuditAction::EvaluationModerated,
                description: sprintf(
                    'Moderated %.2f%% → %.2f%% (%s)',
                    $originalPercent,
                    $newPercent,
                    $reason
                ),
                subject: $evaluation,
                before: $before,
                after: $evaluation->getAttributes(),
                actor: $actor,
            );

            foreach ($evaluation->project->students as $student) {
                $this->computeFinalGrade($evaluation->project, $student->id);
            }

            return $evaluation->fresh();
        });
    }

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
    public function computeFinalGrade(Project $project, int $studentProfileId): FinalGrade
    {
        $scheme = GradeScheme::ensureFor($project);

        $evaluations = $project->evaluations()
            ->with(['scores', 'assessor'])
            ->whereIn('status', [
                EvaluationStatus::Submitted->value,
                EvaluationStatus::Moderated->value,
                EvaluationStatus::Released->value,
            ])
            ->where('psm_part', $project->psm_part)
            ->get();

        // --- 2. Per-type combination ---------------------------------
        $perType = [];

        foreach (AssessorType::cases() as $type) {
            $group = $evaluations->where('assessor_type', $type);

            if ($group->isEmpty()) {
                continue;
            }

            $perType[$type->value] = [
                'subtotal'        => $this->combineGroup($group, $scheme),
                'assessor_count'  => $group->count(),
                'weight'          => $scheme->weightFor($type),
                'individual'      => $group->map(fn (Evaluation $e) => [
                    'assessor_id'   => $e->assessor_id,
                    'assessor_name' => $e->assessor?->name,
                    'percent'       => $e->effectivePercent(),
                    'moderated'     => $e->hasBeenModerated(),
                ])->values()->all(),
            ];
        }

        // --- 3/4. Weighted sum ---------------------------------------
        $weightedTotal = 0.0;
        $weightSum     = 0.0;

        foreach ($perType as $data) {
            $weightedTotal += $data['subtotal'] * ($data['weight'] / 100);
            $weightSum     += $data['weight'];
        }

        // If the configured weights do not sum to 100 (e.g. only an examiner
        // marked, and supervisors carry 60%), rescale so the aggregate stays
        // on a 0-100 scale rather than silently capping at 40.
        $aggregate = $weightSum > 0
            ? ($weightedTotal / $weightSum) * 100
            : 0.0;

        $aggregate = round($aggregate, 2);

        // --- 5. Blend with milestone completion ----------------------
        $milestoneScore = $project->milestoneProgressPercent();

        // Milestones act as a gate rather than a large share of the mark:
        // a student who never submitted cannot score 100 overall.
        $milestoneBlendPercent = (float) config('psm.milestone_blend_percent', 20);
        $finalMark = round(
            ($aggregate * ((100 - $milestoneBlendPercent) / 100))
            + ($milestoneScore * ($milestoneBlendPercent / 100)),
            2
        );

        // --- 6. Band and persist -------------------------------------
        $band = FinalGrade::bandFor($finalMark);

        $breakdown = [
            'algorithm'            => 'weighted_assessor_subtotals_blended_with_milestones',
            'aggregation_rule'     => $scheme->aggregation,
            'trim_extremes'        => $scheme->trim_extremes,
            'weights_sum_to_100'   => $scheme->weightsBalance(),
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
                'examiner_score'    => $perType[AssessorType::Examiner->value]['subtotal'] ?? null,
                'coordinator_score' => $perType[AssessorType::Coordinator->value]['subtotal'] ?? null,
                'aggregate_percent' => $aggregate,
                'milestone_score'   => $milestoneScore,
                'final_mark'        => $finalMark,
                'grade_letter'      => $band['grade'],
                'grade_point'       => $band['point'],
                'is_pass'           => $finalMark >= (float) $scheme->pass_mark,
                'assessor_count'    => $evaluations->count(),
                'computation_breakdown' => $breakdown,
                'computed_at'       => now(),
            ]
        );

        $this->audit->log(
            action: AuditAction::GradeRecalculated,
            description: "Final mark {$finalMark}% ({$band['grade']}) from {$evaluations->count()} assessor(s)",
            subject: $grade,
        );

        return $grade;
    }

    /**
     * Release a grade so the student can see it and Module 8 may rank it.
     */
    public function releaseGrade(FinalGrade $grade, User $actor): FinalGrade
    {
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
            description: "Released {$grade->final_mark}% ({$grade->grade_letter})",
            subject: $grade,
            before: $before,
            after: $grade->getAttributes(),
            actor: $actor,
        );

        $this->notifications->notify(
            $grade->project->students->pluck('user')->filter(),
            NotificationType::GradeReleased,
            [
                'title'      => 'Grade released',
                'body'       => "Your result for {$grade->project->title} is now available.",
                'action_url' => "/projects/{$grade->project_id}/result",
            ],
            $grade,
        );

        return $grade->fresh();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Combine several evaluations of the same assessor type into one subtotal.
     */
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
            'weighted_mean' => $this->weightedMean($group),
            // default: plain arithmetic mean
            default => round((float) $percentages->avg(), 2),
        };
    }

    /** Respect each supervisor's declared share of responsibility. */
    protected function weightedMean(Collection $group): float
    {
        $totalWeight = 0.0;
        $total       = 0.0;

        foreach ($group as $evaluation) {
            $weight = (float) ($evaluation->assessor_type->defaultWeight() ?: 1.0);

            $total       += $evaluation->effectivePercent() * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($total / $totalWeight, 2) : 0.0;
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
