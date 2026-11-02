<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coursework moves from four items to three criteria (2026-11-02).
 *
 * The faculty's mark sheet for the service course assesses three domains —
 * affective, cognitive and psychomotor — at 8, 15 and 7 marks. The old split
 * (Quiz 5, Assignment-C 12, Assignment-P 9, Assignment-A 9) was a guess at what
 * "C", "P" and "A" stood for and it was wrong: `Assignment-C` was never a
 * cognitive assignment, it was the criterion itself, and there was never a
 * quiz.
 *
 * **The four old columns are kept, nullable, and nothing reads them.** A term
 * that has already been marked under the old split must keep its numbers: the
 * aggregate for that term was computed from them, and dropping the columns
 * would silently change a mark that has already been released to a student.
 * `LectureMark::components()` now declares only the three new keys, so the
 * aggregate, the breakdown and the validation all ignore the old four and a
 * term marked under them reads as "not keyed" rather than as a zero.
 *
 * That is the deliberate trade: a re-marked term is better than a wrong mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecturer_marks', function (Blueprint $table) {
            // In the criterion's own units, and nullable for the same reason the
            // old columns were: an unkeyed criterion and a criterion awarded
            // nothing are different facts, and a zero default would fail a
            // student whose marks have simply not been entered yet.
            $table->decimal('affective', 5, 2)->nullable()->after('assignment_a');
            $table->decimal('cognitive', 5, 2)->nullable()->after('affective');
            $table->decimal('psychomotor', 5, 2)->nullable()->after('cognitive');
        });
    }

    public function down(): void
    {
        Schema::table('lecturer_marks', function (Blueprint $table) {
            $table->dropColumn(['affective', 'cognitive', 'psychomotor']);
        });
    }
};
