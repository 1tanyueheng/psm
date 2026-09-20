<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 7 — Coarse safety net for the audit trail.
 *
 * Services write precise, structured audit entries (with diffs and reasons).
 * This middleware exists to catch mutations that bypass the services: any
 * successful POST/PUT/PATCH/DELETE on an /api route that did not already
 * produce an audit row gets a generic one.
 *
 * The result is a trail that has no holes even if a controller forgets to log,
 * while still preferring the rich entries written by the service layer.
 */
class RecordAuditTrail
{
    /** Methods considered state-changing. */
    protected array $mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Route prefixes where a generic entry adds noise rather than value. */
    protected array $exempt = [
        'api/auth/login',
        'api/auth/logout',
        'api/notifications/*',
        'api/me/notifications/*',
    ];

    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldRecord($request, $response)) {
            return $response;
        }

        // Only log if nothing more precise was already written for this request
        if ($this->auditAlreadyWrittenThisRequest()) {
            return $response;
        }

        $this->audit->log(
            action: $this->actionFor($request),
            description: $this->describe($request, $response),
        );

        return $response;
    }

    protected function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        if (! in_array($request->method(), $this->mutating, true)) {
            return false;
        }

        // Only record successful mutations
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        foreach ($this->exempt as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cheap heuristic: an audit row created during this request means a
     * service already handled it properly.
     */
    protected function auditAlreadyWrittenThisRequest(): bool
    {
        static $checked = [];

        $key = spl_object_id(request());

        if (isset($checked[$key])) {
            return true;
        }

        $written = \App\Models\AuditLog::query()
            ->where('created_at', '>=', now()->subSeconds(5))
            ->where(function ($q) {
                $q->where('request_url', request()->fullUrl())
                  ->orWhereNull('request_url');
            })
            ->exists();

        if ($written) {
            $checked[$key] = true;
        }

        return $written;
    }

    protected function actionFor(Request $request): AuditAction
    {
        return match ($request->method()) {
            'DELETE' => AuditAction::UserUpdated,
            'POST'   => AuditAction::ProfileUpdated,
            default  => AuditAction::ProfileUpdated,
        };
    }

    protected function describe(Request $request, Response $response): string
    {
        $route = $request->route()?->getName() ?? $request->path();

        return "{$request->method()} {$route} ({$response->getStatusCode()})";
    }
}
