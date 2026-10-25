<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One active supervision per (student, supervisor).
 *
 * The table had no uniqueness on that pair, and the seeder's `updateOrCreate`
 * key included `psm_part` — a column `ProgressionService::progress()` rewrites
 * from `PSM1` to `BOTH` when a student moves on. The two together meant a
 * re-seed inserted a second active pairing for people who already had one: 22
 * duplicate groups in the demo cohort.
 *
 * The damage was not cosmetic. `currentLoad()` counts active pairings, so a
 * duplicated pairing counted twice and a supervisor carrying five students
 * reported ten. `SupervisorProfile::capacityForPart()` then refused allocations
 * against a load that was never real, and the workload report overstated every
 * affected supervisor.
 *
 * **Only `is_active` rows are collapsed.** Retired pairings are history — an
 * ended supervision, or a PSM 1 pairing superseded by a `BOTH` one — and
 * deleting them would erase the record of who supervised whom. The unique index
 * is therefore partial, on `is_active`, which is the rule the application
 * actually holds: one live primary supervisor per student.
 *
 * The survivor is the **oldest** row. It is the one the rest of the system was
 * already reading (`activeSupervisions()->latest('id')` takes the newest, but
 * both carry identical data here), and keeping the earliest preserves the real
 * start of the relationship rather than the date a re-seed happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Collapse duplicates before the index can be created — the index would
         * otherwise fail on the existing rows.
         *
         * Ranking is done in SQL rather than in PHP so the whole fix is one
         * statement over the table, and so it behaves the same whatever the
         * cohort size.
         */
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY student_profile_id, supervisor_profile_id
                           ORDER BY id
                       ) AS rn
                FROM supervision_assignments
                WHERE is_active = true
            )
            UPDATE supervision_assignments sa
               SET is_active  = false,
                   ended_at   = COALESCE(sa.ended_at, NOW()),
                   end_reason = 'Duplicate active pairing removed'
              FROM ranked r
             WHERE sa.id = r.id
               AND r.rn > 1
        SQL);

        /**
         * The partial unique index.
         *
         * Partial because retired rows must stay: a student may legitimately
         * have several historical pairings, and a whole-table unique index would
         * forbid the second one ever existing.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX supervision_one_active_primary
                ON supervision_assignments (student_profile_id, supervisor_profile_id)
                WHERE is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS supervision_one_active_primary');

        // The collapsed rows are not restored. They were duplicates that should
        // never have existed, and re-activating them would double every affected
        // supervisor's load again.
    }
};
