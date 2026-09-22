<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 — Student and supervisor profiles.
 *
 * Split from `users` because the attribute sets are disjoint and a user only
 * ever has one of them. Keeping them separate avoids nullable-column sprawl.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Student profile
        // ---------------------------------------------------------------
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('student_id', 32)->unique();
            $table->string('program', 128);                 // e.g. Bachelor of Computer Science
            $table->string('program_code', 32)->nullable(); // e.g. CS230
            $table->string('batch', 32);                    // e.g. 2026
            $table->string('faculty')->nullable();
            $table->unsignedTinyInteger('current_semester')->nullable();
            $table->string('phone_emergency', 32)->nullable();

            // Module 3 — the working title, replaced once a project is registered
            $table->string('thesis_title')->nullable();
            $table->text('thesis_abstract')->nullable();

            // Supervision capacity is per-student; default comes from config/psm.php
            $table->unsignedTinyInteger('max_supervisors')->default(2);
            $table->boolean('is_active_cohort')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch', 'program']);
        });

        // ---------------------------------------------------------------
        // Supervisor profile
        // ---------------------------------------------------------------
        Schema::create('supervisor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('staff_no', 32)->unique();
            $table->string('academic_title', 64)->nullable();   // Dr., Prof., Ts.
            $table->string('office_location')->nullable();

            // Module 2 — capacity is the hard constraint the coordinator UI checks
            $table->unsignedSmallInteger('max_supervisees')->default(8);
            $table->boolean('is_accepting_students')->default(true)->index();

            // Module 5 — workload weighting for the analytics dashboard
            $table->decimal('workload_release_percent', 5, 2)->default(0.00);

            $table->text('bio')->nullable();

            // Whether this supervisor may also act as an internal examiner
            $table->boolean('can_examine')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });

        // ---------------------------------------------------------------
        // Module 2 — Expertise areas (many-to-many with a controlled vocabulary)
        // ---------------------------------------------------------------
        Schema::create('expertise_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('slug', 128)->unique();
            $table->string('category', 64)->nullable();   // e.g. AI, Networks, HCI
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('supervisor_expertise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervisor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expertise_area_id')->constrained()->cascadeOnDelete();
            // 1 = incidental familiarity, 5 = primary research field
            $table->unsignedTinyInteger('proficiency')->default(3);
            $table->timestamps();

            $table->unique(
                ['supervisor_profile_id', 'expertise_area_id'],
                'supervisor_expertise_unique'
            );
        });

        // ---------------------------------------------------------------
        // Module 2 — Coordinator ↔ cohort scope
        // ---------------------------------------------------------------
        // Which batches/programs a coordinator is responsible for. Without this
        // every coordinator implicitly sees the whole faculty.
        Schema::create('coordinator_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('batch', 32)->nullable();
            $table->string('program', 128)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_scopes');
        Schema::dropIfExists('supervisor_expertise');
        Schema::dropIfExists('expertise_areas');
        Schema::dropIfExists('supervisor_profiles');
        Schema::dropIfExists('student_profiles');
    }
};
