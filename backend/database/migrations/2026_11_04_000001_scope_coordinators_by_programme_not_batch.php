<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinator scope loses `batch` and gains a real meaning for `is_primary`.
 *
 * **`batch` was a scope filter that acted as a permission.** A coordinator with
 * `batch = 2026` could see *only* 2026 projects, on every screen, with no way to
 * widen it — there was no screen that could edit a scope, so a wrong value was
 * permanent. It also duplicated something the report screen already offers
 * (`?batch=2026` narrows a report without restricting who may look). Removed
 * 2026-11-04 at the user's instruction: **a coordinator is limited by programme,
 * not by intake year.**
 *
 * **`is_primary` has been in this table since the beginning and nothing ever read
 * it.** It now means what it should have meant: *this coordinator heads the
 * cohort and is not limited by programme.* A head sees every programme; everybody
 * else sees the programmes on their scope rows.
 *
 * The three cases after this migration:
 *
 *   is_primary = true          -> sees every programme
 *   scope rows with a program  -> sees only those programmes
 *   a coordinator with none    -> sees every programme
 *
 * **That last line is the one this migration deliberately leaves alone.** The
 * empty case has always meant "no restriction" and it is what makes an
 * administrator who forgets to scope somebody grant too much rather than lock
 * them out. Changing it here would silently strip access from every coordinator
 * created through a UI that has no scope field. It is recorded as an open issue
 * instead — see PROGRESS.md — because fixing it properly needs the scope editor
 * that does not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = DB::table('coordinator_scopes')->whereNotNull('batch')->count();

        if ($cleared > 0) {
            // Named in the log rather than silently dropped: these rows *were*
            // restricting who could see what, and after this they do not.
            Log::info("Dropping coordinator_scopes.batch: clearing {$cleared} row(s) that were restricting visibility by intake year.");

            DB::table('coordinator_scopes')->update(['batch' => null]);
        }

        Schema::table('coordinator_scopes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'batch']);
        });

        Schema::table('coordinator_scopes', function (Blueprint $table) {
            $table->dropColumn('batch');
            $table->index('user_id');
        });
    }

    /**
     * Restores the column, not the data.
     *
     * A rollback cannot know which batch a coordinator "would have" been scoped
     * to — the values were cleared because they were wrong, not because they were
     * unknown. The column comes back nullable and empty, which fails open (no
     * restriction) exactly as an unset scope always has.
     */
    public function down(): void
    {
        Schema::table('coordinator_scopes', function (Blueprint $table) {
            $table->string('batch', 32)->nullable()->after('user_id');
        });

        Schema::table('coordinator_scopes', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->index(['user_id', 'batch']);
        });
    }
};
