<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 1 — Role gate.
 *
 * Usage:  ->middleware('role:coordinator,admin')
 *         ->middleware('role:supervisor|examiner')
 *
 * A pipe (`|`) means "any of these"; a comma means the same thing, but the
 * author may prefer it for readability. Both are accepted deliberately so the
 * route files read naturally.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please sign in to continue.',
            ], 401);
        }

        // Allow both "role:a,b" and "role:a|b" spellings
        $wanted = collect($roles)
            ->flatMap(fn (string $r) => explode('|', $r))
            ->map(fn (string $r) => trim($r))
            ->filter()
            ->all();

        if ($wanted === []) {
            return $next($request);
        }

        if (! $user->hasRole(...$wanted)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource.',
                'required_roles' => $wanted,
            ], 403);
        }

        return $next($request);
    }
}
