<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — Evaluations (one assessor's completed form) and criterion marks.
 * Module 4 / 8 — Final grades (the computed aggregate).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Grade scheme — how assessor marks combine
        // ---------------------------------------------------------------
        // Stored per project so the weighting can differ between cohorts and
        // no historical result is invalidated when a default changes.
        Schema::create('grade_schemes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();

            // [{ assessor_type: 'supervisor', weight: 60 }, { assessor_type: 'examiner', weight: 40 }]
            $table->json('weights');

            // How multiple assessors of the *same* type combine
            $table->string('aggregation')
                  ->default('mean');

            // Discard the highest and lowest examiner mark when >= 3 examiners
            $table->boolean('trim_extremes')->default(false);

            $table->decimal('pass_mark', 6, 2)->default(50.00);
            $table->boolean('is_locked')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // Module 4 — Evaluations
        // ---------------------------------------------------------------
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessor_id')->constrained('users')->cascadeOnDelete();

            // Which rubric this assessment used (frozen reference)
            $table->foreignId('rubric_template_id')->constrained()->restrictOnDelete();

            // The set of marks actually applied, copied from the template so a
            // later template edit cannot rewrite history (Module 7 integrity).
            $table->json('rubric_snapshot');

            $table->string('assessor_type')->index();
            $table->string('psm_part')->default('PSM2');

            $table->string('status')
                  ->default('draft')->index();

            // Raw rubric total before any moderation adjustment
            $table->decimal('raw_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->default(100.00);

            // Percentage, and the moderated figure that feeds the aggregate
            $table->decimal('score_percent', 6, 2)->nullable();
            $table->decimal('final_score', 8, 2)->nullable();
            $table->decimal('moderation_delta', 6, 2)->nullable();

            // Assessor's overall comment, separate from per-criterion notes
            $table->text('comment')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();

            $table->boolean('is_late_assessment')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();

            // Conflict-of-interest declaration
            $table->text('coi_declaration')->nullable();

            $table->timestamps();

            $table->unique(
                ['project_id', 'assessor_id', 'rubric_template_id'],
                'evaluation_unique_per_assessor'
            );
            $table->index(['project_id', 'status']);
            $table->index(['assessor_id', 'status']);
        });

        // ---------------------------------------------------------------
        // Module 4 — Per-criterion marks
        // ---------------------------------------------------------------
        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();

            // Keep the taxonomy link *and* a denormalised label: the label lets
            // the archive (Module 7) stay readable even if a criterion is retired.
            $table->foreignId('rubric_component_id')->nullable()
                  ->constrained('rubric_components')->nullOnDelete();
            $table->foreignId('rubric_criterion_id')->nullable()
                  ->constrained('rubric_criteria')->nullOnDelete();

            $table->string('component_code', 32)->nullable();
            $table->string('criterion_code', 32)->nullable();
            $table->string('criterion_title')->nullable();

            $table->decimal('max_marks', 6, 2);
            $table->decimal('marks_awarded', 6, 2);

            // Derived convenience value for the UI progress bars
            $table->decimal('weighted_contribution', 8, 4)->nullable();

            $table->text('comment')->nullable();
            $table->boolean('is_flagged')->default(false);

            $table->timestamps();

            $table->index(['evaluation_id', 'rubric_component_id']);
        });

        // ---------------------------------------------------------------
        // Module 4 / 8 — Final grade (computed, then frozen on release)
        // ---------------------------------------------------------------
        Schema::create('final_grades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            $table->string('psm_part')->default('PSM2');

            // Per-assessor-type subtotals, kept for transparent reporting
            $table->decimal('supervisor_score', 6, 2)->nullable();
            $table->decimal('examiner_score', 6, 2)->nullable();
            $table->decimal('coordinator_score', 6, 2)->nullable();

            // The weighted aggregate
            $table->decimal('aggregate_percent', 6, 2)->nullable();

            // Milestone-completion component (Module 3 weight rollup)
            $table->decimal('milestone_score', 6, 2)->nullable();

            // The number shown to the student
            $table->decimal('final_mark', 6, 2)->nullable();

            $table->string('grade_letter', 4)->nullable()->index();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->boolean('is_pass')->nullable();

            $table->unsignedSmallInteger('assessor_count')->default(0);

            // Snapshot of the arithmetic, so a result is reproducible
            $table->json('computation_breakdown')->nullable();

            $table->string('status')
                  ->default('provisional')->index();

            $table->timestamp('computed_at')->nullable();
            $table->timestamp('released_at')->nullable()->index();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            // Module 8 gating: only these grades are eligible for public display
            $table->boolean('is_publishable')->default(false)->index();

            $table->timestamps();

            // One final grade per student per project per PSM part
            $table->unique(
                ['project_id', 'student_profile_id', 'psm_part'],
                'final_grade_unique'
            );
            $table->index(['status', 'aggregate_percent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_grades');
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('grade_schemes');
    }
};
