<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 — Milestones (instantiated per project) and submissions.
 *
 * A milestone is the *instance*; it copies its defaults from a
 * milestone_template_item at registration time and is then independently
 * schedulable (a coordinator can move one project's deadline without
 * touching anyone else's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Provenance: which template row this was cloned from (nullable so
            // an ad-hoc milestone can be added manually).
            $table->foreignId('milestone_template_item_id')
                  ->nullable()
                  ->constrained('milestone_template_items')
                  ->nullOnDelete();

            $table->string('code', 32);
            $table->string('title');
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('sequence');
            $table->decimal('weight_percent', 5, 2)->default(0.00);

            // Module 3 — `status` is derived/maintained by MilestoneService.
            $table->enum('status', [
                'pending',
                'open',
                'submitted',
                'reviewed',
                'approved',
                'rejected',
                'overdue',
            ])->default('pending')->index();

            // Scheduling
            $table->date('opens_at')->nullable();
            $table->date('due_at')->nullable()->index();
            $table->boolean('allow_late_submission')->default(true);
            $table->unsignedSmallInteger('late_window_days')->default(7);
            $table->timestamp('extended_until')->nullable();

            // Review outcome
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->unsignedTinyInteger('revision_count')->default(0);

            // Admin/coordinator override of the deadline
            $table->foreignId('deadline_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deadline_override_reason')->nullable();

            $table->json('allowed_file_types')->nullable();
            $table->unsignedSmallInteger('max_files')->default(3);
            $table->boolean('requires_supervisor_approval')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'code'], 'milestone_project_code_unique');
            $table->index(['project_id', 'sequence']);
            $table->index(['status', 'due_at']);
        });

        // ---------------------------------------------------------------
        // Module 3 — Submission files
        // ---------------------------------------------------------------
        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            // Stored on the private disk; never publicly addressable.
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable()->index();

            // Distinguishes a first submission from a revision (Module 7 trail)
            $table->unsignedTinyInteger('revision_no')->default(1);
            $table->boolean('is_current')->default(true)->index();

            // Soft-remove so the audit trail can still reference the file
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['milestone_id', 'is_current']);
        });

        // ---------------------------------------------------------------
        // Module 3 — Submission events (the narrative history of a milestone)
        // ---------------------------------------------------------------
        Schema::create('submission_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event', 64);        // uploaded | replaced | reviewed | approved | rejected | deadline_changed
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('comment')->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['milestone_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_events');
        Schema::dropIfExists('submission_files');
        Schema::dropIfExists('milestones');
    }
};
