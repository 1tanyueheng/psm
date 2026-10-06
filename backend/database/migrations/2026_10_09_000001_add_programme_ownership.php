<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programme ownership, and the five official programme codes.
 *
 * FSKTM offers five undergraduate programmes, and a supervisor may only
 * supervise — and an examiner may only examine — a student from their own. That
 * rule needs both sides to carry a comparable programme value, and neither did:
 * `student_profiles.program_code` held six ad-hoc strings that matched none of
 * the official codes, and `supervisor_profiles` had no programme column at all,
 * so there was nowhere to record which programme a member of staff belongs to.
 *
 * Three things happen here, in order:
 *
 *   1. `supervisor_profiles.programme` is added, nullable. Nullable because the
 *      column is meaningless for a coordinator or admin account that happens to
 *      carry a supervisor profile, and because a legacy row whose programme
 *      nobody can determine must stay readable rather than be guessed at.
 *
 *   2. Student programme codes are mapped onto the official five. The mapping is
 *      deliberately conservative — every existing value has a defensible target,
 *      and anything unrecognised is left untouched so a human can see it rather
 *      than have it silently rewritten:
 *
 *        CS230, CS240 -> BIS     the CS intake is the Information Security stream
 *        IT240        -> BIT
 *        SE240, SE250 -> BIK
 *
 *   3. `student_profiles.program` (the free-text name) is brought in line with
 *      its code, so the two cannot disagree. It was already inconsistent —
 *      `IT240` appeared under two different names.
 *
 * `program_code` is kept as the column name rather than renamed. It is
 * referenced by `Project::nextCode()`, the reports, and the project code format
 * (`PSM1-2025-CS2-001`), and a rename would touch all of them for no gain.
 */
return new class extends Migration
{
    /** Legacy student programme -> official code. */
    private const CODE_MAP = [
        'CS230' => 'BIS',
        'CS240' => 'BIS',
        'IT240' => 'BIT',
        'IT250' => 'BIT',
        'SE240' => 'BIK',
        'SE250' => 'BIK',
    ];

    /** Official code -> the full name, for `student_profiles.program`. */
    private const NAMES = [
        'BIS' => 'Bachelor of Computer Science (Information Security) With Honours',
        'BIM' => 'Bachelor of Computer Science (Multimedia Computing) With Honours',
        'BIK' => 'Bachelor of Computer Science (Software Engineering) With Honours',
        'BIW' => 'Bachelor of Computer Science (Web Technology) With Honours',
        'BIT' => 'Bachelor of Information Technology With Honours',
    ];

    public function up(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->string('programme', 8)
                  ->nullable()
                  ->after('staff_no')
                  ->index()
                  ->comment('One of BIS/BIM/BIK/BIW/BIT. A supervisor may only take students from this programme.');
        });

        $this->normaliseStudentProgrammes();
    }

    /**
     * Bring every student onto an official programme code and matching name.
     *
     * Matched case-insensitively and trimmed, because the values arrived from
     * hand-entered forms as well as seeders.
     */
    private function normaliseStudentProgrammes(): void
    {
        foreach (self::CODE_MAP as $legacy => $official) {
            DB::table('student_profiles')
                ->whereRaw('UPPER(TRIM(program_code)) = ?', [$legacy])
                ->update([
                    'program_code' => $official,
                    'program'      => self::NAMES[$official],
                ]);
        }

        // A row already carrying an official code but a stale name — e.g. a
        // resumed import that set the code and not the label.
        foreach (self::NAMES as $code => $name) {
            DB::table('student_profiles')
                ->whereRaw('UPPER(TRIM(program_code)) = ?', [$code])
                ->where('program', '!=', $name)
                ->update(['program' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->dropIndex(['programme']);
            $table->dropColumn('programme');
        });

        // The programme-code rewrite is not reversed. The old values were
        // inconsistent with each other (`IT240` under two different names), so
        // there is no single correct value to restore, and guessing one would be
        // worse than leaving the official code in place.
    }
};
