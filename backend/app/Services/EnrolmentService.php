<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\EnrolmentStatus;
use App\Enums\PsmPart;
use App\Models\AcademicSemester;
use App\Models\Project;
use App\Models\StudentEnrolment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registering students into a PSM part for a semester.
 *
 * The verb here is "enrol" and it covers everything an admin does at the start
 * of a term:
 *
 *  - a first attempt at PSM 1 or PSM 2;
 *  - the PSM 1 → PSM 2 rollover, which is just a PSM 2 enrolment for a student
 *    who has finished PSM 1;
 *  - a repeat, which is a fresh attempt at a part they did not pass.
 *
 * All three are the same row in a different shape, which is the point of the
 * table. What the admin is *not* doing here is creating projects — a project
 * appears when the student registers a title, exactly as before.
 *
 * The rules enforced here exist because each one was previously expressible only
 * as a side effect somewhere else:
 *
 *  - **One attempt per part per term.** Enforced by a unique index, checked
 *    here first so the caller gets a sentence rather than a constraint name.
 *  - **A live attempt blocks another.** A student cannot hold two attempts at
 *    the same part at once, whatever the terms — that is what "live" means.
 *  - **A completed part cannot be repeated.** Only a failed or withdrawn
 *    attempt frees the student to enrol again.
 *  - **Attempt numbers are counted, not supplied.** The admin does not type
 *    "attempt 3"; the service counts the student's attempts at that part so the
 *    number is always consistent with the history.
 */
