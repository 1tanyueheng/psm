<?php
/**
 * Weight audit for the seeded rubrics.
 *
 * Run: php tools/audit_rubric_weights.php
 *
 * Kept as a script so the same check can be re-run after any future edit to
 * RubricTemplateSeeder — an unbalanced rubric silently corrupts every mark
 * computed against it, so this is worth being able to verify quickly.
 */

$templates = [
    'supervisor/system' => [
        'process'         => ['planning' => 30, 'meetings' => 30, 'documentation' => 20, 'independence' => 20],
        'requirements'    => ['elicitation' => 35, 'architecture' => 35, 'data_model' => 30],
        'implementation'  => ['functionality' => 40, 'code_quality' => 30, 'robustness' => 30],
        'professionalism' => ['ethics' => 35, 'testing' => 35, 'reflection' => 30],
    ],
    'supervisor/research' => [
        'process'     => ['planning' => 30, 'meetings' => 30, 'recordkeeping' => 20, 'independence' => 20],
        'literature'  => ['coverage' => 30, 'criticality' => 40, 'gap' => 30],
        'method'      => ['design' => 30, 'sampling' => 25, 'analysis' => 25, 'validity' => 20],
        'academic'    => ['argument' => 35, 'referencing' => 35, 'ethics' => 30],
    ],
    'examiner/both' => [
        'report'   => ['structure' => 30, 'clarity' => 35, 'evidence' => 35],
        'artefact' => ['completeness' => 35, 'technical' => 35, 'usability' => 30],
        'defence'  => ['presentation' => 30, 'defence_qa' => 45, 'contribution' => 25],
    ],
    'coordinator/both' => [
        'compliance' => ['milestones' => 40, 'supervision' => 30, 'format' => 30],
        'standards'  => ['level' => 40, 'consistency' => 35, 'integrity' => 25],
    ],
];

$componentWeights = [
    'supervisor/system'   => ['process' => 25, 'requirements' => 25, 'implementation' => 30, 'professionalism' => 20],
    'supervisor/research' => ['process' => 25, 'literature' => 25, 'method' => 30, 'academic' => 20],
    'examiner/both'       => ['report' => 35, 'artefact' => 40, 'defence' => 25],
    'coordinator/both'    => ['compliance' => 40, 'standards' => 60],
];

$fail = 0;

foreach ($templates as $name => $components) {
    $sum = array_sum($componentWeights[$name]);

    printf("%-22s components = %5.2f%%  %s\n", $name, $sum, abs($sum - 100) < 0.01 ? 'OK' : 'FAIL');
    if (abs($sum - 100) >= 0.01) {
        $fail++;
    }

    foreach ($components as $code => $criteria) {
        $total = array_sum($criteria);
        $declared = $componentWeights[$name][$code] ?? 0;
        // Absolute marks for this component, then the criteria split of it.
        $marks = round(100 * ($declared / 100), 2);
        $split = 0.0;

        foreach ($criteria as $weight) {
            $split += round($marks * ($weight / 100), 2);
        }

        $ok = abs($total - 100) < 0.01 && abs($split - $marks) < 0.05;
        printf("   %-16s criteria = %5.2f%%  marks = %6.2f  %s\n", $code, $total, $split, $ok ? 'OK' : 'FAIL');
        if (! $ok) {
            $fail++;
        }
    }
}

echo $fail === 0
    ? "\nAll rubric weights balanced.\n"
    : "\n{$fail} problem(s) found.\n";

exit($fail === 0 ? 0 : 1);
