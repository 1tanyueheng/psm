<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coursework marks a lecturer keys in.
 *
 * Four fixed components per student per term, summing to the `CW` weight in
 * `psm.assessment_weights`. They are stored as raw marks in the component's own
 * units — 5, 12, 9 and 9 — not as percentages, because that is what the lecturer
 * reads off their own mark sheet and what the student is shown. The conversion
 * to a percentage happens once, in `LecturerMark::percent()`, so there is a
 * single place the four become a mark out of 100.
 *
 * **One row per student per term**, unique on that pair. A term is the unit the
 * faculty marks in, and keying the same student twice in one term would make the
 * aggregate depend on which row was read first.
 *
 * A lecturer's own row is the only thing they may write. There is no window and
 * no submission lifecycle here, deliberately: the coordinator's assessment
 * windows govern *Lampiran*, and coursework is not one. The faculty's control
 * over coursework timing is the course's own timetable, not this system.
 *
 * `entered_by` records who typed the marks, which is not always the section's
 * lecturer — an admin correcting a typo is a real case, and the audit trail
 * should not claim the lecturer did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturer_marks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('section_id')
                  ->nullable()
                  ->constrained('sections')
                  ->nullOnDelete();

            $table->foreignId('academic_semester_id')
                  ->nullable()
                  ->constrained('academic_semesters')
                  ->nullOnDelete();

            /**
             * The four components, in the component's own units.
             *
             * Nullable rather than defaulting to zero: an unkeyed quiz and a
             * quiz the lecturer awarded nothing are different facts, and
             * defaulting to zero would silently fail a student whose marks have
             * simply not been entered yet. The aggregate treats a row with any
             * null as "not yet marked".
             */
            $table->decimal('quiz', 5, 2)->nullable();
            $table->decimal('assignment_c', 5, 2)->nullable();
            $table->decimal('assignment_p', 5, 2)->nullable();
            $table->decimal('assignment_a', 5, 2)->nullable();

            $table->foreignId('entered_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('entered_at')->nullable();

            $table->timestamps();

            // One set of marks per student per term.
            $table->unique(['student_profile_id', 'academic_semester_id']);
            $table->index('section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturer_marks');
    }
};
