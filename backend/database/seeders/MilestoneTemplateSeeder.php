<?php

namespace Database\Seeders;

use App\Enums\ProjectCategory;
use App\Models\MilestoneTemplate;
use App\Models\MilestoneTemplateItem;
use Illuminate\Database\Seeder;

/**
 * Module 3 — Milestone templates.
 *
 * One template per (category, psm_part) pair, each versioned. The item chain
 * comes straight from ProjectCategory::defaultMilestones(), so the enum stays
 * the single source of truth for what a PSM project must deliver.
 *
 * Why versioning matters: next semester the faculty will want to reword a
 * milestone or shift a weight. Bumping the version creates a new template row
 * and leaves this one intact, so cohorts already in flight keep the deadlines
 * and weights they were told about at registration.
 *
 * Templates are created for BOTH PSM1 and PSM2 because the same categories
 * exist in both parts; MilestoneTemplate::resolveFor() prefers a part-specific
 * row and falls back to BOTH.
 */
class MilestoneTemplateSeeder extends Seeder
{
    /** Working days a milestone stays open for submission, by sequence. */
    protected const DURATION_DAYS = [
        'proposal'  => 14,
        'review'    => 21,
        'methods'   => 21,
        'design'    => 21,
        'implement' => 42,
        'analysis'  => 28,
        'testing'   => 21,
        'report'    => 28,
    ];

    /** File types a milestone will accept in the upload form. */
    protected const DOCUMENT_TYPES = ['pdf', 'doc', 'docx'];
    protected const ARCHIVE_TYPES  = ['pdf', 'doc', 'docx', 'zip', 'pptx'];

    public function run(): void
    {
        foreach (ProjectCategory::cases() as $category) {
            foreach (['PSM1', 'PSM2'] as $psmPart) {
                $template = $this->createTemplate($category, $psmPart);

                foreach ($category->defaultMilestones() as $index => $definition) {
                    $this->createItem($template, $definition, $index + 1);
                }

                $this->assertWeightsBalance($template, $category, $psmPart);
            }
        }

        $this->command?->info(sprintf(
            '  Milestone templates: %d templates, %d items.',
            MilestoneTemplate::count(),
            MilestoneTemplateItem::count()
        ));
    }

    /**
     * Templates are keyed on (category, psm_part, version) so this seeder can be
     * run repeatedly without creating duplicates.
     */
    protected function createTemplate(ProjectCategory $category, string $psmPart): MilestoneTemplate
    {
        $lastOffset = collect($category->defaultMilestones())->max('offset_days');
        $lastDuration = (int) (self::DURATION_DAYS['report'] ?? 14);

        return MilestoneTemplate::updateOrCreate(
            [
                'category' => $category->value,
                'psm_part' => $psmPart,
                'version'  => 1,
            ],
            [
                'name'                  => sprintf('%s — %s', $category->label(), $psmPart),
                'notes'                 => $this->describe($category, $psmPart),
                'default_duration_days' => $lastOffset + $lastDuration,
                'is_active'             => true,
            ]
        );
    }

    /**
     * @param  array{code:string, title:string, weight:float, offset_days:int}  $definition
     */
    protected function createItem(MilestoneTemplate $template, array $definition, int $sequence): MilestoneTemplateItem
    {
        return MilestoneTemplateItem::updateOrCreate(
            [
                'milestone_template_id' => $template->id,
                'code'                  => $definition['code'],
            ],
            [
                'title'                  => $definition['title'],
                'description'            => $this->itemDescription($definition['code'], $definition['title']),
                'deliverable_expectation' => $this->itemExpectation($definition['code']),
                'sequence'               => $sequence,
                'offset_days'            => $definition['offset_days'],
                'duration_days'          => self::DURATION_DAYS[$definition['code']] ?? 14,
                'weight_percent'         => $definition['weight'],
                'allowed_file_types'     => in_array($definition['code'], ['implement', 'testing'], true)
                    ? self::ARCHIVE_TYPES
                    : self::DOCUMENT_TYPES,
                'max_files'              => $definition['code'] === 'report' ? 5 : 3,
                'requires_supervisor_approval' => true,
            ]
        );
    }

    // -----------------------------------------------------------------
    // Copy
    // -----------------------------------------------------------------

    protected function describe(ProjectCategory $category, string $psmPart): string
    {
        return $category === ProjectCategory::System
            ? "Standard delivery plan for a system-development {$psmPart} project: proposal, design, build, test, report."
            : "Standard delivery plan for a research-based {$psmPart} project: proposal, literature review, methodology, analysis, report.";
    }

    protected function itemDescription(string $code, string $title): string
    {
        return match ($code) {
            'proposal'  => 'Submit the project proposal for supervisor approval: problem statement, objectives, scope and expected outcome.',
            'design'    => 'Submit the system architecture, database design and interface specification.',
            'litreview' => 'Submit a critical review of the literature, establishing the research gap this project addresses.',
            'methods'   => 'Submit the research methodology: design, sampling, instruments, and analysis plan.',
            'implement' => 'Submit the working system build together with source code and deployment notes.',
            'analysis'  => 'Submit the results of the data analysis and their interpretation against the research questions.',
            'testing'   => 'Submit the test plan, test results, and a defect log with resolutions.',
            'report'    => 'Submit the complete final report for examination.',
            default     => "Submit {$title}.",
        };
    }

    protected function itemExpectation(string $code): string
    {
        return match ($code) {
            'proposal'  => '3-5 pages: problem statement, objectives, scope, methodology outline, Gantt chart.',
            'design'    => 'Architecture diagram, ERD, use-case and interface mockups.',
            'litreview' => 'Minimum 15 peer-reviewed sources, synthesised thematically, not summarised one by one.',
            'methods'   => 'Justified research design, sampling strategy, instruments and validity considerations.',
            'implement' => 'Runnable build plus source archive; setup instructions must be reproducible by the examiner.',
            'analysis'  => 'Findings presented with appropriate tables or figures and discussed against the literature.',
            'testing'   => 'Test cases mapped to requirements, with pass/fail evidence and outstanding defect severity.',
            'report'    => 'Full report following the faculty template, including references and appendices.',
            default     => 'As specified in the project brief.',
        };
    }

    /**
     * Guard: a template whose weights do not total 100 would silently distort
     * every project's milestone progress figure. Fail loudly at seed time.
     */
    protected function assertWeightsBalance(MilestoneTemplate $template, ProjectCategory $category, string $psmPart): void
    {
        $total = (float) $template->items()->sum('weight_percent');

        if (abs($total - 100.0) > 0.01) {
            throw new \RuntimeException(sprintf(
                'Milestone template for %s/%s has item weights totalling %.2f%%, expected 100%%.',
                $category->value,
                $psmPart,
                $total
            ));
        }
    }
}
