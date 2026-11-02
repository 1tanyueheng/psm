<?php

/**
 * Why do term 3's sections have no lecturer, and why is there no BIT offering?
 *
 * Read-only. Prints what the seeder would have done and what the term actually
 * looks like, so the answer is the data rather than a guess.
 */

use App\Models\AcademicSemester;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "terms:\n";
foreach (AcademicSemester::query()->orderBy('id')->get() as $t) {
    printf("  id=%-3s %-26s active=%-3s current=%s\n",
        $t->id, $t->name, $t->is_active ? 'yes' : 'no',
        (string) AcademicSemester::current()?->id);
}

echo "\nlecturer accounts (LEC-*):\n";
foreach (User::query()->where('staff_id', 'like', 'LEC-%')->orderBy('staff_id')
    ->get(['id', 'staff_id', 'name', 'email']) as $u) {
    printf("  %-8s id=%-4s %-30s %s\n", $u->staff_id, $u->id, $u->name, $u->email);
}

echo "\nsections per term:\n";
foreach (DB::table('sections')
    ->select('academic_semester_id', DB::raw('count(*) as n'),
             DB::raw('count(lecturer_id) as with_teacher'))
    ->groupBy('academic_semester_id')->orderBy('academic_semester_id')->get() as $r) {
    printf("  term %-3s %d section(s), %d with a teacher\n",
        $r->academic_semester_id, $r->n, $r->with_teacher);
}

echo "\nstudents placed in a section, by term:\n";
foreach (DB::table('student_profiles')
    ->join('sections', 'sections.id', '=', 'student_profiles.section_id')
    ->select('sections.academic_semester_id', DB::raw('count(*) as n'))
    ->groupBy('sections.academic_semester_id')->get() as $r) {
    printf("  term %-3s %d student(s)\n", $r->academic_semester_id, $r->n);
}
