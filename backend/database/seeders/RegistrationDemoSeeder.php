<?php

namespace Database\Seeders;

use App\Enums\MilestoneStatus;
use App\Enums\Role;
use App\Models\AcademicSemester;
use App\Models\Milestone;
use App\Models\StudentProfile;
use App\Models\SupervisorAgreement;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\ExaminerPairingService;
use App\Services\MilestoneService;
use App\Services\ProposalReviewService;
use App\Services\RegistrationService;
use App\Services\SemesterService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Module 3 — a self-contained cohort for exercising the registration flow.
 *
 * The seeded demo cohort cannot populate the registration queue on its own: its
 * 22 projects were created directly by ProjectSeeder, so no Lampiran A agreement
 * exists behind them. Opening the registration screen therefore showed an empty
 * table with no way to tell whether that was correct or broken.
 *
 * This seeder builds the missing half: four students who really did file
 * Lampiran A, get the supervisor's acknowledgement, register a project, and have
 * their proposal put in front of a panel. It deliberately creates *new* students
 * rather than attaching agreements to the existing cohort, so the 22 seeded
 * projects are left untouched.
 *
 * Everything goes through the real services — `RegistrationService`,
 * `ExaminerPairingService`, `MilestoneService`, `ProposalReviewService` — so the
 * demo cannot display a state the running application could not have produced.
 *
 * Four students, on purpose, so every state of the proposal milestone is visible
 * at once:
 *
 *   Aisyah Nuraini  Approved            the rest of the chain is open
 *   Faizal Hakim    ConditionalApprove  owes Lampiran C; the chain is still shut
 *   Siti Rahmah     Rejected            the title must be changed before anything
 *                                       else can start
 *   Muhammad Danish **awaiting the panel** — the proposal is submitted and nobody
 *                                       has ruled on it, so the decision itself
 *                                       can be demonstrated
 *
 * The last one matters: without it every student already has a verdict, and the
 * decision form would never have anything to act on after a fresh seed.
 *
 * It also creates a **fifth student who has filed nothing at all** — no
 * supervision, no agreement, no project. That is the account to sign in with to
 * walk the flow from the beginning: Lampiran A → supervisor acknowledges →
 * Lampiran B → the proposal milestone → the panel's decision. A student who
 * already holds an agreement cannot demonstrate that path, because Lampiran A is
 * refused once the registration exists.
 *
 * Idempotent: accounts are keyed on email, and each student's paperwork is only
 * built when they have no agreement yet.
 */
class RegistrationDemoSeeder extends Seeder
{
    public function __construct(
        protected SemesterService $semesters,
        protected RegistrationService $registrations,
        protected ExaminerPairingService $pairing,
        protected MilestoneService $milestones,
        protected ProposalReviewService $proposals,
    ) {
    }

