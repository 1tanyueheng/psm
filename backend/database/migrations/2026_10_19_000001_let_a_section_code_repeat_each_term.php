<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A section belongs to a term, so its code is unique *within* a term.
 *
 * The create migration made `sections.code` globally unique, on the view that
 * four sections exist and their lecturer is appointed permanently. Both halves
 * of that changed:
 *
 *   - Sections are provisioned **per term**, because the class the faculty
 *     teaches is different every term. "S1" is the same *name* for a different
 *     group of students in 2026/2027 than it was in 2025/2026.
 *   - A section's lecturer is appointed **for that term**, because the seating
 *     rotates: the person who teaches S1 this term may teach S2 the next, and
 *     the section rows are per-term anyway, so the appointment needs no table
 *     of its own — the row *is* the appointment.
 *
 * A global unique code is incompatible with both: the second term cannot create
 * its S1 at all. The code becomes unique per term, and the term stops being
 * optional — a section with no term is a section nobody can place a student in,
 * because every screen resolves its list through `forSemester()`.
 *
 * Existing rows are stamped with the live term. That is the only defensible
 * answer available: they were created while that term was running, and a
 * section's term cannot be recovered from the row itself. The alternative —
 * refusing to backfill — would leave rows that no screen can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------
        // Every section needs a term before the column can be required
        // -----------------------------------------------------------------
        $termId = DB::table('academic_semesters')
            ->orderByRaw('is_active DESC')
            ->orderByDesc('starts_at')
            ->orderByDesc('semester_number')
            ->orderByDesc('id')
            ->value('id');

        if ($termId !== null) {
            DB::table('sections')
                ->whereNull('academic_semester_id')
                ->update(['academic_semester_id' => $termId]);
        }

        // Failing here is the honest outcome: NOT NULL would fail anyway, but
        // with a database error naming a constraint rather than the cause. The
        // only way to reach this is a database with sections and no academic
        // term at all, which needs seeding before the schema can be tightened.
        $unplaced = DB::table('sections')->whereNull('academic_semester_id')->count();

        if ($unplaced > 0) {
            throw new RuntimeException(
                "{$unplaced} section(s) have no academic term, and no term exists to stamp them with. "
                .'Create a term with `php artisan db:seed` (or set one active) and migrate again.'
            );
        }

        Schema::table('sections', function (Blueprint $table) {
            // The term is what a section belongs to now, so it is required.
            $table->foreignId('academic_semester_id')->nullable(false)->change();

            /**
             * And the delete rule has to agree with that.
             *
             * The create migration made the column nullable with `nullOnDelete`,
             * so deleting a term detached its sections — they survived, pointing
             * at nothing, invisible to every screen that resolves its list
             * through `forSemester()`. A required column cannot be nulled, so
             * that rule is now unimplementable: it would fail the not-null
             * constraint instead. `restrict` states what is actually true — a
             * term that still has sections is not deleted until somebody deals
             * with them — and says so at the point of the delete rather than
             * leaving a half-detached row behind.
             */
            $table->dropForeign(['academic_semester_id']);
            $table->foreign('academic_semester_id')
                  ->references('id')
                  ->on('academic_semesters')
                  ->restrictOnDelete();

            // The old constraint is global: one S1 in the whole database. It is
            // dropped rather than renamed because it constrains the wrong thing
            // (a bare code), not the same thing in a narrower scope.
            $table->dropUnique('sections_code_unique');

            // One S1 per term. Two sections with the same code in one term
            // would make "place this student in S1" ambiguous, which is the
            // whole reason the code is unique at all.
            $table->unique(['academic_semester_id', 'code'], 'sections_semester_code_unique');
        });
    }

    /**
     * Reversing the index and the nullability, but not the stamping.
     *
     * The backfilled term is correct data — it is the term each section was
     * created in — so clearing it would recreate the invisible-row problem this
     * migration exists to fix. Reversing it would also have to drop the terms
     * that were since added, which is not this migration's call to make.
     *
     * One caveat, stated rather than hidden: once two terms hold an `S1` each,
     * restoring the global unique index cannot succeed, because the rows it
     * forbids already exist. That is the correct outcome — rolling back a schema
     * change is not a reason to delete a term's sections — but it means the
     * rollback only works on a database that has not yet provisioned a second
     * term.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique('sections_semester_code_unique');
            $table->unique('code', 'sections_code_unique');
            $table->foreignId('academic_semester_id')->nullable()->change();

            // Back to the create migration's rule, which is only meaningful once
            // the column is nullable again.
            $table->dropForeign(['academic_semester_id']);
            $table->foreign('academic_semester_id')
                  ->references('id')
                  ->on('academic_semesters')
                  ->nullOnDelete();
        });
    }
};
