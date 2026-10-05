<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 — supervision capacity, split per PSM part.
 *
 * The single `max_supervisees` column is the reason the coordinator's
 * allocation screen could not staff two concurrent batches. A supervisor
 * holding 4 PSM 1 and 3 PSM 2 students had seven pieces of work in front of
 * them and read as "7/8", one short of the ceiling — yet there was no way to
 * offer them the eighth, because the remaining PSM 2 students had nowhere else
 * to go either. Worse, the flat number hid which batch was actually full.
 *
 * The fix is a cap per part rather than one shared budget, so the workload is
 * bounded *per batch* and a supervisor can carry a full PSM 2 list and still
 * take on PSM 1 students, or the reverse.
 *
 * `max_supervisees` is kept and backfilled as the **sum** of the two per-part
 * caps rather than being left at whatever it happened to be. It is still read
 * by Module 5's workload report and by the supervisor profile screen, and
 * leaving it at 8 against per-part caps of 5 + 5 would report a permanently
 * overloaded supervisor. Any coordinator who deliberately set a flat ceiling
 * above the sum of the parts keeps it; anyone left at the default gets the sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_supervisees_psm1')
                  ->nullable()->after('max_supervisees')
                  ->comment('Per-part ceiling for PSM 1; null falls back to config/psm.php');

            $table->unsignedSmallInteger('max_supervisees_psm2')
                  ->nullable()->after('max_supervisees_psm1')
                  ->comment('Per-part ceiling for PSM 2; null falls back to config/psm.php');
        });

        $psm1 = (int) config('psm.supervisor_capacity.PSM1', 5);
        $psm2 = (int) config('psm.supervisor_capacity.PSM2', 5);

        // Snapshot the effective caps onto every profile so an existing
        // coordinator's overrides survive the switch and the new columns are not
        // left reading config at three different values over time.
        DB::table('supervisor_profiles')->update([
            'max_supervisees_psm1' => $psm1,
            'max_supervisees_psm2' => $psm2,
        ]);

        // Only rows still sitting at the schema default get the new aggregate,
        // which keeps a deliberately lowered ceiling intact.
        DB::table('supervisor_profiles')
            ->where('max_supervisees', 8)
            ->update(['max_supervisees' => $psm1 + $psm2]);
    }

    public function down(): void
    {
        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->dropColumn(['max_supervisees_psm1', 'max_supervisees_psm2']);
        });
    }
};