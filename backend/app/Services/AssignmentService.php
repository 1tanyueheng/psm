<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
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
     * Enforces, in order:
     *   1. the supervisor is accepting students
     *   2. the supervisor has spare capacity
     *   3. the student has spare supervisor slots
     *   4. this exact pairing is not already active for this PSM part
     */
    public function assignSupervisor(
        StudentProfile $student,
        SupervisorProfile $supervisor,
        User $actor,
        string $psmPart = 'BOTH',
        string $role = 'primary',
        ?float $responsibility = null,
        ?string $note = null,
    ): SupervisionAssignment {
        if (! $supervisor->is_accepting_students) {
            throw new InvalidArgumentException(
                "{$supervisor->label()} لا يقبل طلابًا جددًا حاليًا."
            );
        }

        if (! $supervisor->hasCapacity()) {
            throw new InvalidArgumentException(
                "{$supervisor->label()} عند السعة القصوى "
                ."({$supervisor->currentLoad()}/{$supervisor->max_supervisees})."
            );
        }

        if ($student->remainingSupervisorSlots() <= 0) {
            throw new InvalidArgumentException(
                "لدى {$student->student_id} بالفعل العدد الأقصى من المشرفين "
                ."({$student->max_supervisors})."
            );
        }

        $existing = SupervisionAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('supervisor_profile_id', $supervisor->id)
            ->where('psm_part', $psmPart)
            ->where('is_active', true)
            ->exists();

        if ($existing) {
            throw new InvalidArgumentException(
                'هذا المشرف مكلّف بالفعل على هذا الطالب لجزء '.$psmPart.'.'
            );
        }

        return DB::transaction(function () use (
            $student, $supervisor, $actor, $psmPart, $role, $responsibility, $note
        ) {
            $assignment = SupervisionAssignment::create([
                'student_profile_id'     => $student->id,
                'supervisor_profile_id'  => $supervisor->id,
                'psm_part'               => $psmPart,
                'role'                   => $role,
                'responsibility_percent' => $responsibility
                    ?? $this->defaultResponsibility($student, $supervisor, $psmPart),
                'is_active'              => true,
                'assigned_by'            => $actor->id,
                'assignment_note'        => $note,
                'effective_from'         => now()->toDateString(),
            ]);

            $this->audit->log(
                action: AuditAction::SupervisorAssigned,
                description: "{$supervisor->label()} ← {$student->student_id} ({$psmPart})",
                subject: $assignment,
                actor: $actor,
            );

            // Warn the supervisor if this pairing pushed them to capacity
            if ($supervisor->fresh()->isFull()) {
                $this->notifications->notify(
                    [$supervisor->user],
                    NotificationType::CapacityWarning,
                    [
                        'title'      => 'أنت الآن في سعة الإشراف القصوى',
                        'body'       => "أنت تشرف على {$supervisor->max_supervisees} طلاب، وهو الحد الأقصى المحدد لك.",
                        'action_url' => '/dashboard/supervisor',
                    ],
                    $supervisor,
                );
            }

            if ($student->user) {
                $this->notifications->notify(
                    [$student->user],
                    NotificationType::SupervisorAssigned,
                    [
                        'title'      => 'تم تعيين مشرف',
                        'body'       => "تم تعيين {$supervisor->label()} مشرفًا {$role} عليك لمشروع {$psmPart}.",
                        'action_url' => '/profile',
                    ],
                    $assignment,
                );
            }

            return $assignment;
        });
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
            description: 'تمت الإزالة '.($reason ? "({$reason})" : ''),
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
                    'title'      => 'تغير الإشراف',
                    'body'       => 'تمت إزالة أحد مشرفيك من سجلك.',
                    'action_url' => '/profile',
                ],
                $assignment,
            );
        }

        return $assignment->fresh();
    }

    /**
     * Change a supervisor's maximum capacity, warning them if the new limit
     * is below their current load (an over-allocation the coordinator must
     * resolve, not silently accept).
     */
    public function setCapacity(SupervisorProfile $supervisor, int $max, User $actor): SupervisorProfile
    {
        if ($max < 0) {
            throw new InvalidArgumentException('لا يمكن أن تكون السعة سالبة.');
        }

        $before = $supervisor->getAttributes();

        $supervisor->update(['max_supervisees' => $max]);

        $this->audit->log(
            action: AuditAction::CapacityChanged,
            description: "تم تحديد السعة بـ {$max}",
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
                    'title'      => 'تجاوزت سعة الإشراف',
                    'body'       => "حدك {$max} طالب لكن لديك {$supervisor->currentLoad()} طلاب مسجلين.",
                    'action_url' => '/dashboard/supervisor',
                ],
                $supervisor,
            );
        }

        return $supervisor->fresh();
    }

    // -----------------------------------------------------------------
    // Examiner allocation
    // -----------------------------------------------------------------

    /**
     * Allocate an examiner to a project. Prevents double-allocation and
     * self-examination (a supervisor of a project may not examine it).
     */
    public function assignExaminer(
        Project $project,
        User $examiner,
        User $actor,
        string $psmPart = 'PSM2',
        ?string $panelRole = null,
    ): ExaminerAssignment {
        if (! $examiner->isExaminer() && ! $examiner->isSupervisor()) {
            throw new InvalidArgumentException(
                'فقط المستخدم بدور ممتحن أو مشرف يمكن تكليفه كممتحن.'
            );
        }

        $supervisorIds = $project->students
            ->flatMap(fn ($s) => $s->activeSupervisions->pluck('supervisor_profile_id'));

        $isOwnProject = SupervisorProfile::whereIn('id', $supervisorIds)
            ->where('user_id', $examiner->id)
            ->exists();

        if ($isOwnProject) {
            throw new InvalidArgumentException(
                'مشرف هذا المشروع لا يمكنه أيضًا الامتحان فيه (تضارب مصالح).'
            );
        }

        $existing = ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('examiner_id', $examiner->id)
            ->where('psm_part', $psmPart)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('هذا الممتحن مكلّف بالفعل على هذا المشروع.');
        }

        return DB::transaction(function () use ($project, $examiner, $actor, $psmPart, $panelRole) {
            $assignment = ExaminerAssignment::create([
                'project_id'  => $project->id,
                'examiner_id' => $examiner->id,
                'psm_part'    => $psmPart,
                'panel_role'  => $panelRole,
                'is_active'   => true,
                'assigned_by' => $actor->id,
            ]);

            $this->audit->log(
                action: AuditAction::SupervisorAssigned,
                description: "ممتحن {$examiner->name} ← {$project->code} ({$psmPart})",
                subject: $assignment,
                actor: $actor,
            );

            return $assignment;
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Split responsibility evenly among a student's supervisors of the same
     * role, so the percentages always sum to 100.
     */
    protected function defaultResponsibility(
        StudentProfile $student,
        SupervisorProfile $supervisor,
        string $psmPart,
    ): float {
        $existing = SupervisionAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('is_active', true)
            ->whereIn('psm_part', [$psmPart, 'BOTH'])
            ->count();

        $total = $existing + 1;

        return round(100 / max($total, 1), 2);
    }

    /**
     * Auto-suggest supervisors for a student, ranked by expertise overlap with
     * the student's declared thesis title/abstract.
     *
     * A deliberately simple keyword match: it is a *suggestion* in the
     * coordinator UI, not an assignment, so a lightweight heuristic that a
     * coordinator can reason about beats an opaque score.
     */
    public function suggestSupervisors(StudentProfile $student, int $limit = 5): array
    {
        $haystack = strtolower(($student->thesis_title ?? '').' '.($student->thesis_abstract ?? ''));

        if (trim($haystack) === '') {
            // No declared topic: fall back to whoever has the most free capacity
            return SupervisorProfile::query()
                ->accepting()
                ->with('user')
                ->get()
                ->filter(fn (SupervisorProfile $s) => $s->hasCapacity())
                ->sortByDesc(fn (SupervisorProfile $s) => $s->remainingCapacity())
                ->take($limit)
                ->map(fn (SupervisorProfile $s) => [
                    'supervisor'  => $s,
                    'score'       => 0,
                    'matched_on'  => [],
                    'reason'      => 'اختيار حسب السعة المتاحة',
                ])
                ->values()
                ->all();
        }

        return SupervisorProfile::query()
            ->accepting()
            ->with(['user', 'expertiseAreas'])
            ->get()
            ->filter(fn (SupervisorProfile $s) => $s->hasCapacity())
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
                        ? 'لا تداخل في المواضيع — حسب السعة المتاحة فقط'
                        : 'تطابق: '.$matched->pluck('name')->implode('، '),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }
}
