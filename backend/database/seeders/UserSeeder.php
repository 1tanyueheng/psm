<?php

namespace Database\Seeders;

use App\Enums\Programme;
use App\Enums\Role;
use App\Models\CoordinatorScope;
use App\Models\ExpertiseArea;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Modules 1 & 2 — Users, profiles, expertise and supervision pairings.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // -----------------------------------------------------------------
        // Named demo accounts, one per role
        // -----------------------------------------------------------------
        $admin = User::updateOrCreate(
            ['email' => 'admin@psm.test'],
            [
                'name'     => 'System Administrator',
                'password' => Hash::make('password'),
                'role'     => Role::Admin,
                'status'   => 'active',
                'department' => 'Faculty of Computing',
            ]
        );

        $coordinator = User::updateOrCreate(
            ['email' => 'coordinator@psm.test'],
            [
                'name'       => 'Dr. Farah binti Ismail',
                'password'   => Hash::make('password'),
                'role'       => Role::Coordinator,
                'status'     => 'active',
                'staff_id'   => 'STF-COORD-001',
                'department' => 'Faculty of Computing',
            ]
        );

        CoordinatorScope::updateOrCreate(
            ['user_id' => $coordinator->id, 'batch' => '2026', 'program' => null],
            ['is_primary' => true]
        );

        // -----------------------------------------------------------------
        // Expertise vocabulary
        // -----------------------------------------------------------------
        $expertise = [
            ['name' => 'Artificial Intelligence',      'category' => 'AI & Data'],
            ['name' => 'Machine Learning',             'category' => 'AI & Data'],
            ['name' => 'Data Mining',                  'category' => 'AI & Data'],
            ['name' => 'Computer Vision',              'category' => 'AI & Data'],
            ['name' => 'Natural Language Processing',  'category' => 'AI & Data'],
            ['name' => 'Cybersecurity',                'category' => 'Security'],
            ['name' => 'Cryptography',                 'category' => 'Security'],
            ['name' => 'Digital Forensics',            'category' => 'Security'],
            ['name' => 'Software Engineering',         'category' => 'Software'],
            ['name' => 'Web Development',              'category' => 'Software'],
            ['name' => 'Mobile Computing',             'category' => 'Software'],
            ['name' => 'Human-Computer Interaction',   'category' => 'HCI'],
            ['name' => 'User Experience Design',       'category' => 'HCI'],
            ['name' => 'Internet of Things',           'category' => 'Networks'],
            ['name' => 'Computer Networks',            'category' => 'Networks'],
            ['name' => 'Cloud Computing',              'category' => 'Networks'],
            ['name' => 'Database Systems',             'category' => 'Data'],
            ['name' => 'Big Data Analytics',           'category' => 'Data'],
            ['name' => 'Educational Technology',       'category' => 'Applied'],
            ['name' => 'Healthcare Informatics',       'category' => 'Applied'],
        ];

        foreach ($expertise as $area) {
            ExpertiseArea::updateOrCreate(
                ['name' => $area['name']],
                ['category' => $area['category']]
            );
        }

        $this->command->line('  Expertise areas: '.count($expertise));

        // -----------------------------------------------------------------
        // Supervisors
        // -----------------------------------------------------------------
        /**
         * Supervisor roster.
         *
         * Trailing columns are optional per-batch ceilings. They exist so the
         * seed exercises the case acceptance criterion #4 is actually about:
         * capacity enforced *per batch*, not globally. With every supervisor at
         * the config default of 5+5, a screen could show a single "5/10" total
         * and still pass, because the two ceilings never diverge.
         *
         * SUP-001 carries 2 PSM 1 and 2 PSM 2 students in the active term, so
         * capping PSM 1 at 2 makes them exactly full on one batch while still
         * having room on the other — the situation a supervisor dashboard has
         * to be able to express.
         *
         * The last column is the staff member's programme, and it matters for
         * the same reason a student's does: a supervisor may only take students
         * from their own programme, and an examiner may only be seated on a
         * panel for their own. Without a programme on the staff row the rule
         * cannot fire at all — it reads "unknown" as "permit", deliberately, so
         * that legacy rows are not silently locked out. Every staff row here
         * therefore gets one.
         *
         * They are spread across the five programmes so each has supervisors,
         * which is what lets the pairing logic actually place the cohort.
         *
         * **Four per programme, deliberately.** A panel needs two examiners from
         * the student's own programme, so a programme with one member of staff
         * can never seat a panel at all, and a programme with two deadlocks the
         * moment one of them supervises the student and the other is already
         * seated. BIW was seeded with a single supervisor and BIM with two, which
         * made those programmes unexaminable — correctly, under the programme
         * rule, but with no way out. Four leaves one supervising, one examining,
         * and two with room as the cohort grows.
         *
         * SUP-009 to SUP-014 are those additions. They start with no students
         * and no panels: the point of adding them is that capacity exists to be
         * allocated, and pre-loading them would hide whether the pairing screens
         * actually work.
         */
        $supervisorData = [
            ['Dr.',  'Ahmad Faizal bin Hassan',  'SUP-001',  8,  ['Artificial Intelligence', 'Machine Learning', 'Data Mining'], 2, null, 'BIP'],
            ['Dr.',  'Tan Wei Ming',             'SUP-002',  6,  ['Cybersecurity', 'Cryptography', 'Digital Forensics'], null, null, 'BIS'],
            ['Prof.', 'Siti Nurhaliza binti Ali','SUP-003', 10, ['Software Engineering', 'Web Development', 'Cloud Computing'], null, null, 'BIP'],
            ['Dr.',  'Rajesh Kumar',             'SUP-004',  8,  ['Internet of Things', 'Computer Networks', 'Cloud Computing'], null, null, 'BIT'],
            ['Ts.',  'Lim Chee Keong',           'SUP-005',  6,  ['Human-Computer Interaction', 'User Experience Design', 'Mobile Computing'], null, null, 'BIM'],
            ['Dr.',  'Nurul Aini binti Yusof',   'SUP-006',  8,  ['Database Systems', 'Big Data Analytics'], null, null, 'BIT'],
            ['Dr.',  'Wong Hui Ling',            'SUP-007',  6,  ['Computer Vision', 'Natural Language Processing', 'Machine Learning'], null, null, 'BIW'],
            ['Dr.',  'Muhammad Arif bin Zainal', 'SUP-008',  6,  ['Educational Technology', 'Healthcare Informatics'], null, null, 'BIS'],

            // --- BIW: was one supervisor, so no panel could ever be formed ---
            // Expertise is drawn only from the seeded `expertise_areas` table;
            // an area that does not exist is silently dropped by the sync, so a
            // plausible-sounding invention here would leave the profile empty
            // and the workload report unable to match them to a topic.
            ['Dr.',  'Nor Azlina binti Mokhtar', 'SUP-009',  6,  ['Web Development', 'User Experience Design', 'Software Engineering'], null, null, 'BIW'],
            ['Ts.',  'Goh Chun Wei',             'SUP-010',  6,  ['Cybersecurity', 'Computer Networks', 'Cloud Computing'], null, null, 'BIW'],
            ['Dr.',  'Saraswathy a/p Muniandy',  'SUP-011',  6,  ['Database Systems', 'Big Data Analytics', 'Machine Learning'], null, null, 'BIW'],

            // --- BIM: two staff, both fully committed ---
            ['Dr.',  'Izzat bin Kamaruddin',     'SUP-012',  6,  ['Mobile Computing', 'Human-Computer Interaction', 'Software Engineering'], null, null, 'BIM'],
            ['Dr.',  'Ho Li Shan',               'SUP-013',  6,  ['Computer Vision', 'Machine Learning', 'Natural Language Processing'], null, null, 'BIM'],

            // --- BIS: three, to match the others ---
            ['Dr.',  'Faizal bin Ramlee',        'SUP-014',  6,  ['Cybersecurity', 'Digital Forensics', 'Computer Networks'], null, null, 'BIS'],
        ];

        $supervisors = [];

        // Pad every row to eight columns so the destructuring below never hits a
