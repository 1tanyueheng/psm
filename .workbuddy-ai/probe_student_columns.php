<?php

/** What is the student's programme column actually called? */

use Illuminate\Support\Facades\DB;

foreach (DB::select("select column_name, data_type from information_schema.columns
                     where table_name = 'student_profiles' order by ordinal_position") as $c) {
    printf("%-24s %s\n", $c->column_name, $c->data_type);
}

echo "\nprogram_code values in use:\n";
foreach (DB::select("select program_code, count(*) as n from student_profiles
                     group by program_code order by n desc") as $r) {
    printf("  %-8s %d\n", $r->program_code ?? 'NULL', $r->n);
}
