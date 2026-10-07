<?php

use App\Models\AcademicSemester;
use App\Models\AssessmentWindow;
use Illuminate\Database\Migrations\Migration;

/**
 * Provision the two assessment windows for every term that already exists.
 *
 * Marking is now fail-closed: a batch with no window is not open for marking.
 * Terms created before windows were provisioned automatically would therefore
 * have become silently unmarkable — every assessor's draft hidden and every
 * write refused — with nothing on screen explaining why.
 *
 * The windows are created `scheduled`, so this opens nothing. It only makes the
 * "marking has not been opened" state explicit and actionable, which is what the
 * coordinator's screen needs in order to offer "Open for marking".
 *
 * Existing windows are left untouched: a term whose marking is deliberately
 * open or closed keeps that state, and re-running this is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parts = ['PSM1' => 'PSM 1', 'PSM2' => 'PSM 2'];

        AcademicSemester::query()->each(function (AcademicSemester $semester) use ($parts) {
            foreach ($parts as $value => $label) {
                AssessmentWindow::firstOrCreate(
                    [
                        'academic_semester_id' => $semester->id,
                        'psm_part'             => $value,
                    ],
                    [
                        'name'             => $label,
                        'academic_session' => $semester->academic_session,
                        'status'           => AssessmentWindow::STATUS_SCHEDULED,
                    ],
                );
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo. The rows this creates are indistinguishable from ones
        // a coordinator created, and deleting them would destroy real windows.
    }
};