    public function run(): void
    {
        $admin = User::where('email', 'admin@psm.test')->firstOrFail();
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();

        $term = AcademicSemester::current();

        if ($term === null) {
            $this->command?->warn('  No active semester — skipping the registration demo.');

            return;
        }

        /**
         * Make this the one active term, with registration open.
         *
         * Going through `SemesterService::update()` rather than a bare model
         * save is what enforces the single-active-term invariant: the service
         * deactivates every other active term. It also clears `closed_at`, which
         * is what lets a term that was closed by accident accept registrations
         * again.
         *
         * Registration must be open for `submitAgreement()` to accept Lampiran A
         * at all, and a demo where the student cannot file one is not a demo.
         */
        $this->semesters->update($term, [
            'is_active'            => true,
            'is_registration_open' => true,
        ], $admin);

        $supervisor = $this->supervisor();

        // -----------------------------------------------------------------
        // The three students
        // -----------------------------------------------------------------
        $roster = [
            [
                'name'       => 'Aisyah Nuraini binti Kamal',
                'email'      => 'rd.student1@psm.test',
                'student_id' => 'RD24001',
                'program'    => 'Computer Science',
                'code'       => 'CS240',
                'title'      => 'Real-Time Attendance Analytics for Lecture Halls',
                'decision'   => 'approved',
                'reason'     => null,
            ],
            [
                'name'       => 'Faizal Hakim bin Roslan',
                'email'      => 'rd.student2@psm.test',
                'student_id' => 'RD24002',
                'program'    => 'Information Technology',
                'code'       => 'IT240',
                'title'      => 'A Secure Document Exchange Portal for Faculty Boards',
                'decision'   => 'conditional_approve',
                'reason'     => 'The scope covers two portals. Narrow it to the faculty board exchange '
                    .'only, and justify the encryption choice against the threat model.',
            ],
            [
                'name'       => 'Siti Rahmah binti Osman',
                'email'      => 'rd.student3@psm.test',
                'student_id' => 'RD24003',
                'program'    => 'Software Engineering',
                'code'       => 'SE240',
                'title'      => 'A Campus Navigation App for New Students',
                'decision'   => 'rejected',
                'reason'     => 'The problem statement is not specific enough to be examinable. '
                    .'Choose a title with a measurable outcome.',
            ],
            [
                // Left awaiting the panel on purpose: without this account there
                // is no way to demonstrate the decision itself. Every other
                // student here already has a verdict, so the decision form would
                // never have anything to act on after a fresh seed.
                'name'       => 'Muhammad Danish bin Azlan',
                'email'      => 'rd.student4@psm.test',
                'student_id' => 'RD24004',
                'program'    => 'Computer Science',
                'code'       => 'CS240',
                'title'      => 'An Automated Timetabling Tool for Faculty Scheduling',
                'decision'   => null,
                'reason'     => null,
            ],
        ];

        foreach ($roster as $entry) {
            $this->registerStudent($entry, $term, $supervisor, $coordinator);
        }

        // -----------------------------------------------------------------
        // A student who has filed nothing, to walk the flow from the start
        // -----------------------------------------------------------------
        $fresh = $this->freshStudent([
            'name'       => 'Nur Izzati binti Sulaiman',
            'email'      => 'rd.fresh@psm.test',
            'student_id' => 'RD24005',
            'program'    => 'Software Engineering',
            'code'       => 'SE240',
        ], $term);

        // A re-run after the user has filed Lampiran A themselves cannot make
        // them fresh again, so the output says which state they are in rather
        // than promising an empty slate that is no longer there.
        $freshState = SupervisorAgreement::where('student_profile_id', $fresh->id)->exists()
            ? 'already has a Lampiran A on file'
            : 'nothing filed yet — start here';

        // Printed from the rows that were actually written. Hardcoding the
        // addresses here is how an earlier version of this seeder announced
        // credentials that simply did not work.
        $credentials = [
            ['Coordinator', 'coordinator@psm.test', 'password'],
            ['Supervisor',  $supervisor->user->email, 'password'],
        ];

        foreach ($roster as $entry) {
            $credentials[] = [
                'Student — '.($entry['decision'] ?? 'awaiting the panel'),
                $entry['email'],
                'password',
            ];
        }

        $credentials[] = ['Student (clean slate)', $fresh->user->email, 'password'];

        $this->command?->newLine();
        $this->command?->info('Registration + proposal milestone demo ready.');
        $this->command?->table(['Role', 'Email', 'Password'], $credentials);
        $this->command?->line('  Proposal states: approved / conditional / rejected, plus one awaiting the panel.');
        $this->command?->line('  Sign in as the panel to decide: '.$supervisor->user->email.' is not on it —');
        $this->command?->line('  the seated examiners are listed on the milestone screen itself.');
        $this->command?->line("  {$fresh->user->email}: {$freshState}");
        $this->command?->line('  Registration is open on '.$term->name.'.');
    }

