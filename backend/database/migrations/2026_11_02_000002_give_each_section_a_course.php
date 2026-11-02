<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A section belongs to exactly one programme's course (2026-11-02).
 *
 * The service course runs twice: `BIC31602 - PROJEK SARJANA MUDA I` for BIP,
 * BIW, BIS and BIM, and `BIT34002 - PROJEK SARJANA MUDA I` for BIT alone. They
 * are different courses with different codes, different sections and different
 * lecturers — a BIT cohort has its own class and its own teacher, and nobody
 * teaches across the two.
 *
 * Until now a section was only a *code within a term* (`S1`, `S2`, …), which
 * carried no course at all. That was fine while there was one class of course
 * and became wrong the moment there were two: `S1` in the BIC group and `S1` in
 * the BIT group are different classes with different rosters, but the unique
 * index `(academic_semester_id, code)` treated them as the same row.
 *
 * **The key widens to `(term, course, code)`.** Every existing row is backfilled
 * to `BIC31602`, because every row that exists today was created under the
 * one-course assumption and its students are in the BIC group.
 *
 * Why the course lives here rather than on `student_profiles`: a student's
 * programme is already recorded (`student_profiles.programme`), and the course
 * is a **property of the class**, not of the student. Deriving it at read time
 * would make the roster depend on a join and would let a student's section and
 * their programme disagree silently. The section states the course; placement
 * checks the student against it.
 */
return new class extends Migration
{
    /** The course every pre-existing section belonged to. */
    private const BACKFILL = 'BIC31602';

    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            /**
             * The course code, e.g. `BIC31602` or `BIT34002`.
             *
             * Defaulted rather than nullable-under-backfill: a section with no
             * course is a class nobody can place a student into, and the whole
             * point of the column is that the question always has an answer.
             */
            $table->string('course_code', 16)->default(self::BACKFILL)->after('name');
        });

        // Existing rows pre-date the split, so they are all BIC.
        DB::table('sections')->whereNull('course_code')->update(['course_code' => self::BACKFILL]);

        Schema::table('sections', function (Blueprint $table) {
            // One S1 per course per term. Replaces the narrower index, which
            // forbids a BIT S1 existing in the same term as a BIC S1.
            $table->dropUnique('sections_semester_code_unique');
            $table->unique(
                ['academic_semester_id', 'course_code', 'code'],
                'sections_semester_course_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique('sections_semester_course_code_unique');
            $table->unique(['academic_semester_id', 'code'], 'sections_semester_code_unique');
        });

        // The rollback cannot restore what this migration destroyed: if a term
        // genuinely had a BIC S1 and a BIT S1, the narrower unique index above
        // fails on this database rather than merging two rosters. That is the
        // honest failure — the alternative is dropping students' marks.
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('course_code');
        });
    }
};
