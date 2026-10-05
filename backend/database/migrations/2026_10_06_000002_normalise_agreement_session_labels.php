<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise `supervisor_agreements.session` to the session string.
 *
 * The column was being stamped with the term's *display name*
 * ("2025/2026 Semester II") while everything else in the system keys on the
 * session string ("2025/2026") — `projects.academic_session`,
 * `title_defence_sessions.academic_session`, and the `(psm_part,
 * academic_session)` bucket `Project::nextCode()` counts within.
 *
 * The consequence was not cosmetic. A Lampiran B filed in a term that already
 * held projects counted zero existing codes, so `nextCode()` returned 001 and
 * the insert died on the unique key:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1062
 *   Duplicate entry 'PSM1-2025-CS2-001' for key 'projects.projects_code_unique'
 *
 * `RegistrationService::submitAgreement()` now stamps the session string, and
 * this migration brings existing rows into line. Rows whose `session` already
 * is a session string match no semester *name*, so the update skips them and the
 * migration is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $semesters = DB::table('academic_semesters')
            ->select('name', 'academic_session')
            ->get();

        foreach ($semesters as $semester) {
            if ($semester->name === $semester->academic_session) {
                continue;
            }

            // Matches only rows still carrying the display name, which is what
            // makes this idempotent: once rewritten, the value no longer equals
            // any term's name.
            DB::table('supervisor_agreements')
                ->where('session', $semester->name)
                ->update(['session' => $semester->academic_session]);
        }
    }

    /**
     * Not reversible.
     *
     * Two terms share one session string — "2025/2026 Semester I" and
     * "2025/2026 Semester II" both collapse to "2025/2026" — so the display name
     * cannot be recovered from the normalised value. Rolling back the code is the
     * only way to revert, and that is the correct direction anyway: the session
     * string is the canonical label.
     */
    public function down(): void
    {
        // Deliberately empty.
    }
};
