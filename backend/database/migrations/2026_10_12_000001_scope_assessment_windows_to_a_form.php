<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment windows become per form, not per batch.
 *
 * A window was scoped to (term, batch), so opening PSM 1 opened Lampiran E and
 * Lampiran I together. That is wrong for the real process: the supervisor's
 * evaluation and the seminar examiner's are different events, marked at
 * different times by different people, and the coordinator needs to open one
 * without opening the other.
 *
 * The column is nullable and the unique key keeps `null` as a distinct value,
 * so an existing batch-wide window still means "every form in this batch" —
 * which is how the rows already in the table behave. Narrowing to one form is
 * opt-in per window rather than a forced rewrite of history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_windows', function (Blueprint $table) {
            // Null = the whole batch (the old behaviour). A form code = only
            // that form. Matches the width of `rubric_templates.form_code`.
            $table->string('form_code', 16)->nullable()->after('psm_part');

            $table->index(['academic_semester_id', 'psm_part', 'form_code'], 'assessment_windows_term_part_form_idx');
        });

        /**
         * Replace the (term, batch) unique key with one that includes the form.
         *
         * The old key allowed exactly one window per batch, which is what made
         * "open Lampiran E separately" impossible. The new key allows one
         * window per (term, batch, form) while still forbidding two windows
         * that would both claim to govern the same form.
         *
         * PostgreSQL treats NULLs as distinct in a unique index, so the old
         * batch-wide rows do not collide with each other — but they would also
         * not be protected from duplication. The partial unique index below
         * covers that case explicitly.
         */
        DB::statement('ALTER TABLE assessment_windows DROP CONSTRAINT IF EXISTS assessment_windows_term_part_unique');

        // One window per (term, batch, form) when a form is named.
        DB::statement('
            CREATE UNIQUE INDEX assessment_windows_term_part_form_unique
            ON assessment_windows (academic_semester_id, psm_part, form_code)
            WHERE form_code IS NOT NULL
        ');

        // And still only one batch-wide window per (term, batch), so the old
        // behaviour cannot be duplicated either.
        DB::statement('
            CREATE UNIQUE INDEX assessment_windows_term_part_whole_unique
            ON assessment_windows (academic_semester_id, psm_part)
            WHERE form_code IS NULL
        ');

        /**
         * Existing windows become form-scoped where the batch has exactly one
         * form per assessor role, so the coordinator sees the finer control
         * immediately rather than after re-creating every window.
         *
         * PSM 1 has two forms (E, I) and PSM 2 has four (G, H1, H2, J). Rather
         * than guess a split, the batch-wide window is left intact: it still
         * governs every form in its batch, which is exactly what it did before.
         * The coordinator can then open form-specific windows alongside it.
         */
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS assessment_windows_term_part_form_unique');
        DB::statement('DROP INDEX IF EXISTS assessment_windows_term_part_whole_unique');

        Schema::table('assessment_windows', function (Blueprint $table) {
            $table->dropIndex('assessment_windows_term_part_form_idx');
            $table->dropColumn('form_code');
        });

        DB::statement('
            ALTER TABLE assessment_windows
            ADD CONSTRAINT assessment_windows_term_part_unique
            UNIQUE (academic_semester_id, psm_part)
        ');
    }
};
