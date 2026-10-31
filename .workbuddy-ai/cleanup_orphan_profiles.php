<?php
// Remove the four student profiles left behind by soft-deleted probe accounts.
// Guards first: print exactly what will go, and refuse if anything looks like a
// real student (a live user, an enrolment, a supervision or a project).
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

$targets = StudentProfile::where('student_id', 'like', 'AI907%')
    ->with(['user' => fn ($q) => $q->withTrashed()])
    ->get();

if ($targets->isEmpty()) {
    echo "nothing to do\n";
    return;
}

$safe = true;

foreach ($targets as $s) {
    $user = $s->user;
    $enrolments = DB::table('student_enrolments')->where('student_profile_id', $s->id)->count();
    $supervisions = DB::table('supervision_assignments')->where('student_profile_id', $s->id)->count();
    $projects = DB::table('project_members')->where('student_profile_id', $s->id)->count();

    $isOrphan = $user === null || $user->trashed();
    $clean = $enrolments === 0 && $supervisions === 0 && $projects === 0;

    printf(
        "#%-3d %-9s name=%-14s user=%s enrolments=%d supervisions=%d projects=%d  %s\n",
        $s->id,
        $s->student_id,
        var_export($user?->name, true),
        $user === null ? 'MISSING' : ($user->trashed() ? 'trashed' : 'LIVE'),
        $enrolments,
        $supervisions,
        $projects,
        ($isOrphan && $clean) ? 'DELETE' : 'KEEP (not safe)'
    );

    if (! ($isOrphan && $clean)) {
        $safe = false;
    }
}

if (! $safe) {
    echo "\nRefusing: at least one row is not a clean orphan.\n";
    return;
}

DB::transaction(function () use ($targets) {
    foreach ($targets as $s) {
        $s->forceDelete();
    }
});

echo "\ndeleted ".$targets->count()." profile(s)\n";
echo 'AI907* remaining: '.StudentProfile::where('student_id', 'like', 'AI907%')->count().PHP_EOL;
