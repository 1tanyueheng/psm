<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove co-supervision from the whole system.
 *
 * The decision is that a student has exactly one supervisor and that supervisor
 * is the primary one. Three things follow:
 *
 *   1. `SupervisionAssignment::creating()` now refuses any role other than
 *      'primary', so new co-supervisor rows cannot be written through the API,
 *      a console call or a seeder.
 *   2. Existing co-supervisor rows have to go. They are deleted rather than
 *      deactivated so `supervisions()` returns a single pairing per student.
 *   3. `supervisor_agreements.supervision_assignment_id` is a
 *      nullOnDelete() foreign key, so any agreement signed against a removed
 *      co-supervisor keeps its paper trail with a null link.
 *
 * The `role` column is intentionally left in place: it is part of the archived
 * record of past supervision and reads naturally as 'primary' everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        $removed = DB::table('supervision_assignments')
            ->where('role', '<>', 'primary')
            ->delete();

        if ($removed > 0) {
            $this->report("Removed {$removed} co-supervisor assignment(s).");
        }
    }

    public function down(): void
    {
        // Not reversible on purpose: the removed rows described a division of
        // responsibility that no longer exists, and guessing at their values
        // would fabricate supervision history. Restore from a backup instead.
        $this->report('Co-supervisor assignments were deleted, not archived. Restore from a backup to reverse.');
    }

    private function report(string $message): void
    {
        if (app()->runningInConsole()) {
            fwrite(STDOUT, $message.PHP_EOL);
        }
    }
};
