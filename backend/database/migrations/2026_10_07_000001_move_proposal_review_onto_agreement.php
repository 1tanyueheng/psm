<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold the title defence into the proposal.
 *
 * The defence was a separate event with its own tables, its own sitting to
 * schedule, its own roster, and its own sheet. That duplicated the proposal:
 * both asked the panel to judge the same three candidate titles, and the student
 * had to be registered before they could be defended.
 *
 * The review now happens **on the proposal** (Lampiran A), by the same two
 * examiners who would have sat the defence, and the panel's decision is final —
 * the coordinator's separate approval step goes with it.
 *
 *   before:  Lampiran A → supervisor → JKPSM approves → title defence → Lampiran B
 *   after:   Lampiran A → supervisor → panel reviews → Lampiran B
 *
 * The columns the defence sheet carried move onto `supervisor_agreements`
 * rather than being dropped, because the panel still records them: the accepted
 * title, the reason, project type and area, comments, and the two panel names.
 *
 * `title_defences` and `title_defence_sessions` are dropped, and so are the
 * migrations that created and altered them — a fresh install should not build
 * tables for a module that no longer exists. The rows in `migrations` for those
 * two deleted files are removed as well, so the history does not list migrations
 * that are not on disk.
 */
return new class extends Migration
{
    /** Migrations whose files were deleted with the module. */
    protected const REMOVED_MIGRATIONS = [
        '2026_10_03_000002_create_title_defence_tables',
        '2026_10_06_000001_rebase_title_defence_on_agreement',
    ];

    public function up(): void
    {
        Schema::table('supervisor_agreements', function (Blueprint $table) {
            // How many times the proposal has been submitted. A rejection sends
            // the student back with another candidate title, and the count keeps
            // that history legible without a second table.
            $table->unsignedTinyInteger('attempt')->default(1);

            // --- The panel's review of the proposal ----------------------
            // `approved | conditional_approve | rejected`. Null until a panel
            // sits; a proposal awaiting review is `pending_panel`.
            $table->string('panel_decision', 24)->nullable();

            // The title the panel settled on — the panel's accepted title when
            // it changed one, the corrected title once Lampiran C is accepted,
            // and otherwise the supervisor's agreed title. This is what
            // Lampiran B registers.
            $table->string('confirmed_title', 255)->nullable();

            $table->text('panel_reason')->nullable();      // why, for a non-approval
            $table->text('panel_comments')->nullable();    // the panel's notes
            $table->string('project_type', 64)->nullable();
            $table->string('project_area', 128)->nullable();
            $table->string('panel_1_name', 128)->nullable();
            $table->string('panel_2_name', 128)->nullable();
            $table->timestamp('panel_decided_at')->nullable();
            $table->foreignId('panel_decided_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            // --- Lampiran C — the conditional branch ---------------------
            $table->string('corrections_title', 255)->nullable();
            $table->json('corrections_actions')->nullable();
            $table->timestamp('corrections_at')->nullable();
            $table->foreignId('corrections_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            // --- Re-proposal after a rejection ---------------------------
            $table->timestamp('resubmitted_at')->nullable();
        });

        // The module's tables. Children first: title_defences holds the foreign
        // keys. Guarded because a fresh install never creates them — the
        // migrations that did have been deleted.
        Schema::dropIfExists('title_defences');
        Schema::dropIfExists('title_defence_sessions');

        // Drop the history rows for the two deleted migration files, so
        // `migrate:status` does not list migrations that are not on disk.
        DB::table('migrations')->whereIn('migration', self::REMOVED_MIGRATIONS)->delete();
    }

    /**
     * Only the added columns can be reversed.
     *
     * The dropped tables cannot be restored here: their create migration is gone
     * with the module, so there is no definition to rebuild them from. Restoring
     * them means restoring the files and rolling back past this point.
     */
    public function down(): void
    {
        Schema::table('supervisor_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('panel_decided_by');
            $table->dropConstrainedForeignId('corrections_by');
            $table->dropColumn([
                'attempt',
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
                'resubmitted_at',
            ]);
        });
    }
};
