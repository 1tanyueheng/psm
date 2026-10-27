<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a milestone repeat within a project, once per attempt.
 *
 * `milestones` carried `unique(['project_id', 'code'])`, which was true while
 * every milestone happened exactly once: one Lampiran A, one Lampiran B, one
 * proposal, one chapter. Lampiran C is inserted as an extra step but only ever
 * exists once, so it never met the constraint.
 *
 * A **re-presented title** breaks that assumption. The faculty's rule is that a
 * refused title goes back for a fresh Lampiran B and a fresh defence, in the same
 * semester, as many times as it takes (spec §3.2, R4). So a project can hold
 * several `proposal` milestones, and several `lampiran_b` records — and the
 * refused one must stay on the chain as the record of what was refused.
 *
 * The key becomes (project, code, attempt). `attempt` defaults to 1, so every
 * existing row keeps the identity it already had and the backfill changes
 * nothing.
 *
 * `attempt` is deliberately *not* the same as `sequence`: sequence is the
 * position in the chain and moves when a step is inserted, while attempt is how
 * many times this particular step has happened. Proposal #2 must keep attempt 2
 * even after Lampiran C is inserted before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt')->default(1)->after('code');
        });

        // Every existing row is attempt 1 — there are no repeated codes yet.
        DB::table('milestones')->update(['attempt' => 1]);

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropUnique('milestone_project_code_unique');
            $table->unique(['project_id', 'code', 'attempt'], 'milestone_project_code_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropUnique('milestone_project_code_attempt_unique');
            $table->unique(['project_id', 'code'], 'milestone_project_code_unique');
            $table->dropColumn('attempt');
        });
    }
};
