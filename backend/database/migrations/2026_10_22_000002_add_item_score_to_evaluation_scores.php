<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — record the 0-5 item score alongside the weighted mark.
 *
 * The official Lampiran forms score every item on a 0-5 scale and then weight
 * it: `weighted = (score / 5) x weight`. The system stores `marks_awarded` on
 * that weighted scale (Lampiran J's criterion A1 is worth 1.45 of its 40), which
 * is correct for the grade arithmetic but means the 0-5 score the marker
 * actually chose was being thrown away — the printed sheet could only be
 * reproduced by dividing back out, and a reprint after a rubric change would
 * show a number nobody typed.
 *
 * `item_score` keeps the marker's own 0-5 answer, so:
 *
 *   - the R1/Lampiran sheet can be reprinted exactly as the marker filled it;
 *   - the conversion is auditable (score 4 of 5 on a 1.45 weight is 1.16);
 *   - a future re-weight of the rubric does not silently change what the marker
 *     is on record as having judged.
 *
 * NULL means the row predates the 0-5 entry screen: its `marks_awarded` was
 * typed directly on the weighted scale. Nothing is back-filled from those,
 * because the two cannot be told apart reliably — a stored 1.00 could be a 1.00
 * mark or the result of scoring 3 or 4 out of 5 against a 1.4x weight. Readers
 * must therefore treat NULL as "entered on the weighted scale" and show
 * `marks_awarded` alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_scores', function (Blueprint $table) {
            $table->decimal('item_score', 3, 2)
                  ->nullable()
                  ->after('marks_awarded')
                  ->comment('The 0-5 score the marker chose, before weighting');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_scores', function (Blueprint $table) {
            $table->dropColumn('item_score');
        });
    }
};
