<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Module 3 — Project category.
 *
 * The category is not cosmetic: it selects which MilestoneTemplate and which
 * RubricTemplate apply to a project (see MilestoneService::instantiateFor()).
 */
enum ProjectCategory: string
{
    case System   = 'system';
    case Research = 'research';

    public function label(): string
    {
        return match ($this) {
            self::System   => 'System Development',
            self::Research => 'Research',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System   => 'Builds a working artefact: requirements, design, implementation, testing, deployment.',
            self::Research => 'Produces empirical findings: literature review, methodology, data collection, analysis, conclusion.',
        };
    }

    /**
     * Default milestone chain seeded by MilestoneTemplateSeeder.
     *
     * The chain is deliberately identical for both categories: a PSM project
     * is assessed as a written document that is built up chapter by chapter,
     * and a system-development project and a research project are marked
     * against the same chapter structure. What differs between the two is the
     * *content* expected inside a chapter, not the shape of the submission
     * chain — that lives in the per-chapter guidance below and in the rubric.
     *
     * The chain DOES differ between PSM 1 and PSM 2, because the two parts
     * cover different chapters — see the per-part chains below.
     *
     * Each weight is also the milestone's share of overall project progress,
     * so a chapter worth 15% moves the progress bar by 15% once it is
     * approved. See Project::milestoneProgressPercent().
     *
     * @param  string  $psmPart  'PSM1' or 'PSM2'
     * @return array<int, array{code:string, title:string, weight:float, offset_days:int}>
     */
    public function defaultMilestones(string $psmPart = 'PSM1'): array
    {
        /**
         * PSM 1 — the proposal and Chapters 1 to 4.
         *
         * Runs from the problem statement through to an implemented system or
         * a completed analysis. Chapter 4 carries the largest single weight
         * because it is where the bulk of the work lands.
         */
        $psm1 = [
            ['code' => 'proposal',     'title' => 'Proposal',     'weight' => 10.0, 'offset_days' => 21],

            /**
             * OSHE — the occupational safety, health and environment form.
             *
             * Sits **immediately after the proposal**, because it is the gate on
             * *doing* the project work rather than on writing it: the faculty
             * will not let a student into a lab or onto a site without a signed
             * OSHE form, and the implementation starts with Chapter 1's
             * requirements work. Moving it here from after Chapter 4 means the
             * form is settled before the student needs access, rather than
             * mid-implementation.
             *
             * **Carries no weight.** It is a compliance document, not an
             * assessment: giving it marks would dilute every other milestone's
             * percentage for a form nobody is graded on. Zero weight still lets
             * it gate the chain, which is what it is for.
             *
             * Two-party: the student uploads the filled form, the supervisor
             * signs it and uploads the signed copy back. See
             * OsheController.
             *
             * On a project whose panel approved the title **conditionally**, the
             * Lampiran C corrections milestone is inserted between this and the
             * proposal (see ProposalReviewService::openLampiranCMilestone), so
             * the effective order there is proposal -> lampiran_c -> oshe.
             */
            ['code' => 'oshe',         'title' => 'OSHE Form',    'weight' => 0.0,  'offset_days' => 28],

            ['code' => 'chapter_1',    'title' => 'Chapter 1',    'weight' => 15.0, 'offset_days' => 42],
            ['code' => 'chapter_2',    'title' => 'Chapter 2',    'weight' => 15.0, 'offset_days' => 63],
            ['code' => 'chapter_3',    'title' => 'Chapter 3',    'weight' => 15.0, 'offset_days' => 84],
            ['code' => 'chapter_4',    'title' => 'Chapter 4',    'weight' => 20.0, 'offset_days' => 112],

            /**
             * The system demo — a face-to-face session, marked on Lampiran R1.
             *
             * **The one R1 milestone with nothing to submit.** The student
             * demonstrates a working system to the supervisor; there is no
             * document to upload, so `weight` stays 0 (the demo is judged, not
             * scored as a deliverable) and the R1 sheet is filled in during the
             * session.
             *
             * Placed after Chapter 4 because that is where the implementation is
             * signed off.
             */
            ['code' => 'demo',         'title' => 'System Demo',  'weight' => 0.0,  'offset_days' => 119],

            ['code' => 'final_report', 'title' => 'Final Report', 'weight' => 25.0, 'offset_days' => 140],
        ];

        /**
         * PSM 2 — Chapters 5 to 7 and the consolidated report.
         *
         * PSM 1 and PSM 2 are one project run across two consecutive terms, on
         * one title: PSM 1 delivers the proposal and Chapters 1–4, PSM 2
         * delivers Chapters 5–7 and assembles the whole document. **Chapter 4
         * belongs to PSM 1 only.**
         *
         * An earlier version of this chain opened with `chapter_4`, carrying the
         * same title, weight and description as PSM 1's. A student running
         * PSM 1 → PSM 2 on one title was therefore asked to submit the identical
         * Chapter 4 milestone twice. The overlap was documented as intentional
         * at the time, but it contradicts the process: the implementation is
         * signed off in PSM 1, and PSM 2 examines what was built rather than
         * rebuilding it.
         *
         * The chain is shorter than PSM 1's, so the chapters carry more weight
         * each and the final report carries more still — by this point the
         * consolidated document is most of what is being examined.
         */
        $psm2 = [
            ['code' => 'chapter_5',    'title' => 'Chapter 5',    'weight' => 30.0, 'offset_days' => 21],
            ['code' => 'chapter_6',    'title' => 'Chapter 6',    'weight' => 20.0, 'offset_days' => 56],
            ['code' => 'chapter_7',    'title' => 'Chapter 7',    'weight' => 20.0, 'offset_days' => 91],
            ['code' => 'final_report', 'title' => 'Final Report', 'weight' => 30.0, 'offset_days' => 126],
        ];

        return match ($psmPart) {
            'PSM2'  => $psm2,
            'PSM1'  => $psm1,
            // 'BOTH' spans two parts and therefore has no single chain. Failing
            // loudly is better than silently handing it PSM 1's chapters and
            // producing a project that is missing half its deliverables.
            default => throw new InvalidArgumentException(
                "No milestone chain is defined for PSM part [{$psmPart}]. "
                .'Expected PSM1 or PSM2.'
            ),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
