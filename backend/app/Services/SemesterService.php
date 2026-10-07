<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MarkSubmissionStatus;
use App\Enums\PsmPart;
use App\Models\AcademicSemester;
use App\Models\AssessmentWindow;
use App\Models\FinalGrade;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\SupervisorAgreement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — academic semester lifecycle.
 *
 * A term is the unit the faculty administers: registration opens and closes on
 * it, results are released on it, and it is closed when it ends. All three were
 * previously session-wide, which produced one concrete bug — a coordinator
 * releasing PSM 1 results also published the PSM 2 results sitting in the same
 * session — and one process gap, since there was nothing on which to hang a
 * registration window at all.
 *
 * The "one active term per faculty" rule (requirement §7.3) is enforced here
 * rather than by a partial unique index, because MySQL cannot express "at most
 * one active row" as a constraint. It is enforced in a transaction with a lock
 * on the candidates so two coordinators creating the same term concurrently
 * cannot both succeed.
 */
class SemesterService
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    // -----------------------------------------------------------------
    // Lookup
    // -----------------------------------------------------------------

    /**
     * The term the caller is working in.
     *
     * For a coordinator or admin this is simply the active term — requirement
     * §7.3 defines one per faculty. For a student or supervisor the term they
     * are personally enrolled in is more useful than the faculty's, because a
     * final-year supervisor will also have PSM 1 students from a term whose
     * enrolment has since moved on. Falls back to the active term.
     */
    public function currentFor(User $actor): ?AcademicSemester
    {
        if ($actor->isStudent()) {
            $own = $actor->studentProfile?->academicSemester;

            if ($own !== null && $own->is_active) {
                return $own;
            }
        }

        return AcademicSemester::current();
    }

    // -----------------------------------------------------------------
    // Create / update
    // -----------------------------------------------------------------

    /**
     * Create a term.
     *
     * Refuses a duplicate (session, number) before writing rather than relying on
     * the unique index to throw: "2025/2026 Semester II already exists" is a
     * message a coordinator can act on, where a QueryException is not.
     */
    public function create(array $data, User $actor): AcademicSemester
    {
        $session = trim((string) $data['academic_session']);
        $number = (int) ($data['semester_number'] ?? 1);

        $this->assertTermIsFree($session, $number);

        [$defaultStart, $defaultEnd] = $this->termDates($session, $number);

        return DB::transaction(function () use ($data, $actor, $session, $number, $defaultStart, $defaultEnd) {
            $semester = AcademicSemester::create([
                'name'                  => $data['name'] ?? ($session . ' Semester ' . ($number === 2 ? 'II' : 'I')),
                'academic_session'      => $session,
                'semester_number'       => $number,
                'starts_at'             => $data['starts_at'] ?? $defaultStart,
                'ends_at'               => $data['ends_at'] ?? $defaultEnd,

                // A new term starts inactive unless it is created as the current
                // one. Two active terms would make every "default to the active
                // term" default in the UI ambiguous, and the ambiguity would be
                // invisible until a coordinator released the wrong cohort's
                // grades.
                'is_active'             => (bool) ($data['is_active'] ?? false),
                'is_registration_open'  => (bool) ($data['is_registration_open'] ?? false),
                'metadata'              => $data['metadata'] ?? null,
            ]);

            if ($semester->is_registration_open) {
                $semester->registration_opened_at = now();
                $semester->save();
            }

            if ($semester->is_active) {
                $this->deactivateOthers($semester);
            }

            // Both batches get their (closed) window now, so "marking has not
            // been opened" is a state the coordinator can act on from the first
            // day rather than a missing row.
            $this->provisionAssessmentWindows($semester, $actor);

            $this->audit->log(
                action: AuditAction::SemesterCreated,
                description: "Semester created — {$semester->name}",
                subject: $semester,
                actor: $actor,
            );

            return $semester;
        });
    }

    /**
     * Patch a term.
     *
     * The three flags are separated out from the plain attribute update because
     * two of them have side effects that must not be skipped by a bare
     * `fill()`: activating a term closes the previously active one, and
     * releasing grades stamps the release time and writes the affected marks.
     */
    public function update(AcademicSemester $semester, array $data, User $actor): AcademicSemester
    {
        $changingSessionOrNumber = isset($data['academic_session'])
            || isset($data['semester_number']);

        if ($changingSessionOrNumber) {
            $session = $data['academic_session'] ?? $semester->academic_session;
            $number = (int) ($data['semester_number'] ?? $semester->semester_number);

            $this->assertTermIsFree($session, $number, $semester->id);
        }

        return DB::transaction(function () use ($semester, $data, $actor) {
            // Re-read the row before touching anything.
            //
            // Eloquent decides what to persist by comparing against the values
            // captured when the model was hydrated, not against the row as it
            // stands now. A caller can easily hold an instance loaded before
            // another request deactivated that term, in which case `is_active`
            // is already `true` in memory: assigning `true` leaves the model
            // clean, `save()` issues no UPDATE, and "activate this term"
            // silently does nothing while still returning the term as though
            // it had worked. Refreshing first makes the dirty check honest.
            $semester->refresh();

            foreach (['name', 'academic_session', 'semester_number', 'starts_at', 'ends_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $semester->{$key} = $data[$key];
                }
            }

            if (array_key_exists('is_active', $data) && $data['is_active']) {
                $semester->is_active = true;
                $semester->closed_at = null;
            }

            if (array_key_exists('is_registration_open', $data)) {
                $open = (bool) $data['is_registration_open'];
                $semester->is_registration_open = $open;
                $semester->registration_opened_at = $open ? ($semester->registration_opened_at ?? now()) : null;
            }

            $semester->save();

            if ($semester->is_active) {
                $this->deactivateOthers($semester);
            }

            // A term created before windows were provisioned — or one whose rows
            // were removed by hand — gets them back when it is activated. This is
            // the upgrade path as well as a repair: activating is the moment the
            // term starts being marked, so it is the right moment to guarantee
            // the windows exist.
            $this->provisionAssessmentWindows($semester, $actor);

            $this->audit->log(
                action: AuditAction::SemesterUpdated,
                description: "Semester updated — {$semester->name}",
                subject: $semester,
                actor: $actor,
            );

            return $semester->fresh();
        });
    }

    /**
     * Close a term (requirement §4.1, POST /semesters/{id}/close).
     *
     * Closes registration as well as deactivating the term. A closed term that
     * still accepted Lampiran A submissions would let a student register for a
     * term whose milestones and evaluation windows had all passed.
     *
     * An open assessment window blocks the close, because closing the term while
     * marks are still being filed would strand the assessors mid-window. This
     * check used to guard open title-defence sittings; the defence was folded
     * onto the proposal, so the window is what is left to protect.
     *
     * **Every mark submission must also be complete.** Closing a term freezes
     * it, and a term frozen with forms still outstanding strands those students
     * with no mark and no way to file one — the semester is no longer active, so
     * reopening the marking window would be a lie about which term is running.
     * The same condition gates PSM 1 → PSM 2 progression, so "this batch is
     * finished" has one definition rather than two that can disagree.
     *
     * A student with no submission opened at all counts as outstanding. That is
     * the case worth catching: nothing was ever allocated for them, so no form
     * is missing in a way any list would show.
     */
    public function close(AcademicSemester $semester, User $actor): AcademicSemester
    {
        $openWindows = AssessmentWindow::query()
            ->forSemesterPart($semester, null)
            ->open()
            ->count();

        if ($openWindows > 0) {
            throw new InvalidArgumentException(
                "This semester still has {$openWindows} assessment window(s) open. "
                .'Close them before closing the semester.'
            );
        }

        $this->assertMarkingIsComplete($semester);

        $semester->update([
            'is_active'            => false,
            'is_registration_open' => false,
            'closed_at'            => now(),
        ]);

        $this->audit->log(
            action: AuditAction::SemesterClosed,
            description: "Semester closed — {$semester->name}",
            subject: $semester,
            actor: $actor,
        );

        return $semester->fresh();
    }

    /**
     * Refuse to close a term that still has marks to come.
     *
     * The message names the students and what each is waiting on, because
     * "3 submissions outstanding" leaves a coordinator with nothing to act on
     * while "2210456 (Supervisor form is not submitted yet)" is a phone call.
     *
     * @throws InvalidArgumentException
     */
    protected function assertMarkingIsComplete(AcademicSemester $semester): void
    {
        $submissions = MarkSubmission::query()
            ->where('academic_semester_id', $semester->id)
            ->with(['project', 'studentProfile'])
            ->get();

        if ($submissions->isEmpty()) {
            return;
        }

        $outstanding = $submissions
            ->reject(fn (MarkSubmission $s) => $s->status === MarkSubmissionStatus::Locked);

        if ($outstanding->isEmpty()) {
            return;
        }

        // Cap the list: a whole cohort's worth of names in an error toast is
        // unreadable, and the count plus a sample is enough to act on.
        $named = $outstanding->take(5)->map(function (MarkSubmission $s) {
            $who = $s->studentProfile?->student_id ?? "submission #{$s->id}";
            $part = $s->psm_part ?? '—';

            $reasons = $s->readiness()['outstanding'];

            // `readiness()` returns one entry per missing form plus a summary
            // line, so the same sentence can appear twice — collapse rather
            // than make a coordinator read it again.
            $why = collect($reasons)
                ->pluck('reason')
                ->filter()
                ->unique()
                ->take(2)
                ->implode('; ');

            return "{$who} ({$part}: "
                .($why === '' ? 'no submission opened' : $why)
                .')';
        })->implode(', ');

        $remaining = $outstanding->count() - 5;

        throw new InvalidArgumentException(
            "{$outstanding->count()} of {$submissions->count()} mark submission(s) for "
            ."{$semester->name} are not complete. Every student's forms must be in before the "
            ."semester can be closed. Outstanding: {$named}"
            .($remaining > 0 ? " and {$remaining} more" : '')
            .'.'
        );
    }

    /**
     * Reopen a closed term (POST /semesters/{id}/reopen).
     *
     * Closing was previously a one-way door: `close()` was the only lifecycle
     * action on the term, and nothing reopened it. An accidental close left the
     * term inert — no way back through the API, and no control on the semester
     * screen — while the coordinator was told to "close them before closing the
     * semester" in other messages, so the door clearly was not meant to be
     * one-way.
     *
     * Reopening restores the term as *live*: `is_active` true and `closed_at`
     * cleared, which deactivates whichever term was active instead (a faculty
     * has exactly one live term). **Registration stays closed.** Opening it is a
     * separate, deliberate decision with its own control, and `close()` does not
     * record whether it had been open, so restoring it would be a guess — and
     * guessing "open" would silently reopen Lampiran A for a term the faculty
     * may have deliberately stopped accepting registrations on.
     */
    public function reopen(AcademicSemester $semester, User $actor): AcademicSemester
    {
        if ($semester->is_active && $semester->closed_at === null) {
            throw new InvalidArgumentException("{$semester->name} is not closed — there is nothing to reopen.");
        }

        return DB::transaction(function () use ($semester, $actor) {
            $semester->update([
                'is_active' => true,
                'closed_at' => null,
            ]);

            // Exactly one term is live per faculty, so reactivating this one
            // retires whichever term was active instead.
            $this->deactivateOthers($semester);

            // A term reopened for marking must be markable, so it gets the same
            // guarantee activation does. Windows that already exist are untouched
            // — a reopened term does not silently reopen its marking.
            $this->provisionAssessmentWindows($semester, $actor);

            $this->audit->log(
                action: AuditAction::SemesterReopened,
                description: "Semester reopened — {$semester->name}",
                subject: $semester,
                actor: $actor,
            );

            return $semester->fresh();
        });
    }

    /**
     * Open or close registration for one term.
     *
     * Distinct from close() on purpose: the faculty opens registration well
     * before the term starts and closes it while the term is still running, so
     * this must not require the term to be inactive or vice versa.
     */
    public function setRegistrationOpen(
        AcademicSemester $semester,
        bool $open,
        User $actor
    ): AcademicSemester {
        if ($open && ! $semester->is_active) {
            throw new InvalidArgumentException(
                "{$semester->name} is not the active semester, so registration cannot be opened for it."
            );
        }

        $semester->update([
            'is_registration_open'  => $open,
            'registration_opened_at'=> $open ? ($semester->registration_opened_at ?? now()) : null,
        ]);

        $this->audit->log(
            action: AuditAction::RegistrationWindowSet,
            description: sprintf(
                'Registration %s for %s',
                $open ? 'opened' : 'closed',
                $semester->name
            ),
            subject: $semester,
            actor: $actor,
        );

        return $semester->fresh();
    }

    // -----------------------------------------------------------------
    // Mark status — derived, never set
    // -----------------------------------------------------------------

    /**
     * How far the term's marking has got, read from the submissions themselves.
     *
     * This replaces the `is_marks_released` flag and its Release/Withhold
     * control. That flag was a second source of truth for something the data
     * already knew: a mark is visible as soon as its supervisor form is in, so
     * "have this term's marks gone out?" is not a decision anybody makes any
     * more. Storing it would let the flag drift out of step with the marks it
     * claimed to describe — which is exactly what happened on the live data,
     * where a term read as released while all twelve of its submissions still
     * sat open.
     *
     * `complete` counts submissions where every expected form is in. That is the
     * number a coordinator actually needs: it answers "can I close this term?"
     * without them adding up PSM 1 and PSM 2 in their head.
     *
     * `marks_released` is kept in the payload under its old name so existing
     * screens keep working; it now means "some mark in this term is readable"
     * rather than "a coordinator published the term".
     *
     * @return array{
     *     total:int, complete:int, outstanding:int, published:int,
     *     marks_released:bool, by_part:array<string, array{total:int, complete:int, published:int}>
     * }
     */
    public function marksState(AcademicSemester $semester): array
    {
        $byPart = [];
        $total = 0;
        $complete = 0;
        $published = 0;

        foreach (PsmPart::deliverables() as $part) {
            $submissions = MarkSubmission::query()
                ->where('academic_semester_id', $semester->id)
                ->where('psm_part', $part->value)
                ->get();

            $partComplete = $submissions->where('status', MarkSubmissionStatus::Locked)->count();

            // A submission counts as published when its grade is readable. The
            // grade is the authority: a submission can be locked with its mark
            // still withheld if the forms that backed it were retired.
            $partPublished = FinalGrade::query()
                ->whereIn('project_id', $submissions->pluck('project_id'))
                ->whereIn('status', ['released', 'locked'])
                ->distinct()
                ->count('student_profile_id');

            $byPart[$part->value] = [
                'total'     => $submissions->count(),
                'complete'  => $partComplete,
                'published' => $partPublished,
            ];

            $total += $submissions->count();
            $complete += $partComplete;
            $published += $partPublished;
        }

        return [
            'total'          => $total,
            'complete'       => $complete,
            'outstanding'    => max(0, $total - $complete),
            'published'      => $published,
            'marks_released' => $published > 0,
            'by_part'        => $byPart,
        ];
    }

    // -----------------------------------------------------------------
    // Registration gate (acceptance criterion #2)
    // -----------------------------------------------------------------

    /**
     * May a student submit Lampiran A right now?
     *
     * Returns a reason rather than a boolean so the caller can put the same
     * sentence in front of the student that the gate would have produced — the
     * difference between "Registration is not open" and "Registration for
     * 2025/2026 Semester II is not open" is the difference between a student
     * retrying and a student emailing the coordinator.
     *
     * @return array{open: bool, reason: ?string, semester: ?AcademicSemester}
     */
    public function registrationGate(?AcademicSemester $semester = null): array
    {
        $semester ??= AcademicSemester::current();

        if ($semester === null) {
            return [
                'open'     => false,
                'reason'   => 'No active semester has been set up yet. '
                    .'Your coordinator has to create one before registration can open.',
                'semester' => null,
            ];
        }

        if (! $semester->is_active) {
            return [
                'open'     => false,
                'reason'   => "{$semester->name} is no longer the active semester. "
                    .'Registration is open for the current semester instead.',
                'semester' => $semester,
            ];
        }

        if (! $semester->is_registration_open) {
            return [
                'open'     => false,
                'reason'   => "Registration is not open for {$semester->name} yet. "
                    .'It opens at the start of the semester.',
                'semester' => $semester,
            ];
        }

        return ['open' => true, 'reason' => null, 'semester' => $semester];
    }

    /** Throwing form of registrationGate(), for use inside a transaction. */
    public function assertRegistrationOpen(?AcademicSemester $semester = null): AcademicSemester
    {
        $gate = $this->registrationGate($semester);

        if (! $gate['open']) {
            throw new InvalidArgumentException((string) $gate['reason']);
        }

        return $gate['semester'];
    }

    // -----------------------------------------------------------------
    // Stats
    // -----------------------------------------------------------------

    /**
     * The per-batch figures the semester screen and the coordinator's tabbed
     * cohort overview read (acceptance criteria #1, #8).
     *
     * One query per concern rather than one giant join: the counts are
     * independent and a coordinator's screen does not need row-level detail.
     * `by_part` is always keyed by both PSM1 and PSM2 — even at zero — so the
     * frontend can render two tabs without a null check, and a batch with no
     * students shows as an explicit 0 rather than being missing.
     *
     * @return array<string, mixed>
     */
    public function stats(AcademicSemester $semester): array
    {
        $all = Project::query()
            ->forSemester($semester)
            ->withCount('members')
            ->get(['id', 'psm_part', 'status', 'archived_at']);

        // Archived projects are counted separately rather than by dropping them
        // in the query: they are part of the term's history and the coordinator
        // needs to see that a batch was retired, but they must not inflate the
        // live figures.
        $projects = $all->whereNull('archived_at')->values();

        $byPart = [];

        foreach (PsmPart::deliverables() as $part) {
            $subset = $projects->where('psm_part', $part->value)->values();

            $byPart[$part->value] = [
                'label'               => $part->label(),
                'total'               => $subset->count(),
                'students'            => (int) $subset->sum('members_count'),
                'draft'               => $subset->where('status', 'draft')->count(),
                'submitted'           => $subset->where('status', 'submitted')->count(),
                'in_progress'         => $subset->where('status', 'in_progress')->count(),
                'completed'           => $subset->where('status', 'completed')->count(),
                'archived'            => $all->where('psm_part', $part->value)
                                            ->whereNotNull('archived_at')->count(),

                'with_supervisor'     => $this->countWithSupervisor($subset),
                'with_examiners'      => $this->countWithExaminers($subset),
                'titles_approved'     => $this->countTitlesApproved($semester, $part),
            ];
        }

        return [
            'total_projects' => $projects->count(),
            'by_part'        => $byPart,
            // Attached here rather than fetched separately by the semester
            // screen: the screen already calls this for the per-batch columns,
            // and one extra request per row would be a request per term.
            'marks'          => $this->marksState($semester),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, Project> $projects */
    protected function countWithSupervisor($projects): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        return (int) Project::query()
            ->whereIn('id', $projects->pluck('id'))
            ->whereHas(
                'members.studentProfile.activeSupervisions',
                fn ($q) => $q->where('is_active', true)
            )
            ->distinct()
            ->count('projects.id');
    }

    /** @param \Illuminate\Support\Collection<int, Project> $projects */
    protected function countWithExaminers($projects): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        return (int) Project::query()
            ->whereIn('id', $projects->pluck('id'))
            ->whereHas('examinerAssignments', fn ($q) => $q->where('is_active', true))
            ->distinct()
            ->count('projects.id');
    }

    /**
     * Proposals whose title the panel has approved for this term and batch.
     *
     * This is the replacement for the old title-defence coverage figure: the
     * panel's verdict now lives on the agreement, so the count is of agreements
     * the panel has cleared — the same "is this student's title settled?" answer
     * the coordinator used to read off the defence roster.
     *
     * Counted for both parts, because a PSM 2 agreement carries its own
     * registration even though it inherits the PSM 1 title.
     */
    protected function countTitlesApproved(AcademicSemester $semester, PsmPart $part): int
    {
        return SupervisorAgreement::query()
            ->where('status', SupervisorAgreement::STATUS_APPROVED)
            // A BOTH agreement covers both batches, so it belongs in either
            // count. An exact-part agreement only in its own.
            ->whereIn('psm_part', array_values(array_unique([$part->value, 'BOTH'])))
            // The agreement stores the *session string* ("2025/2026"), which is
            // the column `academic_session` holds — not the term's display name.
            ->where('session', $semester->academic_session)
            ->distinct()
            ->count('student_profile_id');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Reject a duplicate term.
     *
     * Also refuses a term whose number is being moved onto a session where that
     * number is taken — the same check, phrased for the update path.
     */
    protected function assertTermIsFree(string $session, int $number, ?int $ignoreId = null): void
    {
        $clash = AcademicSemester::query()
            ->where('academic_session', $session)
            ->where('semester_number', $number)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($clash) {
            throw new InvalidArgumentException(
                "{$session} Semester " . ($number === 2 ? 'II' : 'I') . ' already exists.'
            );
        }
    }

    /**
     * Provision the two assessment windows for a term, closed.
     *
     * Every term has exactly two batches, and marking for each needs a window
     * before anyone may file a mark. Creating them up front — `scheduled`, so
     * not accepting marks — means the coordinator's "open marking" is a single
     * deliberate act rather than also being a data-entry task, and it removes
     * the "no window yet" case that used to read as *permission* to mark.
     *
     * `firstOrCreate` keyed on (term, part), which the table's unique index also
     * enforces, so re-activating a term or racing two requests cannot duplicate.
     * Existing windows are left exactly as they are: re-provisioning must never
     * reopen marking a coordinator deliberately closed.
     */
    public function provisionAssessmentWindows(AcademicSemester $semester, ?User $actor = null): void
    {
        foreach ([PsmPart::Psm1, PsmPart::Psm2] as $part) {
            AssessmentWindow::firstOrCreate(
                [
                    'academic_semester_id' => $semester->id,
                    'psm_part'             => $part->value,
                ],
                [
                    'name'             => $part->label(),
                    'academic_session' => $semester->academic_session,
                    'status'           => AssessmentWindow::STATUS_SCHEDULED,
                    'created_by'       => $actor?->id,
                ],
            );
        }
    }

    /**
     * Enforce one active term per faculty.
     *
     * `lockForUpdate` on the whole active set is what makes this safe: without
     * it, two coordinators creating the next term at the same moment both read
     * "no other active term" and both insert, leaving every screen's
     * "default to the active term" quietly ambiguous.
     */
    protected function deactivateOthers(AcademicSemester $semester): void
    {
        AcademicSemester::query()
            ->active()
            ->where('id', '!=', $semester->id)
            ->lockForUpdate()
            ->get()
            ->each(fn (AcademicSemester $other) => $other->update(['is_active' => false]));
    }

    /**
     * Conventional term dates, used only when the coordinator supplies none.
     *
     * Semester I is September–February and Semester II is March–August on the
     * assumption that PSM follows the Malaysian academic calendar. Deriving them
     * saves the coordinator typing two dates; they stay editable, and the
     * validation on `ends_at` catches a term that runs past the next one.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function termDates(string $session, int $number): array
    {
        if (! preg_match('/^(\d{4})/', trim($session), $matches)) {
            return [null, null];
        }

        $year = (int) $matches[1];

        return $number === 2
            ? [($year + 1) . '-03-01', ($year + 1) . '-08-31']
            : [$year . '-09-01', ($year + 1) . '-02-28'];
    }
}