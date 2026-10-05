<?php

use Database\Seeders\MarkingFormSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — one rubric per form *per project category*, reversing
 * `unify_rubric_categories`.
 *
 * That migration merged the `system` and `research` copies of each form onto a
 * single row on the grounds that "exactly ONE criterion differs across the
 * whole set". Reading the forms again, that is not true: Lampiran E's C(i)
 * Prototype and C(ii) Research Framework are different item sets, I's B(i)/B(ii)
 * likewise, G's C(i)/C(ii) are worded for development and study respectively,
 * and J's B(ii) even closes on Significance where B(i) closes on Commercial
 * Value. Marking a study against the Development wording is wrong, and the
 * collapse also left `rubric_form_version_unique` without `category`, so the
 * split could not be reintroduced without first widening that key.
 *
 * So: Lampiran E, G, I and J are restored to one template per project
 * category — eight templates — and Lampiran H stays category-agnostic, since
 * its three items are the same either way. Nine in total.
 *
 * Historical marks are unharmed. Every evaluation carries a `rubric_snapshot`
 * holding the full rubric it was marked against, and `evaluation_scores`
 * denormalises the component code, criterion code, title and max marks, so a
 * deleted template's criteria are not needed to read a past evaluation. The
 * criteria foreign keys are ON DELETE SET NULL for exactly that reason.
 *
 * Evaluations are repointed to the variant matching their project's category
 * before the superseded shared rows go, since that foreign key is RESTRICT. If
 * an evaluation's project has no usable category its shared row is kept rather
 * than orphaning the mark.
 */
return new class extends Migration
{
    /** Forms that print a different item set per project category. */
    private const CATEGORY_FORMS = ['E', 'G', 'I', 'J'];

    public function up(): void
    {
        // 1. Widen the key first: the split cannot be reintroduced while
        //    `category` is absent from it.
        Schema::table('rubric_templates', function ($table) {
            $table->dropUnique('rubric_form_version_unique');
        });

        Schema::table('rubric_templates', function ($table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });

        // 2. Drop the invented rubrics: no form_code means no form. Components
        //    and criteria cascade; nothing may reference them, because no
        //    evaluation was ever marked against one.
        $legacyIds = DB::table('rubric_templates')->whereNull('form_code')->pluck('id');

        if ($legacyIds->isNotEmpty()) {
            DB::table('rubric_templates')->whereIn('id', $legacyIds)->delete();
        }

        // 3. Seed the catalogue. MarkingFormSeeder is the single owner of these
        //    rows, so deferring to it keeps the migration and `db:seed` from
        //    producing two different rubrics for the same form.
        (new MarkingFormSeeder)->run();

        // 4. Move every evaluation onto the variant for its project's category.
        foreach ($this->sharedFormsNeedingVariants() as $shared) {
            // Keyed by category so the project's category selects the variant.
            $variants = DB::table('rubric_templates')
                ->where('form_code', $shared->form_code)
                ->where('psm_part', $shared->psm_part)
                ->where('assessor_type', $shared->assessor_type)
                ->where('version', $shared->version)
                ->whereNotNull('category')
                ->pluck('id', 'category');

            if ($variants->isEmpty()) {
                continue;
            }

            $evaluations = DB::table('evaluations')
                ->where('rubric_template_id', $shared->id)
                ->get(['id', 'project_id']);

            foreach ($evaluations as $evaluation) {
                $category = $this->categoryFor($evaluation->project_id);

                if ($category === null) {
                    continue;
                }

                $variantId = $variants->get($category);

                if ($variantId === null || (int) $variantId === (int) $shared->id) {
                    continue;
                }

                DB::table('evaluations')
                    ->where('id', $evaluation->id)
                    ->update(['rubric_template_id' => $variantId]);
            }
        }

        // 5. Retire the shared rows now superseded by the variants. Lampiran H
        //    is genuinely category-agnostic and so is never a candidate here.
        //    A shared row still carrying marks is kept as the fallback.
        $retired = DB::table('rubric_templates')
            ->whereIn('form_code', self::CATEGORY_FORMS)
            ->whereNull('category')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('evaluations')
                    ->whereColumn('evaluations.rubric_template_id', 'rubric_templates.id');
            })
            ->pluck('id');

        if ($retired->isNotEmpty()) {
            DB::table('rubric_templates')->whereIn('id', $retired)->delete();
        }
    }

    public function down(): void
    {
        // Collapse each (form, part, assessor, version) group back onto one
        // row, the way unify_rubric_categories did. Which variant survives is
        // whichever held the lowest id, so the wording of the merged rubric
        // depends on insert order — the evaluations' snapshots keep the truth.
        $groups = DB::table('rubric_templates')
            ->whereNotNull('form_code')
            ->select('psm_part', 'assessor_type', 'form_code', 'version')
            ->groupBy('psm_part', 'assessor_type', 'form_code', 'version')
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('rubric_templates')
                ->where('psm_part', $group->psm_part)
                ->where('assessor_type', $group->assessor_type)
                ->where('form_code', $group->form_code)
                ->where('version', $group->version)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $keeper = array_shift($ids);

            DB::table('evaluations')
                ->whereIn('rubric_template_id', $ids)
                ->update(['rubric_template_id' => $keeper]);

            DB::table('rubric_templates')->whereIn('id', $ids)->delete();

            DB::table('rubric_templates')
                ->where('id', $keeper)
                ->update(['category' => null]);
        }

        Schema::table('rubric_templates', function ($table) {
            $table->dropUnique('rubric_form_version_unique');
        });

        Schema::table('rubric_templates', function ($table) {
            $table->unique(
                ['psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });
    }

    /**
     * The category-agnostic rows for the forms that now have variants.
     */
    private function sharedFormsNeedingVariants()
    {
        return DB::table('rubric_templates')
            ->whereIn('form_code', self::CATEGORY_FORMS)
            ->whereNull('category')
            ->orderBy('id')
            ->get();
    }

    private function categoryFor(int $projectId): ?string
    {
        $category = DB::table('projects')->where('id', $projectId)->value('category');

        return in_array($category, ['system', 'research'], true) ? $category : null;
    }
};