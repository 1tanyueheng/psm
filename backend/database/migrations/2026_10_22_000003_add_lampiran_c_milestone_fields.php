<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 — Lampiran C as its own milestone.
 *
 * Lampiran C is the corrections form a **conditional approval** obliges the
 * student to file: the panel accepted the title subject to changes, and the form
 * records the previous title, the new title and what was corrected.
 *
 * Until now it was a form on the *proposal* milestone: `lampiran_c_title`,
 * `lampiran_c_actions`, `lampiran_c_at` and `lampiran_c_by` lived on
 * `milestones`, and filing it moved the proposal from `conditional` to
 * `approved`. That worked, but it hid a real step of the process inside another
 * milestone: a reader looking at the chain saw "Proposal — conditional" and had
 * no way to see that a separate document was owed, or whether it had arrived.
 *
 * The columns below move that state onto a **dedicated milestone**, created only
 * when a panel conditionally approves:
 *
 *   - `requires_lampiran_c`  why this milestone exists, and what the panel asked
 *                            for, so the form can be prefilled with the reason.
 *   - `lampiran_c_*`         the same fields as before, now read on the C
 *                            milestone rather than the proposal.
 *
 * The old columns stay on `milestones` untouched. They are still the record of
 * *which* proposal produced the requirement, and dropping them would destroy the
 * only link between a conditional verdict and the corrections it demanded. New
 * writes go to the C milestone; the proposal keeps its history.
 *
 * `supervisor_signed_*` is the signature half: the student fills the digital
 * form, the supervisor reviews and signs it. A signature is what approves the
 * milestone — see MilestoneController::signLampiranC().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            /**
             * Set on the *proposal* milestone when the panel conditionally
             * approves: what the panel asked the student to correct.
             *
             * Null on every other milestone and on a proposal that was approved
             * outright or rejected, which is how the chain decides whether to
             * show a Lampiran C step at all.
             */
            $table->text('requires_lampiran_c')->nullable()->after('lampiran_c_by');

            /**
             * The student's side of Lampiran C, on the C milestone itself.
             *
             * `previous_title` and `new_title` are both stored: the form's whole
             * purpose is to record the change, and keeping only the new one would
             * make the record unable to show what changed.
             */
            $table->string('lampiran_c_previous_title', 255)->nullable()->after('requires_lampiran_c');
            $table->string('lampiran_c_new_title', 255)->nullable()->after('lampiran_c_previous_title');
            $table->timestamp('lampiran_c_filed_at')->nullable()->after('lampiran_c_new_title');
            $table->foreignId('lampiran_c_filed_by')->nullable()->after('lampiran_c_filed_at')
                  ->constrained('users')->nullOnDelete();

            /**
             * The supervisor's side: the signature that approves the form.
             *
             * Stored on the milestone rather than in a separate table because
             * there is exactly one signature per Lampiran C — unlike R1, which
             * repeats per revision round.
             */
            $table->string('supervisor_signature_path')->nullable()->after('lampiran_c_filed_by');
            $table->string('supervisor_signature_disk', 32)->nullable()->after('supervisor_signature_path');
            $table->string('supervisor_signature_type', 16)->nullable()->after('supervisor_signature_disk');
            $table->timestamp('supervisor_signed_at')->nullable()->after('supervisor_signature_type');
            $table->foreignId('supervisor_signed_by')->nullable()->after('supervisor_signed_at')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_signed_by');
            $table->dropColumn([
                'requires_lampiran_c',
                'lampiran_c_previous_title',
                'lampiran_c_new_title',
                'lampiran_c_filed_at',
                'lampiran_c_filed_by',
                'supervisor_signature_path',
                'supervisor_signature_disk',
                'supervisor_signature_type',
                'supervisor_signed_at',
            ]);
        });
    }
};
