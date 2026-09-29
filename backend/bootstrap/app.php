<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ---------------------------------------------------------------
        // Global middleware
        // ---------------------------------------------------------------
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Global and prepended, NOT appended to the api group.
        //
        // Laravel sorts middleware by a priority list, and
        // Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests is on it
        // while this class is not. Registering it on the api group therefore
        // let `auth:sanctum` run FIRST — rejecting the request before the
        // Accept header had been set, so Laravel tried to redirect to a
        // non-existent `login` route and answered 500 instead of 401.
        //
        // Out here it runs before any route middleware, priority or not.
        $middleware->prepend(\App\Http\Middleware\ForceJsonResponse::class);

        // ---------------------------------------------------------------
        // Named middleware aliases (Module 1 RBAC enforcement)
        // ---------------------------------------------------------------
        $middleware->alias([
            'role'                 => \App\Http\Middleware\EnsureUserHasRole::class,
            'active'               => \App\Http\Middleware\EnsureAccountIsActive::class,
            'audit'                => \App\Http\Middleware\RecordAuditTrail::class,
            'first.login'          => \App\Http\Middleware\ForcePasswordChange::class,
        ]);

        // ---------------------------------------------------------------
        // CORS
        // ---------------------------------------------------------------
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'api/auth/login',
            'api/auth/forgot-password',
            'api/auth/reset-password',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ---------------------------------------------------------------
        // Always answer the API in JSON
        // ---------------------------------------------------------------
        // Laravel decides how to report an AuthenticationException by asking
        // whether the request "expects JSON". When it does not, it tries to
        // redirect to a named `login` route — and this backend has none, so
        // route('login') throws RouteNotFoundException and the caller gets a
        // confusing 500 instead of 401:
        //
        //   GET /api/projects          -> 500  Route [login] not defined
        //   GET /api/projects  (JSON)  -> 401  Unauthenticated
        //
        // The SPA always sends Accept: application/json, so this never shows
        // up in the browser — only to curl, Postman, uptime monitors, and
        // anything else that forgets the header. An API should not depend on
        // a header being present to fail correctly.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // ---------------------------------------------------------------
        // Uniform JSON error envelope for the SPA
        // ---------------------------------------------------------------
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [
                    422,
                    'The given data was invalid.',
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    401,
                    'Unauthenticated. Please sign in to continue.',
                    null,
                ],
                $e instanceof AuthorizationException => [
                    403,
                    'You are not permitted to perform this action.',
                    null,
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    404,
                    'The requested resource was not found.',
                    null,
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: 'Request failed.',
                    null,
                ],
                default => [
                    500,
                    config('app.debug') ? $e->getMessage() : 'An unexpected server error occurred.',
                    config('app.debug') ? [
                        'exception' => get_class($e),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ] : null,
                ],
            };

            $payload = [
                'success' => false,
                'message' => $message,
            ];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $status);
        });
    })
    ->create();
