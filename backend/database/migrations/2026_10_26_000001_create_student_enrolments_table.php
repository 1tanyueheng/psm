<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per student, per PSM part, per semester.
 *
 * Until now a student's term lived on their *project*: `projects` carried
 * `academic_semester_id` and `psm_part`, and `student_profiles` carried no term
 * at all. "Is this student doing PSM 2 this semester?" could only be answered by
 * joining through a project, which meant the question could not be asked before
 * a project existed — that is, before the student had registered a title.
 *
 * That single gap caused three separate problems:
 *
 *  1. **Registration had nothing to attach to.** A repeat of PSM 1 returns the
 *     student to Lampiran A, and PSM 1 repeat was expressed as "there is no
 *     active project, so registration is allowed" — a rule that reads as an
 *     absence rather than a decision, and is why `assertStudentMayRegister()`
 *     had to be re-pointed at `->active()` when the archive module was removed.
 *  2. **A repeat had to guess its term.** PSM 2 repeat created a new project
 *     immediately, in `AcademicSemester::current()` or the next term after the
 *     failed one — and when neither resolved, the project was written with a
 *     null `academic_semester_id`. A repeat "completed" with no term behind it.
 *  3. **Attempts could not be counted.** Only repeats carried
 *     `metadata.repeat_number`; the first attempt carried nothing, so "how many
 *     attempts has this student had" meant walking a chain of
 *     `repeated_from_project_id` links.
 *
 * With this table the admin enrols a student into a PSM part for a semester,
 * which is the one thing they actually do at the start of every term, and it
 * covers first attempts, repeats and the PSM 1 → PSM 2 rollover with the same
 * verb. A repeat becomes "enrol them again next term"; the project follows from
 * registration as it always did.
 *
 * `project_id` is nullable because the enrolment comes first. It is unique so
 * two enrolments cannot point at one project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrolments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();

            /**
             * Nullable, and only for one case: **an opened repeat that has not
             * been placed into a term yet.**
             *
             * When the committee fails an attempt, the student becomes entitled
             * to another one immediately — but which term holds that attempt is
             * the admin's decision, made when the next cohort is registered. A
             * row with no term records "entitled to repeat" without pretending
             * to know when. Everything else is created through
             * `EnrolmentService::enrol()`, which requires a real term.
             */
            $table->foreignId('academic_semester_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('psm_part', 8);

            /**
             * The attempt number, counted per (student, part) — not per term.
             *
             * Attempt 1 is the first ever go at that part, whatever term it fell
             * in. A student who does PSM 2, fails, and repeats has attempts 1 and
             * 2 even if a year passed between them.
             */
            $table->unsignedSmallInteger('attempt_no')->default(1);

            $table->string('status', 16)->default('enrolling');

            /** Attached once registration produces the project. */
            $table->foreignId('project_id')->nullable()->unique()->constrained()->nullOnDelete();

            /**
             * Where this attempt came from, when it is a repeat.
             *
             * Kept as a plain reference rather than derived by looking for the
             * previous attempt: a student may withdraw for a term and come back,
             * so attempt N-1 is not always the thing being repeated.
             */
            $table->foreignId('repeats_enrolment_id')->nullable()
                ->constrained('student_enrolments')->nullOnDelete();

            $table->dateTime('enrolled_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /**
             * A student may hold only one enrolment per part per term.
             *
             * Partial on `academic_semester_id IS NOT NULL`, because unplaced
             * repeats carry no term — several students may each have one
             * waiting, and a plain unique index would treat all those nulls as
             * one another (or, depending on the engine, collide outright).
             *
             * Not partial on live status: an attempt is settled in the term it
             * happened in, so there is never a legitimate reason for a second row
             * in the same term. A repeat is always a *later* term.
             */
            $table->index(['academic_semester_id', 'psm_part', 'status']);
        });

        /**
         * The uniqueness has to be a partial index rather than `$table->unique()`,
         * since Laravel's helper cannot express a WHERE clause. Two unplaced
         * repeats for the same student and part are still forbidden — that is a
         * genuine conflict — so the partial index is split in two.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enrolment_one_per_part_per_term
                ON student_enrolments (student_profile_id, academic_semester_id, psm_part)
                WHERE academic_semester_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enrolment_one_unplaced_repeat_per_part
                ON student_enrolments (student_profile_id, psm_part)
                WHERE academic_semester_id IS NULL
        SQL);

        /**
         * Backfill: every existing project becomes one enrolment.
         *
         * Attempt 1 for all of them — the chain of repeats that predates this
         * table is rare enough to correct by hand, and inventing attempt numbers
         * from `metadata.repeat_number` would need the links to be intact, which
         * is exactly the fragility this table removes. Status follows the
         * project: a completed project is a completed attempt, a failed one is a
         * failed attempt, anything still running is active.
         */
        DB::statement(<<<'SQL'
            INSERT INTO student_enrolments (
                student_profile_id, academic_semester_id, psm_part, attempt_no,
                status, project_id, enrolled_at, settled_at, created_at, updated_at
            )
            SELECT
                pm.student_profile_id,
                p.academic_semester_id,
                p.psm_part,
                1,
                CASE p.status
                    WHEN 'completed' THEN 'completed'
                    WHEN 'failed'    THEN 'failed'
                    ELSE 'active'
                END,
                p.id,
                p.created_at,
                CASE p.status
                    WHEN 'completed' THEN (p.metadata ->> 'resolved_at')::timestamptz
                    WHEN 'failed'    THEN (p.metadata ->> 'resolved_at')::timestamptz
                    ELSE NULL
                END,
                NOW(),
                NOW()
            FROM projects p
            JOIN project_members pm ON pm.project_id = p.id
            WHERE p.academic_semester_id IS NOT NULL
              -- Only the leader holds the enrolment: the attempt is the
              -- student's, and a second member on a shared project would
              -- otherwise produce a duplicate attempt count for one piece of
              -- work. Membership beyond the leader is a supervision matter.
              AND pm.is_leader = true
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrolments');
    }
};
