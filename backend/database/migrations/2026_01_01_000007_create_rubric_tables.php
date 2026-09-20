<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — Rubrics.
 *
 * A rubric template is scoped to a (category, psm_part) pair and versioned.
 * Each template holds weighted components; each component holds weighted
 * criteria. Marks are entered at criterion level and rolled up.
 *
 *   RubricTemplate (weight 100%)
 *     └── RubricComponent  (weight, e.g. Report 40%)
 *           └── RubricCriterion (weight within component, e.g. Clarity 25%)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->enum('category', ['system', 'research'])->index();
            $table->enum('psm_part', ['PSM1', 'PSM2', 'BOTH'])->default('BOTH');

            // Which assessment deliverable this rubric scores
            $table->enum('assessor_type', ['supervisor', 'examiner', 'coordinator'])
                  ->default('supervisor')->index();

            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_published')->default(false);   // visible to assessors

            // Maximum achievable mark; kept explicit so criteria weights can stay
            // readable percentages while the total remains configurable.
            $table->decimal('total_marks', 6, 2)->default(100.00);
            $table->decimal('pass_mark', 6, 2)->default(50.00);

            $table->text('description')->nullable();
            $table->text('grading_guide')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'version'],
                'rubric_version_unique'
            );
        });

        Schema::create('rubric_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_template_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);              // report, presentation, demo…
            $table->string('title');
            $table->text('description')->nullable();

            // Share of the rubric total. Sum across components must be 100 —
            // validated by RubricService::assertWeightsBalance().
            $table->decimal('weight_percent', 5, 2);

            $table->unsignedSmallInteger('sequence')->default(0);

            // If true, an assessor must give a comment when marking below the
            // component's minimum threshold (encourages actionable feedback).
            $table->boolean('requires_comment_below')->default(false);
            $table->decimal('comment_threshold_percent', 5, 2)->default(50.00);

            $table->timestamps();

            $table->unique(['rubric_template_id', 'code'], 'rubric_component_code_unique');
            $table->index(['rubric_template_id', 'sequence']);
        });

        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_component_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('guidance')->nullable();     // what "excellent" looks like

            // Weight *within* the parent component (sum to 100 per component)
            $table->decimal('weight_percent', 5, 2);

            // Absolute cap for this criterion; derived from the rubric total and
            // the two weights, stored so historical marks stay interpretable if a
            // template is later edited.
            $table->decimal('max_marks', 6, 2);

            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->unique(['rubric_component_id', 'code'], 'rubric_criterion_code_unique');
            $table->index(['rubric_component_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('rubric_components');
        Schema::dropIfExists('rubric_templates');
    }
};
