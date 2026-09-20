<?php

namespace Database\Seeders;

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
        $supervisorData = [
            ['Dr.',  'Ahmad Faizal bin Hassan',  'SUP-001',  8,  ['Artificial Intelligence', 'Machine Learning', 'Data Mining']],
            ['Dr.',  'Tan Wei Ming',             'SUP-002',  6,  ['Cybersecurity', 'Cryptography', 'Digital Forensics']],
            ['Prof.', 'Siti Nurhaliza binti Ali','SUP-003', 10, ['Software Engineering', 'Web Development', 'Cloud Computing']],
            ['Dr.',  'Rajesh Kumar',             'SUP-004',  8,  ['Internet of Things', 'Computer Networks', 'Cloud Computing']],
            ['Ts.',  'Lim Chee Keong',           'SUP-005',  6,  ['Human-Computer Interaction', 'User Experience Design', 'Mobile Computing']],
            ['Dr.',  'Nurul Aini binti Yusof',   'SUP-006',  8,  ['Database Systems', 'Big Data Analytics']],
            ['Dr.',  'Wong Hui Ling',            'SUP-007',  6,  ['Computer Vision', 'Natural Language Processing', 'Machine Learning']],
            ['Dr.',  'Muhammad Arif bin Zainal', 'SUP-008',  6,  ['Educational Technology', 'Healthcare Informatics']],
        ];

        $supervisors = [];

        foreach ($supervisorData as [$title, $name, $staffNo, $capacity, $areas]) {
            $user = User::updateOrCreate(
                ['email' => strtolower(str_replace([' ', '.'], ['.', ''], $staffNo)).'@psm.test'],
                [
                    'name'       => $name,
                    'password'   => Hash::make('password'),
                    'role'       => Role::Supervisor,
                    'status'     => 'active',
                    'staff_id'   => $staffNo,
                    'department' => 'Faculty of Computing',
                ]
            );

            $profile = SupervisorProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'staff_no'         => $staffNo,
                    'academic_title'   => $title,
                    'max_supervisees'  => $capacity,
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
        // Examiners
        // -----------------------------------------------------------------
        $examinerData = [
            ['Dr.', 'Zulkifli bin Omar',   'EXM-001', ['Artificial Intelligence', 'Software Engineering']],
            ['Dr.', 'Chong Mei Ling',      'EXM-002', ['Cybersecurity', 'Computer Networks']],
            ['Prof.', 'Azlina binti Rahim','EXM-003', ['Database Systems', 'Big Data Analytics']],
            ['Dr.', 'Kavitha Subramaniam', 'EXM-004', ['Human-Computer Interaction', 'Mobile Computing']],
        ];

        foreach ($examinerData as [$title, $name, $staffNo, $areas]) {
            $user = User::updateOrCreate(
                ['email' => strtolower($staffNo).'@psm.test'],
                [
                    'name'       => $name,
                    'password'   => Hash::make('password'),
                    'role'       => Role::Examiner,
                    'status'     => 'active',
                    'staff_id'   => $staffNo,
                    'department' => 'Faculty of Computing',
                ]
            );

            // Examiners need a supervisor profile for staff_no and expertise
            $profile = SupervisorProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'staff_no'              => $staffNo,
                    'academic_title'        => $title,
                    'max_supervisees'       => 0,      // examiners do not supervise
                    'is_accepting_students' => false,
                    'can_examine'           => true,
                ]
            );

            $profile->expertiseAreas()->sync(
                ExpertiseArea::whereIn('name', $areas)->pluck('id')
                    ->mapWithKeys(fn ($id, $i) => [$id => ['proficiency' => 5 - $i]])
                    ->all()
            );
        }

        User::where('email', 'exm-001@psm.test')->update(['email' => 'examiner@psm.test']);

        $this->command->line('  Examiners: '.count($examinerData));

        // -----------------------------------------------------------------
        // Students — a full cohort
        // -----------------------------------------------------------------
        $programs = [
            ['Bachelor of Computer Science',            'CS230'],
            ['Bachelor of Information Technology',      'IT240'],
            ['Bachelor of Software Engineering',        'SE250'],
        ];

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
            $studentId = sprintf('S%05d', 23001 + $index);
            $program   = $programs[$index % count($programs)];
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
                    'program'        => $program[0],
                    'program_code'   => $program[1],
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
        // Distributed across supervisors so the workload report shows a
        // realistic spread, including one supervisor near capacity.
        $paired = 0;

        foreach ($students as $index => $student) {
            // Leave the last two students unassigned so the coordinator's
            // "unassigned students" queue is not empty in the demo.
            if ($index >= count($students) - 2) {
                continue;
            }

            $supervisor = $supervisors[$index % count($supervisors)];

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

            // Give every third student a co-supervisor for realism
            if ($index % 3 === 0) {
                $co = $supervisors[($index + 3) % count($supervisors)];

                if ($co->id !== $supervisor->id && $co->hasCapacity()) {
                    SupervisionAssignment::updateOrCreate(
                        [
                            'student_profile_id'    => $student->id,
                            'supervisor_profile_id' => $co->id,
                            'psm_part'              => 'BOTH',
                        ],
                        [
                            'role'                   => 'co',
                            'responsibility_percent' => 30,
                            'is_active'              => true,
                            'assigned_by'            => $coordinator->id,
                            'assignment_note'        => 'Co-supervisor for the technical component.',
                            'effective_from'         => now()->subMonths(3)->toDateString(),
                        ]
                    );
                }
            }
        }

        $this->command->line("  Supervision pairings: {$paired} (".(count($students) - $paired).' unassigned)');
    }
}
