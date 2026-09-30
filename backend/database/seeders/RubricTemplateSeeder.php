<?php

namespace Database\Seeders;

use App\Enums\AssessorType;
use App\Enums\ProjectCategory;
use App\Models\RubricComponent;
use App\Models\RubricCriterion;
use App\Models\RubricTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Module 4 — Rubric templates.
 *
 * Three assessor perspectives per category:
 *
 *   supervisor  — process, independence, engineering discipline (60% of grade)
 *   examiner    — the artefact and the defence (40% of grade)
 *   coordinator — used only for moderation or a special-case correction
 *
 * Two weight levels must both balance, and both are enforced here:
 *
 *   RubricTemplate → components sum to 100%
 *   RubricComponent → criteria  sum to 100%
 *
 * `max_marks` is stored on every criterion because the weighted contribution
 * shown to an assessor is computed from it. Without persistence, editing a
 * template later would silently change how an old assessment reads.
 *
 * The supervisor rubric (version 2) is structured one component per chapter,
 * matching the Proposal / Chapter 1-5 / Final Report submission chain. Each
 * component stores the milestone code it scores, which is what lets the
 * marking form show a chapter's submission status beside its mark.
 */
class RubricTemplateSeeder extends Seeder
{
    /** The coordinator account is recorded as the author of every template. */
    protected ?int $coordinatorId = null;

    public function run(): void
    {
        $this->coordinatorId = User::query()
            ->where('email', 'coordinator@psm.test')
            ->value('id');

        foreach (ProjectCategory::cases() as $category) {
            foreach ([AssessorType::Supervisor, AssessorType::Examiner, AssessorType::Coordinator] as $type) {
                $template = $this->createTemplate($category, $type);

                foreach ($this->componentsFor($category, $type) as $index => $definition) {
                    $this->createComponentWithCriteria($template, $definition, $index + 1);
                }

                $this->assertBalanced($template, $category, $type);
            }
        }

        $this->command?->info(sprintf(
            '  Rubrics: %d templates, %d components, %d criteria.',
            RubricTemplate::count(),
            RubricComponent::count(),
            RubricCriterion::count()
        ));
    }

    // -----------------------------------------------------------------
    // Template
    // -----------------------------------------------------------------

    protected function createTemplate(ProjectCategory $category, AssessorType $type): RubricTemplate
    {
        return RubricTemplate::updateOrCreate(
            [
                'category'      => $category->value,
                'psm_part'      => 'BOTH',
                'assessor_type' => $type->value,
                'version'       => 2,
            ],
            [
                'name'         => sprintf('%s — %s Rubric', $category->label(), $type->label()),
                'total_marks'  => 100.00,
                'pass_mark'    => 50.00,
                'is_active'    => true,
                // Only published rubrics can be used to mark (resolveFor filters on this)
                'is_published' => true,
                'description'  => $this->templateDescription($category, $type),
                'grading_guide' => $this->gradingGuide($type),
                'created_by'   => $this->coordinatorId,
            ]
        );
    }

    // -----------------------------------------------------------------
    // Components + criteria
    // -----------------------------------------------------------------

    /**
     * @param  array{code:string, milestone_code?:string, title:string, weight:float, description:string,
     *               comment_below?:bool, criteria:array<int,array{code:string,title:string,weight:float,guidance:string}>}  $definition
     */
    protected function createComponentWithCriteria(RubricTemplate $template, array $definition, int $sequence): RubricComponent
    {
        $component = RubricComponent::updateOrCreate(
            [
                'rubric_template_id' => $template->id,
                'code'               => $definition['code'],
            ],
            [
                'milestone_code'            => $definition['milestone_code'] ?? null,
                'title'                     => $definition['title'],
                'description'               => $definition['description'],
                'weight_percent'            => $definition['weight'],
                'sequence'                  => $sequence,
                'requires_comment_below'    => $definition['comment_below'] ?? false,
                'comment_threshold_percent' => 50.00,
            ]
        );

        $total = (float) $template->total_marks;

        foreach ($definition['criteria'] as $index => $criterion) {
            RubricCriterion::updateOrCreate(
                [
                    'rubric_component_id' => $component->id,
                    'code'                => $criterion['code'],
                ],
                [
                    'title'          => $criterion['title'],
                    'guidance'       => $criterion['guidance'],
                    // Absolute cap = template total × component share × criterion share
                    'max_marks'      => round(
                        $total * ($definition['weight'] / 100) * ($criterion['weight'] / 100),
                        2
                    ),
                    'weight_percent' => $criterion['weight'],
                    'sequence'       => $index + 1,
                    'is_required'    => true,
                ]
            );
        }

        return $component;
    }

