<?php

/**
 * Behavioural probe for the 2026-11-02 coursework change.
 *
 * Everything runs inside a transaction that is rolled back, so the demo data is
 * untouched. The rules exercised:
 *
 *   1. a term can hold a BIT31602-BIC S1 and a BIT34002 S1 at once;
 *   2. the same code twice in the SAME course is refused;
 *   3. one teacher may hold one section of each course, not two of one;
 *   4. a BIT student may not be placed in the BIC section;
 *   5. marks save and total under the three new criteria;
 *   6. an unkeyed criterion leaves the total null rather than zero.
 */

use App\Models\AcademicSemester;
use App\Models\LecturerMark;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

$fail = 0;
$pass = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}".($detail !== '' ? "  -- {$detail}" : '')."\n";
    }
}

$term = AcademicSemester::query()->orderByDesc('id')->first();
echo "term: {$term->name} (id {$term->id})\n\n";

// --- 1. a BIT section can sit alongside the BIC ones -------------------------
echo "1. a BIT34002 S1 exists alongside the BIC S1\n";
$bit = Section::create([
    'academic_semester_id' => $term->id,
    'course_code'          => Section::COURSE_BIT,
    'code'                 => 'S1',
    'name'                 => 'Section 1',
    'lecturer_id'          => null,
]);
check('BIT S1 created in a term that already has a BIC S1', $bit->exists && $bit->id > 0);
check(
    'both rows can be found by code S1',
    Section::query()->forSemester($term->id)->where('code', 'S1')->count() >= 2,
    'count='.Section::query()->forSemester($term->id)->where('code', 'S1')->count()
);

// --- 2. the same code twice in one course is refused by the index -----------
echo "\n2. the same code twice in the same course is refused\n";
$duplicateRefused = false;
try {
    DB::transaction(fn () => Section::create([
        'academic_semester_id' => $term->id,
        'course_code'          => Section::COURSE_BIT,
        'code'                 => 'S1',
        'name'                 => 'Duplicate',
    ]));
} catch (\Throwable $e) {
    $duplicateRefused = true;
    echo '     ('.$e->getCode().' from the unique index)'."\n";
} finally {
    // The failed save may have poisoned the transaction; the caller's rollback
    // still runs, so nothing is committed either way.
}
check('a second BIT S1 in the same term is refused', $duplicateRefused);

// --- 3. one section per teacher per COURSE, not per term --------------------
echo "\n3. a teacher may hold one section of each course\n";
$teacher = User::query()->whereIn('role', ['lecturer', 'supervisor'])
    ->whereHas('sectionsLectured', fn ($q) => $q->where('academic_semester_id', $term->id))
    ->first();

if ($teacher === null) {
    echo "  SKIP  no teacher with a section this term\n";
} else {
    $bicSection = Section::query()->forSemester($term->id)
        ->where('course_code', Section::COURSE_BIC)->where('lecturer_id', $teacher->id)->first();
    echo "     teacher {$teacher->name} holds BIC " . ($bicSection->code ?? '-') . "\n";

    $bit->forceFill(['lecturer_id' => $teacher->id])->save();
    $bit->refresh();

    check(
        'the same teacher now holds a BIC and a BIT section in one term',
        $bit->lecturer_id === $teacher->id,
        "lecturer_id={$bit->lecturer_id}"
    );

    // The controller's rule, reproduced: same course, same term, another row.
    $rival = App\Models\Section::query()
        ->where('lecturer_id', $teacher->id)
        ->where('academic_semester_id', $term->id)
        ->where('course_code', Section::COURSE_BIT)
        ->whereKeyNot($bit->id)
        ->exists();
    check('no second BIT section for the same teacher', ! $rival);
}

// --- 4. a BIT student may not be placed in a BIC section --------------------
echo "\n4. placement follows the student's programme\n";
$bitStudent = StudentProfile::query()->where('program_code', 'BIT')->first()
    ?? StudentProfile::query()->first();

if ($bitStudent === null) {
    echo "  SKIP  no student profiles\n";
} else {
    echo "     student {$bitStudent->student_id} is programme {$bitStudent->program_code}\n";

    $bicSection = Section::query()->forSemester($term->id)
        ->where('course_code', Section::COURSE_BIC)->first();

    check(
        'courseForProgramme(BIT) is BIT34002',
        Section::courseForProgramme('BIT') === Section::COURSE_BIT
    );
    check(
        'the BIC section is therefore NOT the BIT student\'s course',
        $bicSection->course_code !== Section::courseForProgramme($bitStudent->program_code)
    );
    check(
        'the BIT section IS the BIT student\'s course',
        $bit->course_code === Section::courseForProgramme($bitStudent->program_code)
    );
}

// --- 4b. every live student resolves to a course ---------------------------
echo "\n4b. every live student resolves to a course\n";
$unresolved = 0;
$byCourse = [];
foreach (StudentProfile::query()->pluck('program_code') as $programCode) {
    $course = Section::courseForProgramme($programCode);
    $byCourse[$course] = ($byCourse[$course] ?? 0) + 1;
    if ($programCode === null) {
        $unresolved++;
    }
}
foreach ($byCourse as $course => $n) {
    echo "     {$course}: {$n} student(s)\n";
}
check('every student resolves (null falls back to BIC by design)', $unresolved === 0, "{$unresolved} null");

// --- 5 & 6. the three criteria, and an unkeyed one is not a zero -----------
echo "\n5. marks save under the three new criteria\n";
$mark = new LecturerMark([
    'affective'   => 6.5,
    'cognitive'   => 12.0,
    'psychomotor' => 5.0,
]);

check('maxTotal is 30', LecturerMark::maxTotal() === 30.0, 'got '.LecturerMark::maxTotal());
check('total = 23.5 when all three are keyed', $mark->total() === 23.5, 'got '.var_export($mark->total(), true));
check('percent = 78.33', $mark->percent() === 78.33, 'got '.var_export($mark->percent(), true));
check('isComplete is true', $mark->isComplete());

echo "\n6. an unkeyed criterion leaves no total at all\n";
$partial = new LecturerMark(['affective' => 6.5, 'cognitive' => 12.0]);
check('total is null, not 18.5', $partial->total() === null, 'got '.var_export($partial->total(), true));
check('percent is null', $partial->percent() === null);
check('isComplete is false', $partial->isComplete() === false);

$zero = new LecturerMark(['affective' => 0.0, 'cognitive' => 12.0, 'psychomotor' => 0.0]);
check('a keyed ZERO is different from a blank', $zero->total() === 12.0, 'got '.var_export($zero->total(), true));

echo "\n7. the breakdown lists the three criteria and their maxima\n";
foreach ($mark->breakdown() as $row) {
    printf("     %-12s %-26s max=%s\n", $row['key'], $row['label'], $row['max']);
}
check('three breakdown rows', count($mark->breakdown()) === 3);

DB::rollBack();
echo "\nrolled back — nothing written\n";
echo "\n{$pass} passed, {$fail} failed\n";
