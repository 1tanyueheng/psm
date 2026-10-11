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
     * `$onlyForm` narrows the allocation to a single Lampiran, which is what a
     * form-scoped assessment window asks for: opening "PSM 1 — Lampiran E" must
     * allocate E and nothing else, so that Lampiran I stays untouched until its
     * own window opens. Null allocates every form the batch needs, which is the
     * batch-wide window's behaviour.
     *
     * The panel is still resolved and recorded even when only one form is being
     * allocated. `expected_panel_size` is the contract for the *student*, not for
     * this one window, so a later window must not have to re-state it.
     *
     * @throws InvalidArgumentException if the project has no active supervision
     *         for this student, or no active panel examiners.
     */
    /**
     * Panels already resolved in this request, keyed by project id.
     *
     * A panel is a property of the *project*, not of the student being
     * allocated, so opening a window for a 15-student batch resolved the same
     * two examiners fifteen times. On a hosted database every one of those is a
     * round trip at ~70ms, which is the difference between an open that returns
     * and one the browser abandons.
     *
     * Per request, not per process: an examiner stood down mid-request must be
     * visible to the next allocation.
     *
     * @var array<int, Collection<int, User>>
     */
    private array $panelCache = [];

    /**
     * Supervisors already resolved in this request, keyed by student profile id.
     *
     * The same idea as `$panelCache`, one level down: a student's supervisor is
     * a property of the student, and resolving it for each of a batch's students
     * cost three round trips each — `supervision_assignments`, then
     * `supervisor_profiles`, then `users`. `warmSupervisors()` turns those into
     * one query for the whole batch.
     *
     * @var array<int, User|null>
     */
    private array $supervisorCache = [];

    /**
     * Resolve every supervisor a batch needs, in one query.
     *
     * Called by the window service before it walks the students, because that
     * walk is the one place where the per-student cost is multiplied by the size
     * of a cohort. Idempotent: any student not covered here still resolves on
     * demand through `resolveSupervisor()`.
     *
     * @param  Collection<int, Project>  $projects
     */
    public function warmSupervisors(Collection $projects): void
    {
        $studentIds = $projects
            ->flatMap(fn (Project $p) => $p->students->pluck('id'))
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return;
        }

        // One query for every supervision in the batch, with the supervisor's
        // profile and user eager-loaded. `latest('id')` semantics are preserved
        // below by taking the last row per student, which is what the per-student
        // `latest('id')->first()` would have returned.
        $assignments = SupervisionAssignment::query()
            ->whereIn('student_profile_id', $studentIds)
            ->active()
            ->with('supervisorProfile.user')
            ->orderBy('id')
            ->get()
            ->groupBy('student_profile_id');

        // Which part each student's project belongs to, so a BOTH-part
        // supervision is matched the same way `resolveSupervisor()` matches it.
        $partByStudent = $projects
            ->flatMap(fn (Project $p) => $p->students->mapWithKeys(
                fn ($s) => [$s->id => $p->psm_part]
            ))
            ->all();

        foreach ($studentIds as $studentId) {
            $part = $partByStudent[$studentId] ?? null;

            $assignment = ($assignments->get($studentId) ?? collect())
                ->filter(fn (SupervisionAssignment $a) => $part === null
                    || $a->psm_part === $part
                    || $a->psm_part === 'BOTH')
                // Last by id, matching `latest('id')`.
                ->last();

            $this->supervisorCache[$studentId] = $assignment?->supervisorProfile?->user;
        }
    }

    public function open(
        Project $project,
        int $studentProfileId,
        Authenticatable $actor,
        ?string $onlyForm = null,
    ): MarkSubmission {
        // The semester is read from the `academic_semester_id` column below, so
        // there is no relation to eager-load here.
        $project->loadMissing(['students']);

        // Resolve from the loaded collection rather than re-querying. The
        // caller has already eager-loaded the students, and a fresh query here
        // was one round trip per student for data already in memory.
        $student = $project->students->firstWhere('id', $studentProfileId)
            ?? $project->students()->where('student_profiles.id', $studentProfileId)->first();

        if (! $student) {
            throw new InvalidArgumentException('Student is not enrolled on this project.');
        }

        /**
         * Fast path: this student is already fully allocated, so there is
         * nothing to do.
         *
         * Re-opening a window is documented as a top-up — "re-opening only tops
         * up students who were missed" — but it walked every student through a
         * full allocation regardless. Resolving the supervisor, resolving the
         * panel, resolving the rubric, checking its weights and re-snapshotting
         * it cost ~10 round trips per student, all to discover that nothing had
         * changed. On a hosted database at ~60ms per query that is ~15 seconds
         * for a 15-student batch of pure no-ops, and the coordinator's browser
         * abandons the request at 30s — so the window appeared to hang and never
         * opened.
         *
         * The check is deliberately narrow. It only skips when a submission row
         * exists *and* every form this call would allocate already exists for
         * the assessor who would receive it. Anything else — a missing form, a
         * panel that grew, a submission that was never opened — falls through to
         * the full path below, which is the path that can actually create
         * something.
         *
         * The assessors are found through the submission's own relations, so
         * this costs two queries rather than the full resolution.
         */
        // Resolve the expected assessors *before* creating anything, so the open
        // fails early if the panel isn't ready. Resolved once and reused: the
        // fast-path check below needs the same two answers, and asking for them
        // twice was eight round trips per student instead of four.
        $supervisor = $this->resolveSupervisor($project, $studentProfileId);
        $examiners  = $this->panelCache[$project->id] ??= $this->resolvePanel($project);

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

        $existingSubmission = MarkSubmission::query()
            ->where('project_id', $project->id)
            ->where('student_profile_id', $studentProfileId)
            ->where('psm_part', $project->psm_part)
            ->first();

        if ($existingSubmission !== null
            && $this->isFullyAllocated($project, $existingSubmission, $onlyForm, $supervisor, $examiners)) {
            return $existingSubmission;
        }

        /**
         * Which Lampiran this open is responsible for.
         *
         * Null means every form the batch needs — the supervisor's own form, the
         * PSM 2 progress reports, and one per panel examiner. A form code means
         * exactly that one, resolved to whoever marks it.
         */
        $wantsSupervisorForm = $onlyForm === null
            || $onlyForm === RubricTemplate::formCodeFor($project->psm_part, AssessorType::Supervisor);

        $wantsExaminerForm = $onlyForm === null
            || $onlyForm === RubricTemplate::formCodeFor($project->psm_part, AssessorType::Examiner);

        // Create the forms (idempotent). Any exception here means the
        // submission was not opened. The return value is not needed: `forms()`
        // reads the rows back from the database.
        if ($wantsSupervisorForm) {
            $this->evaluations->createForm($project, $supervisor, AssessorType::Supervisor);
        }

        /**
         * PSM 2 carries two progress reports as well as the supervisor's main
         * form, and each carries its own 5%.
         *
         * They are allocated here rather than left to a separate coordinator
         * action so that opening a window produces the complete contract for
         * the batch. Leaving them out meant a PSM 2 submission opened with two
         * of its four forms missing, and readiness could never clear without
         * someone remembering to add them by hand.
         *
         * H1 and H2 are separate form codes, so a form-scoped window can open
         * one reporting period without the other.
         */
        if ($project->psm_part === 'PSM2') {
            foreach ([1, 2] as $laporanNum) {
                $code = EvaluationService::progressReportFormCode($laporanNum);

                if ($onlyForm !== null && $onlyForm !== $code) {
                    continue;
                }

                $this->evaluations->createProgressReportForm($project, $supervisor, $laporanNum);
            }
        }

        if ($wantsExaminerForm) {
            foreach ($examiners as $examiner) {
                $this->evaluations->createForm($project, $examiner, AssessorType::Examiner);
            }
        }

        // Record the submission itself.
        return DB::transaction(function () use (
            $project,
            $studentProfileId,
            $actor,
            $examiners,
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
     * Has this submission already received every form the given call would
     * allocate?
     *
     * Mirrors the form set in `open()` deliberately and explicitly, because the
     * two must agree: if this returns true for a form that `open()` would have
     * created, the student silently ends up without it. It therefore answers
     * only "would `open()` insert anything", and errs toward false.
     *
     * Checks the *allocation* (does a form exist for the right assessor and the
     * right Lampiran), not the form's status. A draft an assessor has not filed
     * yet is still allocated; counting it as missing would re-run the whole
     * allocation on every open, which is the behaviour being fixed.
     *
     * The supervisor and panel are passed in rather than resolved here. They are
     * the two most expensive lookups in this class, and resolving them a second
     * time to answer this question cost as much as the check saves.
     *
     * @param  Collection<int, User>  $examiners
     */
    private function isFullyAllocated(
        Project $project,
        MarkSubmission $submission,
        ?string $onlyForm,
        User $supervisor,
        Collection $examiners,
    ): bool {
        // The forms already on this submission, with the Lampiran each belongs
        // to and the assessor holding it. One query.
        $allocated = Evaluation::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->with('rubricTemplate:id,form_code')
            ->get()
            ->map(fn (Evaluation $e) => ($e->rubricTemplate?->form_code ?? '?').'#'.$e->assessor_id)
            ->all();

        $has = fn (string $code, int $assessorId) => in_array($code.'#'.$assessorId, $allocated, true);

        // Panel size is part of the contract: a panel that grew after opening
        // must be re-allocated, so fall through to the full path.
        if ($examiners->count() > (int) $submission->expected_panel_size) {
            return false;
        }

        $supervisorForm = RubricTemplate::formCodeFor($project->psm_part, AssessorType::Supervisor);
        $examinerForm   = RubricTemplate::formCodeFor($project->psm_part, AssessorType::Examiner);

        // The supervisor's own Lampiran, when this call would allocate it.
        if (($onlyForm === null || $onlyForm === $supervisorForm)
            && $supervisorForm !== null
            && ! $has($supervisorForm, $supervisor->id)) {
            return false;
        }

        // PSM 2's two progress reports, which the same supervisor holds.
        if ($project->psm_part === 'PSM2') {
            foreach ([1, 2] as $laporanNum) {
                $code = EvaluationService::progressReportFormCode($laporanNum);

                if ($onlyForm !== null && $onlyForm !== $code) {
                    continue;
                }

                if (! $has($code, $supervisor->id)) {
                    return false;
                }
            }
        }

        // One form per panel examiner.
        if ($onlyForm === null || $onlyForm === $examinerForm) {
            if ($examinerForm === null) {
                return false;
            }

            foreach ($examiners as $examiner) {
                if (! $has($examinerForm, $examiner->id)) {
                    return false;
                }
            }
        }

        return true;
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
     *
     * Consults the batch-level cache first, so allocating a whole cohort does
     * not run this three-query lookup once per student.
     */
    private function resolveSupervisor(Project $project, int $studentProfileId): ?User
    {
        if (array_key_exists($studentProfileId, $this->supervisorCache)) {
            return $this->supervisorCache[$studentProfileId];
        }

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

        return $this->supervisorCache[$studentProfileId] = $assignment?->supervisorProfile?->user;
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