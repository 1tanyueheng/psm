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

    /** Default milestone chain seeded by MilestoneTemplateSeeder. */
    public function defaultMilestones(): array
    {
        return match ($this) {
            self::System => [
                ['code' => 'proposal',   'title' => 'Proposal',        'weight' => 10.0, 'offset_days' => 21],
                ['code' => 'design',     'title' => 'System Design',   'weight' => 15.0, 'offset_days' => 49],
                ['code' => 'implement',  'title' => 'Implementation',  'weight' => 30.0, 'offset_days' => 91],
                ['code' => 'testing',    'title' => 'Testing',         'weight' => 25.0, 'offset_days' => 119],
                ['code' => 'report',     'title' => 'Final Report',    'weight' => 20.0, 'offset_days' => 140],
            ],
            self::Research => [
                ['code' => 'proposal',   'title' => 'Proposal',        'weight' => 10.0, 'offset_days' => 21],
                ['code' => 'litreview',  'title' => 'Literature Review','weight' => 20.0, 'offset_days' => 49],
                ['code' => 'methods',    'title' => 'Methodology',     'weight' => 20.0, 'offset_days' => 77],
                ['code' => 'analysis',   'title' => 'Data Analysis',   'weight' => 30.0, 'offset_days' => 119],
                ['code' => 'report',     'title' => 'Final Report',    'weight' => 20.0, 'offset_days' => 140],
            ],
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
