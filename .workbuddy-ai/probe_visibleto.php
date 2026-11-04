<?php

/** Does the visibleTo query shape actually work? Read-only. */

use App\Models\Project;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

echo "ProjectMember -> studentProfile relation exists: "
    .(method_exists(App\Models\ProjectMember::class, 'studentProfile') ? 'yes' : 'NO')."\n";

echo "student_profiles has program_code: "
    .(DB::table('information_schema.columns')->where('table_name', 'student_profiles')
        ->where('column_name', 'program_code')->exists() ? 'yes' : 'NO')."\n";

echo "student_profiles has program: "
    .(DB::table('information_schema.columns')->where('table_name', 'student_profiles')
        ->where('column_name', 'program')->exists() ? 'yes' : 'NO')."\n";

echo "\nprojects visible with no programme filter: ".Project::count()."\n";
echo "projects whose member is on BIP: ".Project::whereHas(
    'members.studentProfile',
    fn ($q) => $q->where('program_code', 'BIP')->orWhere('program', 'BIP')
)->count()."\n";
echo "projects whose member is on BIS: ".Project::whereHas(
    'members.studentProfile',
    fn ($q) => $q->where('program_code', 'BIS')->orWhere('program', 'BIS')
)->count()."\n";
