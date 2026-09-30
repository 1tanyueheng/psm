<?php

namespace App\Enums;

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
     * Each weight is also the milestone's share of overall project progress,
     * so a chapter worth 15% moves the progress bar by 15% once it is
     * approved. See Project::milestoneProgressPercent().
     */
    public function defaultMilestones(): array
    {
        $chain = [
            ['code' => 'proposal',     'title' => 'Proposal',     'weight' => 10.0, 'offset_days' => 21],
            ['code' => 'chapter_1',    'title' => 'Chapter 1',    'weight' => 15.0, 'offset_days' => 42],
            ['code' => 'chapter_2',    'title' => 'Chapter 2',    'weight' => 15.0, 'offset_days' => 63],
            ['code' => 'chapter_3',    'title' => 'Chapter 3',    'weight' => 15.0, 'offset_days' => 84],
            ['code' => 'chapter_4',    'title' => 'Chapter 4',    'weight' => 15.0, 'offset_days' => 105],
            ['code' => 'chapter_5',    'title' => 'Chapter 5',    'weight' => 10.0, 'offset_days' => 119],
            ['code' => 'final_report', 'title' => 'Final Report', 'weight' => 20.0, 'offset_days' => 140],
        ];

        // One chain, both categories. `description()` explains the difference
        // in what a chapter is expected to contain.
        return $chain;
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
