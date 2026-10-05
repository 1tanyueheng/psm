<?php

namespace App\Services;

use App\Enums\AssessorType;
use App\Enums\AuditAction;
use App\Enums\EvaluationStatus;
use App\Enums\PsmPart;
use App\Models\Evaluation;
use App\Models\ExaminerAssignment;
use App\Models\ExaminerPair;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 2/4 — automatic assignment of examiner pairs to students.
 *
 * The faculty's model: examiners are grouped into fixed pairs, and a batch of
 * students is assigned to a pair, so every student in the batch is assessed by
 * the same two people for both the proposal review and the final evaluation.
 *
 * The allocation is made against the **student**, not the project. That is what
 * lets the panel review the proposal before registration: the panel must exist
 * before the project does, so it cannot hang off a project that has not been
 * created. The pair is allocated from Lampiran A onward, and the project is
 * stamped onto the allocation when Lampiran B creates it.
 *
 * "Automatic" here means the coordinator does not pick examiners one student at
 * a time. It does NOT mean the system overrides the conflict-of-interest rule:
 * an examiner who supervises a student in the batch is excluded from the whole
 * run, and if that leaves too few examiners the run is refused rather than
 * quietly seating someone on their own student.
 *
 * A run is scoped to ONE (semester, psm_part) pair — requirement §7.2. That is
 * the whole reason this file needed to change: the faculty now runs PSM 1 and
 * PSM 2 in the same semester from the same pool of examiners, and a run that
 * spanned both would hand one pair a mixed roster, which is exactly what the
 * PSM 1/PSM 2 split of the marking forms forbids.
 */
