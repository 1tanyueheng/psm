<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `supervisor_profiles.can_examine`.
 *
 * The column was **decorative**: it was stored, cast, exposed by
 * `SupervisorProfileResource` and shown on the profile page, but no query ever
 * read it. `ExaminerPairingService::eligibleExaminers()` and
 * `AssignmentService::assignExaminer()` both select candidates by **role**
 * alone (`whereIn('role', ['examiner', 'supervisor'])`), so a supervisor with
 * `can_examine = false` was still seatable on a panel. The flag promised a
 * restriction the system did not enforce.
 *
 * Rather than wire it up, the decision was to drop it. Whether someone may
 * examine is already expressed by their **role** and by whether the coordinator
 * seats them — a third, unenforced switch added nothing but a way to be
 * surprised.
 *
 * The create migration is left untouched, following the precedent set by
 * `2026_10_04_000001_remove_grade_concept`: a fresh install builds the schema in
 * order and this migration removes the column at the end of it. Rewriting
 * history would not help a database that has already run the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->dropColumn('can_examine');
        });
    }

    /**
     * Restore the column with the default the schema originally gave it.
     *
     * The values it held are not recoverable, and there is nothing to recover
     * from: no code ever branched on them.
     */
    public function down(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->boolean('can_examine')->default(true);
        });
    }
};
