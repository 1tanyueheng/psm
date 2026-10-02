<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the same assessor hold more than one evaluation of the same rubric on the
 * same project.
 *
 * Lampiran H (PSM2 progress report) is filled twice per student — Laporan
 * Kemajuan 1 and Laporan Kemajuan 2 — which the existing
 * (project_id, assessor_id, rubric_template_id) key forbids. `form_instance`
 * carries that discriminator and joins the key.
 *
 * It is NOT NULL with a 'default' value rather than nullable on purpose: both
 * MySQL and PostgreSQL treat NULLs as distinct inside a unique index, so a
 * nullable column would have silently dropped the existing uniqueness
 * guarantee for every ordinary evaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropUnique('evaluation_unique_per_assessor');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->string('form_instance', 16)->default('default')->after('psm_part');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->unique(
                ['project_id', 'assessor_id', 'rubric_template_id', 'form_instance'],
                'evaluation_unique_per_assessor'
            );
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropUnique('evaluation_unique_per_assessor');
            $table->dropColumn('form_instance');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->unique(
                ['project_id', 'assessor_id', 'rubric_template_id'],
                'evaluation_unique_per_assessor'
            );
        });
    }
};
