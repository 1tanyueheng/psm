<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Module 7 — Append-only audit trail.
 *
 * There is no `updated_at` on this table, by design. Nothing in the
 * application may modify or delete a row; the only permitted removal is the
 * retention prune performed by the scheduled `audit:prune` command.
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'action',
        'category',
        'severity',
        'auditable_type',
        'auditable_id',
        'description',
        'before',
        'after',
        'changes',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
        'session_id',
        'is_suspicious',
    ];

    protected function casts(): array
    {
        return [
            'action'        => AuditAction::class,
            'before'        => 'array',
            'after'         => 'array',
            'changes'       => 'array',
            'is_suspicious' => 'boolean',
            'created_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /** "Dr. Lim reviewed the milestone" — rendered directly in the UI. */
    public function sentence(): string
    {
        $who = $this->actor_name ?? 'System';
        $action = $this->action instanceof AuditAction
            ? $this->action->label()
            : (string) $this->action;

        $what = $this->description ? ": {$this->description}" : '';

        return "{$who} — {$action}{$what}";
    }

    /** Field-level diff, ready for a two-column table in the UI. */
    public function changedFields(): array
    {
        return collect($this->changes ?? [])
            ->map(fn ($change, $field) => [
                'field' => $field,
                'from'  => $change['from'] ?? null,
                'to'    => $change['to'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function isSecurityRelevant(): bool
    {
        return $this->severity !== 'info'
            || ($this->action instanceof AuditAction && $this->action->isSecurityRelevant());
    }

    // -----------------------------------------------------------------
    // Scopes — used by the Module 7 search screen
    // -----------------------------------------------------------------

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('auditable_type', $subject::class)
                     ->where('auditable_id', $subject->getKey());
    }

    public function scopeForActor(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        return $query->where('is_suspicious', true);
    }

    /**
     * Free-text search across the human-readable columns. Deliberately avoids
     * the JSON columns, which MySQL cannot index for LIKE efficiently.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.$term.'%';

            $q->where('actor_name', 'like', $like)
              ->orWhere('description', 'like', $like)
              ->orWhere('action', 'like', $like)
              ->orWhere('ip_address', 'like', $like);
        });
    }
}
