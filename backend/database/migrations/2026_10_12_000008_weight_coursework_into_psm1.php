<?php

use App\Models\FinalGrade;
use App\Models\GradeScheme;
use App\Models\Project;
use App\Services\EvaluationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add coursework to PSM 1's weighting, and recompute the marks already stored.
 *
 * Two things have to change, and only doing one of them is the trap here.
 *
 * **1. The stored scheme weights.** A project's weights are *copied* into
 * `grade_schemes.weights` when the scheme is created — `GradeScheme::ensureFor()`
 * materialises them from config once, and the aggregate reads that column
 * thereafter, not the config. So editing `config/psm.php` alone would change
 * nothing at all for the eleven PSM 1 projects that already have a scheme: they
 * would keep weighting E 35 + I 30 and the coursework would be invisible. The
 * stored rows have to be rewritten.
 *
 * **2. The marks.** `final_grades` holds a computed number. Adding a 35% component
 * changes the weighted mean even when nothing else has, so a stored mark is now
 * stale and must be recomputed through the same service the application uses —
 * never by re-deriving the arithmetic here, which would be a second
 * implementation of the formula and the first place the two would drift.
 *
 * **Why this changes existing results.** The scale is already 0-100: the
 * aggregate is `(weightedTotal / weightSum) * 100`, so PSM 1's E+I were already
 * normalised from 65 up to 100. Coursework therefore does not fix a denominator,
 * it *re-weights the blend* — and a student whose coursework is weaker than their
 * project work will see their PSM 1 mark fall. That is arithmetically correct for
 * a 35% coursework share and is the approved intent, but it is a real change to
 * published results, which is why the recompute is explicit here rather than left
 * to happen whenever someone next opens a page.
 *
 * Grade *status* is preserved. A locked or released grade is recomputed and then
 * put back to the state it was in: this migration corrects arithmetic, it does
 * not unlock anything or publish anything that was withheld. The recompute runs
 * with no actor, so nothing is attributed to a person.
 *
 * `down()` restores the two-form weighting. It does **not** recompute the marks
 * back, because the coursework rows are still there and reversing would need the
 * same judgement again with less information; re-running `up()` is the way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $coursework = (string) config('psm.coursework.form_code', 'CW');
        $weight = (float) (config('psm.assessment_weights.PSM1')[$coursework] ?? 0.0);

        if ($weight <= 0.0) {
            return;
        }

        // --- 1. Rewrite the stored weights for every PSM 1 scheme ---------
        GradeScheme::query()
            ->whereHas('project', fn ($q) => $q->where('psm_part', 'PSM1'))
            ->each(function (GradeScheme $scheme) use ($coursework, $weight) {
                $weights = collect($scheme->weights ?? []);

                // Idempotent: a scheme that already carries the component is
                // left exactly as it is, so a re-run cannot double the weight.
                if ($weights->contains(fn ($w) => ($w['form_code'] ?? null) === $coursework)) {
                    return;
                }

                $weights->push(['form_code' => $coursework, 'weight' => $weight]);

                $scheme->forceFill(['weights' => $weights->values()->all()])->save();
            });

        // --- 2. Recompute every PSM 1 mark --------------------------------
        $evaluations = app(EvaluationService::class);

        $grades = FinalGrade::query()
            ->with('project')
            ->whereHas('project', fn ($q) => $q->where('psm_part', 'PSM1'))
            ->get();

        foreach ($grades as $grade) {
            $project = $grade->project;

            if ($project === null) {
                continue;
            }

            // Recorded before the recompute so the status can be restored
            // exactly — `syncFor()` and `computeFinalGrade()` may write a
            // status of their own, and a locked grade silently becoming
            // provisional would let an assessor edit a mark that was frozen.
            $wasStatus = $grade->status;
            $wasReleasedAt = $grade->released_at;
            $wasLockedAt = $grade->locked_at;
            $wasLockedBy = $grade->locked_by;

            // No actor: the system recomputed, nobody attested anything.
            $evaluations->computeFinalGrade($project, $grade->student_profile_id);

            DB::table('final_grades')
                ->where('id', $grade->id)
                ->update([
                    'status'      => $wasStatus,
                    'released_at' => $wasReleasedAt,
                    'locked_at'   => $wasLockedAt,
                    'locked_by'   => $wasLockedBy,
                ]);
        }
    }

    public function down(): void
    {
        $coursework = (string) config('psm.coursework.form_code', 'CW');

        GradeScheme::query()
            ->whereHas('project', fn ($q) => $q->where('psm_part', 'PSM1'))
            ->each(function (GradeScheme $scheme) use ($coursework) {
                $weights = collect($scheme->weights ?? [])
                    ->reject(fn ($w) => ($w['form_code'] ?? null) === $coursework)
                    ->values()
                    ->all();

                $scheme->forceFill(['weights' => $weights])->save();
            });
    }
};
