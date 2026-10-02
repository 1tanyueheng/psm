<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran A — Borang Persetujuan Penyelia PSM (Supervisor Agreement Form).
 *
 * The entry point of the registration flow. A student names the supervisor who
 * has agreed to take them, together with up to three proposed titles. Once the
 * supervisor acknowledges (Part C) and JKPSM approves (Part D), the system
 * registers the supervisor<->student pairing (Module 2) and the student may
 * proceed to Lampiran B (title proposal).
 *
 * The pairing itself is created through AssignmentService so all capacity and
 * duplication rules stay in one place; this table only records the paperwork
 * and points at the resulting assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_agreements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            // Nullable only for the brief moment before the supervisor is set.
            $table->foreignId('supervisor_profile_id')->nullable()
                  ->constrained('supervisor_profiles')->nullOnDelete();

            $table->string('session', 32);                       // e.g. 2025/2026 Semester I
            $table->string('psm_part')->default('BOTH')->index();

            // Part B — up to three proposed titles; the supervisor picks one.
            $table->string('proposed_title_1');
            $table->string('proposed_title_2')->nullable();
            $table->string('proposed_title_3')->nullable();
            $table->string('agreed_title')->nullable();
            $table->boolean('english_report')->default(false);

            // pending_supervisor -> pending_jkpsm -> approved | rejected | cancelled
            $table->string('status')->default('pending_supervisor')->index();

            $table->timestamp('student_signed_at')->nullable();
            $table->timestamp('supervisor_acknowledged_at')->nullable();
            $table->timestamp('jkpsm_received_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Set once JKPSM approval has registered the pairing (Module 2).
            $table->foreignId('supervision_assignment_id')->nullable()
                  ->constrained('supervision_assignments')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_profile_id', 'session', 'status'], 'agreement_student_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_agreements');
    }
};
