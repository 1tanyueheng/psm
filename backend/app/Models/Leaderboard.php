<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Module 8 — A published leaderboard snapshot.
 *
 * The public route reads only `published` rows, and only through
 * LeaderboardEntry's frozen columns. This is what keeps the public page
 * (a) free of live PII joins and (b) stable once published.
 */
class Leaderboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'description',
        'psm_part',
        'batch',
        'academic_session',
        'top_n',
        'min_assessors',
        'ranking_basis',
        'tie_breaker',
        'status',
        'published_at',
        'unpublished_at',
        'auto_publish_at',
        'created_by',
        'published_by',
        'show_abstract',
        'show_scores',
        'show_student_names',
        'show_program',
        'theme',
    ];

    protected function casts(): array
    {
        return [
            'show_abstract'       => 'boolean',
            'show_scores'         => 'boolean',
            'show_student_names'  => 'boolean',
            'show_program'        => 'boolean',
            'top_n'               => 'integer',
            'min_assessors'       => 'integer',
            'published_at'        => 'datetime',
            'unpublished_at'      => 'datetime',
            'auto_publish_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $board) {
            $board->slug ??= static::uniqueSlug($board->title);
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class)->orderBy('rank');
    }

    /** What the public page actually renders. */
    public function visibleEntries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class)
            ->where('is_hidden', false)
            ->where('is_top_n', true)
            ->orderBy('rank');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // -----------------------------------------------------------------

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** The URL segment the public page is reachable at. */
    public function publicPath(): string
    {
        $base = rtrim((string) config('psm.leaderboard.base_path', '/leaderboard'), '/');

        return "{$base}/{$this->slug}";
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$this->publicPath();
    }

    /** Publish: freeze the current entries and open the public route. */
    public function publish(?User $by = null): void
    {
        $this->update([
            'status'       => 'published',
            'published_at' => now(),
            'published_by' => $by?->id,
            'unpublished_at' => null,
        ]);
    }

    public function unpublish(?string $reason = null): void
    {
        $this->update([
            'status'         => 'unpublished',
            'unpublished_at' => now(),
            'description'    => $reason
                ? trim(($this->description ?? '')."\n\n".$reason)
                : $this->description,
        ]);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at');
    }

    public function scopeForPart(Builder $query, string $psmPart): Builder
    {
        return $query->whereIn('psm_part', [$psmPart, 'BOTH']);
    }

    /** The board a visitor should land on by default. */
    public static function currentPublic(): ?self
    {
        return static::query()
            ->published()
            ->orderByDesc('published_at')
            ->first();
    }

    private static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'leaderboard';
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-".(++$i);
        }

        return $slug;
    }
}
