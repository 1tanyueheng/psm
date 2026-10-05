<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 — `academic_semesters`, the entity that owns both PSM batches.
 *
 * FSKTM runs PSM 1 and PSM 2 *concurrently* out of the same pool of staff, so
 * "2025/2026 Semester I" is not a cohort — it contains two cohorts. Until now
 * the only thing that separated them was `projects.psm_part`, and every
 * session-scoped query (`academic_session = ?`) swept up both batches at once.
 * The three failures that produced, all of which this table fixes:
 *
 *   - one title-defence sitting could pull PSM 2 students into a PSM 1 roster;
 *   - releasing grades flipped every batch in the session at once;
 *   - a supervisor read as "8/8 — full" while each batch individually had room.
 *
 * `academic_session` is kept on every table that had it. It is denormalised, but
 * removing it would mean a join on every cohort filter and every report, and it
 * is what the existing indexes are built on.
 *
 * Everything here is additive and nullable. Requirement §2 puts historical
 * migration out of scope — existing PSM2-only data stays as-is — so the
 * backfills below attach legacy rows to a synthesised Semester I rather than
 * moving them, and a row with no resolvable semester stays NULL instead of
 * being guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_semesters', function (Blueprint $table) {
            $table->id();

            // Human label: "2025/2026 Semester I". Kept as its own column rather
            // than derived, because the faculty does not always name a term the
            // way the session/number pair would suggest.
            $table->string('name', 64);

            $table->string('academic_session', 32)->index();
            $table->unsignedTinyInteger('semester_number');   // 1 | 2

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->boolean('is_active')->default(true)->index();

            // Gates Lampiran A submission for every student in this term.
            $table->boolean('is_registration_open')->default(false);

            // Release is a property of the *term*, not of the session: flipping
            // PSM 1's grades must not publish PSM 2's results in the same term.
            $table->boolean('is_grades_released')->default(false);
            $table->timestamp('grades_released_at')->nullable();

            $table->timestamp('registration_opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // e.g. { "coordinator_id": 7 }
            $table->json('metadata')->nullable();

            $table->timestamps();

            // One term per session+number. Without this a coordinator can create
            // "2025/2026 Semester I" twice and every join below fans out.
            $table->unique(
                ['academic_session', 'semester_number'],
                'academic_semester_session_term_unique'
            );

            $table->index(['is_active', 'starts_at'], 'academic_semester_active_idx');
        });

        $this->addSemesterColumns();
        $this->backfillSemesterColumns();
        $this->scopeExaminerPairUniqueness();
    }

    /**
     * Requirement §3.2/3.3/3.5/3.7 — the four tables that gain the FK.
     *
     * `supervision_assignments` and `milestone_templates` are deliberately NOT
     * here. Both already carry `psm_part`, and both reach a term indirectly:
     * an assignment through its student's project, a template through the
     * project it resolves against. Adding a third copy of the same fact to each
     * would mean the same value has to be kept consistent in three places.
     */
    private function addSemesterColumns(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('academic_semester_id')
                  ->nullable()->after('academic_session')
                  ->constrained('academic_semesters')->nullOnDelete();

            $table->index(['academic_semester_id', 'psm_part'], 'projects_semester_part_idx');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('academic_semester_id')
                  ->nullable()->after('current_semester')
                  ->constrained('academic_semesters')->nullOnDelete();
        });

        // `title_defence_sessions` used to be stamped with a term here. The
        // title defence module was removed — the panel now reviews the proposal
        // itself — and the table with it, so there is nothing to scope.

        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->foreignId('academic_semester_id')
                  ->nullable()->after('psm_part')
                  ->constrained('academic_semesters')->nullOnDelete();
        });
    }

    /**
     * Backfill legacy rows onto a synthesised "Semester I" per session.
     *
     * The faculty ran a single batch per session before this change, so each
     * distinct `academic_session` becomes one Semester I. Term dates come from
     * the session's opening year on the assumption that PSM ran in the
     * September–February window; a session that does not parse as `YYYY/YYYY`
     * gets NULL dates, which is recoverable by hand and better than a wrong
     * date silently driving the registration gate.
     */
    private function backfillSemesterColumns(): void
    {
        $sessions = DB::table('projects')
            ->distinct()
            ->orderBy('academic_session')
            ->pluck('academic_session')
            ->filter()
            ->unique()
            ->values();

        // Only the newest session becomes the active term.
        //
        // Marking every synthesised row active would be convenient for the
        // moment and wrong for good: `is_active` is what every "current term"
        // screen, the registration gate and the dashboard all read, and two
        // active terms means those screens silently disagree with each other.
        // The model is one active term, so the backfill has to respect it —
        // older sessions are history, which is what they are.
        $newestSession = $sessions->last();

        foreach ($sessions as $session) {
            [$startsAt, $endsAt] = $this->termDates($session);

            DB::table('academic_semesters')->insertOrIgnore([
                'name'              => $session . ' Semester I',
                'academic_session'  => $session,
                'semester_number'   => 1,
                'starts_at'         => $startsAt,
                'ends_at'           => $endsAt,
                'is_active'         => $session === $newestSession,
                'is_registration_open' => false,
                'is_grades_released'=> false,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        $sessionLookup = static function (?string $session) {
            if ($session === null) {
                return null;
            }

            return DB::table('academic_semesters')
                ->where('academic_session', $session)
                ->where('semester_number', 1)
                ->value('id');
        };

        // Projects match on their own denormalised session — exact, no guessing.
        foreach (DB::table('projects')->select('id', 'academic_session')->get() as $project) {
            $semesterId = $sessionLookup($project->academic_session);

            if ($semesterId !== null) {
                DB::table('projects')->where('id', $project->id)
                    ->update(['academic_semester_id' => $semesterId]);
            }
        }

        // A student's enrolment term is the term of their newest project, which
        // is a truer answer than "the only semester we just invented" — a
        // final-year student with no project at all stays NULL, and a part-way
        // student points at the term they are actually in.
        if (Schema::hasTable('project_members')) {
            $students = DB::table('project_members as pm')
                ->join('projects as p', 'p.id', '=', 'pm.project_id')
                ->select(
                    'pm.student_profile_id',
                    'p.academic_semester_id',
                    'pm.created_at',
                    'pm.id as membership_id'
                )
                ->orderBy('pm.id')
                ->get()
                ->sortByDesc('membership_id')   // newest membership wins
                ->unique('student_profile_id');

            foreach ($students as $row) {
                if ($row->academic_semester_id === null) {
                    continue;
                }

                DB::table('student_profiles')
                    ->where('id', $row->student_profile_id)
                    ->update(['academic_semester_id' => $row->academic_semester_id]);
            }
        }

        $this->backfillExaminerPairs();
    }

    /**
     * Attach each legacy panel to the term whose work it actually covers.
     *
     * Panels have no session column of their own, so the term has to be derived
     * from the projects sitting on the panel. This is why the earlier "dump them
     * all on the newest active term" answer was wrong: a panel that examined last
     * year's students would be handed this year's, and the two allocations would
     * then share a row that is supposed to mean one term's appointment decision.
     *
     * Resolution order:
     *
     *  1. the term covering the most of the panel's active allocations;
     *  2. the current term, for a panel that was created but never used — the
     *     legacy intent was "available now", so it stays available;
     *  3. left NULL when neither applies, for the coordinator to assign from the
     *     semester screen. `ExaminerPair::scopeForSemester()` excludes NULL panels
     *     deliberately, so an unattached panel stays visible but can never be
     *     allocated by accident.
     */
    private function backfillExaminerPairs(): void
    {
        $currentSemesterId = DB::table('academic_semesters')
            ->where('is_active', true)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->value('id');

        foreach (DB::table('examiner_pairs')->select('id')->get() as $pair) {
            $dominantSemesterId = DB::table('examiner_assignments as ea')
                ->join('projects as p', 'p.id', '=', 'ea.project_id')
                ->where('ea.examiner_pair_id', $pair->id)
                ->where('ea.is_active', true)
                ->whereNotNull('p.academic_semester_id')
                ->groupBy('p.academic_semester_id')
                ->orderByDesc(DB::raw('COUNT(DISTINCT ea.project_id)'))
                ->value('p.academic_semester_id');

            $semesterId = $dominantSemesterId ?? $currentSemesterId;

            if ($semesterId !== null) {
                DB::table('examiner_pairs')
                    ->where('id', $pair->id)
                    ->update(['academic_semester_id' => $semesterId]);
            }
        }
    }

    /**
     * Requirement §3.7 — the same two examiners are a *different* pair in
     * different terms.
     *
     * The old index keyed the pair on (part, examiner 1, examiner 2) alone, so
     * the faculty's own re-use of a panel in the next semester was rejected as
     * a duplicate. Adding the semester to the key permits it. Legacy rows left
     * with a NULL semester are unaffected: MySQL treats NULLs as distinct in a
     * unique index, so they do not collide with each other or with a
     * semester-scoped row.
     */
    private function scopeExaminerPairUniqueness(): void
    {
        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->dropUnique('examiner_pair_unique');
        });

        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->unique(
                ['psm_part', 'academic_semester_id', 'examiner_1_id', 'examiner_2_id'],
                'examiner_pair_unique'
            );
        });
    }

    /**
     * September–February for Semester I, February–August for Semester II.
     *
     * Only the start year is parsed, so "2025/2026" → 1 Sep 2025 – 28 Feb 2026.
     * @return array{0: ?string, 1: ?string}
     */
    private function termDates(string $session): array
    {
        if (! preg_match('/^(\d{4})/', trim($session), $matches)) {
            return [null, null];
        }

        $year = (int) $matches[1];

        return [$year . '-09-01', ($year + 1) . '-02-28'];
    }

    public function down(): void
    {
        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->dropUnique('examiner_pair_unique');
        });

        // The foreign key has to go before the column, not with it. Dropping the
        // column alone leaves SQLite (and MySQL with foreign key checks on)
        // holding a constraint that references a column which no longer exists,
        // and the rollback dies instead of undoing.
        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_semester_id');
        });

        // Restored only once the term column is gone: the pre-change index
        // keyed on (part, examiner 1, examiner 2) alone.
        Schema::table('examiner_pairs', function (Blueprint $table) {
            $table->unique(
                ['psm_part', 'examiner_1_id', 'examiner_2_id'],
                'examiner_pair_unique'
            );
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_semester_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_semester_part_idx');
            $table->dropConstrainedForeignId('academic_semester_id');
        });

        Schema::dropIfExists('academic_semesters');
    }
};