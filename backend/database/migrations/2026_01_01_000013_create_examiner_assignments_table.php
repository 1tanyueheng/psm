<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / 4 — Examiner allocation.
 *
 * Moved here from `2026_01_01_000004_create_supervision_assignments_table`,
 * where it could not run: it declares a foreign key to `projects`, which is
 * only created by migration 000005. MySQL rejects a foreign key to a table
 * that does not exist yet:
 *
 *   SQLSTATE[HY000]: General error: 1824
 *   Failed to open the referenced table 'projects'
 *
 * Explicitly modelled: who examines which project, so Module 4 knows whose
 * evaluation forms to create and Module 5 can report examiner workload.
 *
 * Nothing else references this table, so moving it later is safe. The
 * migration is numbered above every table it depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examiner_id')->constrained('users')->cascadeOnDelete();

            // Which presentation/session this examiner covers
            $table->enum('psm_part', ['PSM1', 'PSM2'])->default('PSM2');
            $table->string('panel_role', 32)->nullable();   // chair | member | reserve

            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'examiner_id', 'psm_part'], 'examiner_unique_per_part');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_assignments');
    }
};
