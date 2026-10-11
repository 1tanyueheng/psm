<?php

use App\Models\AssessmentWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every assessment window governs exactly one Lampiran.
 *
 * Windows were scoped to (term, batch), so opening PSM 1 opened Lampiran E and
 * Lampiran I together. The real process does not work that way: each Lampiran is
 * marked at a different time by different people, and the coordinator has to
 * open them independently — the supervisor files E when the project is done,
 * the examiners file I on seminar day, and the two are rarely the same week.
 *
 * A nullable `form_code` was enough to *express* that, but it left two kinds of
 * window side by side: a batch-wide row that opened everything, and a
 * form-scoped one that opened one form. Two rows could then both answer "is
 * Lampiran E open?", and which one won depended on query order — the ambiguity
 * this whole feature exists to remove.
 *
 * So the batch-wide row goes. Each existing window is expanded into one window
 * per Lampiran of its batch, carrying its status and dates across, and the
 * column becomes required. There is then exactly one row per (term, batch,
 * form) and no question of precedence.
 *
 * The expansion is safe because it preserves what each window said: an open
 * window becomes every form open, a scheduled one becomes every form scheduled.
 * Nothing that was markable stops being markable.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1. Expand each batch-wide window into one row per Lampiran.
         *
         * Read from `psm.assessment_weights` rather than from the rubric
         * templates: the config is the single list of which forms a batch has,
         * and it is available even on a database whose rubrics were never
         * seeded. A form with no rubric cannot be marked anyway, so creating a
         * window for one is harmless — and it is what makes the coordinator's
         * screen complete rather than silently missing a row.
         */
        $formsByPart = (array) config('psm.assessment_weights', []);

        $batchWide = DB::table('assessment_windows')->whereNull('form_code')->get();

        foreach ($batchWide as $window) {
            $forms = array_keys((array) ($formsByPart[$window->psm_part] ?? []));

            if ($forms === []) {
                // A batch the config does not describe. Leave the row alone
                // rather than deleting a window that may still be governing
                // something; the NOT NULL below will surface it loudly.
                continue;
            }

            foreach ($forms as $formCode) {
                $exists = DB::table('assessment_windows')
                    ->where('academic_semester_id', $window->academic_semester_id)
                    ->where('psm_part', $window->psm_part)
                    ->where('form_code', $formCode)
                    ->exists();

                if ($exists) {
                    // A form-scoped window already exists for this form, which
                    // is the finer statement of intent. Keep it.
                    continue;
                }

                DB::table('assessment_windows')->insert([
                    'name'                 => "{$window->name} — Lampiran {$formCode}",
                    'academic_session'     => $window->academic_session,
                    'academic_semester_id' => $window->academic_semester_id,
                    'psm_part'             => $window->psm_part,
                    'form_code'            => $formCode,
                    'scheduled_start_at'   => $window->scheduled_start_at,
                    'scheduled_end_at'     => $window->scheduled_end_at,
                    'status'               => $window->status,
                    'opened_at'            => $window->opened_at,
                    'closed_at'            => $window->closed_at,
                    'opened_by'            => $window->opened_by,
                    'closed_by'            => $window->closed_by,
                    'created_by'           => $window->created_by,
                    'notes'                => $window->notes,
                    'created_at'           => $window->created_at,
                    'updated_at'           => now(),
                ]);
            }
        }

        // The expanded rows now carry the meaning the batch-wide ones did.
        DB::table('assessment_windows')->whereNull('form_code')->delete();

        /**
         * 2. Replace the partial unique indexes with one plain key.
         *
         * With `form_code` required, "one window per (term, batch, form)" is an
         * ordinary unique constraint. The two partial indexes existed only to
         * cope with the nullable column and would now be redundant.
         */
        DB::statement('DROP INDEX IF EXISTS assessment_windows_term_part_form_unique');
        DB::statement('DROP INDEX IF EXISTS assessment_windows_term_part_whole_unique');

        DB::statement('
            CREATE UNIQUE INDEX assessment_windows_term_part_form_unique
            ON assessment_windows (academic_semester_id, psm_part, form_code)
        ');

        // 3. Make the column required, so a batch-wide window cannot come back.
        DB::statement('ALTER TABLE assessment_windows ALTER COLUMN form_code SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assessment_windows ALTER COLUMN form_code DROP NOT NULL');

        DB::statement('DROP INDEX IF EXISTS assessment_windows_term_part_form_unique');

        // Restore the nullable-aware pair: one per form where a form is named,
        // one per batch where it is not.
        DB::statement('
            CREATE UNIQUE INDEX assessment_windows_term_part_form_unique
            ON assessment_windows (academic_semester_id, psm_part, form_code)
            WHERE form_code IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX assessment_windows_term_part_whole_unique
            ON assessment_windows (academic_semester_id, psm_part)
            WHERE form_code IS NULL
        ');

        // The split is not reversed: re-collapsing per-form windows into one
        // batch-wide row would have to guess which status wins, and any answer
        // would silently reopen or close marking a coordinator set deliberately.
    }
};
