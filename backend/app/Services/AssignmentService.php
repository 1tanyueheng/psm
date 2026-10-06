<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\PsmPart;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\SupervisionAssignment;
use App\Models\SupervisorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 2 — Supervisor↔student pairing and examiner allocation.
 *
 * All capacity and duplication rules live here rather than in the controller,
 * so the API, any future import tool, and the seeder share one enforcement
 * point.
 */
class AssignmentService
{
    public function __construct(
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // Supervisor ↔ student
    // -----------------------------------------------------------------

    /**
     * Pair a supervisor with a student.
     *
     * Every pairing is a primary supervision: the system has no co-supervisor
     * role, so a non-primary request is refused before anything is written.
     *
     * Enforces, in order:
     *   1. the requested role is primary
     *   2. the PSM part is one the system knows about
     *   3. the supervisor is accepting students
     *   4. the supervisor has room **in that part** (requirement §7.1)
     *   5. the student has spare supervisor slots
     *   6. this exact pairing is not already active for this PSM part
     *
     * `$psmPart` defaults to `BOTH` rather than `PSM2`, which is what a caller
     * that does not care produces: one supervisor carrying a student through the
     * whole programme. A `BOTH` pairing consumes a slot in *both* parts — see
     * SupervisorProfile::hasCapacityFor() — so it is checked against each cap in
     * turn rather than against one combined number.
     */
    public function assignSupervisor(
        StudentProfile $student,
        SupervisorProfile $supervisor,
        User $actor,
        string $psmPart = 'BOTH',
        string $role = SupervisionAssignment::ROLE_PRIMARY,
        ?float $responsibility = null,
        ?string $note = null,
    ): SupervisionAssignment {
        if ($role !== SupervisionAssignment::ROLE_PRIMARY) {
            throw new InvalidArgumentException(
                'Only a primary supervisor can be assigned; this system has no '
                ."co-supervisor role (received '{$role}')."
            );
        }

        $part = PsmPart::tryParse($psmPart);

        if ($part === null) {
            throw new InvalidArgumentException(
                "'{$psmPart}' is not a PSM part this system recognises. "
                .'Expected '.implode(', ', PsmPart::values()).'.'
            );
        }

        if (! $supervisor->is_accepting_students) {
            throw new InvalidArgumentException(
                "{$supervisor->label()} is not currently accepting new students."
            );
        }

        /**
         * Programme first, then capacity.
         *
         * Order matters for the message the coordinator reads: "no capacity" is
         * misleading when the real reason is that this supervisor could never
         * take this student, and a coordinator would go looking for a free slot
         * that does not exist for them. The programme is a property of who the
         * person is, so it is the more fundamental refusal.
         */
        if ($refusal = $supervisor->programmeRefusal($student->program_code, $student->student_id)) {
            throw new InvalidArgumentException($refusal);
        }

        // The student's own enrolment decides which term's cap applies. Taking it from
        // the student rather than a new parameter means the gate cannot be called
        // the old all-terms way by a caller that simply forgets to pass one — a
        // default of "no term" would reintroduce exactly the bug requirement
        // 7.1 describes, where last term's finished students keep consuming the
        // cap and the supervisor is refused every new allocation in this term.
        $this->assertPartCapacity($supervisor, $part, $student->academic_semester_id);

        if ($student->remainingSupervisorSlots() <= 0) {
            throw new InvalidArgumentException(
                "{$student->student_id} already has the maximum of "
                ."{$student->max_supervisors} supervisors."
            );
        }

        $existing = SupervisionAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('supervisor_profile_id', $supervisor->id)
            ->where('psm_part', $part->value)
            ->where('is_active', true)
            ->exists();

        if ($existing) {
            throw new InvalidArgumentException(
                "This supervisor is already assigned to this student for {$part->value}."
            );
        }

        return DB::transaction(function () use (
            $student, $supervisor, $actor, $part, $role, $responsibility, $note
        ) {
            $assignment = SupervisionAssignment::create([
                'student_profile_id'     => $student->id,
                'supervisor_profile_id'  => $supervisor->id,
                'psm_part'               => $part->value,
                'role'                   => $role,
                'responsibility_percent' => $responsibility
                    ?? $this->defaultResponsibility($student, $supervisor, $part->value),
                'is_active'              => true,
                'assigned_by'            => $actor->id,
                'assignment_note'        => $note,
                'effective_from'         => now()->toDateString(),
            ]);

            $this->audit->log(
                action: AuditAction::SupervisorAssigned,
                description: "{$supervisor->label()} → {$student->student_id} ({$part->value})",
                subject: $assignment,
                actor: $actor,
            );

            $this->warnIfPartNowFull($supervisor, $part);

            if ($student->user) {
                $this->notifications->notify(
                    [$student->user],
                    NotificationType::SupervisorAssigned,
                    [
                        'title'      => 'Supervisor assigned',
                        'body'       => "{$supervisor->label()} has been assigned as your primary supervisor for {$part->label()}.",
                        'action_url' => '/profile',
                    ],
                    $assignment,
                );
            }

            return $assignment;
        });
    }

    /**
     * Refuse a pairing that would breach the cap for the part being allocated.
     *
     * The message names the part and shows the per-part figures, because the old
     * message ("at full capacity (8/8)") was the complaint that started this
     * work: a coordinator looking at a supervisor carrying 4 PSM 1 and 5 PSM 2
     * students was told "8/8 — full" with no way to tell that PSM 1 still had a
     * free slot and PSM 2 did not.
     */
    protected function assertPartCapacity(
        SupervisorProfile $supervisor,
        PsmPart $part,
        int|AcademicSemester|null $semester = null,
    ): void {
        if ($part === PsmPart::Both) {
            // A BOTH pairing occupies a slot in each part, so both caps have to
            // have room for it. Checking only the aggregate here would let a
            // supervisor go to 6 PSM 1 while the per-part cap says 5.
            foreach (PsmPart::deliverables() as $deliverable) {
                if (! $supervisor->hasCapacityForInSemester($deliverable, $semester)) {
                    throw $this->partCapacityException($supervisor, $deliverable, $semester);
                }
            }

            return;
        }

        if (! $supervisor->hasCapacityForInSemester($part, $semester)) {
            throw $this->partCapacityException($supervisor, $part, $semester);
        }
    }

    /**
     * The refusal, naming the limit that actually blocked.
 *
     * Two different limits can stop a pairing and they need different wording:
     * the per-part cap, and the aggregate backstop. Reporting the per-part cap
     * when the aggregate was the real constraint produces "at full PSM 1
     * capacity (0/5)" — a number that plainly says the opposite, which is worse
     * than no message because the coordinator stops believing the figures.
     *
     * The "try the other part instead" suggestion is only offered when the other
     * part genuinely has room. Offering it for a part that is equally full sends
     * the coordinator round the same dead end a second time.
     */
    protected function partCapacityException(
        SupervisorProfile $supervisor,
        PsmPart $part,
        int|AcademicSemester|null $semester = null,
    ): InvalidArgumentException {
        // Reported from the same scope the gate measured, so the numbers in the
        // message and the numbers that caused the refusal can never disagree —
        // a message quoting "4/5" while the gate saw 5/5 is how this complaint
        // started in the first place.
        $load = $semester !== null
            ? ($supervisor->currentLoadByPartInSemester($semester)[$part->value] ?? 0)
            : $supervisor->currentLoadForPartFromDatabase($part);

        $cap = $supervisor->capacityForPart($part);

        $other = $part->counterpart();
        $otherHasRoom = $supervisor->hasCapacityForInSemester($other, $semester);

        $otherLoad = $semester !== null
            ? ($supervisor->currentLoadByPartInSemester($semester)[$other->value] ?? 0)
            : $supervisor->currentLoadForPartFromDatabase($other);

        // Name the term, because "at full capacity" means something different
        // once last term's students are excluded from the count. Falls back to no
        // suffix rather than a dangling " for ." when the term cannot be read.
        $scope = '';

        if ($semester !== null) {
            $name = $semester instanceof AcademicSemester
                ? $semester->name
                : AcademicSemester::find($semester)?->name;

            if ($name !== null && $name !== '') {
                $scope = " for {$name}";
            }
        }

        // The part itself is full — the usual case.
        if ($load >= $cap) {
            $message = "{$supervisor->label()} is at full {$part->label()} capacity ({$load}/{$cap}){$scope}.";

            if ($otherHasRoom) {
                $message .= ' They still have room for '.$other->label()
                    .' ('.$otherLoad.'/'.$supervisor->capacityForPart($other).'), '
                    .'so this student can be paired for that part instead.';
            }

            return new InvalidArgumentException($message);
        }

        // The part has room, so the aggregate backstop is what refused. Say so,
        // and do not pretend the other part is an escape route.
        return new InvalidArgumentException(sprintf(
            '%s has room in %s (%d/%d) but is at their overall supervision limit (%d/%d)%s. '
            .'Raise their overall capacity or pick another supervisor.',
            $supervisor->label(),
            $part->label(),
            $load,
            $cap,
            $semester !== null ? array_sum($supervisor->currentLoadByPartInSemester($semester)) : $supervisor->currentLoad(),
            $supervisor->effectiveTotalCapacity(),
            $scope,
        ));
    }

    /**
     * Tell a supervisor when a pairing fills one of their parts.
     *
     * Fires per part rather than on the aggregate: with 5 + 5 configured, a
     * supervisor reaching 10 total has actually been full in both parts for some
     * time, and a warning that arrives only at the total has told them nothing
     * actionable. A `BOTH` pairing checks both, because it fills both.
     */
    protected function warnIfPartNowFull(SupervisorProfile $supervisor, PsmPart $part): void
    {
        $parts = $part === PsmPart::Both ? PsmPart::deliverables() : [$part];

        foreach ($parts as $check) {
            // From the database: this runs immediately after the insert, so the
            // caller's eager-loaded relation still shows the pre-insert count
            // and the warning would never fire — the one time it is meant to.
            $load = $supervisor->currentLoadForPartFromDatabase($check);
            $cap = $supervisor->capacityForPart($check);

            if ($load < $cap) {
                continue;
            }

            $other = $check->counterpart();

            $this->notifications->notify(
                [$supervisor->user],
                NotificationType::CapacityWarning,
                [
                    'title'      => "You are now at full {$check->label()} capacity",
                    'body'       => "You are supervising {$load} {$check->label()} students, which is your configured maximum. "
                        .($supervisor->hasCapacityFor($other)
                            ? "You can still take students in {$other->label()}."
                            : 'You are also at your overall supervision limit.'),
                    'action_url' => '/dashboard/supervisor',
                ],
                $supervisor,
            );
        }
    }

    /**
     * Remove a pairing. The row is deactivated rather than deleted so the
     * audit trail and historical reports stay intact.
     */
    public function removeSupervisor(
        SupervisionAssignment $assignment,
        User $actor,
        ?string $reason = null,
    ): SupervisionAssignment {
        $before = $assignment->getAttributes();

        $assignment->end($reason);

        $this->audit->log(
            action: AuditAction::SupervisorRemoved,
            description: 'Removed '.($reason ? "({$reason})" : ''),
            subject: $assignment,
            before: $before,
            after: $assignment->getAttributes(),
            actor: $actor,
        );

        $student = $assignment->studentProfile;

        if ($student?->user) {
            $this->notifications->notify(
                [$student->user],
                NotificationType::SupervisorReassigned,
                [
                    'title'      => 'Supervision changed',
                    'body'       => 'One of your supervisors has been removed from your record.',
                    'action_url' => '/profile',
                ],
                $assignment,
            );
        }

        return $assignment->fresh();
    }

    /**
     * Change a supervisor's capacity, warning them if the new limit is below
     * their current load (an over-allocation the coordinator must resolve, not
     * silently accept).
     *
     * `$max` is the AGGREGATE ceiling across both batches. `$part` narrows the
     * write to one batch's own cap, which is what the coordinator's split
     * capacity bar edits. Passing neither leaves the aggregate untouched.
     *
     * The per-part caps are not recomputed from the aggregate on write. Doing so
     * would mean a coordinator lowering the total to 6 silently cut the PSM 1 cap
     * from 5 to 3 — a change they did not ask for and cannot see.
     */
    public function setCapacity(
        SupervisorProfile $supervisor,
        int $max,
        User $actor,
        ?string $part = null,
    ): SupervisorProfile {
        if ($max < 0) {
            throw new InvalidArgumentException('Capacity cannot be negative.');
        }

        $resolvedPart = PsmPart::tryParse($part);

        $column = match ($resolvedPart?->value) {
            PsmPart::Psm1->value => 'max_supervisees_psm1',
            PsmPart::Psm2->value => 'max_supervisees_psm2',
            default              => 'max_supervisees',
        };

        $before = $supervisor->getAttributes();

        $supervisor->update([$column => $max]);

        $this->audit->log(
            action: AuditAction::CapacityChanged,
            description: $column === 'max_supervisees'
                ? "Total capacity set to {$max}"
                : $resolvedPart->label()." capacity set to {$max}",
            subject: $supervisor,
            before: $before,
            after: $supervisor->getAttributes(),
            actor: $actor,
        );

        if ($supervisor->isOverloaded()) {
            $this->notifications->notify(
                [$supervisor->user],
                NotificationType::CapacityWarning,
                [
                    'title'      => 'Supervision capacity exceeded',
                    'body'       => $this->overloadDescription($supervisor),
                    'action_url' => '/dashboard/supervisor',
                ],
                $supervisor,
            );
        }

        return $supervisor->fresh();
    }

    /**
     * Name the batch that is over its cap.
     *
     * "You have 10 students but your limit is 10" is useless once the caps are
     * per part — the actionable fact is which batch has run out of slots, and
     * that the other batch may still have room.
     */
    protected function overloadDescription(SupervisorProfile $supervisor): string
    {
        foreach (PsmPart::deliverables() as $part) {
            $load = $supervisor->currentLoadForPart($part);
            $cap = $supervisor->capacityForPart($part);

            if ($load > $cap) {
                $other = $part->counterpart();

                return "You are supervising {$load} {$part->label()} students but your "
                    ."{$part->label()} limit is {$cap}. "
                    ."{$other->label()} has room for "
                    .max(0, $supervisor->remainingCapacityForPart($other))
                    .' more, so the coordinator can rebalance rather than cut your total.';
            }
        }

        return "Your total limit is {$supervisor->max_supervisees} but you have "
            ."{$supervisor->currentLoad()} students assigned.";
    }

    // -----------------------------------------------------------------
    // Examiner allocation
    // -----------------------------------------------------------------

    /**
     * Does this user supervise any student on this project?
     *
     * The conflict-of-interest rule, in one place. Both the manual allocation
     * path and the automatic pairing path must apply it, and a second copy
     * would drift — the failure mode being that one path quietly allocates an
     * examiner to their own student.
     */
    public function supervisesProject(Project $project, User $user): bool
    {
        return $project->students->contains(
            fn ($student) => $this->supervisesStudent($student, $user)
        );
    }

    /**
     * Does this user supervise this student?
     *
     * The student-scoped form of the conflict-of-interest rule, and the one the
     * automatic pairing path now uses: the panel is allocated before the project
     * exists, so the check cannot go through a project.
     */
    public function supervisesStudent(StudentProfile $student, User $user): bool
    {
        $supervisorIds = ($student->relationLoaded('activeSupervisions')
            ? $student->activeSupervisions
            : $student->activeSupervisions()->get()
        )->pluck('supervisor_profile_id');

        if ($supervisorIds->isEmpty()) {
            return false;
        }

        return SupervisorProfile::whereIn('id', $supervisorIds)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Allocate an examiner to a project. Prevents double-allocation and
     * self-examination (a supervisor of a project may not examine it).
     */
    /**
     * Seat one examiner on a student's panel.
     *
     * Anchored on the **student**, not the project, because that is what a panel
     * is: the same two people decide the proposal and give the final mark, and
     * the proposal is reviewed before the project exists. `project_id` is
     * stamped onto the allocation when Lampiran B registers one — and stamped
     * here too when a project already exists, so the evaluation path (which
     * reads `project_id`) finds the allocation unchanged.
     *
     * Every path that seats a panel comes through here — the automatic run, the
     * project screen and the coordinator's pairing screen — so none of them can
     * seat someone the others would refuse.
     */
    public function assignExaminerToStudent(
        StudentProfile $student,
        User $examiner,
        User $actor,
        string $psmPart = 'PSM2',
        ?string $panelRole = null,
    ): ExaminerAssignment {
        if (! $examiner->isSupervisor()) {
            throw new InvalidArgumentException(
                'Only academic staff may be allocated as an examiner — a panel is drawn from the '
                .'same people who supervise.'
            );
        }

        /**
         * Conflict of interest: nobody examines their own student.
         *
         * Checked on the *student*, not through a project, so the rule holds
         * whether or not Lampiran B has registered one yet. This is the same
         * check the automatic pairing run uses.
         */
        if ($this->supervisesStudent($student, $examiner)) {
            throw new InvalidArgumentException(
                "{$examiner->name} supervises this student and cannot also examine them "
                .'(conflict of interest).'
            );
        }

        /**
         * An examiner examines only their own programme, same as supervision.
         *
         * This is the rule the faculty actually states, and it is not the same as
         * the conflict-of-interest check above: that one stops a *supervisor*
         * examining their own student, this one stops anyone examining outside
         * their programme. A panel could otherwise be fully "legal" by the
         * conflict rule while every member came from the wrong programme.
         *
         * Read through the examiner's supervisor profile, because a panel is drawn
         * from the people who supervise — there is no separate examiner record.
         */
        $examinerProfile = $examiner->supervisorProfile;

        if ($examinerProfile !== null
            && ($refusal = $examinerProfile->programmeRefusal($student->program_code, $student->student_id))
        ) {
            throw new InvalidArgumentException($refusal);
        }

        $existing = ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('examiner_id', $examiner->id)
            ->where('psm_part', $psmPart)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException(
                "{$examiner->name} is already on this student's panel."
            );
        }

        /**
         * The panel is capped, not merely de-duplicated.
         *
         * The requirement fixes the panel at two examiners per student, and the
         * evaluation arithmetic averages every examiner on the panel — so a
         * third allocation would not be rejected anywhere, it would silently
         * change every mark the project receives. Enforcing the cap here is the
         * only place that can prevent it.
         */
        $panelSize = (int) config('psm.examiner_panel_size', 2);

        $alreadyAllocated = ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('psm_part', $psmPart)
            ->where('is_active', true)
            ->count();

        if ($alreadyAllocated >= $panelSize) {
            throw new InvalidArgumentException(
                "This student already has the full panel of {$panelSize} examiners for {$psmPart}. "
                .'Remove one before allocating another.'
            );
        }

        $projectId = $student->projectForPart($psmPart)?->id;

        return DB::transaction(function () use ($student, $examiner, $actor, $psmPart, $panelRole, $projectId) {
            $assignment = ExaminerAssignment::create([
                'project_id'         => $projectId,
                'student_profile_id' => $student->id,
                'examiner_id'        => $examiner->id,
                'psm_part'           => $psmPart,
                'panel_role'         => $panelRole,
                'is_active'          => true,
                'assigned_by'        => $actor->id,
            ]);

            $this->audit->log(
                action: AuditAction::SupervisorAssigned,
                description: "Examiner {$examiner->name} → {$student->student_id} ({$psmPart})",
                subject: $assignment,
                actor: $actor,
            );

            return $assignment;
        });
    }

    /**
     * Seat one examiner on a project's panel.
     *
     * The project-shaped entry point, kept for the project screen. It resolves
     * the student and delegates, so there is exactly one set of rules.
     */
    public function assignExaminer(
        Project $project,
        User $examiner,
        User $actor,
        string $psmPart = 'PSM2',
        ?string $panelRole = null,
    ): ExaminerAssignment {
        $leader = $project->leader();

        if ($leader === null) {
            throw new InvalidArgumentException('This project has no student to seat a panel for.');
        }

        /**
         * A project may carry co-members, and the panel is seated per student.
         * The delegate below checks the leader, so check the rest here — the
         * conflict rule has to hold for every student the project represents.
         */
        $conflict = $project->students
            ->first(fn (StudentProfile $member) => $this->supervisesStudent($member, $examiner));

        if ($conflict !== null && $conflict->id !== $leader->id) {
            throw new InvalidArgumentException(
                "{$examiner->name} supervises {$conflict->student_id} on this project and cannot "
                .'also examine it (conflict of interest).'
            );
        }

        return $this->assignExaminerToStudent($leader, $examiner, $actor, $psmPart, $panelRole);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * A student's only supervisor carries the full responsibility.
     *
     * This used to split 100% across every active supervisor, which only ever
     * produced a fraction once a co-supervisor existed. With primary-only
     * supervision the answer is always 100.
     */
    protected function defaultResponsibility(
        StudentProfile $student,
        SupervisorProfile $supervisor,
        string $psmPart,
    ): float {
        return 100.0;
    }

    /**
     * Auto-suggest supervisors for a student, ranked by expertise overlap with
     * the student's declared thesis title/abstract.
     *
     * A deliberately simple keyword match: it is a *suggestion* in the
     * coordinator UI, not an assignment, so a lightweight heuristic that a
     * coordinator can reason about beats an opaque score.
     *
     * `$psmPart` is not optional in effect: candidates are filtered on room in
     * *that* batch's cap, because a supervisor with a full PSM 1 list is a valid
     * answer for a PSM 2 student and a useless one for a PSM 1 student. Leaving
     * it null checks both parts, which is the honest default when the caller
     * genuinely does not know which part the student is registering for.
     */
    public function suggestSupervisors(
        StudentProfile $student,
        int $limit = 5,
        ?string $psmPart = null,
    ): array {
        $part = PsmPart::tryParse($psmPart);

        $haystack = strtolower(($student->thesis_title ?? '').' '.($student->thesis_abstract ?? ''));

        // Same term-scoped gate the assignment itself enforces, from the same
        // source (the student's enrolment). A suggestion the assignment would
        // then refuse is worse than no suggestion: the coordinator picks from a
        // shortlist, is told the pick is full, and loses trust in both.
        $semester = $student->academic_semester_id;

        $hasRoom = fn (SupervisorProfile $s) => $part === null
            ? ! $s->isFullInSemester($semester)
            : $s->hasCapacityForInSemester($part, $semester);

        // Rank on whichever part has the most headroom, so a supervisor who is
        // full in PSM 1 but empty in PSM 2 still sorts sensibly for a PSM 2 list.
        $headroom = fn (SupervisorProfile $s) => $part !== null
            ? $s->remainingCapacityForPartInSemester($part, $semester)
            : $s->remainingCapacity();

        if (trim($haystack) === '') {
            // No declared topic: fall back to whoever has the most free capacity
            return SupervisorProfile::query()
                ->accepting()
                ->with('user')
                ->get()
                ->filter($hasRoom)
                ->sortByDesc($headroom)
                ->take($limit)
                ->map(fn (SupervisorProfile $s) => [
                    'supervisor'  => $s,
                    'score'       => 0,
                    'matched_on'  => [],
                    'reason'      => $part === null
                        ? 'Selected by available capacity'
                        : 'Selected by available '.$part->label().' capacity',
                ])
                ->values()
                ->all();
        }

        return SupervisorProfile::query()
            ->accepting()
            ->with(['user', 'expertiseAreas'])
            ->get()
            ->filter($hasRoom)
            ->map(function (SupervisorProfile $s) use ($haystack) {
                $matched = $s->expertiseAreas->filter(function ($area) use ($haystack) {
                    $name = strtolower($area->name);

                    return $name !== '' && str_contains($haystack, $name);
                });

                $score = $matched->sum(fn ($a) => (int) $a->pivot->proficiency);

                return [
                    'supervisor' => $s,
                    'score'      => $score,
                    'matched_on' => $matched->pluck('name')->all(),
                    'reason'     => $matched->isEmpty()
                        ? 'No topic overlap — available capacity only'
                        : 'Matches: '.$matched->pluck('name')->implode(', '),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }
}
