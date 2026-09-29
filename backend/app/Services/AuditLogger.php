<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Module 7 — The single write path for the audit trail.
 *
 * Every controller, service and job that changes state must go through here.
 * Centralising it means the actor, IP and request context are captured
 * consistently and can never be forgotten at a call site.
 */
class AuditLogger
{
    /** Fields that must never be written to the trail, whatever the model. */
    protected array $alwaysExcluded = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'token',
    ];

    /**
     * Record an action.
     *
     * @param  AuditAction  $action      What happened
     * @param  string|null  $description Human sentence fragment
     * @param  Model|null   $subject     The thing acted upon
     * @param  array        $before      Snapshot before the change
     * @param  array        $after       Snapshot after the change
     * @param  User|null    $actor       Override the actor (for jobs/cron)
     */
    public function log(
        AuditAction $action,
        ?string $description = null,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        $before = $this->scrub($before);
        $after  = $this->scrub($after);

        return AuditLog::create([
            'user_id'        => $actor?->id,
            'actor_name'     => $actor?->name ?? 'System',
            'actor_role'     => $actor?->role?->value,
            'action'         => $action->value,
            'category'       => $action->category(),
            'severity'       => $action->severity(),
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id'   => $subject?->getKey(),
            'description'    => $description,
            'before'         => $before ?: null,
            'after'          => $after ?: null,
            'changes'        => $this->diff($before, $after) ?: null,
            'ip_address'     => $this->clientIp(),
            'user_agent'     => $this->userAgent(),
            'request_method' => Request::method(),
            'request_url'    => substr((string) Request::fullUrl(), 0, 512),
            'session_id'     => $this->sessionId(),
            'is_suspicious'  => $this->isSuspicious($action, $actor),
        ]);
    }

    /** Convenience wrapper for a model update, computing the diff automatically. */
    public function logModelChange(
        AuditAction $action,
        Model $subject,
        array $before,
        ?string $description = null,
    ): AuditLog {
        return $this->log(
            action: $action,
            description: $description,
            subject: $subject,
            before: $before,
            after: $subject->getAttributes(),
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Remove secrets and bulky columns before persisting. */
    protected function scrub(array $attributes): array
    {
        $excluded = array_merge(
            $this->alwaysExcluded,
            config('psm.audit.excluded_fields', [])
        );

        $attributes = array_diff_key($attributes, array_flip($excluded));

        return collect($attributes)
            ->map(function ($value) {
                // Keep the trail readable: truncate long text blobs
                if (is_string($value) && strlen($value) > 2000) {
                    return substr($value, 0, 2000).'…';
                }

                // JSON strings are useful as-is but should not be double-encoded
                if ($value instanceof \DateTimeInterface) {
                    return $value->format(DATE_ATOM);
                }

                return $value;
            })
            ->all();
    }

    /** Only the fields that actually changed, as ['field' => ['from' =>, 'to' =>]]. */
    protected function diff(array $before, array $after): array
    {
        $changes = [];

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to   = $after[$key] ?? null;

            if ($from != $to) {
                $changes[$key] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** Flag entries that warrant a second look in the security view. */
    protected function isSuspicious(AuditAction $action, ?User $actor): bool
    {
        // A state-changing action with no authenticated actor is worth flagging,
        // unless it is a genuinely unauthenticated event such as a failed login.
        return $actor === null
            && $action !== AuditAction::LoginFailed
            && ! $action->isReadAction();
    }

    protected function clientIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function userAgent(): ?string
    {
        try {
            return substr((string) Request::userAgent(), 0, 1000);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function sessionId(): ?string
    {
        try {
            return Request::hasSession() ? Request::session()->getId() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
