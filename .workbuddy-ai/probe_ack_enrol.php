<?php
// Does acknowledgement now create the enrolment, and does the capacity gate
// then count the student? Rolled back at the end.
use App\Models\AcademicSemester;
use App\Models\StudentEnrolment;
use App\Models\StudentProfile;

DB::beginTransaction();

$term = AcademicSemester::current();
$actor = App\Models\User::where('role', 'coordinator')->first();

$user = App\Models\User::create([
    'name' => 'Probe Two', 'email' => 'probe2.'.uniqid().'@psm.test',
    'password' => bcrypt('x'), 'role' => 'student', 'status' => 'active',
]);
$student = StudentProfile::create([
    'user_id' => $user->id, 'student_id' => 'AI'.random_int(900000, 999999),
    'program' => 'Software Engineering', 'program_code' => 'BIP',
    'batch' => '2026', 'academic_semester_id' => $term?->id,
]);

$sup = App\Models\SupervisorProfile::all()->first(function ($s) use ($term) {
    return $s->hasCapacityForInSemester('PSM1', $term?->id) && $s->canSuperviseProgramme('BIP');
});

$agreement = App\Models\SupervisorAgreement::create([
    'student_profile_id'    => $student->id,
    'supervisor_profile_id' => $sup->id,
    'session'               => $term?->academic_session,
    'academic_semester_id'  => $term?->id,
    'psm_part'              => 'PSM1',
    'proposed_title_1'      => 'Enrolment probe title one',
    'proposed_title_2'      => 'Enrolment probe title two',
    'status'                => App\Models\SupervisorAgreement::STATUS_PENDING_SUPERVISOR,
    'student_signed_at'     => now(),
]);

$before = $sup->fresh()->currentLoadByPartInSemester($term?->id)['PSM1'];
echo "before acknowledgement:\n";
echo "  enrolments: ".StudentEnrolment::where('student_profile_id',$student->id)->count()."\n";
echo "  supervisor PSM1 load: {$before}\n";

app(App\Services\RegistrationService::class)->acknowledgeBySupervisor(
    $agreement, $agreement->proposed_title_1, $sup->user
);

$after = $sup->fresh()->currentLoadByPartInSemester($term?->id)['PSM1'];
echo "\nafter acknowledgement:\n";
echo "  enrolments: ".StudentEnrolment::where('student_profile_id',$student->id)->count()."\n";
$e = StudentEnrolment::where('student_profile_id',$student->id)->first();
echo "  part=".$e->psm_part->value." term=".$e->academic_semester_id
    ." status=".$e->status->value." attempt=".$e->attempt_no."\n";
echo "  supervisor PSM1 load: {$after}\n";

echo "\nVERDICT: ";
echo $after === $before + 1
    ? "load went {$before} -> {$after}: the gate now counts the student.\n"
    : "load stayed at {$after}: STILL BROKEN.\n";

DB::rollBack();
echo "(rolled back)\n";
