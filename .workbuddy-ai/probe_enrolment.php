<?php
// Read-only probe: what does the capacity gate actually count?
$current = App\Models\AcademicSemester::current();
echo "active term: ".($current?->name ?? 'NONE')." (id=".($current?->id ?? 'null').")\n\n";

$students = App\Models\StudentProfile::count();
$enrolled = App\Models\StudentEnrolment::count();
$live = App\Models\StudentEnrolment::whereIn('status', ['enrolling', 'active'])->count();
$withTerm = App\Models\StudentProfile::whereNotNull('academic_semester_id')->count();

echo "student profiles:        {$students}\n";
echo "with academic_semester_id: {$withTerm}\n";
echo "student_enrolments rows:   {$enrolled}\n";
echo "live enrolments:           {$live}\n\n";

// A supervisor who already carries students this term
$sup = App\Models\SupervisorProfile::first();
if ($sup) {
    $load = $sup->currentLoadByPartInSemester($current?->id);
    echo "supervisor: ".$sup->label()."\n";
    echo "  gate load this term: ".json_encode($load)."\n";
    echo "  active supervisions: ".$sup->activeSupervisions()->count()."\n";
}

// Which of those supervisions belong to students who have an enrolment?
$noEnrolment = App\Models\SupervisionAssignment::where('is_active', true)
    ->whereDoesntHave('studentProfile.enrolments')->count();
echo "\nactive supervisions whose student has NO enrolment at all: {$noEnrolment}\n";
