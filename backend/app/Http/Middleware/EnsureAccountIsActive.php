<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 1 — Reject requests from non-active accounts.
 *
 * A suspended or deactivated user may hold a valid token (issued before the
 * change), so status must be re-checked on every request rather than trusted
 * from the token.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);   // let the auth middleware handle it
        }

        if (! $user->isActive()) {
            // Invalidate the token so the SPA cannot keep retrying
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => match ($user->status) {
                    'suspended' => 'تم تعليق حسابك. يرجى التواصل مع المسؤول.',
                    'inactive'  => 'حسابك لم يعد نشطًا.',
                    default     => 'لم يتم التحقق من حسابك بعد.',
                },
                'status' => $user->status,
            ], 403);
        }

        if ($user->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'تم قفل حسابك مؤقتًا بعد محاولات تسجيل دخول فاشلة متكررة.',
                'locked_until' => $user->locked_until?->toIso8601String(),
            ], 423);
        }

        return $next($request);
    }
}
