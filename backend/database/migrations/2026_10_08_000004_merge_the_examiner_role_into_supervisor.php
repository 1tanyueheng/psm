<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge the `examiner` role into `supervisor`.
 *
 * Being an examiner is a **seating**, not a job. A member of academic staff
 * supervises their own students and may additionally be appointed to the panel
 * of someone else's — and `examiner_assignments` already models exactly that
 * (student, project, panel role, pair). A separate role duplicated it and then
 * had to be reconciled with it:
 *
 *  - the panel pool had to accept `examiner` *or* `supervisor`, so the two
 *    "roles" were already interchangeable for the only thing the distinction
 *    was meant to gate;
 *  - a supervisor appointed to a panel could not read the project they were
 *    appointed to examine, because the visibility rules branched on the role.
 *
 * One staff role, `supervisor`, now covers both. `AssessorType` still separates
 * the *forms* — a person fills the supervisor's form for their own student and
 * the examiner's form for a panel student — which is a property of the
 * evaluation, not of the account.
 *
 * Existing examiner rows become supervisors. Their profiles were seeded as pure
 * examiners (`max_supervisees = 0`, `is_accepting_students = false`), which
 * after the merge would mean they could never take a student at all, so those
 * are given the configured default capacity. A coordinator can still adjust any
 * individual afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Capture the ids first: once the role is rewritten they are
        // indistinguishable from any other supervisor.
        $examinerIds = DB::table('users')->where('role', 'examiner')->pluck('id');

        if ($examinerIds->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $examinerIds)->update(['role' => 'supervisor']);

        // Only rows still sitting at zero — a profile that already carried a
        // real capacity was not a "pure examiner" and is left alone.
        DB::table('supervisor_profiles')
            ->whereIn('user_id', $examinerIds)
            ->where('max_supervisees', 0)
            ->update([
                'max_supervisees'       => (int) config('psm.supervisor_max_capacity', 8),
                'max_supervisees_psm1'  => (int) config('psm.supervisor_capacity.PSM1', 5),
                'max_supervisees_psm2'  => (int) config('psm.supervisor_capacity.PSM2', 5),
                'is_accepting_students' => true,
            ]);
    }

    /**
     * Not reversible.
     *
     * Nothing recorded *which* supervisors had previously been examiners, so the
     * distinction cannot be reconstructed — and it should not be. Rolling the
     * code back is the correct direction: the role no longer exists, and the
     * seating is still fully described by `examiner_assignments`.
     */
    public function down(): void
    {
        // Deliberately empty.
    }
};
