<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The SPA is deployed separately (Vercel/Netlify or a Render static site), so
| this file only carries the health probe and the password-reset landing page
| that the reset email links to.
|
*/

Route::get('/', function () {
    return response()->json([
        'service' => config('app.name'),
        'version' => '1.0.0',
        'docs'    => '/api',
    ]);
});

/**
 * Password reset landing.
 *
 * The API sends users here with a token; this page hands off to the SPA's
 * /reset-password route so the SPA owns the actual form. Done with a redirect
 * rather than a Blade view because the SPA is a separate deployment.
 */
Route::get('/reset-password/{token}', function (string $token) {
    $frontend = rtrim((string) config('app.frontend_url'), '/');

    $query = http_build_query([
        'token' => $token,
        'email' => request()->query('email'),
    ]);

    return redirect("{$frontend}/reset-password?{$query}");
})->name('password.reset');
