<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 2 — The batches/programs a coordinator is responsible for.
 * Without a scope row a coordinator sees the whole faculty.
 */
class CoordinatorScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'batch',
        'program',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function describe(): string
    {
        return collect([$this->batch, $this->program])
            ->filter()
            ->implode(' · ') ?: 'All cohorts';
    }
}
