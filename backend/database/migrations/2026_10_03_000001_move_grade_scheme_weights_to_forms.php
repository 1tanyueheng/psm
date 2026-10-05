<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move grade-scheme weights from assessor roles to official forms.
 *
 * The scheme used to weight by role:
 *
 *     [{"assessor_type":"supervisor","weight":60},
 *      {"assessor_type":"examiner","weight":40},
 *      {"assessor_type":"coordinator","weight":0}]
 *
 * That cannot express the actual weighting, because Lampiran G and Lampiran H
 * are both supervisor forms carrying different shares (50 and 5) — grouping by
 * role merges them into a single 55% bucket. Weights are now keyed by form:
 *
 *     [{"form_code":"G","weight":50},{"form_code":"H","weight":5},
 *      {"form_code":"J","weight":40}]
 *
 * Without this migration every existing scheme would keep the role shape,
 * `weightForForm()` would find no match, every weight would read 0, and the
 * aggregate would silently compute as 0.00 for every project — a wrong mark
 * rather than an error.
 *
 * Only schemes still in the legacy shape are touched, so a scheme that has
 * already been customised per form is left alone.
 */
return new class extends Migration
{
    /**
     * The weights are written out here rather than read from
     * `psm.assessment_weights`, because a migration must not depend on mutable
     * application config. The first version of this migration did read the
     * config, and a stale `bootstrap/cache/config.php` made the new key
     * invisible — it then wrote empty weight arrays to every scheme, which
     * would have scored every project 0.00 instead of failing loudly.
     *
     * `config/psm.php` remains the runtime source of truth; this is the
     * historical snapshot the migration is responsible for.
     */
    protected array $weights = [
        'PSM1' => ['E' => 35.0, 'I' => 30.0],
        'PSM2' => ['G' => 50.0, 'H' => 5.0, 'J' => 40.0],
    ];

    public function up(): void
    {
        DB::table('grade_schemes')
            ->join('projects', 'projects.id', '=', 'grade_schemes.project_id')
            ->select('grade_schemes.id', 'grade_schemes.weights', 'projects.psm_part')
            ->orderBy('grade_schemes.id')
            ->each(function ($row) {
                $weights = json_decode((string) $row->weights, true) ?: [];

                // Already form-keyed: leave a scheme someone has customised.
                $hasFormWeights = collect($weights)->contains(
                    fn ($entry) => isset($entry['form_code'])
                );

                if ($hasFormWeights) {
                    return;
                }

                // 'BOTH' has no weighting of its own; PSM 2 is where a combined
                // project is finally assessed.
                $forms = $this->weights[$row->psm_part] ?? $this->weights['PSM2'];

                $new = collect($forms)
                    ->map(fn ($weight, $formCode) => [
                        'form_code' => (string) $formCode,
                        'weight'    => (float) $weight,
                    ])
                    ->values()
                    ->all();

                DB::table('grade_schemes')
                    ->where('id', $row->id)
                    ->update(['weights' => json_encode($new)]);
            });
    }

    public function down(): void
    {
        // Restore the legacy role shape so the rollback is meaningful. The
        // original per-project values are not recoverable, but the shape is
        // what the pre-migration code read.
        $legacy = json_encode([
            ['assessor_type' => 'supervisor',  'weight' => 60],
            ['assessor_type' => 'examiner',    'weight' => 40],
            ['assessor_type' => 'coordinator', 'weight' => 0],
        ]);

        DB::table('grade_schemes')->update(['weights' => $legacy]);
    }
};
