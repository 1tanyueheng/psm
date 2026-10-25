<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolving a project — the committee's pass/fail verdict at the end of a part.
 *
 * A project used to end in only one way: it stayed `in_progress` for ever, or an
 * administrator archived it. Neither is a verdict. This service is the missing
 * step between "the marks are in" and "this student's PSM 1 / PSM 2 is over".
 *
 * **Two outcomes, both final.** `completed` means passed; `failed` means the
 * student must repeat. There is no third. A withheld result — the faculty's
 * "Tidak Selesai" — is a decision about *release*, not about whether the work
 * passed, so it is not modelled here; the coordinator withholds a mark on the
 * grade, and resolves the project once the outcome is known.
 *
 * **Resolving ends the people attached to it.** This is the part that was
 * missing and the part with visible consequences: a resolved project's
 * supervisor stops carrying the student, and its panel examiners are released.
 * Without that, a supervisor's load grew for ever — every student they had ever
 * taught stayed `active`, so capacity checks eventually refused every new
 * allocation against a list of finished work.
 *
 * It writes **no archive record**. Archiving is a separate act — filing a record
 * away — and the two were conflated until the archive module was retired. A
 * resolved project remains a normal project row, visible to its student, with a
 * final status.
 */
class ProjectResolutionService
{
    public const OUTCOME_PASSED = 'completed';
    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        protected AuditLogger $audit,
        protected MilestoneService $milestones,
    ) {
    }

    /**
     * Record the verdict on a project and close everything attached to it.
     *
     * @param  string  $outcome  `completed` (passed) or `failed`
     * @param  string|null  $reason  required for a fail, stored for the record
     *
     * @throws InvalidArgumentException when the verdict cannot be applied
     */
    public function resolve(
        Project $project,
        string $outcome,
        ?string $reason,
        User $actor,
    ): Project {
        if (! in_array($outcome, [self::OUTCOME_PASSED, self::OUTCOME_FAILED], true)) {
            throw new InvalidArgumentException(
                "Unknown outcome [{$outcome}]. A project is either completed (passed) or failed."
            );
        }

        /**
         * Resolving twice is refused rather than repeated.
         *
         * A second verdict would overwrite the first silently, and the two
         * carry different consequences — a fail entitles the student to repeat,
         * a pass closes the part. If a verdict genuinely needs changing, the
         * committee reopens the project deliberately.
         */
        if ($project->isFinished()) {
            throw new InvalidArgumentException(
                "{$project->code} has already been resolved as {$project->status}."
            );
        }

        // A fail is a decision somebody has to justify — it costs the student a
        // semester. A pass needs no explanation.
        if ($outcome === self::OUTCOME_FAILED && trim((string) $reason) === '') {
            throw new InvalidArgumentException(
                'A fail must record a reason. The student is entitled to know why they are repeating.'
            );
        }

        return DB::transaction(function () use ($project, $outcome, $reason, $actor) {
            $before = $project->only(['status']);

            $project->forceFill([
                'status' => $outcome,
                'metadata' => array_merge($project->metadata ?? [], [
                    'resolved_at'     => now()->toIso8601String(),
                    'resolved_by'     => $actor->id,
                    'resolved_by_name'=> $actor->name,
                    'outcome'         => $outcome,
                    'outcome_reason'  => $reason,
                ]),
            ])->save();

            $this->closeSupervisions($project, $outcome, $reason);
            $this->releasePanel($project);

            $this->audit->log(
                action: AuditAction::ProjectResolved,
                description: sprintf(
                    '%s resolved as %s%s',
                    $project->code,
                    $outcome === self::OUTCOME_FAILED ? 'FAILED' : 'passed',
                    $reason !== null && trim($reason) !== '' ? " — {$reason}" : '',
                ),
                subject: $project,
                before: $before,
                after: $project->only(['status']),
                actor: $actor,
            );

            return $project->fresh(['milestones', 'members', 'agreement']);
        });
    }

    /**
     * End the supervision on the parts this project covers.
     *
     * A `BOTH` pairing spans PSM 1 and PSM 2, so resolving *one* part must not
     * necessarily end it: a student who passes PSM 1 and continues to PSM 2 is
     * still supervised, and ending the pairing there would leave the PSM 2
     * project with nobody. The pairing is narrowed to the part that is still
     * running, and only ended when no part remains.
     *
     * This mirrors `ArchiveService::closeSupervisionIfStudentHasNoLiveProject()`,
     * which the archive used to do; the difference is the test — `active()`
     * rather than `archived_at`, because a project resolved as failed is also no
     * longer running.
     */
    protected function closeSupervisions(Project $project, string $outcome, ?string $reason): void
    {
        foreach ($project->members as $member) {
            $studentId = $member->student_profile_id;

            $stillRunning = Project::query()
                ->whereHas('members', fn ($q) => $q->where('student_profile_id', $studentId))
                ->active()
                ->whereKeyNot($project->id)
                ->pluck('psm_part')
                ->unique()
                ->all();

            $assignments = SupervisionAssignment::query()
                ->where('student_profile_id', $studentId)
                ->where('is_active', true)
                ->get();

            foreach ($assignments as $assignment) {
                // Still needed for a different part that is running?
                $stillNeeded = collect($stillRunning)->contains(
                    fn (string $part) => $part === $assignment->psm_part || $assignment->psm_part === 'BOTH'
                );

                if ($stillNeeded) {
                    continue;
                }

                $assignment->forceFill([
                    'is_active'  => false,
                    'ended_at'   => now(),
                    'end_reason' => sprintf(
                        '%s resolved as %s%s',
                        $project->code,
                        $outcome === self::OUTCOME_FAILED ? 'failed' : 'passed',
                        $reason !== null && trim($reason) !== '' ? ": {$reason}" : '',
                    ),
                ])->save();
            }
        }
    }

    /**
     * Release the examiners seated for this project's part.
     *
     * The panel exists to examine one part of one student's work. Once a verdict
     * is recorded there is nothing left to examine, and leaving the rows active
     * would keep the examiners "seated" on a finished student for ever.
     *
     * The rows are retired, never deleted — an appeal months later asks who
     * examined this, and the answer has to still be there.
     */
    protected function releasePanel(Project $project): void
    {
        ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Start a repeat of a failed project.
     *
     * **The two parts are different by faculty rule, and this is the whole of
     * that difference:**
     *
     *  - **PSM 1 repeats from registration.** The student files Lampiran A
     *    again and goes through the chain — new pairing if they choose, new
     *    title, then Lampiran B, proposal, OSHE and Chapters 1-4. So this method
     *    does **not** create a project: a PSM 1 project only exists once
     *    Lampiran B registers it, and creating one here would skip the
     *    registration the faculty requires.
     *  - **PSM 2 repeats in place.** There is no second registration, because
     *    the student already has a title and a supervisor. A new PSM 2 project
     *    is created directly with the Chapters 5-7 chain and the same
     *    supervisor and panel carried across.
     *
     * The failed attempt is **left alone**. It is not archived, not deleted, and
     * its marks stand — an appeal or an academic-record request is answered from
     * it. Only its supervision and panel were closed, by `resolve()`.
     *
     * @return array{mode:string, project_id:?int, code:?string, message:string}
     */
    public function repeat(Project $failed, User $actor, bool $keepTitle = true): array
    {
        if ($failed->status !== self::OUTCOME_FAILED) {
            throw new InvalidArgumentException(
                "{$failed->code} has not been recorded as failed. Only a failed project can be repeated."
            );
        }

        $student = $failed->leader();

        if ($student === null) {
            throw new InvalidArgumentException(
                "{$failed->code} has no student on record, so there is nobody to repeat."
            );
        }

        /**
         * PSM 1 — hand the student back to registration.
         *
         * Nothing is created. The return value tells the caller what to do
         * next, and the registration guard now permits it because
         * `assertStudentMayRegister()` looks at *active* projects and this one
         * is failed.
         */
        if ($failed->psm_part === 'PSM1') {
            if ($student->hasActiveProjectInSemester($failed->academic_semester_id, 'PSM1')) {
                throw new InvalidArgumentException(
                    "{$student->student_id} already has a PSM 1 project in progress. "
                    .'Finish or resolve it before starting the repeat.'
                );
            }

            $this->audit->log(
                action: AuditAction::ProjectResolved,
                description: "PSM 1 repeat opened for {$student->student_id} — repeat of {$failed->code}",
                subject: $failed,
                actor: $actor,
            );

            return [
                'mode'       => 'registration',
                'project_id' => null,
                'code'       => null,
                'message'    => "{$student->student_id} may now file Lampiran A again. "
                    .'A new PSM 1 project is created when Lampiran B registers the title.',
            ];
        }

        /**
         * PSM 2 — repeat in place.
         */
        if ($this->livePsm2ForStudent($student) !== null) {
            throw new InvalidArgumentException(
                "{$student->student_id} already has a PSM 2 attempt in progress. "
                .'Finish or resolve it before starting the repeat.'
            );
        }

        $term = AcademicSemester::current() ?? AcademicSemester::find($failed->academic_semester_id);

        if ($term === null) {
            throw new InvalidArgumentException(
                'No academic term is available to place the repeat into. Create or activate one first.'
            );
        }

        return DB::transaction(function () use ($failed, $student, $actor, $keepTitle, $term) {
            $project = Project::create([
                'code' => Project::nextCode('PSM2', $term->academic_session, $student->program_code),
                /**
                 * Reuse the title or start blank.
                 *
                 * Keeping it is the common case — the work continues, and asking
                 * the student to re-propose three titles for a project they have
                 * already been examined on would be theatre. Starting blank is
                 * for a student whose title itself was the failure.
                 */
                'title'    => $keepTitle ? $failed->title : 'Repeat attempt — title not yet registered',
                'abstract' => $keepTitle ? $failed->abstract : null,
                'objectives' => $keepTitle ? $failed->objectives : null,
                'scope'      => $keepTitle ? $failed->scope : null,
                'category'   => $failed->category,
                'psm_part'   => 'PSM2',
                'academic_semester_id' => $term->id,
                'academic_session'     => $term->academic_session,
                'batch'                => $student->batch,
                'program'              => $student->program_code,
                'status'               => 'in_progress',
                'created_by'           => $actor->id,
                'metadata'             => [
                    'repeated_from_project_id' => $failed->id,
                    'repeated_from_code'       => $failed->code,
                    'repeat_number'            => ((int) ($failed->metadata['repeat_number'] ?? 1)) + 1,
                    'kept_title'               => $keepTitle,
                ],
            ]);

            ProjectMember::create([
                'project_id'           => $project->id,
                'student_profile_id'   => $student->id,
                'is_leader'            => true,
                'contribution_percent' => 100.00,
            ]);

            $this->milestones->instantiateFor($project, now());

            // The student's enrolment moves to the repeating term, so every
            // scoped screen (capacity, dashboards) sees them in the right one.
            $student->forceFill(['academic_semester_id' => $term->id])->save();

            $this->audit->log(
                action: AuditAction::ProjectResolved,
                description: "PSM 2 repeat started for {$student->student_id} — "
                    ."{$project->code} repeats {$failed->code}",
                subject: $project,
                actor: $actor,
            );

            return [
                'mode'       => 'in_place',
                'project_id' => $project->id,
                'code'       => $project->code,
                'message'    => "{$project->code} created for {$student->student_id}.",
            ];
        });
    }

    /** The student's running PSM 2 attempt, if any. */
    protected function livePsm2ForStudent($student): ?Project
    {
        return Project::query()
            ->whereHas('members', fn ($q) => $q->where('student_profile_id', $student->id))
            ->where('psm_part', 'PSM2')
            ->active()
            ->first();
    }
}