    // -----------------------------------------------------------------
    // Rubric definitions
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    protected function componentsFor(ProjectCategory $category, AssessorType $type): array
    {
        return match ($type) {
            AssessorType::Supervisor => $this->supervisorChapters($category),
            AssessorType::Examiner   => $this->examinerRubric($category),
            AssessorType::Coordinator => $this->coordinatorRubric(),
        };
    }

    /**
     * Supervisor rubric.
     *
     * Structured one component per chapter, because that is how the work
     * actually arrives: the student submits Proposal, Chapters 1-5 and the
     * Final Report separately, and the supervisor marks them separately as
     * they are approved. Each component carries the milestone code it scores,
     * so the marking form can show that chapter's submission status beside
     * the mark being given for it.
     *
     * Chapter weights (85% in total) follow the milestone weights — the
     * middle chapters carry the most, the proposal least — with the remaining
     * 15% on supervision and professional practice, which is not attributable
     * to any single chapter. A good demo built in the last fortnight should
     * not out-score twelve weeks of disciplined supervision.
     */
    protected function supervisorChapters(ProjectCategory $category): array
    {
        $scaffolding = [
            ['code' => 'proposal',     'milestone_code' => 'proposal',     'title' => 'Proposal',                    'weight' => 8.00],
            ['code' => 'chapter_1',    'milestone_code' => 'chapter_1',    'title' => 'Chapter 1 — Introduction',    'weight' => 13.00],
            ['code' => 'chapter_2',    'milestone_code' => 'chapter_2',    'title' => 'Chapter 2 — Review & Requirements', 'weight' => 13.00],
            ['code' => 'chapter_3',    'milestone_code' => 'chapter_3',    'title' => 'Chapter 3 — Design',          'weight' => 13.00],
            ['code' => 'chapter_4',    'milestone_code' => 'chapter_4',    'title' => 'Chapter 4 — Implementation',  'weight' => 13.00],
            ['code' => 'chapter_5',    'milestone_code' => 'chapter_5',    'title' => 'Chapter 5 — Testing & Evaluation', 'weight' => 10.00],
            ['code' => 'final_report', 'milestone_code' => 'final_report', 'title' => 'Final Report',                'weight' => 15.00],
        ];

        $criteria = $category === ProjectCategory::System
            ? $this->chapterCriteriaSystem()
            : $this->chapterCriteriaResearch();

        $descriptions = $category === ProjectCategory::System
            ? $this->chapterDescriptionsSystem()
            : $this->chapterDescriptionsResearch();

        $components = [];

        foreach ($scaffolding as $item) {
            $components[] = [
                'code'          => $item['code'],
                'milestone_code'=> $item['milestone_code'],
                'title'         => $item['title'],
                'weight'        => $item['weight'],
                'description'   => $descriptions[$item['code']],
                'comment_below' => true,
                'criteria'      => $criteria[$item['code']],
            ];
        }

        // Supervision is assessed across the whole project rather than
        // against any one chapter, so this component carries no milestone code.
        $components[] = [
            'code'          => 'supervision',
            'milestone_code'=> null,
            'title'         => 'Supervision & Professional Practice',
            'weight'        => 15.00,
            'description'   => 'Engagement across the whole project, and the professional standard of the work.',
            'criteria'      => [
                ['code' => 'planning',    'title' => 'Planning and record keeping', 'weight' => 35.00, 'guidance' => 'A realistic plan exists, is kept current as work develops, and slip is acknowledged rather than hidden.'],
                ['code' => 'meetings',    'title' => 'Supervision engagement',      'weight' => 35.00, 'guidance' => 'Attends prepared, brings specific questions, acts on agreed actions before the next meeting.'],
                ['code' => 'initiative',  'title' => 'Initiative and integrity',    'weight' => 30.00, 'guidance' => 'Solves problems before escalating, proposes options rather than waiting for instructions, and acknowledges limits honestly.'],
            ],
        ];

        return $components;
    }

