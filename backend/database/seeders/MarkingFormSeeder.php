<?php

namespace Database\Seeders;

use App\Models\RubricComponent;
use App\Models\RubricCriterion;
use App\Models\RubricTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Module 4 — Official marking forms (Lampiran E, G, H, I, J).
 *
 * This seeder is the single owner of the rubric catalogue. Nothing else may
 * write `rubric_templates`: a rubric with no `form_code` matches no form the
 * faculty issues and is filtered out by `RubricTemplate::scopeOfficialForms()`.
 *
 * Four of the five forms print a different set of items per project category —
 * Lampiran E carries C(i) Prototype *or* C(ii) Research Framework, I carries
 * B(i) *or* B(ii), and G and J likewise — so those four are seeded once per
 * category and `RubricTemplate::resolveFor()` picks the matching one. Lampiran
 * H has no variant and is seeded once with `category IS NULL`.
 *
 * Both totals come to the same figure either way: on the printed form C(i) and
 * C(ii) are alternatives, each worth the same 10%, and the form's JUMLAH counts
 * only the one actually filled.
 *
 * Weights are taken straight from the official forms. Every item is marked on
 * the 0–5 scale and every form computes its weighted mark the same way,
 * `(score / 5) × weight`, so an item's weight is its maximum contribution.
 * Lampiran H follows that rule too: weights 1.65 / 1.65 / 1.70, form total 5.00.
 *
 * R1 is intentionally not seeded (usage to be confirmed).
 */
class MarkingFormSeeder extends Seeder
{
    protected ?int $authorId = null;

    public function run(): void
    {
        $this->authorId = User::query()
            ->where('email', 'coordinator@psm.test')
            ->value('id');

        foreach ($this->forms() as $form) {
            foreach ($this->categoriesFor($form['code']) as $category) {
                $this->seedTemplate($form, $category);
            }
        }

        $this->command?->info(sprintf(
            '  Marking forms: %d templates, %d components, %d criteria.',
            RubricTemplate::officialForms()->count(),
            RubricComponent::count(),
            RubricCriterion::count()
        ));
    }

    /**
     * Which project categories a form has a distinct item set for.
     *
     * @return array<int, string|null> `null` is the shared, category-agnostic form.
     */
    protected function categoriesFor(string $formCode): array
    {
        return in_array($formCode, ['E', 'I', 'G', 'J'], true)
            ? ['system', 'research']
            : [null];
    }

    // -----------------------------------------------------------------
    // Seeding
    // -----------------------------------------------------------------

