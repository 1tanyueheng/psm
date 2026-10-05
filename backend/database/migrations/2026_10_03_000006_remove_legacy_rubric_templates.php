<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the legacy chapter rubrics (form_code IS NULL). They are not official
 * Lampiran forms, carry coordinator weight 0, and have no evaluations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacyIds = DB::table('rubric_templates')
            ->whereNull('form_code')
            ->pluck('id');

        if ($legacyIds->isEmpty()) {
            return;
        }

        // Delete criteria first (FK to components)
        DB::table('rubric_criteria')
            ->whereIn('rubric_component_id', function ($q) use ($legacyIds) {
                $q->select('id')->from('rubric_components')
                  ->whereIn('rubric_template_id', $legacyIds);
            })->delete();

        // Delete components
        DB::table('rubric_components')
            ->whereIn('rubric_template_id', $legacyIds)
            ->delete();

        // Delete templates
        DB::table('rubric_templates')
            ->whereIn('id', $legacyIds)
            ->delete();
    }

    public function down(): void
    {
        // Not reversible without re-seeding.
    }
};