    /** @return array<string, array<int, array{code:string,title:string,weight:float,guidance:string}>> */
    protected function chapterCriteriaSystem(): array
    {
        return [
            'proposal' => [
                ['code' => 'objectives', 'title' => 'Objectives and scope',      'weight' => 50.00, 'guidance' => 'Aims and objectives are stated so precisely that the chapters that follow can be checked against them, and the boundaries of the build are explicit.'],
                ['code' => 'feasibility','title' => 'Feasibility and plan',      'weight' => 50.00, 'guidance' => 'The approach is achievable in the time available, and the plan shows realistic sequencing rather than an idealised one.'],
            ],
            'chapter_1' => [
                ['code' => 'framing',    'title' => 'Problem framing',          'weight' => 50.00, 'guidance' => 'The problem is established from evidence of need, not asserted, and the contribution is stated in terms a reader unfamiliar with the project can judge.'],
                ['code' => 'clarity',    'title' => 'Clarity and scope',         'weight' => 50.00, 'guidance' => 'Precise technical writing, and the scope stated here is the scope actually delivered.'],
            ],
            'chapter_2' => [
                ['code' => 'criticality','title' => 'Review of prior systems',  'weight' => 50.00, 'guidance' => 'Comparable systems are compared against each other on stated criteria rather than described one after another.'],
                ['code' => 'requirements','title' => 'Requirement quality',     'weight' => 50.00, 'guidance' => 'Requirements trace to a real stakeholder need and are written so they can be tested — and so chapter 5 can be checked against them.'],
            ],
            'chapter_3' => [
                ['code' => 'justification','title' => 'Design justification',   'weight' => 50.00, 'guidance' => 'Structural decisions are argued from constraints and requirements, with the alternatives rejected explained rather than ignored.'],
                ['code' => 'completeness', 'title' => 'Design completeness',     'weight' => 50.00, 'guidance' => 'Architecture, data model and interface are all specified at a level chapter 4 could be built from without inventing anything.'],
            ],
            'chapter_4' => [
                ['code' => 'functionality','title' => 'Functional completeness','weight' => 50.00, 'guidance' => 'The agreed scope works end to end without hand-holding during the demonstration.'],
                ['code' => 'code_quality', 'title' => 'Code quality',            'weight' => 50.00, 'guidance' => 'Readable, consistently structured, no dead code or copy-paste blocks, and errors are surfaced informatively.'],
            ],
            'chapter_5' => [
                ['code' => 'adequacy',   'title' => 'Test adequacy',            'weight' => 50.00, 'guidance' => 'Test cases map back to the chapter 2 requirements and cover failure paths, not only the happy path.'],
                ['code' => 'defects',     'title' => 'Defect handling and evaluation','weight' => 50.00, 'guidance' => 'Defects are logged with severity and resolution, and any user evaluation is reported honestly including what did not work.'],
            ],
            'final_report' => [
                ['code' => 'coherence',  'title' => 'Structure and coherence',  'weight' => 50.00, 'guidance' => 'Follows the faculty template, and the argument holds together from problem statement through to conclusion.'],
                ['code' => 'evidence',   'title' => 'Evidence and referencing', 'weight' => 50.00, 'guidance' => 'Claims are supported by results, citations or reasoning; sources verifiable and consistently referenced.'],
            ],
        ];
    }

