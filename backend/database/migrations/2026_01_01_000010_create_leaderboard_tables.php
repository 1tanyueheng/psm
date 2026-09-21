<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 8 — Public recognition / leaderboard.
 *
 * Publishing is an explicit act. A coordinator builds a *snapshot* of the
 * rankings and publishes it; the public endpoint then reads only published
 * snapshots. This means:
 *   - the public route never touches student PII from the live tables
 *   - a coordinator can preview, then withdraw, without touching grades
 *   - historical "who won in 2025" survives later grade corrections
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Leaderboard snapshots (one per publish event)
        // ---------------------------------------------------------------
        Schema::create('leaderboards', function (Blueprint $table) {
            $table->id();

            $table->string('title');                                  // "PSM2 Showcase 2025/26"
            $table->string('slug', 96)->unique();                     // public URL segment
            $table->text('subtitle')->nullable();
            $table->text('description')->nullable();

            $table->string('psm_part')->default('PSM2');
            $table->string('batch', 32)->nullable()->index();
            $table->string('academic_session', 32)->nullable();

            // How many entries are shown publicly
            $table->unsignedSmallInteger('top_n')->default(3);

            // Minimum number of submitted assessments before a project is
            // eligible — prevents publishing a ranking based on one opinion.
            $table->unsignedTinyInteger('min_assessors')->default(2);

            // Ranking basis, so the published figure is explainable
            $table->string('ranking_basis')
                  ->default('final_mark');
            $table->string('tie_breaker')
                  ->default('assessor_count');

            // Publication state
            $table->string('status')
                  ->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('auto_publish_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // Render options for the public page
            $table->boolean('show_abstract')->default(true);
            $table->boolean('show_scores')->default(true);
            $table->boolean('show_student_names')->default(true);
            $table->boolean('show_program')->default(true);
            $table->string('theme', 32)->default('default');

            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // Leaderboard entries (frozen ranking rows)
        // ---------------------------------------------------------------
        Schema::create('leaderboard_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leaderboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('final_grade_id')->nullable()->constrained('final_grades')->nullOnDelete();

            $table->unsignedSmallInteger('rank');
            $table->boolean('is_winner')->default(false)->index();
            $table->boolean('is_top_n')->default(true)->index();

            // Frozen public copy — intentionally denormalised so the public page
            // never joins to live student records.
            $table->string('project_title');
            $table->text('project_abstract')->nullable();
            $table->string('project_code', 32)->nullable();
            $table->string('category')->nullable();
            $table->string('program', 128)->nullable();

            $table->json('students');      // [{name, student_id}] — no email/phone, ever
            $table->json('supervisors');   // [{name}] — shown only if configured

            $table->decimal('display_score', 6, 2)->nullable();
            $table->string('score_label', 64)->nullable();     // e.g. "Aggregate"
            $table->unsignedSmallInteger('assessor_count')->default(0);

            // Optional per-entry presentation
            $table->string('award_title', 128)->nullable();     // "Best System Project"
            $table->text('citation')->nullable();
            $table->string('poster_path')->nullable();

            $table->boolean('is_hidden')->default(false);       // withdraw a single entry

            $table->timestamps();

            $table->unique(['leaderboard_id', 'rank'], 'leaderboard_rank_unique');
            $table->index(['leaderboard_id', 'is_top_n']);
        });

        // ---------------------------------------------------------------
        // Module 8 — Global leaderboard settings (single row)
        // ---------------------------------------------------------------
        Schema::create('leaderboard_settings', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('default_top_n')->default(3);
            $table->unsignedTinyInteger('default_min_assessors')->default(2);
            $table->string('default_ranking_basis')
                  ->default('final_mark');

            // Whether any leaderboard may be published at all
            $table->boolean('module_enabled')->default(true);
            // Require coordinator approval before the public route serves a snapshot
            $table->boolean('require_approval')->default(false);
            // Global opt-in: students must not be listed unless they were told
            $table->boolean('honour_opt_out')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_settings');
        Schema::dropIfExists('leaderboard_entries');
        Schema::dropIfExists('leaderboards');
    }
};
