<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 — Projects (PSM1 / PSM2 registration).
 *
 * One project row per student per PSM part. `project_members` allows a group
 * project without changing the shape of the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32)->unique();            // e.g. PSM2-2026-CS-014
            $table->string('title');
            $table->text('abstract')->nullable();
            $table->text('objectives')->nullable();
            $table->text('scope')->nullable();

            // Module 3 — category drives which milestone + rubric template apply
            $table->enum('category', ['system', 'research'])->index();
            $table->enum('psm_part', ['PSM1', 'PSM2'])->index();

            $table->string('academic_session', 32);          // e.g. 2025/2026
            $table->string('batch', 32)->index();

            // Denormalised for fast cohort filtering (Module 5)
            $table->string('program', 128)->nullable();

            // Registration lifecycle
            $table->enum('status', [
                'draft',
                'submitted',
                'approved',
                'rejected',
                'in_progress',
                'completed',
                'archived',
            ])->default('draft')->index();

            // Module 3 — who owns the project record
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Module 7 — archive pointer
            $table->timestamp('archived_at')->nullable()->index();

            // Module 8 — opt-out for students who do not consent to public display
            $table->boolean('leaderboard_opt_out')->default(false);

            $table->json('metadata')->nullable();            // category-specific extras
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch', 'status']);
            $table->index(['psm_part', 'category']);
        });

        // ---------------------------------------------------------------
        // Project members — supports solo and group projects
        // ---------------------------------------------------------------
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_leader')->default(false);
            // Share of the project mark per member. Sum per project = 100.
            $table->decimal('contribution_percent', 5, 2)->default(100.00);

            $table->timestamps();

            $table->unique(['project_id', 'student_profile_id'], 'project_member_unique');
        });

        // ---------------------------------------------------------------
        // Module 3 — Milestone templates (per category, versioned)
        // ---------------------------------------------------------------
        // Templates are versioned rather than mutated so a change to the
        // rubric next semester does not retroactively alter existing projects.
        Schema::create('milestone_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');                                  // e.g. "System Development 2025/26"
            $table->enum('category', ['system', 'research'])->index();
            $table->enum('psm_part', ['PSM1', 'PSM2', 'BOTH'])->default('BOTH');

            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();

            // Total duration from project start, used to compute deadlines
            $table->unsignedSmallInteger('default_duration_days')->default(140);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['category', 'psm_part', 'version'],
                'milestone_template_version_unique'
            );
        });

        Schema::create('milestone_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_template_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);                  // proposal, design, implement…
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('deliverable_expectation')->nullable();

            $table->unsignedSmallInteger('sequence');    // display + dependency order
            $table->unsignedSmallInteger('offset_days'); // days from project start
            $table->unsignedSmallInteger('duration_days')->default(14); // window length

            // Share of the overall milestone-progress score
            $table->decimal('weight_percent', 5, 2)->default(0.00);

            $table->json('allowed_file_types')->nullable();
            $table->boolean('requires_supervisor_approval')->default(true);
            $table->unsignedSmallInteger('max_files')->default(3);

            $table->timestamps();

            $table->unique(['milestone_template_id', 'code'], 'template_item_code_unique');
            $table->index(['milestone_template_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_template_items');
        Schema::dropIfExists('milestone_templates');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