    /** @return array<string, array<int, array{code:string,title:string,weight:float,guidance:string}>> */
    protected function chapterCriteriaResearch(): array
    {
        return [
            'proposal' => [
                ['code' => 'questions',  'title' => 'Research questions',       'weight' => 50.00, 'guidance' => 'The questions are explicit, answerable, and specific enough that chapter 4 can be read as a direct answer to them.'],
                ['code' => 'feasibility','title' => 'Feasibility and plan',      'weight' => 50.00, 'guidance' => 'The design is achievable within the time and access constraints, and the plan reflects realistic data collection.'],
            ],
            'chapter_1' => [
                ['code' => 'framing',    'title' => 'Problem framing',          'weight' => 50.00, 'guidance' => 'The problem is grounded in evidence of need, and the significance of answering it is argued rather than assumed.'],
                ['code' => 'clarity',    'title' => 'Clarity and boundaries',    'weight' => 50.00, 'guidance' => 'Precise technical writing, with the boundaries of the study stated so the findings are not over-claimed.'],
            ],
            'chapter_2' => [
                ['code' => 'sources',    'title' => 'Breadth and currency of sources','weight' => 30.00, 'guidance' => 'Sufficient peer-reviewed material, current and genuinely relevant to the question.'],
                ['code' => 'synthesis',  'title' => 'Critical synthesis',       'weight' => 40.00, 'guidance' => 'Sources are compared and contrasted thematically, converging on a position, not listed one after another.'],
                ['code' => 'gap',        'title' => 'Identification of the gap','weight' => 30.00, 'guidance' => 'The specific gap this study addresses is stated and defended against the work already done.'],
            ],
            'chapter_3' => [
                ['code' => 'design',     'title' => 'Research design',          'weight' => 40.00, 'guidance' => 'The design is appropriate to the question and justified against the alternatives available.'],
                ['code' => 'sampling',   'title' => 'Sampling and instruments', 'weight' => 30.00, 'guidance' => 'Population, sample and instruments are described and defensible for the claims being made.'],
                ['code' => 'validity',   'title' => 'Validity, ethics and limitations','weight' => 30.00, 'guidance' => 'Threats to validity are acknowledged honestly, and consent, anonymity and data handling are addressed.'],
            ],
            'chapter_4' => [
                ['code' => 'analysis',   'title' => 'Analytical soundness',     'weight' => 40.00, 'guidance' => 'The analysis technique fits the data type, is applied correctly, and the reported results follow from it.'],
                ['code' => 'presentation','title' => 'Presentation of findings','weight' => 30.00, 'guidance' => 'Findings are presented with appropriate tables or figures and can be followed without the raw data in hand.'],
                ['code' => 'answers',    'title' => 'Answer to the questions',  'weight' => 30.00, 'guidance' => 'Each research question from chapter 1 is answered explicitly, including where the answer is inconclusive.'],
            ],
            'chapter_5' => [
                ['code' => 'interpretation','title' => 'Interpretation against the literature','weight' => 40.00, 'guidance' => 'Findings are interpreted in light of prior work, and where they contradict it, that is addressed rather than avoided.'],
                ['code' => 'limitations','title' => 'Limitations',             'weight' => 30.00, 'guidance' => 'Limitations are acknowledged honestly, with their likely effect on the conclusions stated.'],
                ['code' => 'conclusion', 'title' => 'Conclusion and implications','weight' => 30.00, 'guidance' => 'Conclusions are traceable to the evidence gathered, and implications are neither overstated nor vague.'],
            ],
            'final_report' => [
                ['code' => 'coherence',  'title' => 'Structure and coherence',  'weight' => 50.00, 'guidance' => 'Follows the faculty template, and the argument holds together from research question through to conclusion.'],
                ['code' => 'evidence',   'title' => 'Evidence and referencing', 'weight' => 50.00, 'guidance' => 'Claims are supported by results, citations or reasoning; sources verifiable and consistently referenced.'],
            ],
        ];
    }

    /** @return array<string, string> */
    protected function chapterDescriptionsSystem(): array
    {
        return [
            'proposal'     => 'The agreed scope of the build, and whether it was realistic when first proposed.',
            'chapter_1'    => 'Whether the problem and the claimed contribution are framed convincingly.',
            'chapter_2'    => 'Awareness of prior work, and the quality of the requirements derived from it.',
            'chapter_3'    => 'Quality of the design decisions and how completely they were specified.',
            'chapter_4'    => 'Quality of the working system actually delivered, and the code behind it.',
            'chapter_5'    => 'Verification discipline and honesty about what the evaluation found.',
            'final_report' => 'The consolidated document as submitted for examination.',
        ];
    }

    /** @return array<string, string> */
    protected function chapterDescriptionsResearch(): array
    {
        return [
            'proposal'     => 'The agreed scope of the study, and whether it was realistic when first proposed.',
            'chapter_1'    => 'Whether the problem, the questions and the significance are framed convincingly.',
            'chapter_2'    => 'Depth and criticality of engagement with existing work.',
            'chapter_3'    => 'Whether the method could actually answer the research questions.',
            'chapter_4'    => 'Whether the analysis is sound and the findings are reported faithfully.',
            'chapter_5'    => 'How the findings were interpreted, and how honestly their limits are stated.',
            'final_report' => 'The consolidated document as submitted for examination.',
        ];
    }