// missing key for the supervisors that leave the per-batch ceilings unset.
$supervisorData = array_map(
    fn (array $row) => array_pad($row, 8, null),
    $supervisorData
);

foreach ($supervisorData as [$title, $name, $staffNo, $capacity, $areas, $psm1Cap, $psm2Cap, $programme]) {
            /**
             * Keyed on `staff_id`, not the generated email.
             *
             * The demo supervisor below is renamed to `supervisor@psm.test`, so
             * looking this row up by its generated address missed on every
             * re-run and fell through to an insert — which then collided with
             * the existing `staff_id` and failed the whole seed. `staff_id` is
             * the value nothing rewrites, so it identifies the person reliably.
             *
             * The email is still set on create; the rename below owns it
             * afterwards.
             */
            $user = User::updateOrCreate(
                ['staff_id' => $staffNo],
                [
                    'email'      => strtolower(str_replace([' ', '.'], ['.', ''], $staffNo)).'@psm.test',
                    'name'       => $name,
                    'password'   => Hash::make('password'),
                    'role'       => Role::Supervisor,
                    'status'     => 'active',
                    'department' => 'Faculty of Computing',
                ]
            );

            $profile = SupervisorProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'staff_no'         => $staffNo,
                    'academic_title'   => $title,
                    'max_supervisees'  => $capacity,
                    // Left null where unspecified, so `capacityForPart` falls
                    // back to the configured default rather than pinning every
                    // supervisor to a literal 5.
                    'max_supervisees_psm1' => $psm1Cap,
                    'max_supervisees_psm2' => $psm2Cap,
                    'is_accepting_students' => true,
                    'programme'        => $programme,
                    'office_location'  => 'Block C, Level 3',
                    'bio'              => "{$title} {$name} supervises PSM projects in ".implode(', ', $areas).'.',
                ]
            );

            $profile->expertiseAreas()->sync(
                ExpertiseArea::whereIn('name', $areas)->pluck('id')
                    ->mapWithKeys(fn ($id, $i) => [$id => ['proficiency' => 5 - $i]])
                    ->all()
            );

            $supervisors[] = $profile;
        }

        // The named demo supervisor, guaranteed to be the first one
        $demoSupervisor = $supervisors[0];
        User::where('id', $demoSupervisor->user_id)->update(['email' => 'supervisor@psm.test']);

        $this->command->line('  Supervisors: '.count($supervisors));

        // -----------------------------------------------------------------
        // Academic staff, second cohort
        // -----------------------------------------------------------------
        // These four used to be seeded as a separate `examiner` role. There is
        // no such role any more: a panel is drawn from the same people who
        // supervise, so they are ordinary supervisors with capacity and
        // expertise, and a coordinator may seat any of them on a panel.
        $staffData = [
            ['Dr.', 'Zulkifli bin Omar',   'EXM-001', ['Artificial Intelligence', 'Software Engineering'], 'BIP'],
            ['Dr.', 'Chong Mei Ling',      'EXM-002', ['Cybersecurity', 'Computer Networks'], 'BIS'],
            ['Prof.', 'Azlina binti Rahim','EXM-003', ['Database Systems', 'Big Data Analytics'], 'BIT'],
            ['Dr.', 'Kavitha Subramaniam', 'EXM-004', ['Human-Computer Interaction', 'Mobile Computing'], 'BIM'],
        ];

        foreach ($staffData as [$title, $name, $staffNo, $areas, $programme]) {
            // Keyed on `staff_id` for the same reason as the block above: the
            // first of these is renamed to `examiner@psm.test` below, so its
            // generated address is not a stable lookup key.
            $user = User::updateOrCreate(
                ['staff_id' => $staffNo],
                [
                    'email'      => strtolower($staffNo).'@psm.test',
                    'name'       => $name,
                    'password'   => Hash::make('password'),
                    'role'       => Role::Supervisor,
                    'status'     => 'active',
                    'department' => 'Faculty of Computing',
                ]
            );

            $profile = SupervisorProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'staff_no'              => $staffNo,
                    'academic_title'        => $title,
                    'max_supervisees'       => (int) config('psm.supervisor_max_capacity', 8),
                    'max_supervisees_psm1'  => (int) config('psm.supervisor_capacity.PSM1', 5),
                    'max_supervisees_psm2'  => (int) config('psm.supervisor_capacity.PSM2', 5),
                    'is_accepting_students' => true,
                    'programme'             => $programme,
                ]
            );

            $profile->expertiseAreas()->sync(
                ExpertiseArea::whereIn('name', $areas)->pluck('id')
                    ->mapWithKeys(fn ($id, $i) => [$id => ['proficiency' => 5 - $i]])
                    ->all()
            );
        }

        // The email is kept as a stable demo login; the account is a supervisor.
        User::where('email', 'exm-001@psm.test')->update(['email' => 'examiner@psm.test']);

        $this->command->line('  Academic staff (second cohort): '.count($staffData));

        // -----------------------------------------------------------------
        // Students — a full cohort
        // -----------------------------------------------------------------
        /**
         * Student numbers, and the programme each student belongs to.
         *
         * Codes are `AI` + intake year + a running number, which is the
         * faculty's own form (`AI240001`). They were `S23001…`, an internal
         * sequence that carried no intake and did not match anything a student
         * would recognise on their own records.
         *
         * The programme is one of the five `Programme` cases, and the *code* is
         * what matters: a supervisor may only take students from their own
         * programme, so this value decides who can be paired with whom. It is
         * assigned round-robin below so every programme has students, and so the
         * cross-programme rule has something to actually enforce.
         */
        $programmeCases = Programme::cases();

        $malayNames = [
            'Aisyah binti Rahman', 'Muhammad Danial bin Aziz', 'Nurul Izzah binti Kamal',
            'Tan Wei Jie', 'Lim Pei Shan', 'Arvind Krishnan',
            'Siti Zulaikha binti Mohd', 'Hafiz bin Ismail', 'Chong Jia Hui',
            'Nurfarahin binti Salleh', 'Wong Kai Sheng', 'Ahmad Zaki bin Roslan',
            'Priya Devi', 'Lee Ming Hui', 'Fatin Nabila binti Omar',
            'Muhammad Haziq bin Latif', 'Goh Xin Yi', 'Nadia binti Hamzah',
            'Rajiv Menon', 'Syafiqah binti Zahari', 'Teoh Boon Keat',
            'Amirah binti Nasir', 'Kevin Ng Wei Lun', 'Dayang Nurfarahin',
        ];

        $topics = [
            ['AI-Powered Student Performance Prediction System',      'system'],
            ['Automated Attendance Tracking Using Face Recognition',  'system'],
            ['Phishing Detection Using Machine Learning',             'research'],
            ['Smart Campus Navigation Mobile Application',            'system'],
            ['Blockchain-Based Academic Certificate Verification',    'system'],
            ['Sentiment Analysis of Student Feedback',                'research'],
            ['IoT-Based Smart Laboratory Monitoring',                 'system'],
            ['Comparative Study of Web Framework Performance',        'research'],
            ['Accessible E-Learning Platform for Visually Impaired',  'system'],
            ['Predicting Dropout Risk from LMS Activity Logs',        'research'],
            ['Campus Lost-and-Found Matching System',                 'system'],
            ['Usability Evaluation of University Portals',            'research'],
            ['Real-Time Queue Management for Student Services',       'system'],
            ['Detecting Plagiarism in Source Code Submissions',       'research'],
            ['Virtual Laboratory Simulation for Networking Courses',  'system'],
            ['Analysis of Network Intrusion Patterns',                'research'],
            ['Mobile Health Reminder for Chronic Patients',           'system'],
            ['Energy-Efficient Routing in Wireless Sensor Networks',  'research'],
            ['Digital Scholarship Management System',                 'system'],
            ['Effect of Gamification on Student Engagement',          'research'],
            ['Library Book Recommendation Engine',                    'system'],
            ['Secure File Sharing for Group Projects',                'system'],
            ['Speech-to-Text Note Taking for Lectures',               'system'],
            ['Study on Adoption of Cloud Services in Universities',   'research'],
        ];

        $students = [];
        $studentUsers = [];

        foreach ($malayNames as $index => $name) {
            // AI + intake year + a six-digit running number: AI240001, AI240002…
            $studentId = sprintf('AI24%04d', 1 + $index);
            $programme = $programmeCases[$index % count($programmeCases)];
            $topic     = $topics[$index];

            $email = $index === 0
                ? 'student@psm.test'
                : strtolower(str_replace([' ', "'"], ['.', ''], $name)).'@student.psm.test';

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => Hash::make('password'),
                    'role'     => Role::Student,
                    'status'   => 'active',
                ]
            );

            $profile = StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'student_id'     => $studentId,
                    // The code is what the supervision rule compares; the label
                    // is what a human reads. Both are set so a row is never
                    // half-described.
                    'program'        => $programme->value,
                    'program_code'   => $programme->value,
                    'batch'          => '2026',
                    'faculty'        => 'Faculty of Computing',
                    'current_semester' => 6,
                    'thesis_title'   => $topic[0],
                    'thesis_abstract'=> "This project investigates {$topic[0]}. The work will follow the "
                                       ."standard PSM lifecycle from proposal through to final evaluation.",
                ]
            );

            $students[]     = $profile;
            $studentUsers[] = $user;
        }

        $this->command->line('  Students: '.count($students));

        // -----------------------------------------------------------------
        // Module 2 — Supervision pairings
        // -----------------------------------------------------------------
        /**
         * Each student gets exactly one primary supervisor, chosen from their
         * **own programme**.
         *
         * This used to be a flat round-robin over all eight supervisors, which
         * was fine while nobody had a programme recorded. It is not fine now:
         * FSKTM pairs staff with students from their own programme only, so a
         * positional pairing would create a cohort where most assignments
         * violate the rule the same seeder just enabled — demo data the
         * application could not have produced, and a supervisor dashboard full
         * of pairings that `AssignmentService` would refuse to recreate.
         *
         * Supervisors are therefore grouped by programme and rotated within it.
         * Two are left unassigned on purpose, so the coordinator's queue is not
         * empty in the demo.
         */
        $supervisorsByProgramme = collect($supervisors)
            ->filter(fn (SupervisorProfile $s) => $s->programme !== null)
            ->groupBy(fn (SupervisorProfile $s) => $s->programme->value);

        $paired = 0;
        $unplaceable = [];
        $rotations = [];

        foreach ($students as $index => $student) {
            // Leave the last two students unassigned so the coordinator's
            // "unassigned students" queue is not empty in the demo.
            if ($index >= count($students) - 2) {
                continue;
            }

            $programme = Programme::tryParse($student->program_code);

            if ($programme === null) {
                $unplaceable[] = $student->student_id;

                continue;
            }

            $pool = $supervisorsByProgramme->get($programme->value) ?? collect();

            if ($pool->isEmpty()) {
                // No supervisor owns this programme, so nobody may take the
                // student. Reported rather than paired anyway: a cross-programme
                // pairing here would be a pairing the rule forbids.
                $unplaceable[] = $student->student_id.' ('.$programme->value.')';

                continue;
            }

            $turn = $rotations[$programme->value] ?? 0;
            $rotations[$programme->value] = $turn + 1;

            $supervisor = $pool[$turn % $pool->count()];

            SupervisionAssignment::updateOrCreate(
                [
                    'student_profile_id'    => $student->id,
                    'supervisor_profile_id' => $supervisor->id,
                    'psm_part'              => 'BOTH',
                ],
                [
                    'role'                   => 'primary',
                    'responsibility_percent' => 100,
                    'is_active'              => true,
                    'assigned_by'            => $coordinator->id,
                    'assignment_note'        => 'Paired by the coordinator based on topic overlap.',
                    'effective_from'         => now()->subMonths(3)->toDateString(),
                ]
            );

            $paired++;
        }

        $this->command->line("  Supervision pairings: {$paired} (".(count($students) - $paired).' unassigned)');

        if ($unplaceable !== []) {
            $this->command->warn(
                '  No supervisor in the student\'s own programme for: '.implode(', ', $unplaceable)
            );
        }
    }
}