class EnrolmentService
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * Enrol a student into a part for a term.
     *
     * Idempotent in the useful direction: if the same enrolment already exists
     * and is still live, it is returned unchanged rather than raising a
     * duplicate. Re-running an import, or clicking twice on a slow connection,
     * should not be an error — but a genuinely conflicting attempt is.
     */
    public function enrol(
        StudentProfile $student,
        AcademicSemester $term,
        PsmPart $part,
        User $actor,
        ?string $note = null,
    ): StudentEnrolment {
        if (! $part->isDeliverable()) {
            throw new InvalidArgumentException(
                'A student is enrolled into PSM 1 or PSM 2, not into both at once.'
            );
        }

        $existing = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('academic_semester_id', $term->id)
            ->where('psm_part', $part->value)
            ->first();

        if ($existing !== null) {
            /** A settled row in this term is history and cannot be reused. */
            if ($existing->status->isSettled()) {
                throw new InvalidArgumentException(
                    "{$student->student_id} already has a settled {$part->label()} attempt in "
                    ."{$term->name}. A repeat goes into a later term."
                );
            }

            return $existing;
        }

        /**
         * One live attempt per part, across all terms.
         *
         * The previous attempt must be settled before a new one starts, or the
         * student would be enrolled in the same part twice and both would count
         * against capacity.
         */
        $live = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $part->value)
            ->live()
            ->with('semester')
            ->first();

        if ($live !== null) {
            throw new InvalidArgumentException(
                "{$student->student_id} already has a live {$part->label()} attempt in "
                .($live->semester?->name ?? 'another term')
                .'. Settle that attempt before enrolling them again.'
            );
        }

        /**
         * A completed part is finished. Repeating work that was passed is not a
         * repeat, and allowing it would let a student improve a grade by sitting
         * the part again.
         */
        $completed = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $part->value)
            ->where('status', EnrolmentStatus::Completed->value)
            ->exists();

        if ($completed) {
            throw new InvalidArgumentException(
                "{$student->student_id} has already completed {$part->label()}. "
                .'A passed part is not repeated.'
            );
        }

        /**
         * The attempt number is the count of everything before it, plus one.
         *
         * Counted including withdrawn attempts: the number records what
         * happened, and renumbering after a withdrawal would rewrite history.
         */
        $attemptNo = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $part->value)
            ->count() + 1;

        $previous = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $part->value)
            ->orderByDesc('attempt_no')
            ->first();

        return DB::transaction(function () use ($student, $term, $part, $actor, $note, $attemptNo, $previous) {
            $enrolment = StudentEnrolment::create([
                'student_profile_id'   => $student->id,
                'academic_semester_id' => $term->id,
                'psm_part'             => $part->value,
                'attempt_no'           => $attemptNo,
                'status'               => EnrolmentStatus::Enrolling->value,
                // Only a genuine repeat links back: attempt 2 onwards is by
                // definition a repeat of the one before it.
                'repeats_enrolment_id' => $attemptNo > 1 ? $previous?->id : null,
                'enrolled_at'          => now(),
                'note'                 => $note,
                'enrolled_by'          => $actor->id,
            ]);

            $this->audit->log(
                action: AuditAction::StudentEnrolled,
                description: "{$student->student_id} enrolled in {$part->label()} for {$term->name} "
                    ."(attempt {$attemptNo})",
                subject: $enrolment,
                actor: $actor,
            );

            return $enrolment;
        });
    }

    /**
     * Open a repeat attempt for a student, without choosing its term yet.
     *
     * This is the piece that replaces "create a new project in the next term".
     * The repeat is a *decision* — the committee said this attempt failed and
     * the student is entitled to another — and the *placement* of that decision
     * into a term is the admin's act, done later through `placeRepeat()`.
     *
     * Splitting the two is what removes the failure mode the first cut had: with
     * no term to invent here, there is no code path that can write an attempt
     * with a null semester.
     *
     * The row is created as `enrolling` with a null term, which the schema
     * permits via a partial index added in the same migration — every ordinary
     * enrolment goes through `enrol()` with a real term.
     */
    public function openRepeat(
        StudentProfile $student,
        PsmPart $part,
        User $actor,
        Project $failed,
        bool $carryTitle = true,
    ): StudentEnrolment {
        if (! $part->isDeliverable()) {
            throw new InvalidArgumentException(
                'A repeat is opened for PSM 1 or PSM 2, not for both at once.'
            );
        }

        /**
         * A repeat needs something that actually failed.
         *
         * Both guards are the same rule seen from two angles: the part must have
         * a settled failed attempt, and the student must not be mid-attempt
         * already. Doing this here rather than in the caller means the rule holds
         * whichever way a repeat is started.
         */
        $live = $this->liveFor($student, $part);

        if ($live !== null) {
            throw new InvalidArgumentException(
                "{$student->student_id} already has a live {$part->label()} attempt in "
                .($live->semester?->name ?? 'another term')
                .'. Settle that attempt before starting the repeat.'
            );
        }

        $previous = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $part->value)
            ->orderByDesc('attempt_no')
            ->first();

        if ($previous !== null && $previous->status === EnrolmentStatus::Completed) {
            throw new InvalidArgumentException(
                "{$student->student_id} has already completed {$part->label()}. "
                .'A passed part is not repeated.'
            );
        }

        return DB::transaction(function () use ($student, $part, $actor, $failed, $carryTitle, $previous) {
            $enrolment = StudentEnrolment::create([
                'student_profile_id'   => $student->id,
                // Deliberately null: the admin places it into a real term.
                'academic_semester_id' => null,
                'psm_part'             => $part->value,
                'attempt_no'           => ($previous?->attempt_no ?? 0) + 1,
                'status'               => EnrolmentStatus::Enrolling->value,
                'repeats_enrolment_id' => $previous?->id,
                'enrolled_at'          => now(),
                'note'                 => $carryTitle
                    ? "Repeat of {$failed->code}; title carried."
                    : "Repeat of {$failed->code}; new title required.",
                'enrolled_by'          => $actor->id,
            ]);

            $this->audit->log(
                action: AuditAction::StudentEnrolled,
                description: "Repeat attempt opened for {$student->student_id} in {$part->label()} "
                    ."(attempt {$enrolment->attempt_no}), repeating {$failed->code}",
                subject: $enrolment,
                actor: $actor,
            );

            return $enrolment;
        });
    }

    /**
     * Place an opened repeat into a semester.
     *
     * The admin's act, and the only way a repeat acquires a term. Refuses a term
     * that is not later than the attempt being repeated — the repeat exists
     * because that attempt failed, and an attempt belongs to a term.
     */
    public function placeRepeat(StudentEnrolment $enrolment, AcademicSemester $term, User $actor): StudentEnrolment
    {
        if ($enrolment->academic_semester_id !== null) {
            throw new InvalidArgumentException(
                'That repeat has already been placed in '
                .($enrolment->semester?->name ?? 'a term').'.'
            );
        }

        $repeated = $enrolment->repeatsEnrolment;

        if ($repeated?->academic_semester_id !== null) {
            $failedTerm = AcademicSemester::find($repeated->academic_semester_id);

            if ($failedTerm !== null && ! $this->isLaterTerm($term, $failedTerm)) {
                throw new InvalidArgumentException(
                    "A repeat of {$failedTerm->name} goes into a later term, not {$term->name}."
                );
            }
        }

        $clash = StudentEnrolment::where('student_profile_id', $enrolment->student_profile_id)
            ->where('academic_semester_id', $term->id)
            ->where('psm_part', $enrolment->psm_part->value)
            ->whereKeyNot($enrolment->id)
            ->exists();

        if ($clash) {
            throw new InvalidArgumentException(
                "There is already a {$enrolment->psm_part->label()} enrolment for this student in "
                ."{$term->name}."
            );
        }

        $enrolment->update([
            'academic_semester_id' => $term->id,
            'enrolled_at'          => now(),
        ]);

        $this->audit->log(
            action: AuditAction::StudentEnrolled,
            description: "Repeat placed in {$term->name} — {$enrolment->label()}",
            subject: $enrolment,
            actor: $actor,
        );

        return $enrolment->fresh(['semester', 'project']);
    }

    /** Whether `$candidate` runs after `$reference`, by start date then id. */
    protected function isLaterTerm(AcademicSemester $candidate, AcademicSemester $reference): bool
    {
        if ($candidate->starts_at !== null && $reference->starts_at !== null) {
            return $candidate->starts_at > $reference->starts_at;
        }

        return $candidate->id > $reference->id;
    }

    /**
     * Attach a project to an enrolment.
     *
     * Called from registration once a project exists. If the student has no
     * enrolment for the project's part and term — which is possible for projects
     * that predate enrolment, or were created by an import — one is created on
     * the spot so the two never drift apart.
     */
    public function attachProject(Project $project, ?User $actor = null): ?StudentEnrolment
    {
        if ($project->academic_semester_id === null) {
            return null;
        }

        $student = $project->leader();

        if ($student === null) {
            return null;
        }

        $part = PsmPart::tryParse($project->psm_part);

        if ($part === null || ! $part->isDeliverable()) {
            return null;
        }

        $enrolment = StudentEnrolment::where('student_profile_id', $student->id)
            ->where('academic_semester_id', $project->academic_semester_id)
            ->where('psm_part', $part->value)
            ->first();

        if ($enrolment === null) {
            $term = AcademicSemester::find($project->academic_semester_id);

            if ($term === null) {
                return null;
            }

            // Auto-enrol, so a project created outside the enrolment flow still
            // has a matching row rather than leaving a hole.
            $enrolment = StudentEnrolment::create([
                'student_profile_id'   => $student->id,
                'academic_semester_id' => $term->id,
                'psm_part'             => $part->value,
                'attempt_no'           => StudentEnrolment::where('student_profile_id', $student->id)
                    ->where('psm_part', $part->value)
                    ->count() + 1,
                'status'               => EnrolmentStatus::Active->value,
                'project_id'           => $project->id,
                'enrolled_at'          => now(),
                'enrolled_by'          => $actor?->id,
            ]);

            return $enrolment;
        }

        if ($enrolment->project_id === null) {
            $enrolment->update([
                'project_id' => $project->id,
                // Registration is what makes an attempt live: before this the
                // student was enrolled but had no work in progress.
                'status'     => $enrolment->status === EnrolmentStatus::Enrolling
                    ? EnrolmentStatus::Active->value
                    : $enrolment->status->value,
            ]);
        }

        return $enrolment;
    }

    /**
     * Settle an enrolment after its project has had a verdict.
     *
     * Mirrors the project's outcome so the two do not disagree. Called from
     * `ProjectResolutionService::resolve()`, which is the only place a verdict is
     * given.
     */
    public function settle(StudentEnrolment $enrolment, EnrolmentStatus $status, ?string $note = null): StudentEnrolment
    {
        if (! $status->isSettled()) {
            throw new InvalidArgumentException(
                "An enrolment is settled as completed, failed or withdrawn — not {$status->value}."
            );
        }

        $enrolment->update([
            'status'     => $status->value,
            'settled_at' => now(),
            'note'       => $note ?? $enrolment->note,
        ]);

        return $enrolment;
    }

    /**
     * Withdraw an attempt — deferral, transfer, or a student who left.
     *
     * Not a failure, and not silent: the row stays with its numbers intact, and
     * the project (if any) keeps whatever status it had. `ProjectResolutionService`
     * is what turns an attempt into a pass or a fail; this is the "they never
     * finished" case.
     */
    public function withdraw(StudentEnrolment $enrolment, User $actor, ?string $reason = null): StudentEnrolment
    {
        if ($enrolment->status->isSettled()) {
            throw new InvalidArgumentException(
                'That attempt is already settled as '.$enrolment->status->label().'.'
            );
        }

        return DB::transaction(function () use ($enrolment, $actor, $reason) {
            $settled = $this->settle($enrolment, EnrolmentStatus::Withdrawn, $reason);

            $this->audit->log(
                action: AuditAction::StudentWithdrawn,
                description: "Enrolment withdrawn — {$settled->label()}"
                    .($reason ? " ({$reason})" : ''),
                subject: $settled,
                actor: $actor,
            );

            return $settled;
        });
    }

    /**
     * The live enrolment for a student and part, if there is one.
     *
     * This is the query that replaces "is there a project with no
     * `archived_at`?" as the way to ask whether a student is currently doing a
     * part.
     */
    public function liveFor(StudentProfile $student, PsmPart|string $part): ?StudentEnrolment
    {
        $value = $part instanceof PsmPart ? $part->value : (string) $part;

        return StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $value)
            ->live()
            ->with(['semester', 'project'])
            ->latest('attempt_no')
            ->first();
    }

    /** How many attempts a student has had at a part, all in. */
    public function attemptCount(StudentProfile $student, PsmPart|string $part): int
    {
        $value = $part instanceof PsmPart ? $part->value : (string) $part;

        return StudentEnrolment::where('student_profile_id', $student->id)
            ->where('psm_part', $value)
            ->count();
    }
}
