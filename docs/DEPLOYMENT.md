# DEPLOYMENT

How to get this system onto the internet, and what else you need beyond the
code itself.

---

## 1. Verdict on the proposed stack

The stack as described was:

| Piece | Proposed | Verdict |
|---|---|---|
| Frontend | Render | ✅ Works, but Vercel suits it better |
| Backend | **Vercel** | ❌ **Wrong architecture for this app** |
| Database | **Neon / Supabase** | ✅ Fine — both PostgreSQL, and this project now runs on either |

### Why the backend should not go on Vercel

To be accurate about the platform: **Vercel can run PHP.** It publishes
official guides for deploying Laravel and PHP through Docker with FrankenPHP
(see Vercel's knowledge base, "Deploy Laravel on Vercel with Docker"). So
"Vercel does not support PHP" would be out of date and is not the reason.

The reason is that Vercel runs your code as **short-lived serverless
functions**, and this application depends on two things that model cannot
provide.

1. **A filesystem that survives the request.** Vercel gives each invocation a
   read-only filesystem with a temporary `/tmp` that is discarded afterwards.
   This system lets students **upload thesis files** —
   `MilestoneController::submit()` calls `storeAs(..., 'local')`, and
   `download()` later reads that path back with `Storage::disk($file->disk)`.
   On Vercel every upload would be gone within seconds of being accepted. This
   is silent data loss, and collecting those documents is the point of
   Module 3.

2. **A process that is always running.** Deadline reminders are scheduled in
   `routes/console.php` via `Schedule::command(SendDeadlineRemindersCommand::class)`,
   and notifications implement `ShouldQueue`, so they need a queue worker.
   Serverless functions wake on a request and sleep immediately after, so
   there is nothing left alive to run a scheduler or a worker. Module 6 would
   silently never fire.

Making Laravel fit Vercel would mean moving uploads to S3, replacing the queue
with an external service, and moving the scheduler to an external cron — a
re-architecture of three modules to suit the host, rather than picking a host
that suits the application. **Use a container host instead.**

### The frontend and backend look swapped

Vercel is the *natural* home for a React frontend: it is a static build, and
Vercel's CDN serves static files with no cold start and no configuration.
Render is the natural home for a Docker/PHP backend.

If you meant **Frontend: Vercel, Backend: Render**, that is the standard and
sensible split — see Option B below.

### The database is a smaller problem than it looks

Neon and Supabase are both **PostgreSQL**. This project was written for MySQL.

**This has already been fixed.** Four MySQL-only SQL fragments were replaced
with standard SQL that runs on both engines:

| Was | Now | Where |
|---|---|---|
| `DATE(COALESCE(...))` | `CAST(COALESCE(...) AS date)` | `Milestone.php` |
| `FIELD(psm_part, ?, 'BOTH')` | `CASE WHEN psm_part = ? THEN 0 ELSE 1 END` | `MilestoneTemplate.php`, `RubricTemplate.php` |

Laravel's `enum()` and `json()` column types already translate correctly to
Postgres (`varchar` + check constraint, and `jsonb`). So **you can now use
either engine — it is a configuration change, not a code change.**

---

## 2. What actually works

### Option A — one service (recommended)

Everything in a single Render Web Service, built from the existing `Dockerfile`.

```
Render Web Service (Docker)
  ├── nginx          serves the built React SPA
  ├── PHP-FPM        runs Laravel
  ├── queue worker   sends the emails
  └── scheduler      fires the reminders
        │
        └── Database (Render MySQL, or Neon/Supabase Postgres)
```

**Why this is the right default:** the Docker image already runs all four
processes under `supervisord`. The queue worker and scheduler — the two things
that break on serverless — come along automatically. There is also no CORS
setup, no cross-domain cookies, and one thing to deploy.

### Container startup order

The five supervised programs do not all start at once. Migrations must finish
before anything that touches the `cache` table, because a queue worker's first
action is to read `illuminate:queue:restart` from it.

```
priority  5   php-fpm        no database access
priority 10   nginx          no database access
priority 15   migrate        creates the tables, then exits
priority 18   gate           event listener: releases 20 and 30 when 15 exits
priority 20   queue-worker   x2, autostart = false
priority 30   scheduler      autostart = false
```

`priority` alone is not sufficient — it orders when supervisord *forks* each
program, not when each *finishes*. The workers are therefore held
`STOPPED` via `autostart = false` and released by the event listener at
`priority 18`. See `docker/wait-for-migrations.py`, which documents the
reasoning and supervisord's event protocol.

This ordering is what prevents the `relation "cache" does not exist` crash
loop described in section 8.

**Choose this unless you have a specific reason not to.**

### Option B — split frontend and backend

```
Vercel  ── static React SPA ──┐
                              ├── HTTPS ──> Backend (Render/Railway/Fly, Docker)
Render Static Site ───────────┘                    │
                                                   └──> Database
```

Both hosts work here:
- **Frontend on Vercel** — excellent, this is what Vercel is best at.
- **Frontend on Render Static Site** — also fine.
- **Backend on Render / Railway / Fly.io** — any Docker host. All support PHP.

**What you take on by splitting:**
- CORS must be configured (`config/cors.php`) for the frontend origin.
- Sanctum stateful cookies need `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN`
  set to the frontend domain, or switch to token auth.
- The backend needs a **separate Background Worker service** for the queue, and
  a **Cron Job** for the scheduler. The Docker image's supervisord handles this
  in Option A; in Option B you may or may not get it depending on the host.
- Two deploys to keep in sync.

---

## 3. What else to set up

The code is only part of a working deployment. These are the pieces that are
missing, in priority order.

### Must have

| # | What | Why it is required | Options |
|---|---|---|---|
| 1 | **Object storage** | Uploaded thesis files live on the local disk. **Container disks are ephemeral** — every redeploy wipes them, even on Render. Students would lose their submissions. | Cloudflare R2 (cheapest, no egress fees), AWS S3, Backblaze B2, Supabase Storage |
| 2 | **Email provider** | Module 6 sends notifications and deadline reminders. Render has no mail server. | Resend, Postmark, Mailgun, Amazon SES |
| 3 | **A Git repository** | Render and Vercel both deploy from Git. | GitHub (free) |
| 4 | **Background worker** | Only needed if you pick Option B. The queue must be processed or no email ever sends. | Render Background Worker, `php artisan queue:work` |

### Should have

| # | What | Why | Options |
|---|---|---|---|
| 5 | **Cron / scheduler** | Only needed for Option B. Fires deadline reminders and prunes old audit logs. | Render Cron Job running `php artisan schedule:run` every minute |
| 6 | **Database backups** | A dropped table without a backup is a lost cohort's work. | Neon branching + PITR, Supabase backups, or your own `mysqldump`/`pg_dump` job |
| 7 | **Error monitoring** | Production errors are invisible otherwise — you only find out when a student complains. | Sentry (first-class Laravel SDK) |
| 8 | **Domain name** | Needed for stable cookie domains and a professional URL. | Any registrar |
| 9 | **Redis** | Better than the database driver for sessions, cache and queues once there is real traffic. | Upstash Redis (serverless, free tier) |

### Nice to have

| # | What | Why |
|---|---|---|
| 10 | **Uptime monitoring** | Render's free tier sleeps when idle; monitoring tells you when it is down. |
| 11 | **CI (GitHub Actions)** | Runs `check_seeders.py`, `check_frontend.py`, ESLint and the build on every push, so a broken commit never reaches production. |
| 12 | **Staging environment** | Test a migration against real data before it touches the live cohort. |

### The two that matter most

If you only do two things from this list, do these:

1. **Move uploads to object storage.** This is a silent data-loss bug otherwise
   — everything works in testing, then a redeploy destroys the files.
2. **Set up an email provider.** Without it Module 6 does nothing at all, and
   that is one of your eight required modules.

---

## 4. Choosing the database

### The recommendation: Neon (PostgreSQL)

| Option | Engine | Free tier | Catch |
|---|---|---|---|
| **Neon** | Postgres | 0.5 GB | Scales to zero when idle — first query is slow |
| Supabase | Postgres | 500 MB | Pauses after about a week of inactivity |
| Render | Postgres | 1 GB | **Deleted 30 days after creation on the free tier** |
| Aiven | MySQL | 1 GB | Fewer regions, slower support |
| TiDB Cloud | MySQL-compatible | 5 GB | Compatible, not actually MySQL |

**Pick Neon** if you want the least friction. The free tier does not expire,
which matters for a project that has to survive a semester and a demo. The
"scales to zero" behaviour costs you a slow first page load, nothing worse.

**Pick Render MySQL** instead if you want development and production to be
identical — your local `docker-compose.yml` runs MySQL 8, so staying on MySQL
removes any doubt about engine differences. The trade is that Render's free
database is deleted after 30 days, so you would need to move to a paid plan or
re-create it before then.

Note that free tiers change often. Verify current limits before committing.

### How to switch

Change only the environment variables:

```
DB_CONNECTION=pgsql
DB_HOST=your-project.neon.tech
DB_PORT=5432
DB_DATABASE=psm_system
DB_USERNAME=your_user
DB_PASSWORD=your_password
DB_SSLMODE=require
```

`DB_SSLMODE=require` matters — hosted providers reject unencrypted
connections. The `pgsql` driver block already exists in
`backend/config/database.php`; nothing else needs editing.

Then run the migrations as normal:

```sh
php artisan migrate --force
```

Do not add `--seed` against production. See the pre-flight checklist in
section 6.

**Note on serverless Postgres:** Neon and Supabase both need connection
pooling when used from a serverless host, because each function invocation
opens a connection. From a container host (Option A or B) this is not an issue.

---

## 5. Recommended environment variables for production

```sh
APP_NAME="PSM Management System"
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
APP_DEBUG=false               # never true in production
APP_URL=https://your-domain

DB_CONNECTION=pgsql           # or mysql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=psm_system
DB_USERNAME=...
DB_PASSWORD=...
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=s3            # once uploads move off the local disk
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=psm-submissions
AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com   # if using R2
AWS_USE_PATH_STYLE_ENDPOINT=true

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@your-domain
MAIL_FROM_NAME="${APP_NAME}"

SANCTUM_STATEFUL_DOMAINS=your-frontend-domain     # Option B only
SESSION_DOMAIN=.your-domain                       # Option B only

PSM_MILESTONE_BLEND_PERCENT=20
LEADERBOARD_TOP_N=3
LEADERBOARD_MIN_ASSESSORS=2
REMINDER_DAYS_BEFORE=7,3,1
SUPERVISOR_MAX_CAPACITY=8
SUBMISSION_MAX_MB=25
AUDIT_RETAIN_YEARS=7
```

---

## 6. Pre-flight checklist

Before pointing a real cohort at this:

- [ ] `composer.lock` and `package-lock.json` generated and committed
      (see `frontend/LOCKFILE.md`)
- [ ] `APP_KEY` set, and **never regenerated** — doing so invalidates every
      session and encrypted value
- [ ] `APP_DEBUG=false`
- [ ] Uploads moved to object storage, and a test upload survives a redeploy
- [ ] Email provider configured, and a test notification actually arrives
- [ ] Queue worker running (`queue:work`), and a queued job is processed
- [ ] Scheduler running (`schedule:run` each minute), and `schedule:list` shows
      the expected tasks
- [ ] Database backups enabled and **a restore tested** — an untested backup is
      not a backup
- [ ] `php artisan migrate --force` run as a deploy step, not on every boot
- [ ] Seeder **not** run against production (it creates demo accounts with the
      password `password`)
- [ ] HTTPS working, and `SANCTUM_STATEFUL_DOMAINS` matches the real frontend
      origin
- [ ] A CORS preflight from the real frontend origin returns
      `Access-Control-Allow-Origin` (see section 8)
- [ ] The public leaderboard loads without logging in
- [ ] One full journey tested end to end as a real student: register → upload →
      get marked → see the grade

---

## 7. Troubleshooting the two failures that actually happened

Both of these were hit on a real deploy. Both are fixed in the repository, and
both are recorded here because each is easy to misdiagnose.

### `relation "cache" does not exist`, repeating forever

```
SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "cache" does not exist
LINE 1: select * from "cache" where "key" in ($1)
WARN exited: queue-worker_00 (exit status 1; not expected)
WARN exited: queue-worker_01 (exit status 1; not expected)
```

**This does not necessarily mean migrations failed.** In the case that
produced this log, the migration ran and succeeded. The workers had simply
started before it finished.

A Laravel queue worker's first action is to read `illuminate:queue:restart`
from the cache store, which here is the database `cache` table. Migrations
create that table. Start the worker first and it dies instantly; supervisord
restarts it; forever.

Two things make this confusing:

- **The API is unaffected.** The site loads and the database can look empty at
  the same time, because the error is two workers crash-looping, not a failed
  migration.
- **`priority` does not fix it.** It orders when supervisord forks each
  program, not when each finishes. Workers still reach `RUNNING` while
  `php artisan migrate` is opening its connection.

**Check what actually applied** before assuming anything:

```sh
php artisan migrate:status
```

Point a throwaway container at the same database (Neon is reachable from
anywhere) — see the shell-free artisan recipe in `GO_LIVE.md` step 6.

**The fix** is in `docker/supervisord.conf` and
`docker/wait-for-migrations.py`: workers and scheduler have
`autostart = false` and an event listener releases them once the migrate
program exits. See "Container startup order" in section 2.

#### What the listener does when a migration genuinely fails

Releasing the workers anyway is the right default — a worker that starts and
reports a missing table once beats one that crash-loops — but there is a trap.
**supervisord gives up on a program permanently after three fast failures:**

```
INFO gave up: queue-worker_00 entered FATAL state, too many start retries too quickly
```

Releasing the workers the instant a migration fails spends all three attempts
in under a second against a database that is certainly still broken, leaving
the queue dead even after the connection is fixed. So the listener checks the
connection first (`php artisan db:show`) and, if it is still down, leaves the
workers `STOPPED` and logs:

```
[gate] Database still unreachable - leaving the queue workers STOPPED, so their
       start retries are not burned on a connection that cannot succeed. ...
```

Nothing is lost by waiting. Migrations, seeding and every HTTP request run in
the web process; the only thing the queue workers carry is queued mail. Fix the
credentials, redeploy, and the workers start normally.

If the connection *is* reachable but migrations failed, the workers are
released as usual — the failure was something else, and `autorestart` covers
the case where it clears.

### Login fails with a network error — missing CORS config

The symptom is a generic red banner on the login form, with no HTTP status
code. In the browser console there is a CORS error; in the Network tab there
may be no `POST` request at all, only a failed `OPTIONS`.

The cause was that `backend/config/cors.php` **did not exist**. Laravel 11
registers `HandleCors` globally by default, so the middleware was running —
but it reads its allowed paths from `config('cors.paths')`, and with no config
file that returns an empty array. Every path check failed, the middleware did
nothing, and the request fell through to the router.

Worse, Laravel's *bundled* fallback config answers with
`Access-Control-Allow-Origin: *`. That looks permissive but is fatal here:
`Authorization` is a **CORS non-wildcard request-header name** in the Fetch
standard, so a request carrying it can never be satisfied by the wildcard, and
the browser blocks the response.

Verified with a real preflight against the app:

| Origin sent | Result |
|---|---|
| `https://your-app.vercel.app` (allowlisted) | `204` + `Access-Control-Allow-Origin: https://your-app.vercel.app`, `Allow-Credentials: true` |
| `https://evil.example.com` (not allowlisted) | no allow-origin header — blocked, as intended |

**The fix** is `backend/config/cors.php`, which allowlists `FRONTEND_URL` and
`APP_URL` from the environment and sets `supports_credentials = true`.

When debugging, remember:

- `FRONTEND_URL` must match the origin **exactly** — no trailing slash, no
  path, correct subdomain. A Vercel preview URL will not match the production
  domain; add it to `CORS_ALLOWED_ORIGIN_PATTERNS` if you need it.
- **A 401 or 422 in the Network tab means CORS is working.** The request got
  through; the problem is credentials or seed data instead.
- Hard-reload after any change. Preflight responses are cached for
  `CORS_MAX_AGE` seconds (24 hours by default) and a cached failure makes a
  correct fix look like it did nothing.
- A stale `bootstrap/cache/config.php` will shadow a new config file. The
  entrypoint clears it on every boot, but locally run
  `php artisan config:clear`.

---

## 8. Where to go next

- `WHAT_TO_DO_NEXT.md` — getting it running locally first, in plain language
- `docs/SETUP.md` — full setup, including the Render section
- `docs/ARCHITECTURE.md` — how the code is organised and why
- `frontend/LOCKFILE.md` — the lock file situation
- `GO_LIVE.md` — the step-by-step deployment runbook
