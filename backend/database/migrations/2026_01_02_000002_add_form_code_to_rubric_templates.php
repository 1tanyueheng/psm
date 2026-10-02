<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish the official marking forms (Lampiran E, G, H, I, J) on a rubric
 * template.
 *
 * Lampiran G and Lampiran H are both (PSM2, supervisor), so without a form
 * discriminator they collide on the template unique key. `form_code` carries
 * the Lampiran letter and joins that key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->dropUnique('rubric_version_unique');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->string('form_code', 8)->nullable()->index();
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->dropUnique('rubric_form_version_unique');
            $table->dropColumn('form_code');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'version'],
                'rubric_version_unique'
            );
        });
    }
};
