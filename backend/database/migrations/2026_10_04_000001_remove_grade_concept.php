<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the grade concept from the system.
 *
 * The system releases a *mark*, not a grade. The forms it scores carry only
 * part of the assessment — the remainder is marked outside the system — so the
 * released total is not on a 0-100 scale. Mapping it onto A/B/C bands and grade
 * points would invent a grade the faculty never awarded, and a pass/fail flag
 * derived from that same total would be equally unfounded.
 *
 * Dropped here:
 *   final_grades.grade_letter / grade_point / is_pass
 *   archived_projects.grade_letter / grade_point
 *   grade_schemes.pass_mark
 *   rubric_templates.pass_mark
 *
 * Renamed here (the flag now describes what it actually gates):
 *   academic_semesters.is_grades_released -> is_marks_released
 *   academic_semesters.grades_released_at -> marks_released_at
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_grades', function (Blueprint $table) {
            // Dropping grade_letter also drops its index.
            $table->dropColumn(['grade_letter', 'grade_point', 'is_pass']);
        });

        Schema::table('archived_projects', function (Blueprint $table) {
            $table->dropColumn(['grade_letter', 'grade_point']);
        });

        Schema::table('grade_schemes', function (Blueprint $table) {
            $table->dropColumn('pass_mark');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->dropColumn('pass_mark');
        });

        Schema::table('academic_semesters', function (Blueprint $table) {
            $table->renameColumn('is_grades_released', 'is_marks_released');
            $table->renameColumn('grades_released_at', 'marks_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('academic_semesters', function (Blueprint $table) {
            $table->renameColumn('is_marks_released', 'is_grades_released');
            $table->renameColumn('marks_released_at', 'grades_released_at');
        });

        Schema::table('rubric_templates', function (Blueprint $table) {
            $table->decimal('pass_mark', 6, 2)->default(50.00);
        });

        Schema::table('grade_schemes', function (Blueprint $table) {
            $table->decimal('pass_mark', 6, 2)->default(50.00);
        });

        Schema::table('archived_projects', function (Blueprint $table) {
            $table->string('grade_letter', 4)->nullable();
            $table->decimal('grade_point', 3, 2)->nullable();
        });

        Schema::table('final_grades', function (Blueprint $table) {
            $table->string('grade_letter', 4)->nullable()->index();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->boolean('is_pass')->nullable();
        });
    }
};
