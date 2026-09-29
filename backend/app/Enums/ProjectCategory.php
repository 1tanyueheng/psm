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
            self::System   => 'تطوير نظام',
            self::Research => 'بحث علمي',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System   => 'بناء منتج عملي: المتطلبات، التصميم، التنفيذ، الاختبار، والنشر.',
            self::Research => 'إنتاج نتائج تجريبية: مراجعة الأدبيات، المنهجية، جمع البيانات، التحليل، والاستنتاج.',
        };
    }

    /** Default milestone chain seeded by MilestoneTemplateSeeder. */
    public function defaultMilestones(): array
    {
        return match ($this) {
            self::System => [
                ['code' => 'proposal',   'title' => 'المقترح',        'weight' => 10.0, 'offset_days' => 21],
                ['code' => 'design',     'title' => 'تصميم النظام',   'weight' => 15.0, 'offset_days' => 49],
                ['code' => 'implement',  'title' => 'التنفيذ',        'weight' => 30.0, 'offset_days' => 91],
                ['code' => 'testing',    'title' => 'الاختبار',       'weight' => 25.0, 'offset_days' => 119],
                ['code' => 'report',     'title' => 'التقرير النهائي', 'weight' => 20.0, 'offset_days' => 140],
            ],
            self::Research => [
                ['code' => 'proposal',   'title' => 'المقترح',        'weight' => 10.0, 'offset_days' => 21],
                ['code' => 'litreview',  'title' => 'مراجعة الأدبيات', 'weight' => 20.0, 'offset_days' => 49],
                ['code' => 'methods',    'title' => 'المنهجية',       'weight' => 20.0, 'offset_days' => 77],
                ['code' => 'analysis',   'title' => 'تحليل البيانات', 'weight' => 30.0, 'offset_days' => 119],
                ['code' => 'report',     'title' => 'التقرير النهائي', 'weight' => 20.0, 'offset_days' => 140],
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
