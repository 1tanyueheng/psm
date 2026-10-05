<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\FinalGrade;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 — when a mark becomes visible, and what it is made of.
 *
 * This module exists because "who may see this mark" used to be answered in
 * three unrelated places: the term's `is_marks_released` flag, a per-grade
 * Release button, and the student-facing resources, each of which had to agree
 * with the other two. They did not — a coordinator could withhold a term and
 * still publish a single grade, and the resources gated a number the aggregate
 * had already recomputed.
 *
 * Now there is one answer, computed from the forms themselves:
 *
 *   the mark is visible as soon as the supervisor's form is in, and it grows
 *   as the panel files. Nothing has to be released by anybody.
 *
 * A coordinator's remaining act is the **lock** — the attestation that every
 * expected form came back — and that is a fact about completeness, not about
 * visibility. `MarkSubmission::readiness()` already owns the completeness
 * arithmetic, so this service reads it rather than restating it.
 *
 * Two deliberate asymmetries:
 *
 *  - The **examiner half is withheld until the whole panel is in**. One
 *    examiner's Lampiran I is not half a panel average, and showing it as the
 *    examiner component would put a number on the student's page that the final
 *    mark will contradict. The `$expectedPanelSize` gate on
 *    `EvaluationService::computeFinalGrade()` already implements this rule; this
 *    service is what finally passes it a size.
 *
 *  - A mark that **stops** being visible is withheld again. If the only form
 *    backing a mark is retired — a panel re-pairing, a declared conflict — the
 *    student must not keep reading a number nothing supports any more.
 */
class MarkVisibilityService
{
    public function __construct(
        protected EvaluationService $evaluations,
        protected MarkSubmissionService $submissions,
        protected AuditLogger $audit,
    ) {
    }

    // -----------------------------------------------------------------
    // Completeness — the coordinator's checklist
    // -----------------------------------------------------------------

    /**
     * The forms this submission is still waiting on.
     *
     * Reads live evaluation rows through the model's own readiness check, so a
     * stood-down examiner drops out of the expectation automatically rather than
     * being demanded forever.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formsOutstanding(MarkSubmission $submission): array
    {
        return $submission->readiness()['outstanding'];
    }

    /** Is every form this submission promised actually in? */
    public function isComplete(MarkSubmission $submission): bool
    {
        return $submission->isReadyToLock();
    }

    /** The submission covering one student on one project, if one was opened. */
    public function submissionFor(Project $project, int $studentProfileId): ?MarkSubmission
    {
        return MarkSubmission::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->first();
    }

    // -----------------------------------------------------------------
    // Visibility — what the student may see
    // -----------------------------------------------------------------

    /**
     * Which halves of the mark exist right now.
     *
     * `supervisor` is trustworthy as soon as it is true: there is exactly one
     * supervisor form per part. `examiner` is true only when the whole panel has
     * returned, which is what lets the caller hold that half back.
     *
     * @return array{supervisor: bool, examiner: bool}
     */
    public function publishableComponents(MarkSubmission $submission): array
    {
        $forms = $submission->forms();

        $supervisor = $forms->contains(
            fn (Evaluation $e) => $e->assessor_type?->value === 'supervisor'
                && in_array($e->status?->value, $this->visibleStatuses(), true)
        );

        $expected = max(0, (int) $submission->expected_panel_size);

        $returnedExaminers = $forms
            ->filter(fn (Evaluation $e) => $e->assessor_type?->value === 'examiner'
                && in_array($e->status?->value, $this->visibleStatuses(), true))
            ->count();

        return [
            'supervisor' => $supervisor,
            // A panel of size zero is not "complete" — it is unallocated. The
            // two halves then stand on the supervisor form alone.
            'examiner'   => $expected > 0 && $returnedExaminers >= $expected,
        ];
    }