    protected function seedTemplate(array $form, ?string $category): void
    {
        $components = $this->componentsFor($form['code'], $category);
        $total = round(array_sum(array_column($components, 'max')), 2);

        $template = RubricTemplate::updateOrCreate(
            [
                'category'      => $category,
                'psm_part'      => $form['psm_part'],
                'assessor_type' => $form['assessor_type'],
                'form_code'     => $form['code'],
                'version'       => 1,
            ],
            [
                'name'          => sprintf(
                    'Lampiran %s — %s%s',
                    $form['code'],
                    $form['title'],
                    $category === null ? '' : sprintf(' (%s)', ucfirst($category))
                ),
                'total_marks'   => $total,
                'is_active'     => true,
                'is_published'  => true,
                'description'   => $form['description'],
                'grading_guide' => $this->gradingGuide(),
                'created_by'    => $this->authorId,
            ]
        );

        foreach ($components as $i => $comp) {
            $component = RubricComponent::updateOrCreate(
                ['rubric_template_id' => $template->id, 'code' => $comp['code']],
                [
                    'title'          => $comp['title'],
                    'description'    => $comp['description'] ?? null,
                    'weight_percent' => round($comp['max'] / $total * 100, 2),
                    'sequence'       => $i + 1,
                ]
            );

            foreach ($comp['items'] as $j => $item) {
                RubricCriterion::updateOrCreate(
                    ['rubric_component_id' => $component->id, 'code' => $item['code']],
                    [
                        'title'          => $item['title'],
                        'guidance'       => $item['guidance'] ?? null,
                        'weight_percent' => round($item['max'] / $comp['max'] * 100, 2),
                        'max_marks'      => $item['max'],
                        'sequence'       => $j + 1,
                        'is_required'    => true,
                    ]
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Form catalogue
    // -----------------------------------------------------------------

    /** @return array<int, array<string, string>> */
    protected function forms(): array
    {
        return [
            ['code' => 'E', 'psm_part' => 'PSM1', 'assessor_type' => 'supervisor', 'title' => 'Penilaian PSM 1 bagi Penyelia', 'description' => 'Supervisor evaluation of a PSM 1 student: logbook, written chapters and the prototype or research framework.'],
            ['code' => 'I', 'psm_part' => 'PSM1', 'assessor_type' => 'examiner',   'title' => 'Penilaian Seminar 1 bagi Penilai', 'description' => 'Examiner evaluation of the PSM 1 seminar: proceeding paper, presentation of the prototype or framework, and delivery.'],
            ['code' => 'G', 'psm_part' => 'PSM2', 'assessor_type' => 'supervisor', 'title' => 'Penilaian PSM 2 bagi Penyelia', 'description' => 'Supervisor evaluation of a PSM 2 student: logbook, the PSM 2 report and the final product.'],
            ['code' => 'H', 'psm_part' => 'PSM2', 'assessor_type' => 'supervisor', 'title' => 'Penilaian Laporan Kemajuan bagi Penyelia', 'description' => 'Supervisor evaluation of a PSM 2 progress report (Laporan Kemajuan 1 or 2).'],
            ['code' => 'J', 'psm_part' => 'PSM2', 'assessor_type' => 'examiner',   'title' => 'Penilaian Seminar Akhir bagi Penilai', 'description' => 'Examiner evaluation of the PSM 2 final seminar: proceeding paper, the final product and delivery.'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function componentsFor(string $form, ?string $category): array
    {
        return match ($form) {
            'E' => $this->formE($category),
            'I' => $this->formI($category),
            'G' => $this->formG($category),
            'H' => $this->formH(),
            'J' => $this->formJ($category),
        };
    }

    /**
     * Lampiran E — component C differs by category.
     *
     * C(i) Prototype for a development project, C(ii) Research Framework for a
     * study. Same 10% either way.
     */
    protected function componentE(?string $category): array
    {
        if ($category === 'research') {
            return [
                'code'        => 'C',
                'title'       => 'C(ii) — Penilaian Kerangka Kajian',
                'max'         => 10.00,
                'description' => 'Research Framework assessment. For research projects, assess the research framework.',
                'items'       => [
                    ['code' => 'C1', 'title' => 'Alatan / Data / Kajian Kes (Tools / Data / Case Studies)', 'max' => 3.30],
                    ['code' => 'C2', 'title' => 'Teknik / Kaedah / Algoritma (Technique / Method / Algorithm)', 'max' => 3.30],
                    ['code' => 'C3', 'title' => 'Teknik / Kaedah Pengukuran (Measurement Technique)', 'max' => 3.40],
                ],
            ];
        }

        return [
            'code'        => 'C',
            'title'       => 'C(i) — Penilaian Prototaip',
            'max'         => 10.00,
            'description' => 'Prototype assessment. For development projects, assess the prototype.',
            'items'       => [
                ['code' => 'C1', 'title' => 'Analisis & Spesifikasi (Analysis & Specifications)', 'max' => 3.30, 'guidance' => 'Requirements captured and specified.'],
                ['code' => 'C2', 'title' => 'Antaramuka / Papan Cerita (User Interface / Storyboard)', 'max' => 3.30, 'guidance' => 'Interface designed and described.'],
                ['code' => 'C3', 'title' => 'Prototaip (Prototype)', 'max' => 3.40, 'guidance' => 'A working prototype demonstrates the concept.'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Lampiran E — PSM 1 Supervisor (max 35)
    // -----------------------------------------------------------------

    protected function formE(?string $category): array
    {
        return [
            $this->logbook(),

            [
                'code'        => 'B_report',
                'title'       => 'B — Report Writing',
                'max'         => 20.00,
                'description' => 'Quality of the written chapters submitted in PSM 1.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Chapter 1', 'max' => 5.00, 'guidance' => 'Problem framing, objectives and scope.'],
                    ['code' => 'B2', 'title' => 'Chapter 2', 'max' => 5.00, 'guidance' => 'Review of prior work and requirements.'],
                    ['code' => 'B3', 'title' => 'Chapter 3', 'max' => 5.00, 'guidance' => 'Design and justification of decisions.'],
                    ['code' => 'B4', 'title' => 'Chapter 4', 'max' => 5.00, 'guidance' => 'Implementation and its quality.'],
                ],
            ],

            $this->componentE($category),
        ];
    }

    // -----------------------------------------------------------------
    // Lampiran I — PSM 1 Examiner (max 30)
    // -----------------------------------------------------------------

    protected function formI(?string $category): array
    {
        $b = $category === 'research'
            ? [
                'code'        => 'B',
                'title'       => 'B(ii) — Pembentangan Kerangka Kajian',
                'max'         => 15.00,
                'description' => 'Content of the research framework presentation.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Alatan / Data / Kajian Kes (Tools / Data / Case Studies)', 'max' => 4.95],
                    ['code' => 'B2', 'title' => 'Teknik / Kaedah / Algoritma (Technique / Method / Algorithm)', 'max' => 4.95],
                    ['code' => 'B3', 'title' => 'Teknik / Kaedah Pengukuran (Measurement Technique)', 'max' => 5.10],
                ],
            ]
            : [
                'code'        => 'B',
                'title'       => 'B(i) — Pembentangan Prototaip',
                'max'         => 15.00,
                'description' => 'Content of the prototype presentation.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Analisis & Spesifikasi (Analysis & Specifications)', 'max' => 4.95],
                    ['code' => 'B2', 'title' => 'Antaramuka / Papan Cerita (User Interface / Storyboard)', 'max' => 4.95],
                    ['code' => 'B3', 'title' => 'Prototaip (Prototype)', 'max' => 5.10],
                ],
            ];

        return [
            [
                'code'        => 'A_paper',
                'title'       => 'A — Proceeding Paper',
                'max'         => 10.00,
                'description' => 'The written proceeding paper submitted for the seminar.',
                'items'       => [
                    ['code' => 'A1', 'title' => 'Abstrak (Abstract)', 'max' => 1.45],
                    ['code' => 'A2', 'title' => 'Pengenalan (Introduction)', 'max' => 1.45],
                    ['code' => 'A3', 'title' => 'Kajian Literatur (Literature Review)', 'max' => 1.45],
                    ['code' => 'A4', 'title' => 'Metodologi (Methodology)', 'max' => 1.40],
                    ['code' => 'A5', 'title' => 'Analisis & Rekabentuk (Analysis & Design)', 'max' => 1.45],
                    ['code' => 'A6', 'title' => 'Kesimpulan & Rujukan (Conclusion & Reference)', 'max' => 1.40],
                    ['code' => 'A7', 'title' => 'Format Laporan (Report Formatting)', 'max' => 1.40],
                ],
            ],

            $b,

            [
                'code'        => 'C_delivery',
                'title'       => 'C — Presentation Delivery',
                'max'         => 5.00,
                'description' => 'How the seminar was delivered.',
                'items'       => [
                    ['code' => 'C1', 'title' => 'Appearance', 'max' => 1.65],
                    ['code' => 'C2', 'title' => 'Q&A Session', 'max' => 1.65],
                    ['code' => 'C3', 'title' => 'Presentation Organisation', 'max' => 1.70],
                ],
            ],
        ];
    }

    /**
     * Lampiran G — component C's first four items differ by category.
     *
     * C(i) is worded for a development project (system analysis, system design,
     * translation of development), C(ii) for a study (analysis, design /
     * algorithm, translation of methodology). Items 5 and 6 are worded
     * identically on both and are therefore shared.
     */
    protected function componentG(?string $category): array
    {
        $head = $category === 'research'
            ? [
                ['code' => 'C1', 'title' => 'Analisa (Analysis)', 'max' => 4.19],
                ['code' => 'C2', 'title' => 'Rekabentuk / Teknik / Algoritma (Design / Algorithm)', 'max' => 4.19],
                ['code' => 'C3', 'title' => 'Terjemahan Metodologi (Translation of Methodology)', 'max' => 4.19],
                ['code' => 'C4', 'title' => 'Implementasi / Simulasi (Implementation / Simulation)', 'max' => 4.19],
            ]
            : [
                ['code' => 'C1', 'title' => 'Analisa Sistem (System Analysis)', 'max' => 4.19],
                ['code' => 'C2', 'title' => 'Rekabentuk Sistem (System Design)', 'max' => 4.19],
                ['code' => 'C3', 'title' => 'Terjemahan Pembangunan (Translation of Development)', 'max' => 4.19],
                ['code' => 'C4', 'title' => 'Implementasi (Implementation)', 'max' => 4.19],
            ];

        $tail = [
            ['code' => 'C5', 'title' => 'Pengujian, verifikasi dan validasi (Testing & Validation)', 'max' => 4.12],
            ['code' => 'C6', 'title' => 'Kreatif, inovatif dan bercirikan komersial (Commercial Value)', 'max' => 4.12],
        ];

        return [
            'code'        => 'C',
            'title'       => $category === 'research'
                ? 'C(ii) — Produk PSM 2 (Kajian)'
                : 'C(i) — Produk PSM 2 (Pembangunan)',
            'max'         => 25.00,
            'description' => 'The completed product, system or research output.',
            'items'       => [...$head, ...$tail],
        ];
    }

    // -----------------------------------------------------------------
    // Lampiran G — PSM 2 Supervisor (max 50)
    // -----------------------------------------------------------------

    protected function formG(?string $category): array
    {
        return [
            $this->logbook(),

            [
                'code'        => 'B_report',
                'title'       => 'B — PSM 2 Report',
                'max'         => 20.00,
                'description' => 'Quality of the final PSM 2 report.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Abstract', 'max' => 1.15],
                    ['code' => 'B2', 'title' => 'Introduction', 'max' => 2.20],
                    ['code' => 'B3', 'title' => 'Literature Review', 'max' => 3.30],
                    ['code' => 'B4', 'title' => 'Methodology', 'max' => 2.20],
                    ['code' => 'B5', 'title' => 'Analysis and Design', 'max' => 3.30],
                    ['code' => 'B6', 'title' => 'Results / Findings / Output', 'max' => 4.50],
                    ['code' => 'B7', 'title' => 'Conclusion and Suggestion', 'max' => 1.15],
                    ['code' => 'B8', 'title' => 'Report Formatting', 'max' => 2.20],
                ],
            ],

            $this->componentG($category),
        ];
    }

    // -----------------------------------------------------------------
    // Lampiran H — PSM 2 Progress Report (max 5, taken twice per student)
    // -----------------------------------------------------------------

    protected function formH(): array
    {
        return [
            [
                'code'        => 'progress',
                'title'       => 'Progress Report Evaluation',
                'max'         => 5.00,
                'description' => 'Progress achieved at the point this report was submitted.',
                'items'       => [
                    ['code' => 'H1', 'title' => 'Milestone Achievement', 'max' => 1.65, 'guidance' => 'Benchmarks set for this period were met.'],
                    ['code' => 'H2', 'title' => 'Project Progressed as Planned', 'max' => 1.65, 'guidance' => 'Work advanced in line with the agreed plan.'],
                    ['code' => 'H3', 'title' => 'Increased Knowledge and Skills', 'max' => 1.70, 'guidance' => 'Demonstrable growth in knowledge and skills.'],
                ],
            ],
        ];
    }

    /**
     * Lampiran J — component B differs by category, mirroring Lampiran G's C.
     *
     * Items 1–5 are worded for the category, as on Lampiran G. Item 6 is the
     * one real divergence between the two forms' research variants: J closes on
     * Significance, where G still closes on Commercial Value.
     */
    protected function formJ(?string $category): array
    {
        $b = $category === 'research'
            ? [
                'code'        => 'B',
                'title'       => 'B(ii) — Produk Akhir PSM 2 (Kajian)',
                'max'         => 25.00,
                'description' => 'The completed research output, judged as a study.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Analisa (Analysis)', 'max' => 4.19],
                    ['code' => 'B2', 'title' => 'Rekabentuk / Teknik / Algoritma (Design / Algorithm)', 'max' => 4.19],
                    ['code' => 'B3', 'title' => 'Terjemahan Metodologi (Translation of Methodology)', 'max' => 4.19],
                    ['code' => 'B4', 'title' => 'Implementasi / Simulasi (Implementation / Simulation)', 'max' => 4.19],
                    ['code' => 'B5', 'title' => 'Pengujian, verifikasi dan validasi (Testing & Validation)', 'max' => 4.12],
                    ['code' => 'B6', 'title' => 'Kepentingan / Signifikan kepada bidang ilmu (Significance)', 'max' => 4.12],
                ],
            ]
            : [
                'code'        => 'B',
                'title'       => 'B(i) — Produk Akhir PSM 2 (Pembangunan)',
                'max'         => 25.00,
                'description' => 'The completed product or system.',
                'items'       => [
                    ['code' => 'B1', 'title' => 'Analisa Sistem (System Analysis)', 'max' => 4.19],
                    ['code' => 'B2', 'title' => 'Rekabentuk Sistem (System Design)', 'max' => 4.19],
                    ['code' => 'B3', 'title' => 'Terjemahan Pembangunan (Translation of Development)', 'max' => 4.19],
                    ['code' => 'B4', 'title' => 'Implementasi (Implementation)', 'max' => 4.19],
                    ['code' => 'B5', 'title' => 'Pengujian, verifikasi dan validasi (Testing & Validation)', 'max' => 4.12],
                    ['code' => 'B6', 'title' => 'Kreatif, inovatif dan bercirikan komersial (Commercial Value)', 'max' => 4.12],
                ],
            ];

        return [
            [
                'code'        => 'A_paper',
                'title'       => 'A — Proceeding Paper',
                'max'         => 10.00,
                'description' => 'The written proceeding paper submitted for the final seminar.',
                'items'       => [
                    ['code' => 'A1', 'title' => 'Abstract', 'max' => 1.45],
                    ['code' => 'A2', 'title' => 'Introduction and Literature Review', 'max' => 1.45],
                    ['code' => 'A3', 'title' => 'Methodology', 'max' => 1.45],
                    ['code' => 'A4', 'title' => 'Findings / Discussion / Analysis', 'max' => 1.45],
                    ['code' => 'A5', 'title' => 'Conclusion and Suggestion', 'max' => 1.40],
                    ['code' => 'A6', 'title' => 'Reference', 'max' => 1.40],
                    ['code' => 'A7', 'title' => 'Report Formatting', 'max' => 1.40],
                ],
            ],

            $b,

            [
                'code'        => 'C_delivery',
                'title'       => 'C — Presentation Delivery',
                'max'         => 5.00,
                'description' => 'How the final seminar was delivered.',
                'items'       => [
                    ['code' => 'C1', 'title' => 'Appearance', 'max' => 1.25],
                    ['code' => 'C2', 'title' => 'Field Knowledge', 'max' => 1.25],
                    ['code' => 'C3', 'title' => 'Presentation Organisation', 'max' => 1.25],
                    ['code' => 'C4', 'title' => 'Q&A Session', 'max' => 1.25],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Shared pieces
    // -----------------------------------------------------------------

    /** Component A — Logbook, shared by Lampiran E and G. */
    protected function logbook(): array
    {
        return [
            'code'        => 'A_logbook',
            'title'       => 'A — Logbook',
            'max'         => 5.00,
            'description' => 'Continuous record of planning, engagement and weekly work.',
            'items'       => [
                ['code' => 'A1', 'title' => 'PSM Implementation Planning', 'max' => 1.25, 'guidance' => 'A realistic plan exists and is kept current.'],
                ['code' => 'A2', 'title' => 'Interaction with Supervisor and Discipline', 'max' => 1.25, 'guidance' => 'Attends prepared and acts on agreed actions.'],
                ['code' => 'A3', 'title' => 'Weekly Activities / Attachments', 'max' => 1.25, 'guidance' => 'Weekly activity recorded with attachments.'],
                ['code' => 'A4', 'title' => 'Progress Report Discussion', 'max' => 1.25, 'guidance' => 'Progress discussed openly, including slippage.'],
            ],
        ];
    }

    protected function gradingGuide(): string
    {
        return <<<'TXT'
        Evaluation scale (0–5) applied to every item:
          0  Tiada Data              — No data / not submitted
          1  Sangat Kurang Memuaskan — Very unsatisfactory
          2  Kurang Memuaskan        — Unsatisfactory
          3  Sederhana               — Moderate
          4  Baik                    — Good
          5  Sangat Baik             — Very good

        Item contribution = (score ÷ 5) × item weight, summed to the component
        and form totals. This is how every official form computes its weighted
        mark, Lampiran H included.
        TXT;
    }
}
