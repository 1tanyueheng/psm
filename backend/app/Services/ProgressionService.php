<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentProfile;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — PSM 1 → PSM 2 progression.
 *
 * PSM 1 and PSM 2 are one project run across two consecutive terms on **one
 * title**. PSM 1 delivers the proposal and Chapters 1–4; PSM 2 delivers
 * Chapters 5–7 and assembles the whole document for examination.
 *
 * Only PSM 1 registers a title. There is no second Lampiran A, no second
 * proposal review, and no re-allocation: the title, the supervisor and the
 * examiner pair all carry over. What this service does is move the student
 * forward — creating the PSM 2 project from the PSM 1 title, moving the
 * enrolment into the next term, carrying the supervisor and panel across, and
 * freezing PSM 1.
 *
 * That is why it exists rather than a re-registration. Re-registering would ask
 * the student to propose three titles for a project they have already been
 * examined on, and would leave the PSM 2 project unrelated to the PSM 1 one it
 * continues.
 *
 * **Trigger:** the PSM 1 marks must have been released. Progressing earlier
 * would enrol the student in PSM 2 while PSM 1 is still unresolved, and the
 * released mark is what the archived PSM 1 record is supposed to carry.
 */
class ProgressionService
{
    public function __construct(
        protected MilestoneService $milestones,
        protected ArchiveService $archive,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Move a student from their PSM 1 project into PSM 2.
     *
     * Idempotent by refusal rather than by repetition: a student who already has
     * a live PSM 2 project is told so, because silently returning the existing
     * project would hide a second press from the coordinator.
     */
    public function progress(Project $psm1, User $actor): Project
    {
        if ($psm1->psm_part !== 'PSM1') {
            throw new InvalidArgumentException(
                "{$psm1->code} is a {$psm1->psm_part} project. Only a PSM 1 project can be progressed."
            );
        }

        if ($psm1->archived_at !== null) {
            throw new InvalidArgumentException(
                "{$psm1->code} has already been archived, so it has already been progressed."
            );
        }

        $student = $psm1->leader();

        if ($student === null) {
            throw new InvalidArgumentException(
                "{$psm1->code} has no student on record, so there is nobody to progress."
            );
        }

        $sourceTerm = AcademicSemester::find($psm1->academic_semester_id);

        if ($sourceTerm === null) {
            throw new InvalidArgumentException(
                "{$psm1->code} has no academic term recorded, so the next term cannot be derived."
            );
        }

        if (! $sourceTerm->is_marks_released) {
            throw new InvalidArgumentException(
                "Marks have not been released for {$sourceTerm->name}. Release them before "
                ."progressing {$student->student_id} to PSM 2."
            );
        }

        if ($this->livePsm2For($student) !== null) {
            throw new InvalidArgumentException(
                "{$student->student_id} already has a live PSM 2 project. Archive it before progressing again."
            );
        }

        $targetTerm = $this->targetTerm($sourceTerm);

        return DB::transaction(function () use ($psm1, $student, $sourceTerm, $targetTerm, $actor) {
            $project = Project::create([
                'code'                 => Project::nextCode('PSM2', $targetTerm->academic_session, $student->program_code),
                // One title across both parts. PSM 2 continues the project the
                // student was already examined on, so the title is inherited
                // rather than re-proposed.
                'title'                => $psm1->title,
                'abstract'             => $psm1->abstract,
                'objectives'           => $psm1->objectives,
                'scope'                => $psm1->scope,
                'category'             => $psm1->category,
                'psm_part'             => 'PSM2',
                'academic_semester_id' => $targetTerm->id,
                'academic_session'     => $targetTerm->academic_session,
                'batch'                => $student->batch,
                'program'              => $student->program_code,
                // In progress from the outset: this is a continuation, not a
                // fresh submission awaiting approval.
                'status'               => 'in_progress',
                'created_by'           => $actor->id,
                'approved_by'          => $actor->id,
                'submitted_at'         => now(),
                'approved_at'          => now(),
                'metadata'             => [
                    'progressed_from_project_id' => $psm1->id,
                    'progressed_from_code'       => $psm1->code,
                    'agreement_id'               => $psm1->agreement_id,
                    'inherited_title'            => true,
                ],
            ]);

            ProjectMember::create([
                'project_id'           => $project->id,
                'student_profile_id'   => $student->id,
                'is_leader'            => true,
                'contribution_percent' => 100.00,
            ]);

            // The PSM 2 chain — Chapters 5-7 and the final report. Resolved
            // through the template, so this follows the versioned chain rather
            // than a hardcoded list.
            $this->milestones->instantiateFor($project, now());

            /**
             * Move the enrolment forward.
             *
             * Load-bearing beyond bookkeeping: `AssignmentService` scopes the
             * supervisor capacity gate by `academic_semester_id`, and
             * `SemesterService::currentFor()` hands a student their own term.
             * Leaving it behind would capacity-check PSM 2 against the PSM 1
             * term and show the student the wrong term on every scoped screen.
             */
            $student->forceFill(['academic_semester_id' => $targetTerm->id])->save();

            // Same supervisor: widen the standing pairing to cover both parts
            // rather than registering a second one, which the duplicate check
            // would refuse anyway.
            SupervisionAssignment::query()
                ->where('student_profile_id', $student->id)
                ->where('is_active', true)
                ->where('psm_part', 'PSM1')
                ->update(['psm_part' => 'BOTH']);

            // Same panel, reused rather than re-allocated: the pair already
            // knows the title, and re-running the conflict-of-interest check
            // would only risk refusing the people who examined PSM 1. The PSM 1
            // rows stay attached to the archived project as its history.
            $this->carryPanelAcross($psm1, $project, $student, $actor);

            // PSM 1 is finished. Freezing it keeps the PSM 2 project the only
            // live one and preserves the mark it was awarded.
            $this->archive->archive($psm1, $actor, 'Progressed to PSM 2 in '.$targetTerm->name);

            $this->audit->log(
                action: AuditAction::ProjectProgressed,
                description: sprintf(
                    'Progressed %s to PSM 2 — %s created from %s (%s → %s)',
                    $student->student_id,
                    $project->code,
                    $psm1->code,
                    $sourceTerm->name,
                    $targetTerm->name,
                ),
                subject: $project,
                actor: $actor,
            );

            return $project->fresh(['milestones', 'members', 'agreement']);
        });
    }

    /** The student's live PSM 2 project, if they already have one. */
    public function livePsm2For(StudentProfile $student): ?Project
    {
        return Project::query()
            ->whereHas('members', fn ($q) => $q->where('student_profile_id', $student->id))
            ->where('psm_part', 'PSM2')
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Copy the PSM 1 panel onto the PSM 2 project.
     *
     * New rows keyed on (student, examiner, PSM2), so the PSM 1 allocations are
     * left untouched for the archived project.
     */
    protected function carryPanelAcross(
        Project $psm1,
        Project $psm2,
        StudentProfile $student,
        User $actor,
    ): void {
        $allocations = ExaminerAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('psm_part', 'PSM1')
            ->where('is_active', true)
            ->get();

        foreach ($allocations as $allocation) {
            ExaminerAssignment::updateOrCreate(
                [
                    'student_profile_id' => $student->id,
                    'examiner_id'        => $allocation->examiner_id,
                    'psm_part'           => 'PSM2',
                ],
                [
                    'project_id'       => $psm2->id,
                    'examiner_pair_id' => $allocation->examiner_pair_id,
                    'panel_role'       => $allocation->panel_role,
                    'is_active'        => true,
                    'assigned_by'      => $actor->id,
                ]
            );
        }
    }

    /**
     * The term PSM 2 runs in: `psm.progression_term_gap` terms after the PSM 1
     * term, counting the immediately following term as 0.
     *
     * The default is 0 — PSM 2 runs in the term straight after PSM 1, which is
     * what "two continuous semesters" means. A faculty that gives students a
     * term off between the parts raises the gap.
     */
    protected function targetTerm(AcademicSemester $sourceTerm): AcademicSemester
    {
        // chronological() is newest-first; the gap arithmetic reads better
        // oldest-first, so it is reversed once here.
        $terms = AcademicSemester::query()->chronological()->get()->reverse()->values();

        $index = $terms->search(fn (AcademicSemester $term) => $term->id === $sourceTerm->id);

        if ($index === false) {
            throw new InvalidArgumentException(
                "The PSM 1 term ({$sourceTerm->name}) is not among the recorded semesters."
            );
        }

        $gap = max(0, (int) config('psm.progression_term_gap', 0));
        $target = $terms->get($index + $gap + 1);

        if ($target === null) {
            throw new InvalidArgumentException(
                "No later term exists for PSM 2. PSM 1 ran in {$sourceTerm->name}; create the next "
                .'term before progressing the student.'
            );
        }

        return $target;
    }
}
