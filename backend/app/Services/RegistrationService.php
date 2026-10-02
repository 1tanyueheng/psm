<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ProjectCategory;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentProfile;
use App\Models\SupervisorAgreement;
use App\Models\SupervisorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registration flow — Lampiran A (supervisor agreement) and Lampiran B (title
 * proposal) through to a registered supervisor<->student pairing.
 *
 * The pairing itself is created by AssignmentService so every capacity and
 * duplication rule stays in one enforcement point. This service only
 * orchestrates the paperwork -> pairing transition and the title proposal.
 */
class RegistrationService
{
    public function __construct(
        protected AssignmentService $assignments,
        protected MilestoneService $milestones,
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    // -----------------------------------------------------------------
    // Lampiran A — Supervisor Agreement
    // -----------------------------------------------------------------

    /**
     * Student submits Lampiran A: names a supervisor and proposes up to three
     * titles. The agreement waits on the supervisor's acknowledgement.
     */
    public function submitAgreement(StudentProfile $student, array $data, User $actor): SupervisorAgreement
    {
        $supervisor = SupervisorProfile::findOrFail($data['supervisor_profile_id']);

        $agreement = SupervisorAgreement::create([
            'student_profile_id'    => $student->id,
            'supervisor_profile_id' => $supervisor->id,
            'session'               => $data['session'],
            'psm_part'              => $data['psm_part'] ?? 'BOTH',
            'proposed_title_1'      => $data['proposed_title_1'],
            'proposed_title_2'      => $data['proposed_title_2'] ?? null,
            'proposed_title_3'      => $data['proposed_title_3'] ?? null,
            'english_report'        => (bool) ($data['english_report'] ?? false),
            'status'                => SupervisorAgreement::STATUS_PENDING_SUPERVISOR,
            'student_signed_at'     => now(),
        ]);

        $this->audit->log(
            action: AuditAction::ProjectSubmitted,
            description: "Lampiran A submitted — {$student->student_id} → {$supervisor->label()}",
            subject: $agreement,
            actor: $actor,
        );

        if ($supervisor->user) {
            $this->notifications->notify(
                [$supervisor->user],
                NotificationType::SupervisorAssigned,
                [
                    'title'      => 'New Lampiran A to acknowledge',
                    'body'       => "{$student->student_id} has named you as supervisor. Please acknowledge the agreement.",
                    'action_url' => '/registrations',
                ],
                $agreement,
            );
        }

        return $agreement;
    }

    /**
     * Part C — the supervisor acknowledges and picks the agreed title. The
     * agreement then moves to JKPSM for approval.
     */
    public function acknowledgeBySupervisor(
        SupervisorAgreement $agreement,
        string $agreedTitle,
        User $actor,
    ): SupervisorAgreement {
        if ($agreement->status !== SupervisorAgreement::STATUS_PENDING_SUPERVISOR) {
            throw new InvalidArgumentException('This agreement is not awaiting supervisor acknowledgement.');
        }

        $agreement->update([
            'agreed_title'               => $agreedTitle,
            'supervisor_acknowledged_at' => now(),
            'status'                     => SupervisorAgreement::STATUS_PENDING_JKPSM,
        ]);

        $this->audit->log(
            action: AuditAction::ProjectUpdated,
            description: "Lampiran A acknowledged by {$actor->name} for {$agreement->studentProfile?->student_id}",
            subject: $agreement,
            actor: $actor,
        );

        return $agreement->fresh();
    }

    /**
     * Part D — JKPSM/coordinator approves. THIS is where the pairing is
     * registered: an active supervision assignment is created via
     * AssignmentService and linked back to the agreement.
     */
    public function approve(SupervisorAgreement $agreement, User $actor): SupervisorAgreement
    {
        if ($agreement->status !== SupervisorAgreement::STATUS_PENDING_JKPSM) {
            throw new InvalidArgumentException('Only an acknowledged agreement can be approved.');
        }

        return DB::transaction(function () use ($agreement, $actor) {
            $student    = $agreement->studentProfile;
            $supervisor = $agreement->supervisorProfile;

            if (! $student || ! $supervisor) {
                throw new InvalidArgumentException('The agreement is missing its student or supervisor.');
            }

            // Register the pairing (capacity + duplication rules live inside).
            $assignment = $this->assignments->assignSupervisor(
                $student,
                $supervisor,
                $actor,
                $agreement->psm_part,
                'primary',
                null,
                'Registered from Lampiran A (Supervisor Agreement)',
            );

            $agreement->update([
                'status'                    => SupervisorAgreement::STATUS_APPROVED,
                'decided_by'                => $actor->id,
                'decided_at'                => now(),
                'jkpsm_received_at'         => $agreement->jkpsm_received_at ?? now(),
                'supervision_assignment_id' => $assignment->id,
            ]);

            $this->audit->log(
                action: AuditAction::ProjectApproved,
                description: "Lampiran A approved — pairing registered for {$student->student_id}",
                subject: $agreement,
                actor: $actor,
            );

            return $agreement->fresh();
        });
    }

    public function reject(SupervisorAgreement $agreement, User $actor, string $reason): SupervisorAgreement
    {
        $agreement->update([
            'status'           => SupervisorAgreement::STATUS_REJECTED,
            'decided_by'       => $actor->id,
            'decided_at'       => now(),
            'rejection_reason' => $reason,
        ]);

        $this->audit->log(
            action: AuditAction::ProjectRejected,
            description: "Lampiran A rejected for {$agreement->studentProfile?->student_id}",
            subject: $agreement,
            actor: $actor,
        );

        return $agreement->fresh();
    }

    // -----------------------------------------------------------------
    // Lampiran B — Title Proposal
    // -----------------------------------------------------------------

    /**
     * Create the project record for an approved agreement. Project type maps
     * to ProjectCategory, which in turn selects the milestone and rubric
     * templates (see MilestoneService::instantiateFor()).
     *
     * One agreement produces exactly one project. The agreement is the pairing
     * record, so a second Lampiran B is either a double-tap or a stale tab — not
     * a new project — and it is refused rather than silently returning the first
     * one, so the student is told which project to look at instead of watching
     * two identical projects appear.
     */
    public function submitTitleProposal(
        SupervisorAgreement $agreement,
        array $data,
        User $actor,
    ): Project {
        if (! $agreement->isApproved()) {
            throw new InvalidArgumentException('Lampiran A must be approved before Lampiran B can be submitted.');
        }

        // Portable across drivers: a JSON path comparison in the WHERE clause is
        // spelled differently on MySQL and Postgres, and the candidate set (one
        // PSM part, one session) is small enough to filter in PHP.
        $existing = Project::query()
            ->where('psm_part', $agreement->psm_part)
            ->where('academic_session', $agreement->session)
            ->get(['id', 'code', 'metadata'])
            ->first(fn (Project $p) => data_get($p->metadata, 'agreement_id') === $agreement->id);

        if ($existing) {
            throw new InvalidArgumentException(sprintf(
                'Lampiran B has already been submitted for this agreement as project %s.',
                $existing->code
            ));
        }

        $student = $agreement->studentProfile;

        return DB::transaction(function () use ($agreement, $data, $actor, $student) {
            $category = ($data['project_type'] ?? 'Pembangunan') === 'Kajian'
                ? ProjectCategory::Research
                : ProjectCategory::System;

            $project = Project::create([
                'code'             => Project::nextCode(
                    $agreement->psm_part,
                    $agreement->session,
                    $student?->program_code,
                ),
                'title'            => $data['project_title'],
                'category'         => $category,
                'psm_part'         => $agreement->psm_part,
                'academic_session' => $agreement->session,
                'batch'            => $student?->batch ?? 'unknown',
                'program'          => $student?->program_code,
                'status'           => 'submitted',
                'created_by'       => $actor->id,
                'submitted_at'     => now(),
                'metadata'         => [
                    'agreement_id' => $agreement->id,
                    'field_study'  => $data['field_study'] ?? null,
                    'origin'       => $data['project_origin'] ?? null,
                    'requirements' => [
                        'software'   => $data['req_software'] ?? null,
                        'hardware'   => $data['req_hardware'] ?? null,
                        'technology' => $data['req_tech'] ?? null,
                    ],
                ],
            ]);

            ProjectMember::create([
                'project_id'           => $project->id,
                'student_profile_id'   => $student->id,
                'is_leader'            => true,
                'contribution_percent' => 100.00,
            ]);

            // Lampiran B *is* the project submission, so the milestone chain is
            // generated here rather than waiting for ProjectController::submit()
            // — the project is already in 'submitted' status and that endpoint
            // is not part of this flow.
            $this->milestones->instantiateFor($project, now());

            $this->audit->log(
                action: AuditAction::ProjectCreated,
                description: "Lampiran B submitted — {$project->code} ({$project->title})",
                subject: $project,
                actor: $actor,
            );

            return $project;
        });
    }
}
