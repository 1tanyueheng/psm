<?php

/**
 * Weight audit for the seeded rubrics.
 *
 * Run: php tools/audit_rubric_weights.php
 *
 * Reads the rubrics that are actually in the database rather than a copy of
 * them held in this file. An earlier version kept its own hardcoded copy of
 * the weights, which meant it happily reported "balanced" while the real
 * templates were wrong — and went silently out of date the moment the
 * chapter-aligned v2 rubrics replaced the v1 ones.
 *
 * An unbalanced rubric silently corrupts every mark computed against it, so
 * this stays worth being able to re-run after any edit to the seeders.
 *
 * Checks, per template:
 *   - component weights total 100
 *   - each component's criterion weights total 100
 *   - each component's criterion max_marks sum to the component's own
 *     share of the template's total_marks
 *   - a milestone-scoped component points at a milestone code the milestone
 *     templates actually define
 */

use App\Models\MilestoneTemplateItem;
use App\Models\RubricTemplate;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** Every milestone code any template defines, across all versions. */
$knownMilestoneCodes = MilestoneTemplateItem::query()
    ->distinct()
    ->pluck('code')
    ->all();

$fail = 0;

$templates = RubricTemplate::query()
    ->with(['components.criteria'])
    ->orderBy('version')
    ->orderBy('assessor_type')
    ->get();

if ($templates->isEmpty()) {
    fwrite(STDERR, "No rubrics found — run `php artisan migrate:fresh --seed` first.\n");
    exit(1);
}

foreach ($templates as $template) {
    $label = "v{$template->version} {$template->name} [{$template->assessor_type->value}]";
    $components = $template->components;

    $componentSum = (float) $components->sum('weight_percent');
    $componentOk = abs($componentSum - 100.0) < 0.01;

    printf("%s\n", $label);
    printf("   %-40s components = %6.2f%%  %s\n", '(total)', $componentSum, $componentOk ? 'OK' : 'FAIL');

    if (! $componentOk) {
        $fail++;
    }

    foreach ($components as $component) {
        $criteria = $component->criteria;
        $criteriaSum = (float) $criteria->sum('weight_percent');

        // The component's own slice of the template's absolute marks.
        $expectedMarks = round((float) $template->total_marks * ((float) $component->weight_percent / 100), 2);
        $actualMarks = (float) $criteria->sum('max_marks');

        $marksOk = abs($actualMarks - $expectedMarks) < 0.05;
        $weightsOk = abs($criteriaSum - 100.0) < 0.01;

        $problems = [];
        if (! $weightsOk) {
            $problems[] = sprintf('criterion weights %.2f%% != 100%%', $criteriaSum);
        }
        if (! $marksOk) {
            $problems[] = sprintf('max_marks %.2f != %.2f', $actualMarks, $expectedMarks);
        }

        // A chapter component must point at a chapter the templates define,
        // or the marking form has nothing to show beside the mark.
        if ($component->milestone_code !== null
            && ! in_array($component->milestone_code, $knownMilestoneCodes, true)) {
            $problems[] = sprintf('milestone_code "%s" is not a known milestone', $component->milestone_code);
        }

        $ok = ! $problems;
        printf(
            "   %-40s criteria = %6.2f%%  marks = %6.2f/%.2f  chapter=%-12s %s%s\n",
            $component->title,
            $criteriaSum,
            $actualMarks,
            $expectedMarks,
            $component->milestone_code ?? '-',
            $ok ? 'OK' : 'FAIL',
            $ok ? '' : ' — '.implode('; ', $problems)
        );

        if (! $ok) {
            $fail++;
        }
    }

    echo "\n";
}

echo $fail === 0
    ? 'All rubric weights balanced.'.PHP_EOL
    : "{$fail} problem(s) found.".PHP_EOL;

exit($fail === 0 ? 0 : 1);
