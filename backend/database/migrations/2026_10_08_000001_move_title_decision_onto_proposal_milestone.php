<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the title decision off the Lampiran A agreement and onto the proposal
 * milestone.
 *
 * The agreement used to carry the panel's whole review: its verdict, the
 * confirmed title, the Lampiran C corrections and the re-proposal counter. That
 * made the title decision a step *between* Lampiran A and Lampiran B, so the
 * project could not exist until the panel had ruled.
 *
 * The decision now belongs to the **proposal milestone** — the first milestone
 * of every chain. The student files Lampiran A, the supervisor acknowledges, and
 * Lampiran B creates the project straight away. The panel then decides the title
 * at the proposal milestone, which is what gates the rest of the chain:
 *
 *   pass         → the milestone is approved and the remaining chapters open
 *   conditional  → the student files Lampiran C, then the milestone is approved
 *   rejected     → the student changes the title, which updates the project
 *
 * Only the Lampiran C columns have to be added. The panel's verdict, its reason
 * and who recorded it reuse the milestone's existing review fields
 * (`status`, `review_comment`, `reviewed_at`, `reviewed_by`).
 *
 * The agreement's panel columns are dropped rather than left dead: the flow they
 * belonged to no longer exists, and a half-populated review on every Lampiran A
 * row is exactly the kind of stale state that misleads the next reader.
 */
return new class extends Migration
{
    /** The agreement columns the removed review owned. */
    protected const AGREEMENT_COLUMNS = [
        'panel_decision',
        'confirmed_title',
        'panel_reason',
        'panel_comments',
        'project_type',
        'project_area',
        'panel_1_name',
        'panel_2_name',
        'panel_decided_at',
        'corrections_title',
        'corrections_actions',
        'corrections_at',
        'attempt',
        'resubmitted_at',
    ];

    public function up(): void
    {
        // -----------------------------------------------------------------
        // 0. Repair the project ↔ agreement link
        // -----------------------------------------------------------------
        // `submitTitleProposal()` recorded the agreement only inside
        // `projects.metadata->agreement_id` and never wrote the
        // `projects.agreement_id` column, so both `Project::agreement()` and
        // `SupervisorAgreement::project()` resolved to null for every project
        // registered through Lampiran B. The column is written now; this brings
        // the rows already registered into line.
        DB::table('projects')
            ->whereNull('agreement_id')
            ->whereNotNull('metadata')
            ->get(['id', 'metadata'])
            ->each(function ($project) {
                $metadata = json_decode((string) $project->metadata, true);

                if (is_array($metadata) && ! empty($metadata['agreement_id'])) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update(['agreement_id' => $metadata['agreement_id']]);
                }
            });

        // -----------------------------------------------------------------
        // 1. Retire the agreement's panel phase
        // -----------------------------------------------------------------
        // A proposal awaiting the panel, or awaiting Lampiran C, had already
        // been acknowledged by the supervisor — the pairing is registered and
        // the title is agreed. With the review moved to the milestone, both
        // states collapse into `approved`: the student may file Lampiran B, and
        // the title is decided at the proposal milestone from then on.
        DB::table('supervisor_agreements')
            ->whereIn('status', ['pending_panel', 'pending_corrections'])
            ->update(['status' => 'approved']);

        Schema::table('supervisor_agreements', function (Blueprint $table) {
            // Foreign keys first — InnoDB will not drop a column that a
            // constraint still references.
            $table->dropConstrainedForeignId('panel_decided_by');
            $table->dropConstrainedForeignId('corrections_by');

            $table->dropColumn(self::AGREEMENT_COLUMNS);
        });

        // -----------------------------------------------------------------
        // 2. Give the proposal milestone somewhere to record Lampiran C
        // -----------------------------------------------------------------
        Schema::table('milestones', function (Blueprint $table) {
            // The corrected title (Tajuk Baharu). Also written to the project
            // when it differs, because the project title *is* the confirmed title.
            $table->string('lampiran_c_title', 255)->nullable();

            // The panel's comments against the action taken, as rows of
            // `{ comment, action }`.
            $table->json('lampiran_c_actions')->nullable();

            $table->timestamp('lampiran_c_at')->nullable();
            $table->foreignId('lampiran_c_by')->nullable()
                  ->after('lampiran_c_at')
                  ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Restore the agreement's review columns and remove Lampiran C.
     *
     * The status normalisation is not reversed: `pending_panel` and
     * `pending_corrections` are indistinguishable once collapsed into
     * `approved`, and rolling the code back is the correct direction anyway.
     */
    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lampiran_c_by');
            $table->dropColumn([
                'lampiran_c_title',
                'lampiran_c_actions',
                'lampiran_c_at',
            ]);
        });

        Schema::table('supervisor_agreements', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('panel_decision', 24)->nullable();
            $table->string('confirmed_title', 255)->nullable();
            $table->text('panel_reason')->nullable();
            $table->text('panel_comments')->nullable();
            $table->string('project_type', 64)->nullable();
            $table->string('project_area', 128)->nullable();
            $table->string('panel_1_name', 128)->nullable();
            $table->string('panel_2_name', 128)->nullable();
            $table->timestamp('panel_decided_at')->nullable();
            $table->foreignId('panel_decided_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->string('corrections_title', 255)->nullable();
            $table->json('corrections_actions')->nullable();
            $table->timestamp('corrections_at')->nullable();
            $table->foreignId('corrections_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable();
        });
    }
};
