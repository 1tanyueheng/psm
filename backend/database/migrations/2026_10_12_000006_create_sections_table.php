<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sections, and the lecturer who owns each.
 *
 * A *section* is a teaching group for a service course that runs alongside
 * PSM 1 — the faculty runs four, each with one lecturer. It is not a project
 * group, not a supervision pairing and not a batch: a student belongs to one
 * section and, separately and independently, has a PSM project with a
 * supervisor. Conflating the two would say a lecturer supervises students they
 * have never supervised.
 *
 * **One lecturer per section, per term.** The section carries its term, so the
 * appointment is the row: a term's `S1` names the person who teaches S1 *that*
 * term, which is why there is still no separate history table — last term's S1
 * is last term's row, and it keeps the lecturer it was taught by, so the marks
 * keyed against it stay explainable long after the seating rotates.
 *
 * This docblock originally said the appointment was permanent and that no term
 * column was needed. That held only while the faculty taught one class for
 * ever, and it stopped holding the moment the codes had to repeat every term —
 * `2026_10_19_000001` makes the term required and the code unique within it.
 *
 * `lecturer_id` is nullable so a section can exist before its lecturer is
 * recorded; the marks screen treats "no lecturer" as "nobody may key marks yet"
 * rather than as "anyone may".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();

            // `S1`…`S4`, matching how the faculty refers to them. Unique only
            // within a term — `2026_10_19_000001` replaces this index, because
            // a global one forbids the next term from having an S1 at all.
            $table->string('code', 16)->unique();
            $table->string('name', 128);

            /**
             * The lecturer who owns this section.
             *
             * `nullOnDelete` rather than cascade: removing a lecturer's account
             * must not delete the section and orphan every mark keyed against
             * it. The section survives with no lecturer, which the policy reads
             * as "nobody may key marks" until an admin appoints one.
             */
            $table->foreignId('lecturer_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Which term's cohort this section serves, so a section list is not
            // a permanent mixture of every intake the faculty has ever run.
            $table->foreignId('academic_semester_id')
                  ->nullable()
                  ->constrained('academic_semesters')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index('academic_semester_id');
        });

        /**
         * The student's section.
         *
         * Set by an admin, not by the registration portal: the portal records
         * the registration and this system records who teaches them, and the two
         * are not connected. Nullable because a student who is not on the
         * service course — or has not been placed yet — legitimately has none,
         * and a lecturer's list simply omits them.
         */
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('section_id')
                  ->nullable()
                  ->after('academic_semester_id')
                  ->constrained('sections')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });

        Schema::dropIfExists('sections');
    }
};
