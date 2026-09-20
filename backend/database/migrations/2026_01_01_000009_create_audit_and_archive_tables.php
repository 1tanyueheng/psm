<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7 — Audit trail.
 *
 * Append-only. Nothing in the application updates or deletes these rows; the
 * only permitted operation is a retention prune by the scheduled command.
 *
 * Deliberately store *who/what/when/where* and a diff, but never secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Actor — nullable so system/cron actions are representable
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();     // snapshot: survives user deletion
            $table->string('actor_role', 32)->nullable();

            $table->string('action', 64)->index();        // AuditAction value
            $table->string('category', 48)->nullable()->index();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info')->index();

            // Subject — polymorphic so any model can be audited
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->text('description')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changes')->nullable();          // field-level diff only

            // Request context (Module 7 accountability)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_method', 8)->nullable();
            $table->string('request_url', 512)->nullable();
            $table->string('session_id')->nullable();

            $table->boolean('is_suspicious')->default(false)->index();

            // Immutable timestamp — no updated_at, by design
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['auditable_type', 'auditable_id'], 'audit_subject_idx');
            $table->index(['user_id', 'created_at'], 'audit_actor_idx');
        });

        // ---------------------------------------------------------------
        // Module 7 — Long-term archive of completed PSM projects
        // ---------------------------------------------------------------
        // A denormalised, self-contained record: after archiving, the full
        // history is readable even if the live tables are pruned.
        Schema::create('archived_projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 32)->index();
            $table->string('title');
            $table->text('abstract')->nullable();
            $table->enum('category', ['system', 'research']);
            $table->enum('psm_part', ['PSM1', 'PSM2']);
            $table->string('academic_session', 32)->index();
            $table->string('batch', 32)->index();
            $table->string('program', 128)->nullable();

            // Denormalised participant roster (names survive account deletion)
            $table->json('students');          // [{name, student_id, program}]
            $table->json('supervisors');       // [{name, staff_no, role}]
            $table->json('examiners');         // [{name, staff_no}]

            // Final outcomes
            $table->decimal('final_mark', 6, 2)->nullable();
            $table->string('grade_letter', 4)->nullable();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->json('milestone_summary')->nullable();
            $table->json('grade_breakdown')->nullable();

            // Files retained for the archive (paths, original names, sizes)
            $table->json('documents')->nullable();

            // Search
            $table->text('keywords')->nullable();
            $table->string('supervisor_names', 512)->nullable();   // flattened for LIKE search

            $table->boolean('is_public')->default(false)->index();  // visible in search
            $table->timestamp('archived_at')->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_projects');
        Schema::dropIfExists('audit_logs');
    }
};