    /**
     * Should this mark be readable by its student, and is it?
     *
     * @return array{visible: bool, grade: ?FinalGrade}
     */
    public function state(MarkSubmission $submission): array
    {
        $grade = FinalGrade::query()
            ->where('project_id', $submission->project_id)
            ->where('student_profile_id', $submission->student_profile_id)
            ->where('psm_part', $submission->psm_part)
            ->first();

        $components = $this->publishableComponents($submission);

        // A locked mark is published too — the lock records that the set of
        // forms completed, not that the mark was withdrawn. Testing for the
        // literal string 'released' here made the student's mark disappear at
        // the moment their last examiner filed.
        return [
            'visible' => $grade !== null && $grade->isReleased()
                && ($components['supervisor'] || $components['examiner']),
            'grade'   => $grade,
        ];
    }

    // -----------------------------------------------------------------
    // The write path
    // -----------------------------------------------------------------

    /**
     * Recompute one student's mark and reconcile its visibility with the forms.
     *
     * Call this after anything that could change what is on the page — an
     * assessor submitting, a coordinator opening a submission, a form being
     * retired. It is safe to call when nothing changed; it writes only on a
     * transition.
     *
     * `$actor` is the person whose action triggered the recompute, or null when
     * the system did it on its own. It is recorded on the audit entry and never
     * on `final_grades.released_by`: **nobody** attests an automatic release, and
     * writing the triggering assessor's name there would attribute a publication
     * decision to someone who only filed a form.
     *
     * @return array{
     *     grade: FinalGrade,
     *     visible: bool,
     *     became_visible: bool,
     *     became_hidden: bool,
     *     components: array{supervisor: bool, examiner: bool}
     * }
     */
    public function syncFor(
        Project $project,
        int $studentProfileId,
        ?User $actor = null
    ): array {
        $submission = $this->submissionFor($project, $studentProfileId);

        $components = $submission !== null
            ? $this->publishableComponents($submission)
            : ['supervisor' => false, 'examiner' => false];

        // The panel gate. Passing the declared size is what withholds the
        // examiner component until both Lampiran I are in; without it the
        // aggregate averages whatever happens to have arrived.
        $expectedPanelSize = $submission !== null
            ? max(0, (int) $submission->expected_panel_size)
            : null;

        $grade = $this->evaluations->computeFinalGrade(
            $project,
            $studentProfileId,
            $expectedPanelSize,
        );

        $shouldBeVisible = $components['supervisor'] || $components['examiner'];

        // `isReleased()`, not a literal comparison: a grade that auto-locked is
        // published *and* frozen, and reading that as "not visible" would make
        // the very next sync re-publish it and drop the lock.
        $wasVisible = $grade->isReleased();

        if ($shouldBeVisible === $wasVisible) {
            // Nothing about the student's view changed, but the *set of forms*
            // may have just completed. The lock is about completeness, not
            // visibility, so it is reconciled on both paths — otherwise the
            // last form in a batch would land, change no mark, and leave the
            // submission open forever.
            $this->autoLock($submission, $actor);

            return [
                'grade'          => $grade,
                'visible'        => $wasVisible,
                'became_visible' => false,
                'became_hidden'  => false,
                'components'     => $components,
            ];
        }

        return DB::transaction(function () use (
            $grade,
            $project,
            $shouldBeVisible,
            $actor,
            $components,
            $submission,
        ) {
            $before = $grade->getAttributes();

            if ($shouldBeVisible) {
                $this->publish($grade, $project);
            } else {
                $this->withhold($grade);
            }

            $fresh = $grade->fresh();

            $this->autoLock($submission, $actor);

            $this->audit->log(
                action: $shouldBeVisible
                    ? AuditAction::GradeReleased
                    : AuditAction::GradeWithheld,
                description: $shouldBeVisible
                    ? $this->publishedDescription($project, $components, $actor)
                    : "Mark withheld — {$project->code} no longer has the forms to support it",
                subject: $fresh,
                before: $before,
                after: $fresh->getAttributes(),
                actor: $actor,
            );

            return [
                'grade'          => $fresh,
                'visible'        => $shouldBeVisible,
                'became_visible' => $shouldBeVisible,
                'became_hidden'  => ! $shouldBeVisible,
                'components'     => $components,
            ];
        });
    }

