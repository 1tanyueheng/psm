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
                'version'       => 1,
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
     * @param  array{code:string, title:string, weight:float, description:string,
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
            AssessorType::Supervisor => $category === ProjectCategory::System
                ? $this->supervisorSystem()
                : $this->supervisorResearch(),
            AssessorType::Examiner => $this->examinerRubric($category),
            AssessorType::Coordinator => $this->coordinatorRubric(),
        };
    }

    /**
     * Supervisor rubric, system-development category.
     * Emphasis on sustained process: a good demo built in the last fortnight
     * should not out-score twelve weeks of disciplined engineering.
     */
    protected function supervisorSystem(): array
    {
        return [
            [
                'code'        => 'process',
                'title'       => 'Project Process & Management',
                'weight'      => 25.00,
                'description' => 'Consistency of engagement across the whole project, not just at deadlines.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'planning',    'title' => 'Planning and scheduling',   'weight' => 30.00, 'guidance' => 'A realistic plan exists, is kept current, and slip is acknowledged rather than hidden.'],
                    ['code' => 'meetings',    'title' => 'Supervision engagement',    'weight' => 30.00, 'guidance' => 'Attends prepared, brings specific questions, acts on agreed actions before the next meeting.'],
                    ['code' => 'documentation','title' => 'Working documentation',   'weight' => 20.00, 'guidance' => 'Notes, decisions and design changes are recorded as they happen.'],
                    ['code' => 'independence','title' => 'Independence and initiative','weight' => 20.00, 'guidance' => 'Solves problems before escalating; proposes options rather than waiting for instructions.'],
                ],
            ],
            [
                'code'        => 'requirements',
                'title'       => 'Requirements & Design Quality',
                'weight'      => 25.00,
                'description' => 'Whether the right problem was understood and the design answers it.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'elicitation', 'title' => 'Requirement elicitation',  'weight' => 35.00, 'guidance' => 'Requirements trace to real stakeholder needs and are testable.'],
                    ['code' => 'architecture','title' => 'Architecture and design',  'weight' => 35.00, 'guidance' => 'Structure is justified against alternatives; separation of concerns is visible in the code.'],
                    ['code' => 'data_model',  'title' => 'Data modelling',           'weight' => 30.00, 'guidance' => 'Schema is normalised appropriately, constraints enforce the domain rules.'],
                ],
            ],
            [
                'code'        => 'implementation',
                'title'       => 'Implementation',
                'weight'      => 30.00,
                'description' => 'Quality of the working system actually delivered.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'functionality','title' => 'Functional completeness','weight' => 40.00, 'guidance' => 'The agreed scope works end to end without hand-holding during the demo.'],
                    ['code' => 'code_quality', 'title' => 'Code quality',           'weight' => 30.00, 'guidance' => 'Readable, consistently structured, no dead code or copy-paste blocks.'],
                    ['code' => 'robustness',   'title' => 'Robustness',             'weight' => 30.00, 'guidance' => 'Handles invalid input and failure states without crashing; errors are informative.'],
                ],
            ],
            [
                'code'        => 'professionalism',
                'title'       => 'Professional Practice',
                'weight'      => 20.00,
                'description' => 'Working as a computing professional, not just completing an assignment.',
                'criteria'    => [
                    ['code' => 'ethics',   'title' => 'Ethical and security awareness','weight' => 35.00, 'guidance' => 'Considers data protection, user privacy and access control explicitly.'],
                    ['code' => 'testing',  'title' => 'Verification discipline',      'weight' => 35.00, 'guidance' => 'Tests are purposeful and cover edge cases; failures are investigated, not ignored.'],
                    ['code' => 'reflection','title' => 'Critical reflection',         'weight' => 30.00, 'guidance' => 'Can articulate what they would do differently and why.'],
                ],
            ],
        ];
    }

    /**
     * Supervisor rubric, research category.
     * The equivalent weight sits on methodological rigour instead of build quality.
     */
    protected function supervisorResearch(): array
    {
        return [
            [
                'code'        => 'process',
                'title'       => 'Project Process & Management',
                'weight'      => 25.00,
                'description' => 'Consistency of engagement across the whole research project.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'planning',    'title' => 'Research planning',        'weight' => 30.00, 'guidance' => 'A research timetable exists and is revised sensibly as the work develops.'],
                    ['code' => 'meetings',    'title' => 'Supervision engagement',   'weight' => 30.00, 'guidance' => 'Prepared for supervision; acts on methodological feedback.'],
                    ['code' => 'recordkeeping','title' => 'Research record keeping', 'weight' => 20.00, 'guidance' => 'Data, decisions and analysis steps are logged reproducibly.'],
                    ['code' => 'independence','title' => 'Independent scholarship',  'weight' => 20.00, 'guidance' => 'Drives the enquiry; reads beyond the reading list.'],
                ],
            ],
            [
                'code'        => 'literature',
                'title'       => 'Literature Review',
                'weight'      => 25.00,
                'description' => 'Depth and criticality of engagement with existing work.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'coverage',   'title' => 'Breadth of sources',       'weight' => 30.00, 'guidance' => 'Sufficient peer-reviewed material, current and relevant to the question.'],
                    ['code' => 'criticality','title' => 'Critical synthesis',       'weight' => 40.00, 'guidance' => 'Sources are compared and contrasted thematically, not listed one after another.'],
                    ['code' => 'gap',        'title' => 'Identification of the gap','weight' => 30.00, 'guidance' => 'The specific gap this study addresses is stated and defended.'],
                ],
            ],
            [
                'code'        => 'method',
                'title'       => 'Methodology & Rigour',
                'weight'      => 30.00,
                'description' => 'Whether the method can actually answer the research question.',
                'comment_below' => true,
                'criteria'    => [
                    ['code' => 'design',     'title' => 'Research design',          'weight' => 30.00, 'guidance' => 'Design is appropriate and justified against alternatives.'],
                    ['code' => 'sampling',   'title' => 'Sampling and instruments', 'weight' => 25.00, 'guidance' => 'Population, sample and instruments are described and defensible.'],
                    ['code' => 'analysis',   'title' => 'Analysis technique',       'weight' => 25.00, 'guidance' => 'Chosen analysis fits the data type and the question asked.'],
                    ['code' => 'validity',   'title' => 'Validity and limitations', 'weight' => 20.00, 'guidance' => 'Threats to validity are acknowledged honestly, not glossed over.'],
                ],
            ],
            [
                'code'        => 'academic',
                'title'       => 'Academic Writing & Scholarship',
                'weight'      => 20.00,
                'description' => 'Conventions of the discipline.',
                'criteria'    => [
                    ['code' => 'argument',   'title' => 'Argument and coherence',  'weight' => 35.00, 'guidance' => 'A clear thread runs from question to conclusion.'],
                    ['code' => 'referencing','title' => 'Citation and referencing', 'weight' => 35.00, 'guidance' => 'Consistent style, sources verifiable, no uncited borrowing.'],
                    ['code' => 'ethics',     'title' => 'Research ethics',          'weight' => 30.00, 'guidance' => 'Consent, anonymity and data handling are addressed where human subjects are involved.'],
                ],
            ],
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
                ? 'Marks the supervised process, design quality and the delivered system across the whole project.'
                : 'Marks the supervised research process, literature engagement and methodological rigour.',
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
            AssessorType::Supervisor => $shared."\n\nAs supervisor you mark the process and the whole arc of the project, not only the final artefact.",
            AssessorType::Examiner   => $shared."\n\nAs examiner you mark what is in front of you. Judge the submission on its own merits, independent of the supervisor's view.",
            AssessorType::Coordinator => $shared."\n\nThis rubric is for moderation. If you are changing an assessor's mark, the reason must be recorded.",
        };
    }
}
