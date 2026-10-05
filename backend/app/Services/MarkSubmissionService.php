<?php

namespace App\Services;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Enums\MarkSubmissionStatus;
use App\Models\AcademicSemester;
use App\Models\Evaluation;
use App\Models\ExaminerAssignment;
use App\Models\FinalGrade;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\RubricTemplate;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 4 — the coordinator's mark submission lifecycle.
 *
 * open()    allocates the forms (supervisor + every active panel examiner)
 * lock()    attests that every form is in, freezes the aggregate
 * unlock()  lifts the attestation with a mandatory reason
 *
 * The forms themselves are `evaluations` rows — they are allocated by the
 * existing `EvaluationService::createForm()`, which is idempotent. This service
 * only assembles the set, records the promise, and gates the lock.
 */
class MarkSubmissionService
{
    public function __construct(
        private EvaluationService $evaluations,
        private AuditLogger $audit,
    ) {}

    /**
     * Open a mark submission for one student on one project in one PSM part.
     *
     * Idempotent: calling twice returns the existing submission. The forms are
     * created by `EvaluationService::createForm()`, which is also idempotent, so
     * a double-click cannot spawn duplicate forms.
     *
     * The set of forms allocated at this moment is the "contract" this
     * submission promises to receive back. If the panel changes later (an
     * examiner is stood down, or a conflict is declared), the coordinator must
     * reopen — the old promise is void.
     *
     * @throws InvalidArgumentException if the project has no active supervision
     *         for this student, or no active panel examiners.
     */
    public function open(Project $project, int $studentProfileId, Authenticatable $actor): MarkSubmission
    {
        // The semester is read from the `academic_semester_id` column below, so
        // there is no relation to eager-load here.
        $project->loadMissing(['students']);

        $student = $project->students()
            ->where('student_profiles.id', $studentProfileId)
            ->first();

        if (! $student) {
            throw new InvalidArgumentException('Student is not enrolled on this project.');
        }

        // Resolve the expected assessors *before* creating anything, so the open
        // fails early if the panel isn't ready.
        $supervisor = $this->resolveSupervisor($project, $studentProfileId);
        $examiners  = $this->resolvePanel($project);

        if (! $supervisor) {
            throw new InvalidArgumentException(
                'No active supervisor for this student on this project. ' .
                'Assign a supervisor before opening the mark submission.'
            );
        }

        if ($examiners->isEmpty()) {
            throw new InvalidArgumentException(
                'No active examiner panel for this project. ' .
                'Assign at least one examiner before opening the mark submission.'
            );
        }

        // Create the forms (idempotent). Any exception here means the
        // submission was not opened.
        $supEval = $this->evaluations->createForm(
            $project,
            $supervisor,
            AssessorType::Supervisor,
        );

        $exaEvals = $examiners->map(fn (User $e) =>
            $this->evaluations->createForm($project, $e, AssessorType::Examiner)
        );

        // Record the submission itself.
        return DB::transaction(function () use (
            $project,
            $studentProfileId,
            $actor,
            $examiners,
            $supEval,
        ) {
            $existing = MarkSubmission::query()
                ->where('project_id', $project->id)
                ->where('student_profile_id', $studentProfileId)
                ->where('psm_part', $project->psm_part)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // The forms already exist (idempotent createForm). Update the
                // panel size in case it grew, and return.
                $existing->update([
                    'expected_panel_size' => max($existing->expected_panel_size, $examiners->count()),
                ]);

                return $existing->fresh();
            }

            $submission = MarkSubmission::create([
                'project_id'          => $project->id,
                'student_profile_id'  => $studentProfileId,
                'psm_part'            => $project->psm_part,
                'academic_semester_id' => $project->academic_semester_id,
                'status'              => MarkSubmissionStatus::Open,
                'expected_panel_size' => $examiners->count(),
                'opened_by'           => $actor->id,
                'opened_at'           => now(),
            ]);

            return $submission->fresh();
        });
    }

    /**
     * Gate: is the submission ready to be locked?
     *
     * Delegates to the model's `readiness()` which reads live evaluation rows.
     */
    public function isReady(MarkSubmission $submission): bool
    {
        return $submission->isReadyToLock();
    }

    /**
     * Readiness payload for the coordinator's checklist.
     */
    public function readiness(MarkSubmission $submission): array
    {
        return $submission->readiness();
    }

    /**
     * Lock the submission — attest that every expected form is submitted and
     * freeze the aggregate.
     *
     * Never edits a component mark. The `moderated_*` columns on `evaluations`
     * are tombstones from a removed workflow and stay empty. The lock only
     * records *who* attested and *when*, and mirrors the freeze onto the
     * `final_grades` row so the number the student sees carries its own
     * provenance.
     *
     * @throws InvalidArgumentException if the submission is not ready, or if
     *         the final grade could not be computed (missing scheme, etc.).
     */
    public function lock(MarkSubmission $submission, Authenticatable $actor): MarkSubmission
    {
        if (! $submission->isReadyToLock()) {
            $rd = $submission->readiness();
            $names = collect($rd['outstanding'])->pluck('reason')->implode('; ');
            throw new InvalidArgumentException("Cannot lock: {$names}");
        }

        return DB::transaction(function () use ($submission, $actor) {
            // Recompute the final grade from the submitted forms. This is the
            // only place the examiner panel mean is written — it does not live
            // on the individual evaluation rows.
            $finalGrade = $this->evaluations->computeFinalGrade(
                $submission->project,
                $submission->student_profile_id
            );

            $submission->update([
                'status'         => MarkSubmissionStatus::Locked,
                'locked_by'      => $actor->id,
                'locked_at'      => now(),
                'final_grade_id' => $finalGrade->id,
            ]);

            // Mirror the lock onto the grade row so the released number has
            // its own "locked by" provenance.
            $finalGrade->update([
                'status'        => 'locked',
                'locked_at'     => now(),
                'locked_by'     => $actor->id,
            ]);

            $this->audit->log(
                action: AuditAction::MarkSubmissionLocked,
                description: "Mark submission locked for {$submission->project?->code}",
                subject: $submission,
                actor: $actor,
            );

            return $submission->fresh()->load('finalGrade');
        });
    }

    /**
     * Lock the submission if — and only if — every expected form is in.
     *
     * This is the automatic counterpart to `lock()`, called after each
     * submission so the attestation happens the moment it becomes true rather
     * than waiting for a coordinator to press a button. It is the reason the
     * lock can be trusted as "the batch is complete": nobody has to remember to
     * record it.
     *
     * Deliberately silent where `lock()` throws. The caller is an assessor
     * filing a form, not a coordinator asking "may I lock this?" — and the
     * answer to that question, mid-batch, is routinely "not yet". It returns
     * null to mean "nothing to do", which is not an error.
     *
     * `$actor` is the person whose action completed the set. It is recorded on
     * `locked_by` for provenance, but the audit action is
     * `MarkSubmissionAutoLocked` so the trail still distinguishes a system lock
     * from a coordinator's signed-off one.
     */
    public function autoLockIfReady(MarkSubmission $submission, ?User $actor = null): ?MarkSubmission
    {
        if ($submission->status === MarkSubmissionStatus::Locked) {
            return null;
        }

        if (! $submission->isReadyToLock()) {
            return null;
        }

        return DB::transaction(function () use ($submission, $actor) {
            // Re-read under a lock: two assessors can submit the last two forms
            // at the same moment, and both would have seen "not ready" a moment
            // ago.
            $fresh = MarkSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status === MarkSubmissionStatus::Locked) {
                return null;
            }

            if (! $fresh->isReadyToLock()) {
                return null;
            }

            $finalGrade = $this->evaluations->computeFinalGrade(
                $fresh->project,
                $fresh->student_profile_id,
            );

            $fresh->update([
                'status'         => MarkSubmissionStatus::Locked,
                'locked_by'      => $actor?->id,
                'locked_at'      => now(),
                'final_grade_id' => $finalGrade->id,
            ]);

            $finalGrade->update([
                'status'    => 'locked',
                'locked_at' => now(),
                'locked_by' => $actor?->id,
            ]);

            $this->audit->log(
                action: AuditAction::MarkSubmissionAutoLocked,
                description: $actor === null
                    ? "Mark submission closed automatically for {$fresh->project?->code}"
                    : "Mark submission closed automatically for {$fresh->project?->code} — completed by {$actor->name}",
                subject: $fresh,
                actor: $actor,
            );

            return $fresh->fresh()->load('finalGrade');
        });
    }

    /**
     * Unlock a locked submission. Requires a reason, which is stored on the
     * submission for the audit trail.
     *
     * The `final_grades` row is reset to provisional. The evaluation rows are
     * *not* touched — they remain submitted. If a mark needs to change, the
     * assessor must declare a conflict and resubmit, or the coordinator must
     * ask the assessor to reopen their own form (which currently requires
     * deleting the evaluation and re-allocating; a future enhancement could
     * add an explicit reopen).
     *
     * @throws InvalidArgumentException if the submission is not locked.
     */
    public function unlock(MarkSubmission $submission, Authenticatable $actor, string $reason): MarkSubmission
    {
        if ($submission->status !== MarkSubmissionStatus::Locked) {
            throw new InvalidArgumentException('Only a locked submission can be unlocked.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('An unlock reason is required.');
        }

        return DB::transaction(function () use ($submission, $actor, $reason) {
            $submission->update([
                'status'        => MarkSubmissionStatus::Open,
                'locked_by'     => null,
                'locked_at'     => null,
                'unlock_reason' => trim($reason),
                'final_grade_id'=> null,
            ]);

            if ($submission->finalGrade) {
                $submission->finalGrade->update([
                    'status'    => 'provisional',
                    'locked_at' => null,
                    'locked_by' => null,
                ]);
            }

            // Unlocking does not re-hide the mark on its own — that follows from
            // the forms, and is reconciled by MarkVisibilityService after the
            // coordinator stands an examiner down or a conflict is declared.
            // Recorded here regardless, because "who reopened this and why" is
            // the question an appeal turns on.
            $this->audit->log(
                action: AuditAction::MarkSubmissionUnlocked,
                description: "Mark submission unlocked for {$submission->project?->code}: ".trim($reason),
                subject: $submission,
                actor: $actor instanceof User ? $actor : null,
            );

            return $submission->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Resolve the supervisor user for this student on this project.
     *
     * Uses the active `SupervisionAssignment` row. A project can have multiple
     * supervisors (co-supervision) but only one is active per student per part
     * at a time — we take the latest by id.
     */
    private function resolveSupervisor(Project $project, int $studentProfileId): ?User
    {
        // A supervision links a student to a supervisor — there is no
        // project_id on the table. `forPart` matches the project's part or a
        // BOTH-part supervision.
        $assignment = SupervisionAssignment::query()
            ->where('student_profile_id', $studentProfileId)
            ->forPart($project->psm_part)
            ->active()
            ->with('supervisorProfile.user')
            ->latest('id')
            ->first();

        return $assignment?->supervisorProfile?->user;
    }

    /**
     * Resolve the active panel examiners for this project.
     *
     * Returns a collection of User models. The panel size is the count, and
     * `expected_panel_size` on the submission is set to this count at open time.
     * If the panel later changes (an examiner is stood down, or a conflict is
     * declared), the existing submission's `expected_panel_size` does NOT
     * change — the coordinator must unlock and reopen to re-establish the
     * contract. This is deliberate: the lock gate must match the forms that
     * were actually allocated.
     */
    private function resolvePanel(Project $project): Collection
    {
        return ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->active()
            ->with('examiner')
            ->get()
            ->pluck('examiner')
            ->filter();
    }
}