<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 8 — Global settings for the public recognition module.
 * Singleton row; access through current().
 */
class LeaderboardSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'default_top_n',
        'default_min_assessors',
        'default_ranking_basis',
        'module_enabled',
        'require_approval',
        'honour_opt_out',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'module_enabled'  => 'boolean',
            'require_approval'=> 'boolean',
            'honour_opt_out'  => 'boolean',
            'default_top_n'   => 'integer',
            'default_min_assessors' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Always returns a usable settings object, creating defaults on first use. */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'default_top_n'          => (int) config('psm.leaderboard.top_n', 3),
            'default_min_assessors'  => (int) config('psm.leaderboard.min_assessors', 2),
            'default_ranking_basis'  => 'final_mark',
            'module_enabled'         => true,
            'require_approval'       => false,
            'honour_opt_out'         => true,
        ]);
    }

    /** The public route is only served when the module is switched on. */
    public static function isPubliclyAvailable(): bool
    {
        return static::current()->module_enabled;
    }
}