class ExaminerPairingService
{
    public function __construct(
        protected AssignmentService $assignments,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Assign a fixed pair to each student, batching the students across as many
     * pairs as the eligible examiners allow.
     *
     * A run is always scoped to exactly one (semester, psm_part) pair. Both
     * halves are required rather than inferred:
     *
     *  - `$semesterId` is what makes a pair term-scoped. It is stamped onto
     *    every pair this run creates or reuses, so next semester the same two
     *    examiners form a *different* pair row rather than inheriting this
     *    term's allocations (requirement §3.7).
     *  - `$psmPart` is inferred from the students' projects when omitted, but a
     *    mixed list is rejected outright (see below).
     *
     * @param  iterable<StudentProfile>  $students
     * @return array{pairs: array, assigned: int, skipped: array, semester_id: int, psm_part: string}
     */
    public function autoAssign(
        iterable $students,
        User $actor,
        int $perPair = 0,
        ?int $semesterId = null,
        ?string $psmPart = null,
    ): array {
        $students = collect($students)->filter()->values();

        if ($students->isEmpty()) {
            throw new InvalidArgumentException('There are no students to assign a panel to.');
        }

        // Requirement §4.2: a run without a term would draw on every term's
        // panels at once. That is refused rather than guessed, because the
        // failure it prevents — last term's panel marking this term's work — is
        // silent: the allocation succeeds and the damage only shows in the
        // marks.
        if ($semesterId === null) {
            throw new InvalidArgumentException(
                'Pick an academic semester before running auto-assign. Examiner panels are '
                .'appointed for one term, and a run without one would mix this term\'s panels '
                .'with last term\'s.'
            );
        }

        // The batch is taken from the caller when given, and otherwise inferred
        // from the students' projects. It is *not* read off the first student and
        // trusted: a caller that passed a mixed list would otherwise get a PSM 1
        // label on a run that seats PSM 2 students, and the mismatch only shows
        // up later as marks that will not compute.
        $resolvedPart = PsmPart::tryParse($psmPart);

        if ($resolvedPart === null) {
            $parts = $students
                ->flatMap(fn (StudentProfile $s) => $s->projects->pluck('psm_part'))
                ->unique();

            if ($parts->count() > 1) {
                throw new InvalidArgumentException(
                    'This run spans more than one batch ('.$parts->join(', ')
                    .'). Run auto-assign once per batch — a panel cannot examine '
                    .'PSM 1 and PSM 2 work, and the marking forms differ.'
                );
            }

            $resolvedPart = PsmPart::tryParse($parts->first());
        }

        if ($resolvedPart === null || $resolvedPart === PsmPart::Both) {
            throw new InvalidArgumentException(
                'Auto-assign needs to know which batch it is allocating for '
                .'(PSM1 or PSM2).'
            );
        }

        $this->assertStudentsInSemester($students, $semesterId);

        // Everyone who supervises any student in this run is barred from the
        // whole run, not just from their own student — pairs are fixed across
        // the batch, so a member who is conflicted for one student would break
        // the pairing for everyone.
        $excludedUserIds = $this->supervisingUserIds($students);

        $pool = $this->eligibleExaminers($excludedUserIds);

        if ($pool->count() < 2) {
            throw new InvalidArgumentException(sprintf(
                'At least two eligible examiners are needed to form a pair, but only %d %s available '
                .'after excluding everyone who supervises a student in this run.',
                $pool->count(),
                $pool->count() === 1 ? 'is' : 'are'
            ));
        }

        $capacity = $perPair > 0 ? $perPair : $this->pairCapacity($resolvedPart);

        return DB::transaction(function () use (
            $students, $pool, $resolvedPart, $actor, $capacity, $semesterId
        ) {
            $pairs = $this->resolvePairs($pool, $resolvedPart, $actor, $semesterId, $capacity);

            if ($pairs->isEmpty()) {
                throw new InvalidArgumentException(
                    "No examiner pair can be formed for {$resolvedPart->label()} from the "
                    .'examiners available after excluding supervisors.'
                );
            }

            $assigned = 0;
            $skipped = [];

            /**
             * Round-robin across the pairs, so the load is spread evenly and
             * consecutive students in the roster land with different panels
             * rather than one pair taking the whole list.
             *
             * Each pair stops accepting students once it reaches the per-pair
             * cap. Without that, a single pair quietly ends up examining an
             * entire cohort while the others sit idle — and because the loop
             * advances the cursor only on success, a full pair is simply skipped
             * over rather than repeatedly failing every student.
             */
            $cursor = 0;

            foreach ($students as $student) {
                // A panel that has already marked must not be replaced. Retiring
                // a seated examiner strands their released form, and the student
                // ends up assessed by more people than the panel holds. Skip and
                // report it rather than silently re-pairing.
                if ($this->panelHasMarked($student, $resolvedPart)) {
                    $skipped[] = [
                        'project' => $this->labelFor($student),
                        'pair'    => '—',
                        'reason'  => 'A panel member has already submitted a form for this student.',
                    ];

                    continue;
                }

                $candidates = $pairs->count() > 0
                    ? $pairs->slice($cursor % $pairs->count())->concat(
                        $pairs->slice(0, $cursor % $pairs->count())
                    )
                    : $pairs;

                $seated = false;

                foreach ($candidates as $pair) {
                    if ($pair->projectCount() >= $capacity) {
                        continue;
                    }

                    try {
                        $this->seat($student, $pair, $resolvedPart, $actor);
                        $assigned++;
                        $seated = true;
                        break;
                    } catch (InvalidArgumentException $e) {
                        // Try the next panel rather than abandoning the student:
                        // the pair is conflicted on this student, but another
                        // panel in the same run may not be.
                        $skipped[] = [
                            'project' => $this->labelFor($student),
                            'pair'    => $pair->name,
                            'reason'  => $e->getMessage(),
                        ];
                    }
                }

                if ($seated) {
                    $cursor++;
                }
            }

            $this->audit->log(
                action: AuditAction::SupervisorAssigned,
                description: sprintf(
                    'Examiner pairs assigned to %d %s student(s) across %d panel(s) for semester #%d',
                    $assigned,
                    $resolvedPart->value,
                    $pairs->count(),
                    $semesterId,
                ),
                subject: $students->first(),
                actor: $actor,
            );

            return [
                'pairs' => $pairs->map(fn (ExaminerPair $p) => [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'members'    => $p->memberNames(),
                    'projects'   => $p->projectCount(),
                    'students'   => $p->projectCount(),
                    'capacity'   => $capacity,
                    'remaining'  => max(0, $capacity - $p->projectCount()),
                ])->all(),
                'assigned'    => $assigned,
                'skipped'     => $skipped,
                'semester_id' => $semesterId,
                'psm_part'    => $resolvedPart->value,
            ];
        });
    }

    /**
     * Refuse a run whose students do not all belong to the chosen semester.
     *
     * Two different mistakes are caught here, and both would otherwise produce a
     * panel roster that disagrees with the marks:
     *
     *  - the students straddle two terms, so one panel would mark both;
     *  - the caller named a semester the students are not in at all — usually a
     *    stale selection left over from a previous screen. Without this check the
     *    panels would be built for the named term and then attached to another
     *    term's students.
     *
     * Allocating within a term is cheap; discovering a term's marks were
     * computed by last term's panel is not.
     */
    protected function assertStudentsInSemester(Collection $students, int $semesterId): void
    {
        $semesterIds = $students->pluck('academic_semester_id')->unique();

        // A student with no term attached at all (pre-migration legacy row) is
        // tolerated: it is the only thing in the roster, or it is null in every
        // student, and refusing would block a fixable data gap from being
        // allocated at all. Mixed null + one real term is still fine.
        $mismatched = $semesterIds->filter(fn ($id) => $id !== null && $id !== $semesterId);

        if ($mismatched->isEmpty()) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'These students are not all in the selected semester (found %s). Run '
            .'auto-assign once per semester — examiner panels are appointed for a term.',
            $mismatched->map(fn ($id) => (int) $id)->join(', '),
        ));
    }

    /** The per-pair ceiling for a batch, from config/psm.php. */
    protected function pairCapacity(PsmPart $part): int
    {
        return max(1, (int) config("psm.examiner_capacity.{$part->value}", 10));
    }

    /**
     * Reuse the standing pairs, extending them with new ones only when the
     * batch is larger than the existing panels can carry.
     *
     * Reusing is the point of a *fixed* pair: re-running the automatic
     * assignment within the same term should not invent a new set of panels.
     *
     * Pairs are looked up strictly within the run's term and batch. A panel with
     * no term attached is not reused — it belongs to no term, so reusing it here
     * would carry an untraceable allocation into this term. The backfill in the
     * semester migration attaches every pre-existing panel to the term it
     * belonged to, so the reuse case this protects does not lose real panels.
     */
    protected function resolvePairs(
        Collection $pool,
        PsmPart $psmPart,
        User $actor,
        ?int $semesterId,
        int $capacity,
    ): Collection {
        $existing = ExaminerPair::query()
            ->forSemester($semesterId)
            ->where('psm_part', $psmPart->value)
            ->active()
            ->with(['examiner1', 'examiner2'])
            ->get();

        // Every member of an existing pair must be in the pool; a pair with a
        // conflicted member cannot be used for this run at all.
        $usable = $existing->filter(fn (ExaminerPair $p) => $pool->contains(
            fn (User $u) => $u->id === $p->examiner_1_id
        ) && $pool->contains(
            fn (User $u) => $u->id === $p->examiner_2_id
        ))->values();

        // Panels already at their ceiling are not offered as candidates; if they
        // were, the seating loop would skip them one student at a time and burn
        // a conflict-check per student for nothing.
        $withRoom = $usable->filter(fn (ExaminerPair $p) => $p->projectCount() < $capacity)->values();

        if ($withRoom->isNotEmpty()) {
            return $withRoom;
        }

        // None reusable with room: extend the pool with new panels, two at a
        // time, starting from Panel A of this batch.
        $members = $pool->values();
        $pairs = collect();

        for ($i = 0; $i + 1 < $members->count(); $i += 2) {
            $pairs->push(ExaminerPair::firstOrCreate(
                [
                    'psm_part'            => $psmPart->value,
                    'academic_semester_id'=> $semesterId,
                    'examiner_1_id'       => $members[$i]->id,
                    'examiner_2_id'       => $members[$i + 1]->id,
                ],
                [
                    'name'       => $psmPart->shortLabel().' Panel '.chr(65 + $pairs->count()),
                    'is_active'  => true,
                    'created_by' => $actor->id,
                ]
            ));
        }

        return $pairs;
    }

    /**
     * Has anyone on this student's current panel already returned a form?
     *
     * A draft form does not count — nothing has been marked yet, so replacing
     * the panel is harmless. The form hangs off the project, so this is checked
     * across every project the student holds for the batch.
     */
    protected function panelHasMarked(StudentProfile $student, PsmPart $psmPart): bool
    {
        $panelIds = ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('psm_part', $psmPart->value)
            ->active()
            ->pluck('examiner_id')
            ->all();

        if ($panelIds === []) {
            return false;
        }

        $projectIds = $student->projects
            ->where('psm_part', $psmPart->value)
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return false;
        }

        return Evaluation::query()
            ->whereIn('project_id', $projectIds)
            ->where('psm_part', $psmPart->value)
            ->where('assessor_type', AssessorType::Examiner->value)
            ->whereIn('assessor_id', $panelIds)
            ->whereIn('status', [
                EvaluationStatus::Submitted->value,
                EvaluationStatus::Released->value,
            ])
            ->exists();
    }

    /**
     * Seat both members of the pair on one student.
     *
     * Existing allocations are retired rather than left in place, so a student
     * never ends up with three active examiners and an average taken over the
     * wrong panel. The project is stamped on when one already exists, so a
     * re-run after registration keeps the evaluation path working.
     */
    protected function seat(StudentProfile $student, ExaminerPair $pair, PsmPart $psmPart, User $actor): void
    {
        foreach ($pair->members() as $examiner) {
            if ($this->assignments->supervisesStudent($student, $examiner)) {
                throw new InvalidArgumentException(
                    "{$examiner->displayName()} supervises {$this->labelFor($student)}."
                );
            }
        }

        $projectId = $this->projectIdFor($student, $psmPart);

        // Retire any allocation that is not part of this pair.
        ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('psm_part', $psmPart->value)
            ->whereNotIn('examiner_id', collect($pair->members())->pluck('id')->all())
            ->update(['is_active' => false]);

        foreach ($pair->members() as $index => $examiner) {
            ExaminerAssignment::updateOrCreate(
                [
                    'student_profile_id' => $student->id,
                    'examiner_id'        => $examiner->id,
                    'psm_part'           => $psmPart->value,
                ],
                [
                    'project_id'       => $projectId,
                    'examiner_pair_id' => $pair->id,
                    'panel_role'       => $index === 0 ? 'chair' : 'member',
                    'is_active'        => true,
                    'assigned_by'      => $actor->id,
                ]
            );
        }
    }

    /** The student's project for this batch, if registration has already happened. */
    protected function projectIdFor(StudentProfile $student, PsmPart $psmPart): ?int
    {
        $project = $student->projects
            ->where('psm_part', $psmPart->value)
            ->sortByDesc('id')
            ->first();

        return $project?->id;
    }

    /** A human label for a student, for skip reporting. */
    protected function labelFor(StudentProfile $student): string
    {
        return $student->student_id ?: (string) $student->id;
    }

    /**
     * Users who supervise at least one student in the run.
     *
     * @return array<int, int>
     */
    protected function supervisingUserIds(Collection $students): array
    {
        return $students
            ->flatMap(fn (StudentProfile $s) => $s->activeSupervisions)
            ->map(fn ($a) => $a->supervisorProfile?->user_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Manual pairing — the coordinator picks the pair
    // -----------------------------------------------------------------

    /**
     * Who may be seated on this student's panel?
     *
     * The same pool the automatic run draws from, minus two kinds of person:
     *
     *  - **the student's own supervisor(s)** — the conflict-of-interest rule, and
     *    the reason this screen exists at all. It is enforced again when the pair
     *    is seated, so a stale list cannot seat someone the service would refuse;
     *  - anyone already on this student's panel for the batch, so the picker
     *    cannot offer a duplicate that would only be rejected on submit.
     *
     * Capacity is not consulted — `max_supervisees` limits supervising, not
     * examining, so a supervisor who is full can still take a panel.
     *
     * @return Collection<int, User>
     */
    public function panelCandidates(StudentProfile $student, string $psmPart): Collection
    {
        $student->loadMissing('activeSupervisions.supervisorProfile');

        $seated = ExaminerAssignment::query()
            ->forStudent($student->id)
            ->where('psm_part', $psmPart)
            ->where('is_active', true)
            ->pluck('examiner_id')
            ->all();

        $excluded = array_values(array_unique(array_merge(
            $seated,
            $this->supervisingUserIds(collect([$student])),
        )));

        return User::query()
            ->where('role', 'supervisor')
            ->where('status', 'active')
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded))
            ->with('supervisorProfile')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seat a pair the coordinator chose.
     *
     * A panel is two people, so the payload is two ids and **the first is the
     * chair**. Whatever was already seated for this batch is retired first —
     * re-pairing a student is a replacement, not a refusal — and then both seats
     * are filled through `AssignmentService::assignExaminerToStudent()`, so the
     * conflict-of-interest, duplicate and panel-cap rules are the same ones the
     * automatic run and the project screen go through. There is no second
     * implementation of those rules to drift.
     *
     * @param  array<int, int|string>  $examinerIds  exactly two, first is chair
     * @return Collection<int, ExaminerAssignment>
     */
    public function assignPair(
        StudentProfile $student,
        array $examinerIds,
        User $actor,
        string $psmPart = 'PSM1',
    ): Collection {
        $ids = collect($examinerIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $panelSize = (int) config('psm.examiner_panel_size', 2);

        if ($ids->count() !== $panelSize) {
            throw new InvalidArgumentException(
                "Pick exactly {$panelSize} examiners — a panel is a pair, and the examiner mark is "
                .'averaged over the whole panel.'
            );
        }

        $examiners = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($examiners->count() !== $ids->count()) {
            throw new InvalidArgumentException('One of those examiners no longer exists.');
        }

        return DB::transaction(function () use ($student, $ids, $examiners, $actor, $psmPart) {
            // Retire the previous panel before seating the new one. This is what
            // makes re-pairing a replacement: the cap inside
            // `assignExaminerToStudent()` then sees an empty panel rather than a
            // full one. The retired rows are kept, not deleted, so the audit
            // trail still shows who examined before.
            ExaminerAssignment::query()
                ->forStudent($student->id)
                ->where('psm_part', $psmPart)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return $ids->map(fn (int $id, int $index) => $this->assignments->assignExaminerToStudent(
                $student,
                $examiners->get($id),
                $actor,
                $psmPart,
                $index === 0 ? 'chair' : 'member',
            ))->values();
        });
    }

    /**
     * Every student beside every examiner who could examine them.
     *
     * The coordinator's overview: one row per registered student, carrying the
     * people who **cannot** examine them (their own supervisors) and the people
     * who can. `panelCandidates()` answers the same question for one student;
     * this answers it for the whole cohort in a fixed number of queries rather
     * than one per student.
     *
     * A student's eligible list excludes:
     *  - their own supervisor(s) — the conflict-of-interest rule;
     *  - anyone already seated on their panel for this batch, so the list is
     *    "who could fill the remaining seat" rather than "who is theoretically
     *    allowed".
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function matchingOverview(?string $psmPart = null): Collection
    {
        $students = StudentProfile::query()
            ->whereHas('activeSupervisions')
            ->with(['user', 'activeSupervisions.supervisorProfile.user', 'projects'])
            ->get()
            ->sortBy('student_id')
            ->values();

        // The whole pool once, not once per student.
        $pool = User::query()
            ->where('role', 'supervisor')
            ->where('status', 'active')
            ->with('supervisorProfile')
            ->orderBy('name')
            ->get();

        $seated = ExaminerAssignment::query()
            ->whereIn('student_profile_id', $students->pluck('id'))
            ->where('is_active', true)
            ->with('examiner.supervisorProfile')
            ->get()
            ->groupBy('student_profile_id');

        return $students->map(function (StudentProfile $student) use ($pool, $seated, $psmPart) {
            $part = $psmPart ?? $student->projectForPart()?->psm_part ?? 'PSM1';

            $supervisorUserIds = $student->activeSupervisions
                ->map(fn ($a) => $a->supervisorProfile?->user_id)
                ->filter()
                ->all();

            $seatedForPart = ($seated[$student->id] ?? collect())
                ->where('psm_part', $part);

            $seatedIds = $seatedForPart->pluck('examiner_id')->all();

            $eligible = $pool
                ->reject(fn (User $u) => in_array($u->id, $supervisorUserIds, true)
                    || in_array($u->id, $seatedIds, true))
                ->values();

            return [
                'student' => [
                    'profile_id' => $student->id,
                    'student_id' => $student->student_id,
                    'name'       => $student->user?->name,
                    'batch'      => $student->batch,
                    'program'    => $student->program,
                ],
                'psm_part' => $part,
                'project'  => ($p = $student->projectForPart($part)) ? [
                    'id'   => $p->id,
                    'code' => $p->code,
                ] : null,
                'supervisors' => $student->activeSupervisions
                    ->map(fn ($a) => [
                        'id'   => $a->supervisorProfile?->user_id,
                        'name' => $a->supervisorProfile?->label(),
                    ])
                    ->filter(fn ($s) => $s['id'] !== null)
                    ->values(),
                'seated' => $seatedForPart->map(fn (ExaminerAssignment $ea) => [
                    'id'          => $ea->id,
                    'examiner_id' => $ea->examiner_id,
                    'name'        => $ea->examiner?->displayName(),
                    'panel_role'  => $ea->panel_role,
                ])->values(),
                'eligible' => $eligible->map(fn (User $u) => [
                    'examiner_id' => $u->id,
                    'name'        => $u->displayName(),
                    'staff_no'    => $u->supervisorProfile?->staff_no,
                ])->values(),
            ];
        })->values();
    }

    /**
     * Academic staff available for this run.
     *
     * Every active supervisor, because sitting on a panel is a seating rather
     * than a role: the pool is the same people who supervise, and the
     * conflict-of-interest rule below is what keeps someone off their own
     * student's panel. The check matches `AssignmentService::assignExaminer()`
     * so the automatic path cannot seat someone the manual path would refuse.
     *
     * Capacity is deliberately not consulted — `max_supervisees` limits
     * supervision, not examining, so a supervisor who is full can still take a
     * panel.
     *
     * @return Collection<int, User>
     */
    protected function eligibleExaminers(array $excludedUserIds): Collection
    {
        return User::query()
            ->where('role', 'supervisor')
            ->where('status', 'active')
            ->when($excludedUserIds !== [], fn ($q) => $q->whereNotIn('id', $excludedUserIds))
            ->orderBy('id')
            ->get();
    }
}
