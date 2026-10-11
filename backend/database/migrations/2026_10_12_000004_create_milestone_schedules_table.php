<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Term-wide milestone deadlines, set once by the coordinator.
 *
 * Milestone dates were computed per project: at registration, each project took
 * its own start date and added the template's offsets, so Chapter 1 was due 42
 * days after *that student* registered. Two students in the same batch who
 * registered a fortnight apart therefore had deadlines a fortnight apart, and
 * nothing in the system could state when Chapter 1 was actually due.
 *
 * That is not how the faculty works. It publishes a calendar for the term —
 * Chapter 1 due 15 November, Chapter 2 due 6 December — and every student in
 * the batch is held to the same dates. A change is a faculty decision that
 * moves the date for everyone, which is why it is recorded here rather than
 * edited project by project.
 *
 * The row is keyed by (term, batch, milestone code), so it is the term's
 * calendar and not any one student's. `milestones.due_at` remains the value
 * actually enforced — this table is what it is derived from, and what a
 * coordinator edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('academic_semester_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // PSM 1 and PSM 2 run concurrently in one term with different
            // chains, so the calendar is per batch as well as per term.
            $table->string('psm_part', 8);

            // The milestone code (`chapter_1`, `final_report`, …) rather than a
            // template item id: the code is what identifies the milestone
            // across templates, so a template revision does not orphan the
            // term's calendar.
            $table->string('code', 32);

            $table->date('opens_at')->nullable();
            $table->date('due_at');

            // Who last moved it. A term-wide date change is a faculty decision
            // affecting every student in the batch, so it carries provenance
            // for the same reason an individual override does.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One date per milestone per batch per term: two rows would leave
            // two answers to "when is Chapter 1 due?".
            $table->unique(
                ['academic_semester_id', 'psm_part', 'code'],
                'milestone_schedules_term_part_code_unique'
            );
        });

        /**
         * Marks a milestone whose deadline came from the term's calendar.
         *
         * Without this the two scheduling models fight. `repairedDueAt()`
         * deliberately pushes a stale deadline out to "actual open + template
         * duration" — right when dates are relative to a student's own start,
         * wrong when the faculty has published a fixed date, because it would
         * silently move a date the faculty set. The flag is what tells the two
         * apart, and it is a column rather than a lookup so the model needs no
         * hidden query to decide.
         */
        Schema::table('milestones', function (Blueprint $table) {
            $table->boolean('deadline_is_fixed')->default(false)->after('extended_until');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn('deadline_is_fixed');
        });

        Schema::dropIfExists('milestone_schedules');
    }
};