    /**
     * Examiner rubric. Retained by the faculty as an external check on the
     * same deliverables the supervisor has already seen.
     */
    protected function examinerRubric(ProjectCategory $category): array
    {
        $artefact = $category === ProjectCategory::System
            ? [
                'code'        => 'artefact',
                'title'       => 'Deliverable Quality',
                'weight'      => 40.00,
                'description' => 'Independent judgement of the delivered system or study.',
                'criteria'    => [
                    ['code' => 'completeness','title' => 'Completeness against the brief','weight' => 35.00, 'guidance' => 'Everything promised in the proposal is present and working.'],
                    ['code' => 'technical',   'title' => 'Technical soundness',          'weight' => 35.00, 'guidance' => 'The implementation or analysis is technically correct and non-trivial.'],
                    ['code' => 'usability',   'title' => 'Usability and finish',         'weight' => 30.00, 'guidance' => 'The artefact is presentable to a real user without explanation.'],
                ],
            ]
            : [
                'code'        => 'artefact',
                'title'       => 'Study Quality',
                'weight'      => 40.00,
                'description' => 'Independent judgement of the study as submitted.',
                'criteria'    => [
                    ['code' => 'completeness','title' => 'Completeness against the protocol','weight' => 35.00, 'guidance' => 'All planned data collection and analysis is present and accounted for.'],
                    ['code' => 'technical',   'title' => 'Analytical soundness',             'weight' => 35.00, 'guidance' => 'Analysis is correct, and reported results match the data.'],
                    ['code' => 'reproducibility','title' => 'Reproducibility',               'weight' => 30.00, 'guidance' => 'Another researcher could repeat the study from the report alone.'],
                ],
            ];

        return [
            [
                'code'        => 'report',
                'title'       => 'Final Report',
                'weight'      => 35.00,
                'description' => 'The written submission, judged on its own terms.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'structure',  'title' => 'Structure and organisation','weight' => 30.00, 'guidance' => 'Follows the faculty template; sections are proportionate to their importance.'],
                    ['code' => 'clarity',    'title' => 'Clarity of expression',     'weight' => 35.00, 'guidance' => 'Precise technical writing; jargon is defined before use.'],
                    ['code' => 'evidence',   'title' => 'Use of evidence',           'weight' => 35.00, 'guidance' => 'Claims are supported by results, citations or reasoning.'],
                ],
            ],
            $artefact,
            [
                'code'        => 'defence',
                'title'       => 'Presentation & Defence',
                'weight'      => 25.00,
                'description' => 'The oral examination.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'presentation','title' => 'Presentation quality',   'weight' => 30.00, 'guidance' => 'Clear, well-paced, within time, visuals support rather than replace the speaker.'],
                    ['code' => 'defence_qa',  'title' => 'Response to questions',  'weight' => 45.00, 'guidance' => 'Answers directly; distinguishes what is known from what is assumed.'],
                    ['code' => 'contribution','title' => 'Contribution awareness','weight' => 25.00, 'guidance' => 'Can state the contribution precisely and place it in the field.'],
                ],
            ],
        ];
    }

    /**
     * Coordinator rubric. Deliberately broad — it exists for moderation and
     * for grading a student whose normal assessors are unavailable (e.g. a
     * supervisor on extended leave), not for routine marking.
     */
    protected function coordinatorRubric(): array
    {
        return [
            [
                'code'        => 'compliance',
                'title'       => 'Process Compliance',
                'weight'      => 40.00,
                'description' => 'Whether the project met the faculty\'s procedural requirements.',
                'criteria'    => [
                    ['code' => 'milestones',   'title' => 'Milestone completion',    'weight' => 40.00, 'guidance' => 'All required deliverables were submitted and approved, within the published window.'],
                    ['code' => 'supervision',  'title' => 'Supervision record',      'weight' => 30.00, 'guidance' => 'Documented supervision minutes and feedback history exist.'],
                    ['code' => 'format',       'title' => 'Formatting compliance',   'weight' => 30.00, 'guidance' => 'Submission obeyed the template, referencing style and page limits.'],
                ],
            ],
            [
                'code'        => 'standards',
                'title'       => 'Academic Standards',
                'weight'      => 60.00,
                'description' => 'Moderation judgement on the overall standard achieved.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'level',       'title' => 'Level against the descriptor','weight' => 40.00, 'guidance' => 'Work sits correctly against the final-year grade descriptors.'],
                    ['code' => 'consistency', 'title' => 'Consistency of marking',      'weight' => 35.00, 'guidance' => 'Assessor marks agree with each other within acceptable tolerance.'],
                    ['code' => 'integrity',   'title' => 'Academic integrity',          'weight' => 25.00, 'guidance' => 'Original work, correctly attributed; no unacknowledged assistance.'],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Both weight levels must total 100. If either is off, every mark
     * computed against the rubric would be quietly wrong — so throw now,
     * while the mistake is still cheap to fix.
     */
    protected function assertBalanced(RubricTemplate $template, ProjectCategory $category, AssessorType $type): void
    {
        $componentTotal = (float) $template->components()->sum('weight_percent');

        if (abs($componentTotal - 100.0) > 0.01) {
            throw new \RuntimeException(sprintf(
                'Rubric %s/%s has component weights totalling %.2f%%, expected 100%%.',
                $category->value,
                $type->value,
                $componentTotal
            ));
        }

        foreach ($template->components()->with('criteria')->get() as $component) {
            $criterionTotal = (float) $component->criteria->sum('weight_percent');

            if (abs($criterionTotal - 100.0) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'Rubric %s/%s component [%s] has criterion weights totalling %.2f%%, expected 100%%.',
                    $category->value,
                    $type->value,
                    $component->code,
                    $criterionTotal
                ));
            }
        }

        // max_marks are derived, so verify one worked example: the sum of every
        // criterion's max_marks must equal the rubric total.
        $maxMarksTotal = round((float) RubricCriterion::query()
            ->whereIn('rubric_component_id', $template->components()->pluck('id'))
            ->sum('max_marks'), 2);

        $templateTotal = (float) $template->total_marks;

        if (abs($maxMarksTotal - $templateTotal) > 0.05) {
            throw new \RuntimeException(sprintf(
                'Rubric %s/%s criterion max_marks total %.2f, expected %.2f.',
                $category->value,
                $type->value,
                $maxMarksTotal,
                $templateTotal
            ));
        }
    }

    // -----------------------------------------------------------------
    // Copy
    // -----------------------------------------------------------------

    protected function templateDescription(ProjectCategory $category, AssessorType $type): string
    {
        return match ($type) {
            AssessorType::Supervisor => $category === ProjectCategory::System
                ? 'Marks each submitted chapter of a system-development project in turn, plus supervision and professional practice across the whole project.'
                : 'Marks each submitted chapter of a research project in turn, plus supervision and professional practice across the whole project.',
            AssessorType::Examiner => $category === ProjectCategory::System
                ? 'Independent assessment of the written report, the delivered system and the oral defence.'
                : 'Independent assessment of the written report, the study as conducted and the oral defence.',
            AssessorType::Coordinator => 'Faculty-level moderation rubric. Used for moderating assessor marks or covering an unavailable assessor.',
        };
    }

    protected function gradingGuide(AssessorType $type): string
    {
        $shared = <<<'TXT'
        Band descriptors (percentage of the criterion's max marks):
          85-100  Outstanding   — publishable quality, exceeds expectations
          75-84   Excellent     — strong, minor gaps only
          65-74   Good          — solid, meets expectations in most respects
          55-64   Satisfactory  — acceptable, clear room for improvement
          50-54   Pass          — meets the minimum standard, just
          40-49   Weak          — significant shortcomings
           0-39   Insufficient  — does not meet the requirement

        Mark each criterion independently before forming an overall impression.
        Where a criterion is marked below 50%, an explanatory comment is required.
        TXT;

        return match ($type) {
            AssessorType::Supervisor => $shared."\n\nAs supervisor you mark each chapter as it is submitted and approved, then form an overall judgement of the project. Where a chapter has not yet been submitted, leave it unmarked rather than scoring a guess — the form cannot be submitted until every component is marked.",
            AssessorType::Examiner   => $shared."\n\nAs examiner you mark what is in front of you. Judge the submission on its own merits, independent of the supervisor's view.",
            AssessorType::Coordinator => $shared."\n\nThis rubric is for moderation. If you are changing an assessor's mark, the reason must be recorded.",
        };
    }
}
