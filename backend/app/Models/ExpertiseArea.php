<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Module 2 — Controlled vocabulary of research/technical expertise areas.
 * A controlled list (rather than free text) is what makes Module 5's
 * "supervisor matching" and workload-by-domain reports possible.
 */
class ExpertiseArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $area) {
            $area->slug ??= Str::slug($area->name);
        });
    }

    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(
            SupervisorProfile::class,
            'supervisor_expertise',
            'expertise_area_id',
            'supervisor_profile_id'
        )->withPivot('proficiency')->withTimestamps();
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
