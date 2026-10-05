<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp the term on the agreement, and on every project Lampiran B registers.
 *
 * `RegistrationService::submitTitleProposal()` never set
 * `projects.academic_semester_id`. Every other path does — `ProjectController::store()`
 * and `ProgressionService` both write it — so a project registered through
 * Lampiran B had a **null** term.
 *
 * The consequence was not cosmetic. Almost every screen scopes by term:
 * `ProjectController::index()` resolves an absent filter to the *current* term
 * and then applies `forSemester()`, which is `where('academic_semester_id', ?)`.
 * A null term matches no term, so the student's own dashboard showed no project
 * at all — the app looked empty to exactly the people the flow is for. It was
 * invisible until now because `ProjectSeeder` force-fills the column for the 22
 * demo projects, and those are the only ones anyone had looked at.
 *
 * The term is stored on the **agreement**, where it is unambiguous: the agreement
 * is filed against one specific term (`SemesterService::assertRegistrationOpen()`
 * returns it), whereas `session` is only the session string — and two terms share
 * one session string, so it cannot identify the term on its own. Lampiran B then
 * copies it onto the project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_agreements', function (Blueprint $table) {
            $table->foreignId('academic_semester_id')->nullable()
                  ->after('session')
                  ->constrained('academic_semesters')->nullOnDelete();
        });

        // -----------------------------------------------------------------
        // Backfill the agreements already on file
        // -----------------------------------------------------------------
        // Their term is recovered from the session string. Where two terms
        // share one — "2025/2026 Semester I" and "II" both collapse to
        // "2025/2026" — the active one is the term the agreement was filed for,
        // because registration only opens on the live term. Falling back to the
        // latest is a best guess for a term that has since closed.
        DB::table('supervisor_agreements')
            ->whereNull('academic_semester_id')
            ->get(['id', 'session'])
            ->each(function ($agreement) {
                $termId = DB::table('academic_semesters')
                    ->where('academic_session', $agreement->session)
                    ->orderByRaw('is_active DESC')
                    ->orderByDesc('starts_at')
                    ->orderByDesc('semester_number')
                    ->orderByDesc('id')
                    ->value('id');

                if ($termId !== null) {
                    DB::table('supervisor_agreements')
                        ->where('id', $agreement->id)
                        ->update(['academic_semester_id' => $termId]);
                }
            });

        // -----------------------------------------------------------------
        // Backfill the projects Lampiran B registered
        // -----------------------------------------------------------------
        DB::table('projects')
            ->whereNull('academic_semester_id')
            ->whereNotNull('agreement_id')
            ->get(['id', 'agreement_id'])
            ->each(function ($project) {
                $termId = DB::table('supervisor_agreements')
                    ->where('id', $project->agreement_id)
                    ->value('academic_semester_id');

                if ($termId !== null) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update(['academic_semester_id' => $termId]);
                }
            });
    }

    /**
     * Only the column is reversed. The backfilled term is left in place: it is
     * the correct value, and clearing it would put every project back to the
     * state where no screen could find it.
     */
    public function down(): void
    {
        Schema::table('supervisor_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_semester_id');
        });
    }
};
