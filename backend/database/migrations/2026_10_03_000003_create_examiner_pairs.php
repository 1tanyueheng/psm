<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2/4 — fixed examiner pairs.
 *
 * The faculty's model is not "allocate an examiner per project". It is:
 *
 *   1. examiners are grouped into fixed pairs by the PSM Coordinator;
 *   2. a batch of students is assigned to one pair;
 *   3. every student in that batch is assessed by those same two examiners,
 *      for the title defence AND the final evaluation.
 *
 * So a pair is a first-class thing, and a student's panel is inherited from
 * the batch they sit in rather than chosen per project.
 *
 * The pairing is recorded by stamping `examiner_assignments.examiner_pair_id`,
 * rather than by adding a second allocation table. The evaluation flow, the
 * policies and the aggregate all already read `examiner_assignments`; adding a
 * parallel table would give two answers to "who examines this project".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_pairs', function (Blueprint $table) {
            $table->id();

            $table->string('name', 64);                     // "Panel A"
            $table->string('psm_part', 8)->default('PSM1');

            $table->foreignId('examiner_1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('examiner_2_id')->constrained('users')->cascadeOnDelete();

            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A pair is two distinct people, and the same two people should not
            // be registered twice for the same part.
            $table->unique(
                ['psm_part', 'examiner_1_id', 'examiner_2_id'],
                'examiner_pair_unique'
            );
        });

        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->foreignId('examiner_pair_id')->nullable()->after('examiner_id')
                  ->constrained('examiner_pairs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('examiner_pair_id');
        });

        Schema::dropIfExists('examiner_pairs');
    }
};
