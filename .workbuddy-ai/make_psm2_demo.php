<?php
// Create a PSM 2 demo student whose boundary decision is still open, so the
// title prompt can actually be seen. Idempotent: re-running resets the decision.
use App\Models\AcademicSemester;
use App\Models\LampiranBForm;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\StudentEnrolment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$term = AcademicSemester::current() ?? AcademicSemester::orderByDesc('id')->first();

$email = 'psm2.demo@psm.test';

$user = User::firstOrCreate(
    ['email' => $email],
    [
        'name'     => 'Nurul Aisyah binti Kamal',
        'password' => Hash::make('password'),
        'role'     => 'student',
        'status'   => 'active',
    ]
);

$student = StudentProfile::firstOrCreate(
    ['user_id' => $user->id],
    [
        'student_id'           => 'AI240901',
        'program'              => 'Bachelor of Computer Science (Software Engineering) With Honours',
        'program_code'         => 'BIP',
        'batch'                => '2024',
        'faculty'              => 'FSKTM',
        'academic_semester_id' => $term?->id,
    ]
);

// PSM 2 project, title inherited from PSM 1.
$project = Project::firstOrCreate(
    ['code' => 'PSM2-2025-BIP-901'],
    [
        'title'                => 'Attendance tracking using face recognition',
        'psm_part'             => 'PSM2',
        'category'             => 'system',
        'status'               => 'in_progress',
        'academic_semester_id' => $term?->id,
        'academic_session'     => $term?->academic_session,
        'batch'                => '2024',
        'program'              => $student->program,
        'created_by'           => $user->id,
    ]
);

// Lead the project.
\App\Models\ProjectMember::firstOrCreate(
    ['project_id' => $project->id, 'student_profile_id' => $student->id],
    ['role' => 'leader', 'is_leader' => true]
);

// A live PSM 2 enrolment, so the capacity gate counts them.
StudentEnrolment::firstOrCreate(
    [
        'student_profile_id'   => $student->id,
        'academic_semester_id' => $term?->id,
        'psm_part'             => 'PSM2',
    ],
    [
        'attempt_no'  => 1,
        'status'      => 'active',
        'enrolled_at' => now(),
        'enrolled_by' => $user->id,
    ]
);

// Chapter 5 pending — the boundary is open.
$chain = ['chapter_5', 'chapter_6', 'chapter_7', 'final_report', 'form_k'];
foreach ($chain as $i => $code) {
    Milestone::firstOrCreate(
        ['project_id' => $project->id, 'code' => $code, 'attempt' => 1],
        [
            'title'                  => ucwords(str_replace('_', ' ', $code)),
            'sequence'               => $i + 1,
            'status'                 => $code === 'chapter_5' ? 'pending' : 'pending',
            'allow_late_submission'  => true,
            'allowed_file_types'     => ['pdf', 'doc', 'docx'],
            'max_files'              => 3,
        ]
    );
}

// An accepted Lampiran B for the inherited title.
LampiranBForm::where('project_id', $project->id)
    ->where('revision_no', '>', 1)
    ->delete();

LampiranBForm::firstOrCreate(
    ['project_id' => $project->id, 'revision_no' => 1],
    [
        'title'         => $project->title,
        'project_type'  => 'system',
        'field_study'   => 'Software Engineering',
        'status'        => LampiranBForm::STATUS_ACCEPTED,
    ]
);

// Reset the boundary decision so the prompt shows.
$project->forceFill([
    'metadata' => array_merge($project->metadata ?? [], [
        'title_decision' => null,
        'title_decided_at' => null,
        'title_decided_by' => null,
    ]),
])->save();
$meta = $project->metadata;
unset($meta['title_decision'], $meta['title_decided_at'], $meta['title_decided_by'], $meta['title_changed_at_psm2']);
$project->forceFill(['metadata' => $meta])->save();

echo "account : {$email} / password\n";
echo "student : {$student->student_id} (batch {$student->batch})\n";
echo "project : {$project->code} — {$project->title}\n";
echo "term    : ".($term?->name ?? 'none')."\n";
echo "chain   : stage pending, so a title change is still allowed\n";
echo "decision: cleared, so the prompt will show on first visit\n";
