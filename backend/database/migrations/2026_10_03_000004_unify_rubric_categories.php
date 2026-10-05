<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — one rubric per form, not one per project category.
 *
 * The marking forms were seeded twice, once for `system` and once for
 * `research`, on the assumption that a system build and a research project are
 * marked differently. They are not: comparing the two variants, the component
 * codes, titles and weights are identical, and exactly ONE criterion differs
 * across the whole set. The split produced ten templates where five are needed,
 * two near-identical rows in every list, and a category dropdown that asked a
 * question with only one meaningful answer.
 *
 * `category` becomes nullable, where NULL means "applies to every category".
 * The column is kept rather than dropped so a genuinely category-specific
 * rubric can still be added later; `resolveFor()` prefers the category-agnostic
 * row and falls back to a specific one.
 *
 * Duplicates are merged onto the lowest id: evaluations are repointed first,
 * then the surplus rows are deleted (their components and criteria cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the old key before touching the data — it includes `category`
        //    and would block setting the merged rows to NULL.
        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->dropUnique('rubric_form_version_unique');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->string('category')->nullable()->change();
        });

        // 2. Merge each duplicate set onto its lowest id.
        $groups = DB::table('rubric_templates')
            ->select('psm_part', 'assessor_type', 'form_code', 'version')
            ->groupBy('psm_part', 'assessor_type', 'form_code', 'version')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('rubric_templates')
                ->where('psm_part', $group->psm_part)
                ->where('assessor_type', $group->assessor_type)
                ->where('version', $group->version)
                ->when(
                    $group->form_code === null,
                    fn ($q) => $q->whereNull('form_code'),
                    fn ($q) => $q->where('form_code', $group->form_code)
                )
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if (count($rows) < 2) {
                // Nothing to merge, but the row may still be category-specific.
                DB::table('rubric_templates')
                    ->whereIn('id', $rows)
                    ->update(['category' => null]);

                continue;
            }

            $keeper = array_shift($rows);

            // Repoint anything already marked against a duplicate, so no
            // evaluation is left pointing at a row that is about to vanish.
            DB::table('evaluations')
                ->whereIn('rubric_template_id', $rows)
                ->update(['rubric_template_id' => $keeper]);

            DB::table('rubric_templates')->whereIn('id', $rows)->delete();

            DB::table('rubric_templates')
                ->where('id', $keeper)
                ->update(['category' => null]);
        }

        // 3. One template per (part, assessor, form, version) from now on.
        //    `category` is deliberately NOT in the key: that is the whole point.
        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->unique(
                ['psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });
    }

    public function down(): void
    {
        // The merge is not reversible: the duplicate rows were near-identical,
        // and which category an evaluation was marked under is recorded on the
        // evaluation's own rubric_snapshot, not here.
        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->dropUnique('rubric_form_version_unique');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });
    }
};
