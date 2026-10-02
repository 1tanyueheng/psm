<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 — Supervisor ↔ student assignment (the pair, owned by Coordinator).
 *
 * A separate pivot *entity* rather than a plain pivot table, because an
 * assignment carries its own lifecycle (who created it, when, is it active,
 * what is the split of responsibility) and must be auditable (Module 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervision_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_profile_id')->constrained()->cascadeOnDelete();

            // Which part of the project this supervisor covers. PSM1 and PSM2
            // can legitimately have different supervisors.
            $table->string('psm_part')->default('BOTH');

            // Every pairing is a primary supervision. Co-supervision was removed
            // (see 2026_01_04_000001), so this column is now a constant that
            // stays for the archive rather than a choice at pairing time.
            $table->string('role')->default('primary');

            // Share of the supervision mark this supervisor is responsible for.
            // A student has one supervisor, so this is always 100 — kept for
            // the archived record of past pairings.
            $table->decimal('responsibility_percent', 5, 2)->default(100.00);

            $table->boolean('is_active')->default(true)->index();

            // Audit: who paired them and why
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('assignment_note')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('end_reason')->nullable();

            $table->timestamps();

            // A supervisor cannot be assigned to the same student twice for the
            // same PSM part while the assignment is active. Enforced in the
            // service layer; this index keeps lookups fast.
            $table->index(
                ['student_profile_id', 'supervisor_profile_id', 'psm_part', 'is_active'],
                'supervision_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_assignments');
    }
};
