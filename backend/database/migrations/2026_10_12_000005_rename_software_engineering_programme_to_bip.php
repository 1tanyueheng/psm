<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Software Engineering's programme code becomes `BIP`.
 *
 * The `Programme` enum shipped with `BIK`, which was my own abbreviation. The
 * faculty's is **BIP** — Bachelor of Computer Science (Software Engineering) —
 * and it is what appears on the programmes a student actually reads. `BIK` was
 * accepted by `tryParse()` as an alias, so nothing broke while both existed, but
 * a code only this system uses is a code that will disagree with every list the
 * faculty publishes.
 *
 * The rename is applied to the stored values rather than only to the enum,
 * because the code is the value the supervision rule *compares*: leaving
 * `BIK` rows behind would mean a Software Engineering supervisor held `BIK` while
 * a new colleague held `BIP`, and the two would not match despite being the same
 * programme. That is a silent cross-programme refusal — the rule firing on a
 * pair it should have permitted.
 *
 * Only the two columns that hold a programme code are touched. The free-text
 * `student_profiles.program` name is left alone: it is already the full label,
 * which is unchanged, and rewriting a descriptive field to fix a code would
 * obscure what happened.
 *
 * No `down()`: reversing this would restore a code the faculty does not use, and
 * `tryParse()` still resolves `BIK` if any older export depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['student_profiles', 'program_code'],
            ['student_profiles', 'program'],
            ['supervisor_profiles', 'programme'],
            ['coordinator_scopes', 'program'],
        ] as [$table, $column]) {
            if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where($column, 'BIK')
                ->update([$column => 'BIP']);
        }

        // The column comment on `supervisor_profiles.programme` names the five
        // codes, so it is refreshed to match rather than left describing a set
        // that no longer exists.
        DB::statement(
            "COMMENT ON COLUMN supervisor_profiles.programme IS "
            ."'One of BIS/BIM/BIP/BIW/BIT. A supervisor may only take students from this programme.'"
        );
    }

    public function down(): void
    {
        // See the note above: the previous code is not worth restoring.
    }
};
