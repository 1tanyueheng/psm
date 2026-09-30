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
    /**
     * How long each milestone stays open for submission, by code.
     *
     * Chapters are held open for three weeks: long enough to write and revise
     * a chapter, short enough that a stalled student surfaces as overdue
     * before the next chapter is due.
     */
    protected const DURATION_DAYS = [
        'proposal'     => 14,
        'chapter_1'    => 21,
        'chapter_2'    => 21,
        'chapter_3'    => 21,
        'chapter_4'    => 28,
        'chapter_5'    => 21,
        'final_report' => 28,
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
                    $this->createItem($template, $category, $definition, $index + 1);
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
     * Templates are keyed on (category, psm_part, version) so this seeder can
     * be run repeatedly without creating duplicates.
     *
     * Version 2 is the Proposal / Chapter 1-5 / Final Report chain. Version 1
     * is deliberately left in place: projects already in flight keep the
     * milestones, deadlines and weights they were given at registration, while
     * MilestoneTemplate::resolveFor() picks up v2 for every new project.
     */
    protected function createTemplate(ProjectCategory $category, string $psmPart): MilestoneTemplate
    {
        $lastOffset = collect($category->defaultMilestones())->max('offset_days');
        $lastDuration = (int) (self::DURATION_DAYS['final_report'] ?? 14);

        return MilestoneTemplate::updateOrCreate(
            [
                'category' => $category->value,
                'psm_part' => $psmPart,
                'version'  => 2,
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
    protected function createItem(
        MilestoneTemplate $template,
        ProjectCategory $category,
        array $definition,
        int $sequence,
    ): MilestoneTemplateItem {
        return MilestoneTemplateItem::updateOrCreate(
            [
                'milestone_template_id' => $template->id,
                'code'                  => $definition['code'],
            ],
            [
                'title'                  => $definition['title'],
                'description'            => $this->itemDescription($category, $definition['code'], $definition['title']),
                'deliverable_expectation' => $this->itemExpectation($category, $definition['code']),
                'sequence'               => $sequence,
                'offset_days'            => $definition['offset_days'],
                'duration_days'          => self::DURATION_DAYS[$definition['code']] ?? 14,
                'weight_percent'         => $definition['weight'],
                // The final report is the only milestone where bundling the
                // artefact alongside the written submission is expected; every
                // earlier chapter is submitted as a document.
                'allowed_file_types'     => $definition['code'] === 'final_report'
                    ? self::ARCHIVE_TYPES
                    : self::DOCUMENT_TYPES,
                'max_files'              => $definition['code'] === 'final_report' ? 5 : 3,
                'requires_supervisor_approval' => true,
            ]
        );
    }

    // -----------------------------------------------------------------
    // Copy
    // -----------------------------------------------------------------

    protected function describe(ProjectCategory $category, string $psmPart): string
    {
        $shape = 'proposal, chapters 1-5, final report';

        return $category === ProjectCategory::System
            ? "Chapter-by-chapter delivery plan for a system-development {$psmPart} project: {$shape}."
            : "Chapter-by-chapter delivery plan for a research {$psmPart} project: {$shape}.";
    }

    /**
     * What the student is being asked to submit.
     *
     * The chain is shared between categories, so this is where a system build
     * and an empirical study genuinely diverge: seven identical submissions,
     * different expectations for what each chapter contains.
     */
    protected function itemDescription(ProjectCategory $category, string $code, string $title): string
    {
        $system = [
            'proposal'     => 'Submit the project proposal for supervisor approval: problem statement, objectives, scope, requirements outline and expected outcome.',
            'chapter_1'    => 'Submit Chapter 1 — Introduction: background to the problem, aims and objectives, scope, and the contribution claimed.',
            'chapter_2'    => 'Submit Chapter 2 — Literature and requirements review: comparable systems reviewed critically, then functional and non-functional requirements elicited and specified.',
            'chapter_3'    => 'Submit Chapter 3 — System design: architecture, database design, interface specification, and the reasoning behind the alternatives rejected.',
            'chapter_4'    => 'Submit Chapter 4 — Implementation: the working build together with the source code and reproducible deployment instructions.',
            'chapter_5'    => 'Submit Chapter 5 — Testing and evaluation: test plan, results, defect log, and user evaluation of the finished system.',
            'final_report' => 'Submit the complete final report for examination, with all chapters consolidated into the faculty template.',
        ];

        $research = [
            'proposal'     => 'Submit the project proposal for supervisor approval: problem statement, research questions, scope and expected contribution.',
            'chapter_1'    => 'Submit Chapter 1 — Introduction: background to the problem, research questions and objectives, significance, and scope of the study.',
            'chapter_2'    => 'Submit Chapter 2 — Literature review: a critical, thematic synthesis of existing work establishing the gap this study addresses.',
            'chapter_3'    => 'Submit Chapter 3 — Methodology: research design, sampling, instruments, data-collection procedure and ethical considerations.',
            'chapter_4'    => 'Submit Chapter 4 — Results and analysis: findings presented with appropriate tables or figures and tested against the research questions.',
            'chapter_5'    => 'Submit Chapter 5 — Discussion and conclusion: interpretation of the findings against the literature, limitations, implications and conclusion.',
            'final_report' => 'Submit the complete final report for examination, with all chapters consolidated into the faculty template.',
        ];

        $copy = $category === ProjectCategory::System ? $system : $research;

        return $copy[$code] ?? "Submit {$title}.";
    }

    protected function itemExpectation(ProjectCategory $category, string $code): string
    {
        $shared = [
            'proposal'     => '3-5 pages: problem statement, objectives, scope, methodology outline, Gantt chart.',
            'final_report' => 'Full report following the faculty template, including references and appendices.',
        ];

        if (isset($shared[$code])) {
            return $shared[$code];
        }

        $system = [
            'chapter_1' => '3-5 pages: problem context, aims and objectives stated so they can be assessed, scope boundaries, and an explicit contribution statement.',
            'chapter_2' => 'Comparable systems reviewed critically rather than listed; requirements traceable to a stated source and written so they remain testable.',
            'chapter_3' => 'Architecture diagram, ERD and interface mockups, with design decisions justified against the alternatives considered.',
            'chapter_4' => 'Runnable build plus source archive; setup instructions must be reproducible by the examiner on a clean machine.',
            'chapter_5' => 'Test cases mapped to the chapter 2 requirements, with pass/fail evidence and outstanding defect severity.',
        ];

        $research = [
            'chapter_1' => '3-5 pages: problem context, research questions stated explicitly, significance of the work, and the boundaries of the study.',
            'chapter_2' => 'Minimum 15 peer-reviewed sources, synthesised thematically, not summarised one by one; the gap is stated and defended.',
            'chapter_3' => 'Justified research design, sampling strategy, instruments, and validity and ethics considerations.',
            'chapter_4' => 'Findings presented with appropriate tables or figures and discussed against the research questions set out in chapter 1.',
            'chapter_5' => 'Findings interpreted against the literature, limitations acknowledged honestly, and conclusions traceable to the evidence gathered.',
        ];

        $copy = $category === ProjectCategory::System ? $system : $research;

        return $copy[$code] ?? 'As specified in the project brief.';
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
