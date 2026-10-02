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
 * Each form is seeded as one rubric template per project category, so the
 * Development variant (C(i)/B(i)) and the Research variant (C(ii)/B(ii)) are
 * separate templates selected by the project's category. `form_code` carries
 * the Lampiran letter and is what keeps G and H (both PSM2/supervisor) apart.
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
            foreach (['system', 'research'] as $category) {
                $this->seedTemplate($form, $category);
            }
        }

        $this->command?->info(sprintf(
            '  Marking forms: %d templates, %d components, %d criteria.',
            RubricTemplate::whereNotNull('form_code')->count(),
            RubricComponent::count(),
            RubricCriterion::count()
        ));
    }

    // -----------------------------------------------------------------
    // Seeding
    // -----------------------------------------------------------------

    protected function seedTemplate(array $form, string $category): void
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
                'name'          => sprintf('Lampiran %s — %s (%s)', $form['code'], $form['title'], ucfirst($category)),
                'total_marks'   => $total,
                'pass_mark'     => round($total / 2, 2),
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
    protected function componentsFor(string $form, string $category): array
    {
        return match ($form) {
            'E' => $this->formE($category),
            'I' => $this->formI($category),
            'G' => $this->formG($category),
            'H' => $this->formH(),
            'J' => $this->formJ($category),
        };
    }

    // -----------------------------------------------------------------
    // Lampiran E — PSM 1 Supervisor (max 35)
    // -----------------------------------------------------------------

    protected function formE(string $category): array
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

            $this->variant(
                $category,
                'C',
                'C — Prototype / Research Framework',
                10.00,
                [
                    ['code' => 'C1', 'title' => 'Analysis & Specifications', 'max' => 3.30, 'guidance' => 'Requirements captured and specified.'],
                    ['code' => 'C2', 'title' => 'User Interface / Storyboard', 'max' => 3.30, 'guidance' => 'Interface designed and described.'],
                    ['code' => 'C3', 'title' => 'Prototype', 'max' => 3.40, 'guidance' => 'A working prototype demonstrates the concept.'],
                ],
                [
                    ['code' => 'C1', 'title' => 'Tools / Data / Case Studies', 'max' => 3.30, 'guidance' => 'Instruments and data sources are appropriate.'],
                    ['code' => 'C2', 'title' => 'Technique / Method / Algorithm', 'max' => 3.30, 'guidance' => 'Method fits the research question.'],
                    ['code' => 'C3', 'title' => 'Measurement Technique / Method', 'max' => 3.40, 'guidance' => 'Measurement approach is defined and defensible.'],
                ]
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Lampiran I — PSM 1 Examiner (max 30)
    // -----------------------------------------------------------------

    protected function formI(string $category): array
    {
        return [
            [
                'code'        => 'A_paper',
                'title'       => 'A — Proceeding Paper',
                'max'         => 10.00,
                'description' => 'The written proceeding paper submitted for the seminar.',
                'items'       => [
                    ['code' => 'A1', 'title' => 'Abstract', 'max' => 1.45],
                    ['code' => 'A2', 'title' => 'Introduction', 'max' => 1.45],
                    ['code' => 'A3', 'title' => 'Literature Review', 'max' => 1.45],
                    ['code' => 'A4', 'title' => 'Methodology', 'max' => 1.40],
                    ['code' => 'A5', 'title' => 'Analysis & Design', 'max' => 1.45],
                    ['code' => 'A6', 'title' => 'Conclusion & Reference', 'max' => 1.40],
                    ['code' => 'A7', 'title' => 'Report Formatting', 'max' => 1.40],
                ],
            ],

            $this->variant(
                $category,
                'B',
                'B — Presentation',
                15.00,
                [
                    ['code' => 'B1', 'title' => 'Analysis & Specifications', 'max' => 4.95],
                    ['code' => 'B2', 'title' => 'User Interface / Storyboard', 'max' => 4.95],
                    ['code' => 'B3', 'title' => 'Prototype', 'max' => 5.10],
                ],
                [
                    ['code' => 'B1', 'title' => 'Tools / Data / Case Studies', 'max' => 4.95],
                    ['code' => 'B2', 'title' => 'Technique / Method / Algorithm', 'max' => 4.95],
                    ['code' => 'B3', 'title' => 'Measurement Technique / Method', 'max' => 5.10],
                ]
            ),

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

    // -----------------------------------------------------------------
    // Lampiran G — PSM 2 Supervisor (max 50)
    // -----------------------------------------------------------------

    protected function formG(string $category): array
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

            $this->variant(
                $category,
                'C',
                'C — PSM 2 Product',
                25.00,
                [
                    ['code' => 'C1', 'title' => 'System Analysis', 'max' => 4.19],
                    ['code' => 'C2', 'title' => 'System Design', 'max' => 4.19],
                    ['code' => 'C3', 'title' => 'Translation of Development', 'max' => 4.19],
                    ['code' => 'C4', 'title' => 'Implementation', 'max' => 4.19],
                    ['code' => 'C5', 'title' => 'Testing, Verification and Validation', 'max' => 4.12],
                    ['code' => 'C6', 'title' => 'Creative, Innovative and Commercial Value', 'max' => 4.12],
                ],
                [
                    ['code' => 'C1', 'title' => 'Analysis', 'max' => 4.19],
                    ['code' => 'C2', 'title' => 'Design / Technique / Algorithm', 'max' => 4.19],
                    ['code' => 'C3', 'title' => 'Translation of Methodology', 'max' => 4.19],
                    ['code' => 'C4', 'title' => 'Implementation / Simulation', 'max' => 4.19],
                    ['code' => 'C5', 'title' => 'Testing, Verification and Validation', 'max' => 4.12],
                    ['code' => 'C6', 'title' => 'Creative, Innovative and Commercial Value', 'max' => 4.12],
                ]
            ),
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

    // -----------------------------------------------------------------
    // Lampiran J — PSM 2 Examiner (max 40)
    // -----------------------------------------------------------------

    protected function formJ(string $category): array
    {
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

            $this->variant(
                $category,
                'B',
                'B — Final Product',
                25.00,
                [
                    ['code' => 'B1', 'title' => 'System Analysis', 'max' => 4.19],
                    ['code' => 'B2', 'title' => 'System Design', 'max' => 4.19],
                    ['code' => 'B3', 'title' => 'Translation of Development', 'max' => 4.19],
                    ['code' => 'B4', 'title' => 'Implementation', 'max' => 4.19],
                    ['code' => 'B5', 'title' => 'Testing, Verification and Validation', 'max' => 4.12],
                    ['code' => 'B6', 'title' => 'Creative, Innovative and Commercial Value', 'max' => 4.12],
                ],
                [
                    ['code' => 'B1', 'title' => 'Analysis', 'max' => 4.19],
                    ['code' => 'B2', 'title' => 'Design / Technique / Algorithm', 'max' => 4.19],
                    ['code' => 'B3', 'title' => 'Translation of Methodology', 'max' => 4.19],
                    ['code' => 'B4', 'title' => 'Implementation / Simulation', 'max' => 4.19],
                    ['code' => 'B5', 'title' => 'Testing, Verification and Validation', 'max' => 4.12],
                    ['code' => 'B6', 'title' => 'Significance to the Field', 'max' => 4.12],
                ]
            ),

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

    /**
     * Build the category-dependent component: Development items for `system`,
     * Research items for `research`.
     *
     * @param  array<int, array{code:string,title:string,max:float,guidance?:string}>  $development
     * @param  array<int, array{code:string,title:string,max:float,guidance?:string}>  $research
     */
    protected function variant(
        string $category,
        string $code,
        string $title,
        float $max,
        array $development,
        array $research,
    ): array {
        return [
            'code'        => $code,
            'title'       => $title.' — '.($category === 'system' ? 'Development' : 'Research'),
            'max'         => $max,
            'description' => $category === 'system'
                ? 'Assessed against the development variant of the form.'
                : 'Assessed against the research variant of the form.',
            'items'       => $category === 'system' ? $development : $research,
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
