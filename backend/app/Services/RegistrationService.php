<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ProjectCategory;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentProfile;
use App\Models\SupervisorAgreement;
use App\Models\SupervisorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registration flow — Lampiran A (supervisor agreement) and Lampiran B (the
 * agreed title registered as a project).
 *
 * The pairing is created by AssignmentService so every capacity and duplication
 * rule stays in one enforcement point. This service orchestrates the paperwork →
 * pairing transition and the project's creation.
 *
 * **The title is not judged here.** The supervisor's acknowledgement fixes the
 * agreed title and Lampiran B registers it; the panel then decides at the
 * project's **proposal milestone**, which is what gates the rest of the chain —
 * see `ProposalReviewService`. Keeping the decision out of registration is what
 * lets the project exist before the title is ruled on.
 */
class RegistrationService
{
    public function __construct(
        protected AssignmentService $assignments,
        protected MilestoneService $milestones,
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
        protected SemesterService $semesters,
    ) {
    }

    // -----------------------------------------------------------------
    // Lampiran A — Supervisor Agreement
    // -----------------------------------------------------------------

/**
 * Student submits Lampiran A: names a supervisor and proposes up to three
     * titles. The agreement waits on the supervisor's acknowledgement.
     *
     * Gated on the active semester's `is_registration_open` flag (requirement
     * acceptance criterion #2). Previously this method accepted a free-text
     * `session` straight off the request body and never consulted the calendar,
     * so a student could file Lampiran A against a term that had closed, or
     * against a session string that does not exist at all. The session is now
     * taken from the authoritative active semester rather than the client.
     *
     * @throws InvalidArgumentException when registration is closed for the term
     */
    public function submitAgreement(StudentProfile $student, array $data, User $actor): SupervisorAgreement
    {
        $semester = $this->semesters->assertRegistrationOpen();
        $supervisor = SupervisorProfile::findOrFail($data['supervisor_profile_id']);

        $agreement = SupervisorAgreement::create([
            'student_profile_id'    => $student->id,
            'supervisor_profile_id' => $supervisor->id,
            /**
             * Authoritative: the active semester, never the client's `session`.
             *
             * The *session string* ("2025/2026"), not the term's display name
             * ("2025/2026 Semester II"). Everything downstream keys on the
             * session string — `projects.academic_session`,
             * `supervisor_agreements.session` — and
             * `Project::nextCode()` counts existing codes within
             * `(psm_part, academic_session)`. Stamping the display name put the
             * agreement in a bucket of its own, so the first Lampiran B in a
             * term counted zero existing projects and generated a code that was
             * already taken:
             *
             *   SQLSTATE[23000]: 1062 Duplicate entry 'PSM1-2025-CS2-001'
             *   for key 'projects.projects_code_unique'
             */
            'session'               => $semester->academic_session,
            /**
             * The term itself, alongside the session string.
             *
             * The session string cannot identify a term — the faculty runs two
             * per session and they share it — and every term-scoped screen
             * filters on `academic_semester_id`. Lampiran B copies this onto the
             * project it registers; without it a project has no term, and
             * `ProjectController::index()` (which defaults to the current term)
             * returns nothing to its own student.
             */
            'academic_semester_id'  => $semester->id,
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
     * Part C — the supervisor acknowledges and picks the agreed title.
     *
     * THIS is where the pairing is registered, and it is the last step before
     * registration: acknowledgement fixes the agreed title, and Lampiran B
     * creates the project with it.
     *
     * The supervisor judges whether the **supervision** should happen; the
     * **title** is judged afterwards, by the panel, at the proposal milestone
     * (see ProposalReviewService). Keeping the two apart is deliberate: a panel
     * refusal of the title must not undo a pairing the supervisor has accepted.
     *
     * This used to route the proposal on to JKPSM, then to a coordinator-run
     * title defence, then to a panel review on this very row. All three are gone.
     */
    public function acknowledgeBySupervisor(
        SupervisorAgreement $agreement,
        string $agreedTitle,
        User $actor,
    ): SupervisorAgreement {
        if ($agreement->status !== SupervisorAgreement::STATUS_PENDING_SUPERVISOR) {
            throw new InvalidArgumentException('This agreement is not awaiting supervisor acknowledgement.');
        }

        if (trim($agreedTitle) === '') {
            throw new InvalidArgumentException('Pick the title the student will register.');
        }

        /**
         * The agreed title must be one the student actually proposed.
         *
         * Without this the supervisor could name any string at all, and that
         * string becomes the project's title — the candidate set the panel is
         * asked to consider and the title recorded on the registered project
         * would then be two unrelated things, with nothing able to reconcile
         * them. The three candidates are the entire negotiable space: the form
         * offers them as a list, and Part C is a *choice* between them.
         *
         * Compared with whitespace collapsed and case-insensitively, because a
         * supervisor typing or pasting the title should not be refused over
         * spacing or casing — the stored value is still the candidate exactly as
         * proposed, so the two cannot drift.
         */
        $normalise = static fn ($title): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim((string) $title)) ?? ''
        );

        $candidates = collect([
            $agreement->proposed_title_1,
            $agreement->proposed_title_2,
            $agreement->proposed_title_3,
        ])->filter()->values();

        $agreedTitle = $candidates->first(
            fn ($t) => $normalise($t) === $normalise($agreedTitle)
        );

        if ($agreedTitle === null) {
            throw new InvalidArgumentException(
                'The agreed title must be one of the titles the student proposed. '
                .'Part C records the choice between those candidates; a different title has to '
                .'come from a new Lampiran A.'
            );
        }

        return DB::transaction(function () use ($agreement, $agreedTitle, $actor) {
            $student    = $agreement->studentProfile;
            $supervisor = $agreement->supervisorProfile;

            if (! $student || ! $supervisor) {
                throw new InvalidArgumentException('The agreement is missing its student or supervisor.');
            }

            /**
             * Register the pairing, unless one is already attached.
             *
             * The acknowledgement is only reachable once, but a legacy row that
             * already carries a pairing must not be refused as a duplicate — the
             * pairing is the expensive half and re-creating it would be wrong.
             */
            $assignmentId = $agreement->supervision_assignment_id;

            if ($assignmentId === null) {
                $assignmentId = $this->assignments->assignSupervisor(
                    $student,
                    $supervisor,
                    $actor,
                    $agreement->psm_part,
                    'primary',
                    null,
                    'Registered from Lampiran A (Supervisor Agreement)',
                )->id;
            }

            $agreement->update([
                'agreed_title'               => trim($agreedTitle),
                'supervisor_acknowledged_at' => now(),
                'supervision_assignment_id'  => $assignmentId,
                'status'                     => SupervisorAgreement::STATUS_APPROVED,
            ]);

            $this->audit->log(
                action: AuditAction::ProjectApproved,
                description: "Lampiran A acknowledged by {$actor->name} for {$student->student_id} "
                    .'— pairing registered, Lampiran B may be filed',
                subject: $agreement,
                actor: $actor,
            );

            return $agreement->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Lampiran B — Title Proposal
    // -----------------------------------------------------------------

    /**
     * Create the project record for an acknowledged agreement. Project type maps
     * to ProjectCategory, which in turn selects the milestone and rubric
     * templates (see MilestoneService::instantiateFor()).
     *
     * One agreement produces exactly one project. The agreement is the pairing
     * record, so a second Lampiran B is either a double-tap or a stale tab — not
     * a new project — and it is refused rather than silently returning the first
     * one, so the student is told which project to look at instead of watching
     * two identical projects appear.
     *
     * The title is the supervisor's **agreed title**, fixed at acknowledgement.
     * Lampiran B confirms a value already decided rather than letting the student
     * quietly register something else: a mismatch is refused. The title is judged
     * afterwards, at the proposal milestone, and a rejection there rewrites the
     * project title directly.
     */
    public function submitTitleProposal(
        SupervisorAgreement $agreement,
        array $data,
        User $actor,
    ): Project {
        $title = $this->confirmedTitleFor($agreement, $data);

        /**
         * One agreement produces one project, and `projects.agreement_id` is the
         * link — the column both `Project::agreement()` and
         * `SupervisorAgreement::project()` read.
         *
         * This used to be looked up in `metadata->agreement_id` because the
         * column was never written, which left `$agreement->project` null
         * everywhere: the registration list could not link to the project, and
         * the project could not find the agreement it came from. The column is
         * written below now, so the check reads it directly.
         */
        $existing = Project::query()
            ->where('agreement_id', $agreement->id)
            ->first(['id', 'code']);

        if ($existing) {
            throw new InvalidArgumentException(sprintf(
                'Lampiran B has already been submitted for this agreement as project %s.',
                $existing->code
            ));
        }

        $student = $agreement->studentProfile;

        /**
         * The term the project belongs to.
         *
         * Taken from the agreement, which records the exact term it was filed
         * for. The fallback resolves the session string to the *active* term —
         * the best available answer for an agreement filed before that column
         * existed.
         *
         * This must be set: every term-scoped screen filters on
         * `academic_semester_id`, and a project with a null term matches no
         * term at all, so its own student cannot see it.
         */
        $semesterId = $agreement->academic_semester_id
            ?? AcademicSemester::resolveFilterId($agreement->session);

        return DB::transaction(function () use ($agreement, $data, $actor, $student, $title, $semesterId) {
            $category = ($data['project_type'] ?? 'Pembangunan') === 'Kajian'
                ? ProjectCategory::Research
                : ProjectCategory::System;

            $project = Project::create([
                'code'             => Project::nextCode(
                    $agreement->psm_part,
                    $agreement->session,
                    $student?->program_code,
                ),
                // The supervisor's agreed title, not the student's entry.
                'title'            => $title,
                'category'         => $category,
                'psm_part'         => $agreement->psm_part,
                'academic_session' => $agreement->session,
                // The term — what every scoped query actually filters on.
                'academic_semester_id' => $semesterId,
                'batch'            => $student?->batch ?? 'unknown',
                'program'          => $student?->program_code,
                'status'           => 'submitted',
                // The agreement this project registers — the relation both
                // models read, and what makes the duplicate check above work.
                'agreement_id'     => $agreement->id,
                'created_by'       => $actor->id,
                'submitted_at'     => now(),
                'metadata'         => [
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

            // The panel was allocated to the *student* before the project
            // existed, because the same pair reviews the proposal and gives the
            // final mark. Stamp the project onto those allocations now, so the
            // evaluation path — which reads project_id — finds them unchanged.
            if ($student !== null) {
                ExaminerAssignment::query()
                    ->forStudent($student->id)
                    ->where('psm_part', $project->psm_part)
                    ->where('is_active', true)
                    ->update(['project_id' => $project->id]);
            }

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

    /**
     * Decide which title Lampiran B registers, and refuse the submission if the
     * agreement is not in a state that allows it.
     *
     * The gate is the supervisor's acknowledgement, which is also what fixes the
     * agreed title:
     *
     *  - acknowledged — the agreed title is authoritative, and the student's
     *    entry has to match it, so Lampiran B cannot register something else;
     *  - still awaiting the supervisor — refused, naming that step;
     *  - cancelled — refused.
     */
    protected function confirmedTitleFor(SupervisorAgreement $agreement, array $data): string
    {
        if (! $agreement->isApproved()) {
            throw new InvalidArgumentException(match ($agreement->status) {
                SupervisorAgreement::STATUS_PENDING_SUPERVISOR =>
                    'Lampiran A is still awaiting your supervisor\'s acknowledgement, so Lampiran B '
                    .'cannot be submitted yet.',
                SupervisorAgreement::STATUS_CANCELLED =>
                    'This registration was cancelled, so Lampiran B cannot be submitted.',
                default =>
                    'Lampiran A must be acknowledged by your supervisor before Lampiran B can be submitted.',
            });
        }

        $agreed = $agreement->titleForRegistration();

        if ($agreed === null) {
            throw new InvalidArgumentException(
                'No agreed title is recorded, so there is nothing for Lampiran B to register.'
            );
        }

        $submitted = trim((string) ($data['project_title'] ?? ''));

        if ($submitted !== trim($agreed)) {
            throw new InvalidArgumentException(sprintf(
                'Lampiran B must carry the title your supervisor agreed: "%s". '
                .'A different title would register something the supervisor never accepted.',
                $agreed
            ));
        }

        return trim($agreed);
    }
}
