<?php
// Does a student who registers through the normal flow ever get an enrolment?
// Everything happens inside a transaction that is rolled back.
use App\Models\AcademicSemester;
use App\Models\StudentEnrolment;
use App\Models\StudentProfile;

DB::beginTransaction();

$term = AcademicSemester::current();

// A brand-new student profile, as `POST /api/users` would create one.
$user = App\Models\User::create([
    'name' => 'Probe Student', 'email' => 'probe.'.uniqid().'@psm.test',
    'password' => bcrypt('x'), 'role' => 'student', 'status' => 'active',
]);
$student = StudentProfile::create([
    'user_id' => $user->id, 'student_id' => 'AI'.random_int(900000, 999999),
    'program' => 'Software Engineering', 'program_code' => 'BIP',
    'batch' => '2026', 'academic_semester_id' => $term?->id,
]);

echo "after creating the account:\n";
echo "  profile.academic_semester_id = ".($student->academic_semester_id ?? 'null')."\n";
echo "  enrolment rows               = ".StudentEnrolment::where('student_profile_id', $student->id)->count()."\n";

// A supervisor, and the Lampiran A agreement that names them. Pick one with
// room in PSM 1 this term, so the capacity gate is not what we are testing.
$sup = App\Models\SupervisorProfile::all()->first(function ($s) use ($term) {
    return $s->hasCapacityForInSemester('PSM1', $term?->id)
        && $s->canSuperviseProgramme('BIP');
});
echo "using supervisor: ".$sup->label()."\n\n";
$agreement = App\Models\SupervisorAgreement::create([
    'student_profile_id'    => $student->id,
    'supervisor_profile_id' => $sup->id,
    'session'               => $term?->academic_session,
    'academic_semester_id'  => $term?->id,
    'psm_part'              => 'PSM1',
    'proposed_title_1'      => 'Probe title for the enrolment question',
    'agreed_title'          => 'Probe title for the enrolment question',
    'status'                => App\Models\SupervisorAgreement::STATUS_APPROVED,
    'student_signed_at'     => now(),
]);

// Lampiran B: this is the call that creates the project.
$project = app(App\Services\RegistrationService::class)->submitTitleProposal($agreement, [
    'project_title' => 'Probe title for the enrolment question',
    'project_type'  => 'Pembangunan',
], $user);

echo "\nafter Lampiran B creates the project:\n";
echo "  project #{$project->id} ({$project->code}), term ".($project->academic_semester_id ?? 'NULL')."\n";
echo "  enrolment rows = ".StudentEnrolment::where('student_profile_id', $student->id)->count()."\n";

// What the capacity gate counts for the supervisor this student joins.
$before = $sup->fresh()->currentLoadByPartInSemester($term?->id);
echo "\nsupervisor term load BEFORE any pairing: ".json_encode($before)."\n";

app(App\Services\AssignmentService::class)->assignSupervisor(
    $student, $sup, $user, 'PSM1', 'primary', null, 'probe'
);$after = $sup->fresh()->currentLoadByPartInSemester($term?->id);
echo "supervisor term load AFTER the pairing:  ".json_encode($after)."\n";

echo "\nVERDICT: a registered student ";
echo StudentEnrolment::where('student_profile_id', $student->id)->exists()
    ? "HAS an enrolment.\n" : "has NO enrolment - the gate counts nothing for them.\n";

DB::rollBack();
echo "(rolled back)\n";
