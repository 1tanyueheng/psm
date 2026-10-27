<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\EnrolmentStatus;
use App\Enums\MarkSubmissionStatus;
use App\Enums\PsmPart;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\MarkSubmission;
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
        protected EnrolmentService $enrolments,
    ) {
    }

    /**
     * Start a repeat of a failed attempt.
     *
     * **The two parts are different by faculty rule, and this is the whole of
     * that difference:**
     *
     *  - **PSM 1 repeats from registration.** The student files Lampiran A
     *    again and goes through the chain — new pairing if they choose, new
     *    title, then Lampiran B, proposal, OSHE and Chapters 1-4.
     *  - **PSM 2 repeats in place.** There is no second registration, because
     *    the student already has a title and a supervisor. The new attempt
     *    carries the same title (unless the title itself was the failure) and
     *    the Chapters 5-7 chain starts fresh.
     *
     * What is common to both: **nothing is created here, and no term is
     * guessed.** This method only marks the attempt as repeatable — it opens a
     * fresh `enrolling` enrolment for the part, and the admin places that
     * enrolment into a real semester when the next term's cohort is registered.
     *
     * That is a deliberate change from the first cut of this method, which
     * created the PSM 2 project immediately in `AcademicSemester::current()` or
     * the next term by start date. When neither resolved — no active term, no
     * later term yet created — the project was written with a **null**
     * `academic_semester_id`, so the system recorded a repeat that had no term
     * behind it. Letting the admin pick the term removes the failure mode
     * entirely: there is no code path here that can invent one.
     *
     * The failed attempt is **left alone**. It is not archived, not deleted, and
     * its marks stand — an appeal or an academic-record request is answered from
     * it. Only its supervision and panel were closed, by `resolve()`.
     *
     * @return array{mode:string, project_id:?int, code:?string, message:string, enrolment_id:?int}
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

        $part = PsmPart::tryParse($failed->psm_part);

        if ($part === null || ! $part->isDeliverable()) {
            throw new InvalidArgumentException(
                "{$failed->code} is not a PSM 1 or PSM 2 attempt, so it has nothing to repeat."
            );
        }

        /**
         * A repeat starts in a **later** term, never the failed one.
         *
         * The student did not pass, so their attempt is not being extended — they
         * are being given another go, and an attempt belongs to a term. Until the
         * failed term has closed, that term has not finished judging the attempt,
         * so the button is refused rather than the repeat placed prematurely.
         */
        $failedTerm = AcademicSemester::find($failed->academic_semester_id);

        if ($failedTerm === null) {
            throw new InvalidArgumentException(
                "{$failed->code} has no academic term recorded, so the repeat term cannot be derived."
            );
        }

        if (! $failedTerm->isClosed()) {
            throw new InvalidArgumentException(
                "{$failedTerm->name} is still running. A repeat starts in a later term — "
                .'close this semester first.'
            );
        }

        if ($this->nextTermAfter($failedTerm) === null) {
            throw new InvalidArgumentException(
                "{$failedTerm->name} is the last term on record. Create or activate the "
                .'following term before a repeat can be registered.'
            );
        }

        /**
         * `keep_title` is PSM 2 only, and is recorded on the enrolment rather
         * than baked into a project.
         *
         * For PSM 1 the old title is not carried: the student re-proposes
         * through Lampiran A and may file anything they like, so promising to
         * keep the title would be a promise the system cannot keep.
         */
        $carryTitle = $part === PsmPart::Psm2 && $keepTitle;

        return DB::transaction(function () use ($failed, $student, $actor, $part, $carryTitle) {
            /**
             * The new attempt.
             *
             * `EnrolmentService::enrol()` numbers it, refuses a second live
             * attempt at the same part, and refuses to repeat a part already
             * passed — the same rules a hand-enrolled student gets, because this
             * *is* an enrolment. The term is left null in spirit: the row is
             * created against the term the admin chooses next, via
             * `EnrolmentService::placeRepeat()`.
             */
            $enrolment = $this->enrolments->openRepeat($student, $part, $actor, $failed, $carryTitle);

            $this->audit->log(
                action: AuditAction::ProjectResolved,
                description: "{$part->label()} repeat opened for {$student->student_id} — "
                    ."repeating {$failed->code}"
                    .($carryTitle ? ' (title carried)' : ''),
                subject: $failed,
                actor: $actor,
            );

            return [
                'mode'         => $part === PsmPart::Psm1 ? 'registration' : 'enrolment',
                'project_id'   => null,
                'code'         => null,
                'enrolment_id' => $enrolment->id,
                'message'      => $part === PsmPart::Psm1
                    ? "{$student->student_id} may now file Lampiran A again once enrolled for the "
                        .'next semester. A new PSM 1 project is created when Lampiran B registers the title.'
                    : "A repeat attempt for {$student->student_id} is open. Enrol them in "
                        .'PSM 2 for the next semester to place it.',
            ];
        });
    }

    /**
     * The term that follows this one, by start date.
     *
     * Ordered by when a term *begins* rather than by id: ids are insertion order,
     * and a term created out of sequence would otherwise be "later" than one
     * that actually runs after it. Returns null when the failed term is the last
     * one on record, which the caller reports rather than inventing a term.
     *
     * `starts_at` is nullable on the table, so a term with no recorded start
     * cannot be ordered — it is excluded rather than sorted to the front, where
     * a null would otherwise become "the next term".
     */
    protected function nextTermAfter(AcademicSemester $term): ?AcademicSemester
    {
        if ($term->starts_at === null) {
            return null;
        }

        return AcademicSemester::query()
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $term->starts_at)
            ->orderBy('starts_at')
            ->first();
    }
}
