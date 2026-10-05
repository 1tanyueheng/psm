<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — the assessment window.
 *
 * The coordinator's open/close control over marking: an event the coordinator
 * starts, during which assessors may file their marks, and which they close when
 * marking is done.
 *
 * Scoped by (term, psm_part) — a term holds both batches, and their forms are
 * different (PSM 1: E, I — PSM 2: G, H, J), so one window cannot cover both.
 *
 * One window per (term, part): opening a second would leave two answers to
 * "is marking open?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_windows', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Kept alongside the term so a window can be read without a join,
            // matching title_defence_sessions.
            $table->string('academic_session', 32);
            $table->foreignId('academic_semester_id')->constrained('academic_semesters');

            $table->string('psm_part', 16);

            // The scheduled period. A window that is `open` but past its end
            // stops accepting marks without anyone having to close it.
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();

            $table->string('status', 16)->default('scheduled');

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['academic_semester_id', 'psm_part'],
                'assessment_windows_term_part_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_windows');
    }
};
