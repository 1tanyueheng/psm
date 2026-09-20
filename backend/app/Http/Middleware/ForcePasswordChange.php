<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 1 — Force a password change on first sign-in.
 *
 * Users created by an admin (or reset by them) start with
 * must_change_password = true. Until they change it, every API route except
 * the auth ones returns 409 so the SPA can route them to the change form.
 */
class ForcePasswordChange
{
    /** Routes a flagged user is still allowed to reach. */
    protected array $allowed = [
        'api/auth/*',
        'api/me',
        'api/logout',
        'api/health',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        foreach ($this->allowed as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'success'  => false,
            'message'  => 'You must change your password before continuing.',
            'code'     => 'password_change_required',
            'action'   => '/change-password',
        ], 409);
    }
}
