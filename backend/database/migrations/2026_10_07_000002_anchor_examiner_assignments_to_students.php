<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor examiner allocations to the student.
 *
 * The panel is allocated against the **student**, not the project: the same two
 * examiners review the proposal before any project exists and then give the
 * final mark. So `student_profile_id` has to exist from the moment a pair is
 * seated, and `project_id` has to be nullable until Lampiran B creates the
 * project the allocation is finally stamped onto.
 *
 * This was previously half of `2026_10_06_000001_rebase_title_defence_on_agreement`,
 * which was deleted along with the title defence module. The change is not part
 * of that module — it is what lets a panel exist at all before registration — so
 * it is kept here on its own.
 *
 * Guarded, because a database migrated before the module was removed already has
 * the column: there it must be a no-op rather than a duplicate-column error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('examiner_assignments', 'student_profile_id')) {
            return;
        }

        // InnoDB will not modify a column that sits inside a foreign key, so the
        // project key is dropped, the column made nullable, and the key restored.
        $this->dropForeignIfExists('examiner_assignments', 'examiner_assignments_project_id_foreign');

        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });

        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                  ->nullable()
                  ->after('project_id')
                  ->constrained('student_profiles')
                  ->nullOnDelete();
        });

        // One allocation per examiner per student per batch. The old
        // (project, examiner, part) key still covers the project-scoped rows;
        // NULL project ids are distinct under it, so student-only rows do not
        // collide.
        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->unique(
                ['student_profile_id', 'examiner_id', 'psm_part'],
                'examiner_unique_per_student'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('examiner_assignments', 'student_profile_id')) {
            return;
        }

        $this->dropForeignIfExists('examiner_assignments', 'examiner_assignments_student_profile_id_foreign');

        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->dropUnique('examiner_unique_per_student');
        });

        Schema::table('examiner_assignments', function (Blueprint $table) {
            $table->dropColumn('student_profile_id');
        });
    }

    /**
     * Drop a foreign key only when it is there.
     *
     * Portability matters here: the project supports both MySQL and PostgreSQL —
     * `docs/DEPLOYMENT.md` states the code contains no engine-specific SQL — and
     * this method previously broke that in two ways. It asked
     * `information_schema.KEY_COLUMN_USAGE ... WHERE TABLE_SCHEMA = DATABASE()`,
     * and `DATABASE()` exists in MySQL but not in PostgreSQL:
     *
     *   SQLSTATE[42883]: Undefined function: function database() does not exist
     *
     * It then dropped the key with a backtick-quoted `ALTER TABLE`, which
     * PostgreSQL also rejects. Neither syntax is reachable by the MySQL-and-
     * PostgreSQL test matrix, so the failure only appeared on a Postgres deploy
     * — and because `migrate` runs after the schema has already been dropped, the
     * database was left without tables, which is what made every sign-in fail.
     *
     * The existence check is now per driver, and the drop uses the schema builder
     * so each engine quotes the identifier its own way.
     */
    protected function dropForeignIfExists(string $table, string $constraint): void
    {
        if (! $this->foreignKeyExists($table, $constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($constraint) {
            $blueprint->dropForeign($constraint);
        });
    }

    /** Is this named foreign key present, on either supported engine? */
    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Postgres keeps constraints in pg_constraint, keyed by relation
            // name rather than by the schema-qualified name MySQL reports.
            return DB::selectOne(
                'SELECT 1 AS found
                   FROM pg_constraint c
                   JOIN pg_class t ON t.oid = c.conrelid
                  WHERE t.relname = ?
                    AND c.conname = ?
                    AND c.contype = \'f\'
                  LIMIT 1',
                [$table, $constraint]
            ) !== null;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return DB::selectOne(
                'SELECT 1 AS found FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND CONSTRAINT_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                  LIMIT 1',
                [$table, $constraint]
            ) !== null;
        }

        // SQLite has no named constraints to drop, and the guard above the call
        // site already makes this a no-op where the column is present.
        return false;
    }
};
