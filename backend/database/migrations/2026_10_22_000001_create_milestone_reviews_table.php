<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 — R1 review rounds (Lampiran R1, one row per supervisor review).
 *
 * Lampiran R1 is the *supervisor's response* to a chapter submission, not a
 * document the student files and not a milestone of its own. The student
 * submits a chapter; the supervisor either accepts it (`ack_received`) or asks
 * for improvement (`ack_reviewed`); if improvement is asked for, the student
 * revises and the cycle repeats. Every turn of that cycle is one row here, so
 * the history a report needs ("chapter 2 was reviewed four times") is a count,
 * not a reconstruction from a free-text timeline.
 *
 * Deliberate choices:
 *
 *   - **Not a milestone.** A milestone is something the student owes and
 *     submits, with a due date and a place in a chain that gates the next item.
 *     R1 is something the supervisor returns. Adding it to the chain would put
 *     a supervisor-owned record into the student's obligation list, give it a
 *     deadline it has no use for, and make the chain's "each milestone gates
 *     the next" rule lie.
 *
 *   - **The verdict is stored twice, on purpose.** `ack_received` and
 *     `ack_reviewed` are the two checkboxes printed on the official form, kept
 *     here so the stored sheet matches the paper document clause for clause.
 *     `verdict` is the *derived* outcome the state machine acts on. Storing
 *     only the verdict would let the screen and the record drift apart when the
 *     wording of the form is next revised; storing only the checkboxes would
 *     make every reader re-implement the "both ticked means improvement" rule.
 *
 *   - **`declaration` is a frozen snapshot.** The student fills Bahagian A of
 *     the form (name, matric, programme, year, category, title, which items are
 *     being submitted). The supervisor reviews a *specific round*, so the
 *     declaration is copied into the review rather than joined live: a student
 *     who later corrects their title must not retroactively change what a
 *     supervisor is recorded as having reviewed.
 *
 *   - **Scores never reach the grade.** Lampiran R1's 0-5 scale is evidential
 *     only (`evidence_only` below exists to say so at the schema level). The
 *     graded forms are E, G, H, I and J. A chapter reviewed five times must not
 *     out-weigh one reviewed once, which is exactly what folding review scores
 *     into the weighted scheme would do.
 *
 *   - **`revision_no` ties the review to the submission it answered.** The
 *     current submission's `submission_files.revision_no` at the moment of
 *     review, so a supervisor's verdict can be matched to the file version it
 *     was given — not to whatever the student uploaded next.
 *
 *   - **No round cap.** The faculty's rule is that a student may revise as many
 *     times as it takes. There is no `max_rounds` column and nothing enforces
 *     one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();

            // Which submission version this review answered. 1 for the first
            // review of the first submission.
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->unsignedTinyInteger('revision_no')->default(1);

            $table->foreignId('reviewer_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('reviewer_name')->nullable();
            $table->string('reviewer_role', 32)->nullable();

            // ---- Bahagian B: the two checkboxes, as printed -------------
            $table->boolean('ack_received')->default(false);
            $table->boolean('ack_reviewed')->default(false);

            // The derived outcome: `approved` (received only) or
            // `improvement` (reviewed). Null only on a row written before the
            // rule existed, which is why it is nullable rather than defaulted.
            $table->string('verdict', 32)->nullable()->index();

            // ---- Bahagian A: the student's declaration, frozen ----------
            $table->json('declaration')->nullable();

            // ---- The 0-5 review scores, per review item -----------------
            // { "report_content": 4, "report_format": 3, "prototype_analysis": 2, ... }
            $table->json('scores')->nullable();

            // Totals in the form's own units (out of 5 per item), kept for the
            // printed sheet. Not a percentage and not a grade.
            $table->decimal('total_score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();

            $table->text('comments')->nullable();

            // ---- Signature ----------------------------------------------
            // The acknowledgement above is the evidence: an authenticated
            // supervisor, a timestamp and a verdict. The signature image is the
            // endorsement drawn on top of it, and stays optional — a supervisor
            // on a device without a pointer must not be blocked from reviewing.
            $table->string('signature_path')->nullable();
            $table->string('signature_disk', 32)->nullable();
            $table->string('signature_type', 16)->nullable();   // drawn | uploaded
            $table->string('signature_mime_type', 128)->nullable();
            $table->unsignedBigInteger('signature_size_bytes')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();

            // One review per round per milestone: a double-tap on the review
            // button must not manufacture a second historical round.
            $table->unique(['milestone_id', 'round_no'], 'milestone_reviews_round_unique');
            $table->index(['milestone_id', 'created_at']);
            $table->index(['reviewer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_reviews');
    }
};
