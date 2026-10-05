<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — the coordinator-opened mark submission and its lock.
 *
 * Two records already existed for this: an `evaluations` row per assessor, and
 * a `final_grades` row per student. What did not exist was the *round* — the
 * thing a coordinator opens, watches, and finally locks. Without it the two
 * halves of a mark could drift apart (7 of 12 project+part groups in the seed
 * data had a supervisor form with no examiner form, or the reverse) and there
 * was no moment at which the mark became final.
 *
 * `EvaluationStatus::Moderated` looked like that moment but nothing ever wrote
 * it: `EvaluationService::moderate()` was deliberately removed because a
 * coordinator does not award or alter marks. So lock here means *attest* — the
 * coordinator declares the submitted forms complete and freezes the result.
 * It never edits a component mark. The `moderated_*` columns on `evaluations`
 * remain as tombstones for historical rows.
 *
 * The examiner component is the mean of the panel's submitted forms and stays
 * NULL until every panel member has submitted, so a half-returned panel can
 * never contribute a partial mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->string('psm_part')->default('PSM2');

            // The term the submission belongs to. Mirrored from the project so a
            // submission stays findable by term even if the project is later
            // re-pointed, and so the screen can scope by term without a join.
            $table->foreignId('academic_semester_id')->nullable()->constrained()->nullOnDelete();

            // open -> locked -> released. Readiness is deliberately *not*
            // stored: whether every panel member has submitted is derived from
            // the evaluation rows on read, so a stale 'ready' flag can never
            // gate a lock that the marks no longer support.
            $table->string('status')->default('open')->index();

            // How many examiner forms this submission expects back. Recorded so
            // the readiness checklist can say "2 of 2" rather than making the
            // coordinator count rows, and so a panel change after opening does
            // not silently alter the bar the lock has to clear.
            $table->unsignedSmallInteger('expected_panel_size')->default(1);

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();

            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();

            // Why a lock was lifted. A mark that can be reopened needs a paper
            // trail, otherwise "locked" means nothing.
            $table->text('unlock_reason')->nullable();

            $table->foreignId('final_grade_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            // One submission per student per project per part, matching the
            // uniqueness of evaluations and final_grades. `open()` relies on
            // this to stay idempotent under a double click.
            $table->unique(
                ['project_id', 'student_profile_id', 'psm_part'],
                'mark_submissions_project_student_part_unique'
            );

            $table->index(['academic_semester_id', 'psm_part', 'status']);
        });

        Schema::table('final_grades', function (Blueprint $table) {
            // The lock, mirrored onto the grade so the number the student sees
            // carries its own provenance and a released grade can be traced
            // back to the coordinator who attested it.
            $table->timestamp('locked_at')->nullable()->after('computed_at');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete()->after('locked_at');

            // The panel's mean examiner score, kept apart from `examiner_score`
            // so the arithmetic stays reproducible: examiner_score is that mean
            // expressed in the form's own marks, this is the raw average.
            $table->decimal('examiner_panel_average', 6, 2)->nullable()->after('examiner_score');
        });
    }

    public function down(): void
    {
        Schema::table('final_grades', function (Blueprint $table) {
            $table->dropForeign(['locked_by']);
            $table->dropColumn(['locked_at', 'locked_by', 'examiner_panel_average']);
        });

        Schema::dropIfExists('mark_submissions');
    }
};