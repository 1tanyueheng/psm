<?php

/**
 * After the re-seed: does a lecturer see their own course's section, and does
 * one section per teacher per COURSE hold?
 */

use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$term = App\Models\AcademicSemester::current()
    ?? App\Models\AcademicSemester::query()->orderByDesc('id')->first();

echo "term: {$term->name}\n\n";

echo "sections:\n";
foreach (Section::query()->forSemester($term->id)
    ->with(['lecturer:id,name']) 
    ->orderBy('course_code')->orderBy('code')->get() as $s) {
    $marks = LecturerMark::query()->where('section_id', $s->id)->count();
    printf("  %-9s %-4s %-34s %-30s marks=%d\n",
        $s->course_code, $s->code, $s->name,
        $s->lecturer?->name ?? '(none)', $marks);
}

echo "\nsections per teacher this term (should be <= 1 per course):\n";
foreach (Section::query()->forSemester($term->id)
    ->select('lecturer_id', 'course_code', DB::raw('count(*) as n'))
    ->whereNotNull('lecturer_id')
    ->groupBy('lecturer_id', 'course_code')
    ->get() as $row) {
    $name = User::find($row->lecturer_id)?->name ?? '?';
    printf("  %-30s %-9s %d\n", $name, $row->course_code, $row->n);
}

echo "\nwhat each lecturer sees in lecturerSection():\n";
foreach (User::query()->whereIn('role', ['lecturer', 'supervisor'])
    ->whereHas('sectionsLectured')->get() as $u) {
    $s = $u->lecturerSection($term->id);
    printf("  %-30s %-14s -> %s\n",
        $u->name, $u->email,
        $s ? $s->course_code.' '.$s->code : '(none)');
}

echo "\nmarks keyed under the new criteria: ".LecturerMark::query()
    ->whereNotNull('affective')->count()." row(s)\n";
echo "a sample row:\n";
$m = LecturerMark::query()->whereNotNull('affective')->with('studentProfile')->first();
if ($m) {
    printf("  student %s: affective=%s cognitive=%s psychomotor=%s total=%s percent=%s\n",
        $m->studentProfile?->student_id, $m->affective, $m->cognitive, $m->psychomotor,
        $m->total(), $m->percent());
}
