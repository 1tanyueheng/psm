<?php

/**
 * Read-only probe: does the live schema match what this session's code expects?
 *
 * Runs through tinker inside the container. Nothing is written.
 */

use Illuminate\Support\Facades\DB;

echo "sections columns:\n";
foreach (DB::table('information_schema.columns')
    ->where('table_name', 'sections')
    ->orderBy('ordinal_position')
    ->get(['column_name', 'data_type', 'is_nullable', 'column_default']) as $c) {
    printf("  %-22s %-18s null=%-3s default=%s\n",
        $c->column_name, $c->data_type, $c->is_nullable, $c->column_default ?? '-');
}

echo "\nsections indexes:\n";
foreach (DB::select("select indexname, indexdef from pg_indexes where tablename = 'sections'") as $i) {
    echo "  {$i->indexname}\n";
    echo "     {$i->indexdef}\n";
}

echo "\nlecturer_marks columns:\n";
foreach (DB::table('information_schema.columns')
    ->where('table_name', 'lecturer_marks')
    ->orderBy('ordinal_position')
    ->get(['column_name', 'data_type', 'is_nullable']) as $c) {
    printf("  %-22s %-18s null=%s\n", $c->column_name, $c->data_type, $c->is_nullable);
}

echo "\nconfig coursework components:\n";
foreach (config('psm.coursework.components') as $key => $spec) {
    printf("  %-12s %-26s max=%s\n", $key, $spec['label'], $spec['max']);
}

echo "\nLecturerMark::components() keys: "
    .implode(', ', array_keys(App\Models\LecturerMark::components()))."\n";
echo 'LecturerMark::maxTotal(): '.App\Models\LecturerMark::maxTotal()."\n";

echo "\nSection::courses():\n";
foreach (App\Models\Section::courses() as $code => $c) {
    printf("  %s -> %s (%s)\n", $code, $c['title'], implode('/', $c['programmes']));
}
foreach (['BIP', 'BIS', 'BIM', 'BIW', 'BIT', 'XYZ', null] as $p) {
    echo '  courseForProgramme('.var_export($p, true).') = '
        .App\Models\Section::courseForProgramme($p)."\n";
}

echo "\nsections by course (live rows):\n";
foreach (DB::table('sections')
    ->orderBy('academic_semester_id')->orderBy('course_code')->orderBy('code')
    ->get(['id', 'academic_semester_id', 'course_code', 'code', 'name', 'lecturer_id']) as $s) {
    printf("  id=%-4s term=%-3s %-9s %-4s %-10s lecturer=%s\n",
        $s->id, $s->academic_semester_id, $s->course_code, $s->code, $s->name,
        $s->lecturer_id ?? '-');
}

echo "\nlecturer_marks rows: ".DB::table('lecturer_marks')->count()."\n";
$new = DB::table('lecturer_marks')->whereNotNull('affective')
    ->orWhereNotNull('cognitive')->orWhereNotNull('psychomotor')->count();
echo "  rows with any new criterion keyed: {$new}\n";
$old = DB::table('lecturer_marks')->whereNotNull('quiz')
    ->orWhereNotNull('assignment_c')->count();
echo "  rows with the old columns set (kept, unread): {$old}\n";
