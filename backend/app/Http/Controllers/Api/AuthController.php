<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 1 — Authentication.
 *
 * Issues Sanctum tokens for the decoupled React SPA. Sessions are also
 * revoked on password change and account deactivation, so a stolen token
 * cannot outlive the credential it was issued from.
 */
class AuthController extends ApiController
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();

        // -----------------------------------------------------------------
        // Deliberately uniform failure messaging: a different message for
        // "no such account" versus "wrong password" would let an attacker
        // enumerate valid staff and student addresses.
        // -----------------------------------------------------------------
        if ($user === null) {
            $this->audit->log(
                action: AuditAction::LoginFailed,
                description: "Unknown account: {$credentials['email']}",
            );

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => 'This account is temporarily locked. Try again after '
                    .$user->locked_until?->diffForHumans().'.',
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $justLocked = $user->registerFailedLogin();

            $this->audit->log(
                action: AuditAction::LoginFailed,
                description: $justLocked
                    ? "Account locked after repeated failures: {$user->email}"
                    : "Incorrect password for {$user->email}",
                actor: $user,
            );

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => match ($user->status) {
                    'suspended' => 'This account has been suspended. Please contact the administrator.',
                    'inactive'  => 'This account is no longer active.',
                    default     => 'This account has not been verified yet.',
                },
            ]);
        }

        // Revoke prior tokens when the client asks for a clean session
        if ($request->boolean('revoke_existing')) {
            $user->tokens()->delete();
        }

        $tokenName = $request->input('device_name', 'spa');
        $token = $user->createToken($tokenName)->plainTextToken;

        $user->markLoggedIn($request->ip());

        $this->audit->log(
            action: AuditAction::Login,
            description: 'Signed in from '.$request->ip(),
            actor: $user,
        );

        return $this->ok([
            'token' => $token,
            'user'  => new UserResource($user->load(['studentProfile', 'supervisorProfile'])),
            // The SPA uses this to pick a landing dashboard
            'home'  => $user->role->homeRoute(),
        ], 'Signed in successfully.');
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->audit->log(
            action: AuditAction::Logout,
            description: 'Signed out',
            actor: $user,
        );

        // Revoke only the token used for this request
        $user->currentAccessToken()?->delete();

        return $this->ok(null, 'Signed out.');
    }

    /**
     * GET /api/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(
            new UserResource(
                $request->user()->load(['studentProfile', 'supervisorProfile', 'coordinatorScopes'])
            )
        );
    }

    /**
     * POST /api/auth/forgot-password
     *
     * Always returns success, even for an unknown address — the same
     * enumeration concern as login.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return $this->ok(
            null,
            'If that email address is registered, a reset link is on its way.'
        );
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'             => Hash::make($password),
                    'must_change_password' => false,
                    'failed_login_attempts'=> 0,
                    'locked_until'         => null,
                    'remember_token'       => Str::random(60),
                ])->save();

                // Any token issued before the reset is now void
                $user->tokens()->delete();

                $this->audit->log(
                    action: AuditAction::PasswordReset,
                    description: 'Password reset via email link',
                    actor: $user,
                );
            }
        );

        if ($status !== Password::PasswordReset) {
            return $this->fail(__($status), 422);
        }

        return $this->ok(null, 'Your password has been reset. You can sign in now.');
    }

    /**
     * POST /api/auth/change-password
     *
     * Also clears the must_change_password flag set on admin-created accounts.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'Your new password must be different from your current one.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->forceFill([
            'password'             => Hash::make($validated['password']),
            'must_change_password' => false,
            'remember_token'       => Str::random(60),
        ])->save();

        // Revoke every *other* device's token, but keep the current session
        // alive so the user is not bounced to the login screen mid-flow.
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        $this->audit->log(
            action: AuditAction::PasswordChanged,
            description: 'Password changed',
            actor: $user,
        );

        return $this->ok(null, 'Password changed. Other devices have been signed out.');
    }
}
