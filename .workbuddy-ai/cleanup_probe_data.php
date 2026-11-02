<?php

/**
 * Undo the two things the HTTP probes created, so the demo data is back to the
 * seeded state:
 *
 *   * section 22 (BIT34002 S3) was created to test the code-uniqueness refusal
 *     and then appointed to lec-001 to test the per-course teacher rule;
 *   * student AI240005 was moved from BIT S1 to BIT S2 to test the placement
 *     gate.
 *
 * Run for real (no transaction) because these are probes' leftovers, not
 * changes anyone wants — the guard is that each step is idempotent and reported.
 */

use App\Models\Section;
use App\Models\StudentProfile;

$created = Section::query()
    ->where('course_code', Section::COURSE_BIT)
    ->where('code', 'S3')
    ->doesntHave('marks')
    ->get();

foreach ($created as $section) {
    $students = StudentProfile::query()->where('section_id', $section->id)->count();

    if ($students > 0) {
        echo "  kept {$section->course_code} {$section->code}: {$students} student(s) still placed in it\n";
        continue;
    }

    echo "  deleting {$section->course_code} {$section->code} (id {$section->id})\n";
    $section->delete();
}

$student = StudentProfile::query()->where('student_id', 'AI240005')->first();
$bitS1 = Section::query()
    ->where('course_code', Section::COURSE_BIT)
    ->where('code', 'S1')
    ->where('academic_semester_id', $student?->section?->academic_semester_id)
    ->first();

if ($student !== null && $bitS1 !== null && $student->section_id !== $bitS1->id) {
    echo "  moving {$student->student_id} back to BIT34002 {$bitS1->code}\n";
    $student->forceFill(['section_id' => $bitS1->id])->save();
}

echo "\ncleanup done\n";
echo "sections now:\n";
foreach (Section::query()->with('academicSemester')->orderBy('academic_semester_id')
    ->orderBy('course_code')->orderBy('code')->get() as $s) {
    printf("  term=%-3s %-9s %-4s %-8s lecturer=%s\n",
        $s->academic_semester_id, $s->course_code, $s->code,
        $s->academicSemester?->name ?? '?',
        $s->lecturer_id ?? '-');
}