    /**
     * Drive one student through registration and the panel's title decision.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function registerStudent(
        array $entry,
        AcademicSemester $term,
        SupervisorProfile $supervisor,
        User $coordinator,
    ): StudentProfile {
        $profile = $this->student($entry, $term);

        // Already registered: the paperwork is the expensive half and the
        // services create new rows every time, so a re-run must not build a
        // second set.
        if (SupervisorAgreement::where('student_profile_id', $profile->id)->exists()) {
            return $profile;
        }

        $agreement = $this->registrations->submitAgreement($profile, [
            'supervisor_profile_id' => $supervisor->id,
            'psm_part'              => 'PSM1',
            'proposed_title_1'      => $entry['title'],
            'proposed_title_2'      => $entry['title'].' — Mobile Companion',
            'proposed_title_3'      => 'A Comparative Study of '.$entry['program'].' Tooling',
        ], $profile->user);

        // The supervisor's acknowledgement registers the pairing and fixes the
        // agreed title.
        $agreement = $this->registrations->acknowledgeBySupervisor(
            $agreement,
            $entry['title'],
            $supervisor->user,
        );

        // Seat the panel against the *student*, before the project exists — the
        // real service, so the conflict-of-interest rule and the per-panel cap
        // apply exactly as they do at runtime.
        $this->pairing->autoAssign([$profile], $coordinator, 0, $term->id, 'PSM1');

        // Lampiran B creates the project with the agreed title and instantiates
        // the milestone chain. The proposal milestone is sequence 1, so it opens
        // immediately and the rest wait.
        $project = $this->registrations->submitTitleProposal($agreement, [
            'project_title'  => $entry['title'],
            'project_type'   => 'Pembangunan',
            'field_study'    => 'Kejuruteraan perisian',
            'project_origin' => 'Idea saya sendiri',
        ], $profile->user);

        // The student files the proposal. The real flow uploads files here; the
        // seeder moves the milestone through the state machine instead, because
        // there is no document to attach and the decision does not depend on one.
        $proposal = $project->milestones()
            ->where('code', Milestone::CODE_PROPOSAL)
            ->firstOrFail();

        $proposal = $this->milestones->transitionTo(
            $proposal,
            MilestoneStatus::Submitted,
            $profile->user,
            'Proposal submitted.',
        );

        // The panel's verdict — this is what settles the title and gates the
        // rest of the chain. A null decision leaves the proposal awaiting the
        // panel, so the decision itself can be demonstrated.
        if (($entry['decision'] ?? null) !== null) {
            $this->proposals->recordDecision($proposal, [
                'decision'     => $entry['decision'],
                'panel_reason' => $entry['reason'],
            ], $coordinator);
        }

        return $profile;
    }

    /**
     * The supervisor every demo student is registered under.
     *
     * A dedicated account rather than a seeded supervisor, whose per-part
     * capacity is already committed to the main cohort and who would then refuse
     * the pairing.
     */
    protected function supervisor(): SupervisorProfile
    {
        $user = User::updateOrCreate(
            ['email' => 'rd.supervisor@psm.test'],
            [
                // `SupervisorProfile::label()` prefixes the academic title, so the
                // name must not already carry it — otherwise the screen reads
                // "Dr. Dr. Haziq bin Ramli".
                'name'       => 'Haziq bin Ramli',
                'password'   => Hash::make('password'),
                'role'       => Role::Supervisor,
                'status'     => 'active',
                'staff_id'   => 'SUP-RD-001',
                'department' => 'Faculty of Computing',
            ]
        );

        return SupervisorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'staff_no'              => 'SUP-RD-001',
                'academic_title'        => 'Dr.',
                'max_supervisees'       => 10,
                'max_supervisees_psm1'  => 5,
                'max_supervisees_psm2'  => 5,
                'is_accepting_students' => true,
                'office_location'       => 'Block C, Level 2',
                'bio'                   => 'Supervises PSM projects in software engineering and secure systems.',
            ]
        );
    }

    /**
     * Create or find one demo student's account and profile.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function student(array $entry, AcademicSemester $term): StudentProfile
    {
        $user = User::updateOrCreate(
            ['email' => $entry['email']],
            [
                'name'     => $entry['name'],
                'password' => Hash::make('password'),
                'role'     => Role::Student,
                'status'   => 'active',
            ]
        );

        return StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'student_id'           => $entry['student_id'],
                'program'              => $entry['program'],
                'program_code'         => $entry['code'],
                'batch'                => '2026',
                'faculty'              => 'Faculty of Computing',
                'current_semester'     => 6,
                'academic_semester_id' => $term->id,
                'thesis_title'         => $entry['title'] ?? null,
                'thesis_abstract'      => isset($entry['title'])
                    ? "This project investigates {$entry['title']}. The work follows the standard "
                        .'PSM lifecycle from proposal through to final evaluation.'
                    : null,
            ]
        );
    }

    /**
     * Create or find a student with a clean slate.
     *
     * No supervision assignment, no Lampiran A, no project — deliberately. The
     * only way to demonstrate the flow from its first step is to be a student
     * who has not taken it: `submitAgreement()` refuses a second registration for
     * the same pairing, so a student who already holds an agreement cannot show
     * Lampiran A being filed.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function freshStudent(array $entry, AcademicSemester $term): StudentProfile
    {
        return $this->student($entry, $term);
    }
}
