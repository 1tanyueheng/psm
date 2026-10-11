<?php

use App\Models\Milestone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Realign deadlines that were computed before their milestone actually opened.
 *
 * A milestone's `due_at` is set once, at registration, as "planned open +
 * duration" (see MilestoneService::instantiateFor). But the chain is
 * sequential: a milestone opens when its predecessor is approved, and when that
 * runs late the milestone opens late while keeping the deadline it was born
 * with. `activateNext()` stamped `opens_at` and left `due_at` behind, so the
 * milestone opened already past its own deadline — 49 of 134 rows on the
 * development database, and the nightly job flagged them overdue on day one.
 *
 * The same defect has a milder form that is easier to miss: a milestone that
 * opens late but still *before* its planned deadline keeps that deadline, so its
 * window is silently truncated — a 28-day window becomes 14. Nothing on any
 * screen says so, and the student is simply given less time than the template
 * promises. Both forms are the same mistake, so this migration repairs both.
 *
 * The service is fixed to move the deadline when it stamps the open date. This
 * migration repairs the rows that were already written, using the same rule:
 * preserve the *window* (`actual open + template duration`), because the window
 * is what the template promises and what the student is assessed against.
 *
 * Deliberately conservative, and identical to `Milestone::repairedDueAt()`:
 *
 *   - only ever pushes a deadline out, never pulls it in;
 *   - skips any row a coordinator has overridden, or that carries an extension,
 *     since both are deliberate human decisions this must not undo;
 *   - skips rows whose template item has no recorded duration.
 *
 * Rows already consistent with their open date are left untouched, so running
 * this on a healthy database changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repaired = 0;

        /**
         * Chunked, and ordered by id, so a large cohort does not load every
         * milestone at once. The model is used rather than raw SQL because the
         * rule lives on `repairedDueAt()` — duplicating the arithmetic here
         * would give the migration and the running system two answers.
         *
         * Note what is *not* filtered: there is no `opens_at > due_at`
         * predicate. That was the obvious way to find the 49 that had already
         * opened overdue, but it misses the same defect in its milder form — a
         * milestone that opened late yet still before its planned deadline
         * keeps that deadline, so its window is silently truncated (28 days
         * becomes 14). Both are "the deadline was computed from the planned
         * open date, not the actual one", so both belong to this repair, and
         * the model decides each one. Filtering here would have made the
         * migration narrower than the rule it claims to apply.
         */
        Milestone::query()
            ->withTrashed()
            ->whereNotNull('opens_at')
            ->whereNotNull('due_at')
            ->whereNull('deadline_overridden_by')
            ->whereNull('extended_until')
            ->with('templateItem')
            ->chunkById(200, function ($milestones) use (&$repaired) {
                foreach ($milestones as $milestone) {
                    $repairedDueAt = $milestone->repairedDueAt();

                    if ($repairedDueAt === null || $repairedDueAt->equalTo($milestone->due_at)) {
                        continue;
                    }

                    // Written through the query builder, not `save()`, so this
                    // does not touch `updated_at` or fire model events: a data
                    // repair should not look like someone edited every row.
                    DB::table('milestones')
                        ->where('id', $milestone->id)
                        ->update(['due_at' => $repairedDueAt->toDateString()]);

                    $repaired++;
                }
            });

        if ($repaired > 0) {
            echo "  Realigned {$repaired} milestone deadline(s) to their actual open date.\n";
        }
    }

    /**
     * No down migration.
     *
     * The previous `due_at` values were the bug: they described a window that
     * had already closed when the milestone opened. Restoring them would put the
     * database back into the state this migration exists to correct, so there is
     * nothing here that is worth undoing.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
