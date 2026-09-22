<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| This file did not exist before, and that was the cause of the login
| failure on the deployed site.
|
| The SPA is served from Vercel and the API from Render, so every API call
| is cross-origin. The browser sends an OPTIONS preflight first, and if the
| response does not carry `Access-Control-Allow-Origin` it blocks the real
| request before it is ever sent. axios surfaces that as a *network error*
| — no status, no response body — which is why the login form showed a
| generic network message rather than anything useful.
|
| Laravel 11 registers `Illuminate\Http\Middleware\HandleCors` globally by
| default, so the middleware was already running. What it reads, though,
| is `config('cors.paths')`, and with no config file that returns an empty
| array:
|
|     $paths = $this->container['config']->get('cors.paths', []);
|     foreach ($paths as $path) { ... }   // never entered
|
| No paths match, so the middleware calls `$next($request)` and the
| preflight falls through to the router, which has no OPTIONS route and
| answers 405. The middleware being installed is not the same as it being
| configured.
|
| Everything below is env-driven so the same image works in every
| environment without editing this file.
|
*/

$frontend = rtrim((string) env('FRONTEND_URL', ''), '/');
$appUrl   = rtrim((string) env('APP_URL', ''), '/');

/*
 * Origins allowed to call the API.
 *
 * `FRONTEND_URL` is the deployed SPA. `APP_URL` is included because a
 * same-origin deployment (Option A — one Render service serving both the
 * static shell and /api) sends requests from the backend's own origin, and
 * localhost entries keep `npm run dev` working against a local container.
 *
 * Vercel gives every deployment its own hostname: `your-app.vercel.app` for
 * production and `your-app-git-branch-team.vercel.app` for previews. Rather
 * than trying to predict them, set FRONTEND_URL to the production domain and
 * add each preview domain to the comma-separated list if you need to test
 * one. A wrong origin here means the preflight fails, so if login works on
 * the production URL but not on a preview URL, this is why.
 */
$origins = array_values(array_filter(array_unique([
    $frontend,
    $appUrl,
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
])));

return [

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    | Which routes get CORS treatment. Only the API needs it — the SPA and
    | the health probe are same-origin as far as the browser cares.
    |
    | An empty array here is the failure mode described at the top of this
    | file, so `api/*` is non-negotiable. `sanctum/csrf-cookie` is included
    | because Sanctum's cookie-based flow fetches it cross-origin when the
    | SPA uses session auth rather than the bearer token.
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed methods
    |--------------------------------------------------------------------------
    | `*` rather than an explicit list, because the preflight response
    | advertises these to the browser and the SPA uses GET, POST, PUT, PATCH
    | and DELETE across the eight modules. Restricting it would only create a
    | second place to keep in sync with routes/api.php.
    */
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins
    |--------------------------------------------------------------------------
    | Explicit origins, never `*`. A wildcard is rejected outright by the
    | browser as soon as the request is credentialed — and this app sends an
    | Authorization header — so `*` would produce exactly the failure this
    | file exists to prevent.
    */
    'allowed_origins' => $origins,

    /*
    |--------------------------------------------------------------------------
    | Allowed origins, by pattern
    |--------------------------------------------------------------------------
    | Convenience for Vercel preview deployments, which are numerous and
    | short-lived. Opt in per environment rather than shipping a blanket
    | subdomain match: `SANCTUM_STATEFUL_DOMAINS` and this list should agree,
    | or you get a confusing split where CORS passes but credentials do not.
    |
    | Example: CORS_ALLOWED_ORIGIN_PATTERNS=https://my-app-*.vercel.app
    */
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed headers
    |--------------------------------------------------------------------------
    | `*` covers Content-Type, Accept, Authorization, X-Requested-With, and
    | the X-XSRF-TOKEN that Sanctum sends. Being permissive here is safe: the
    | origin allowlist above is what actually restricts who can call the API.
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed headers
    |--------------------------------------------------------------------------
    | The SPA reads Content-Disposition to recover the original filename on
    | submission downloads and CSV exports. Without exposing it, the header
    | is invisible to JavaScript across origins and downloads arrive named
    | after the URL rather than the document.
    */
    'exposed_headers' => [
        'Content-Disposition',
        'X-Request-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max age
    |--------------------------------------------------------------------------
    | How long the browser may cache a preflight response. 24 hours keeps the
    | extra OPTIONS round-trip off almost every request. Shorten this while
    | debugging CORS — a cached preflight will keep a fix from appearing to
    | work.
    */
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    | True so the `Authorization: Bearer` header on every API call is allowed
    | through. Required whenever the request carries credentials, and it
    | forbids a `*` origin — the two settings are connected, which is why
    | `allowed_origins` must stay an explicit list.
    */
    'supports_credentials' => true,

];