    /**
     * Lock the submission if this sync completed it.
     *
     * The lock is the attestation that every expected form came back. It used to
     * require a coordinator to press a button; it now happens here, the moment
     * the last form lands, because the condition is a fact the system can read.
     *
     * Called *after* publishing, so a mark can never be locked while still
     * hidden — completeness and visibility move together.
     */
    protected function autoLock(?MarkSubmission $submission, ?User $actor): void
    {
        if ($submission === null) {
            return;
        }

        $this->submissions->autoLockIfReady($submission, $actor);
    }

    /**
     * Every student on a project, recomputed and reconciled.
     *
     * A project is single-member today, but it is not required to be, and the
     * caller triggering this has one evaluation row and no idea which students
     * read it.
     *
     * @return array<int, array{student_profile_id: int, visible: bool, became_visible: bool}>
     */
    public function syncProject(Project $project, ?User $actor = null): array
    {
        $results = [];

        foreach ($project->students as $student) {
            $result = $this->syncFor($project, $student->id, $actor);

            $results[] = [
                'student_profile_id' => $student->id,
                'visible'            => $result['visible'],
                'became_visible'     => $result['became_visible'],
            ];
        }

        return $results;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** The statuses that mean "this form has been filed". */
    protected function visibleStatuses(): array
    {
        return [
            EvaluationStatus::Submitted->value,
            EvaluationStatus::Moderated->value,
            EvaluationStatus::Released->value,
        ];
    }

    /**
     * Make the mark readable.
     *
     * `released_by` is left null on purpose — see the note on `syncFor()`. The
     * `released_at` stamp is what the UI shows ("the mark appeared on …"), and
     * it is honest about being automatic.
     */
    protected function publish(FinalGrade $grade, Project $project): void
    {
        $minAssessors = (int) config('psm.leaderboard.min_assessors', 2);

        $grade->update([
            'status'         => 'released',
            'released_at'    => now(),
            'released_by'    => null,
            'is_publishable' => $grade->qualifiesForPublication($minAssessors),
        ]);

        // Mirror onto the forms, as the coordinator-driven release used to. A
        // released form is terminal, which is what stops an assessor quietly
        // editing a mark the student has already been shown.
        //
        // Scoped to the project's own part: `project_id` alone would sweep in a
        // stored row belonging to the other batch, and this data contains both
        // parts' evaluations against one project node.
        $project->evaluations()
            ->where('psm_part', $project->psm_part)
            ->whereIn('status', [
                EvaluationStatus::Submitted->value,
                EvaluationStatus::Moderated->value,
            ])
            ->update([
                'status'      => EvaluationStatus::Released->value,
                'released_at' => now(),
            ]);
    }

    /**
     * Take the mark back down.
     *
     * The evaluation rows are deliberately left alone: they are evidence that a
     * form was filed, and rewinding them to draft would invite an assessor to
     * rewrite a mark that is still on record. Only the student-facing aggregate
     * is withdrawn.
     */
    protected function withhold(FinalGrade $grade): void
    {
        $grade->update([
            'status'         => 'provisional',
            'released_at'    => null,
            'released_by'    => null,
            'is_publishable' => false,
        ]);
    }

    /** A sentence naming which half of the mark went out. */
    protected function publishedDescription(
        Project $project,
        array $components,
        ?User $actor,
    ): string {
        $halves = match (true) {
            $components['supervisor'] && $components['examiner'] => 'supervisor and examiner marks',
            $components['supervisor'] => 'supervisor mark — the panel is still to file',
            default                   => 'examiner mark',
        };

        return $actor === null
            ? "Mark published automatically for {$project->code} — {$halves}"
            : "Mark published automatically for {$project->code} — {$halves} (triggered by {$actor->name})";
    }
}
