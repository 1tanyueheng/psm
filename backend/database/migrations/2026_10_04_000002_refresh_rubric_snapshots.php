<?php

use App\Models\Evaluation;
use App\Models\RubricTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-freeze every evaluation's rubric snapshot from its current template.
 *
 * `expand_rubrics_by_project_category` split each form back into a system and a
 * research variant and repointed every evaluation at the right one — but the
 * frozen `rubric_snapshot` on those evaluations still held the old shared
 * rubric. So a *research* project's marking form was showing the Development
 * wording ("C — Prototype / Research Framework", criteria "Analysis &
 * Specifications / User Interface / Prototype") while the rubric page showed
 * the Research variant ("C(ii) — Penilaian Kerangka Kajian", criteria
 * "Tools / Data / Case Studies / …"). The mark was therefore being entered
 * against the wrong item set.
 *
 * This migration refreshes the snapshot so the marking form follows the rubric.
 *
 * Safety: a snapshot is refreshed **only** when its shape — the component
 * codes, their weights, and each criterion code and max mark — is identical to
 * the template's. In that case only titles and descriptions can differ, so no
 * awarded mark changes meaning. Anything whose shape differs is left untouched
 * and counted, rather than silently re-pointed at criteria that never scored it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $refreshed = 0;
        $skipped = 0;

        foreach (Evaluation::with('rubricTemplate')->cursor() as $evaluation) {
            $template = $evaluation->rubricTemplate;

            if ($template === null) {
                $skipped++;
                continue;
            }

            $current = $evaluation->rubric_snapshot;
            $fresh = $template->snapshot();

            if (! $this->sameShape($current, $fresh)) {
                $skipped++;
                continue;
            }

            DB::table('evaluations')
                ->where('id', $evaluation->id)
                ->update(['rubric_snapshot' => json_encode($fresh)]);

            $refreshed++;
        }

        echo "  rubric snapshots refreshed: {$refreshed}, skipped (shape differs): {$skipped}\n";
    }

    public function down(): void
    {
        // The previous snapshot is not recoverable, and re-freezing it would
        // put the wrong category's wording back. Nothing to undo.
    }

    /**
     * True when two snapshots score exactly the same things: same component
     * codes at the same weights, same criterion codes at the same max marks.
     *
     * Titles and descriptions are deliberately excluded — those are what this
     * migration is allowed to change.
     */
    private function sameShape(?array $a, array $b): bool
    {
        return $this->shape($a ?? []) === $this->shape($b);
    }

    /** @return array<string, array{weight: string, criteria: array<int, string>}> */
    private function shape(array $snapshot): array
    {
        $out = [];

        foreach ($snapshot['components'] ?? [] as $component) {
            $criteria = [];

            foreach ($component['criteria'] ?? [] as $criterion) {
                $criteria[] = $criterion['code'].':'.$this->decimal($criterion['max_marks'] ?? 0);
            }

            $out[$component['code']] = [
                'weight'   => $this->decimal($component['weight_percent'] ?? 0),
                'criteria' => $criteria,
            ];
        }

        return $out;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
};
