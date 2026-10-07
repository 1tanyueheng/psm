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
   `MilestoneController::submit()` writes them through the configured disk and
   `download()` later reads each path back with `Storage::disk($file->disk)`.
   The disk is configurable (section 5), so uploads can live in object storage;
   on Vercel the *default* local disk would lose every upload within seconds of
   accepting it. That is silent data loss, and collecting those documents is
   the point of Module 3.

2. **A process that is always running.** Deadline reminders are scheduled in
   `routes/console.php` via `Schedule::command(SendDeadlineRemindersCommand::class)`,
   and notifications implement `ShouldQueue`, so they need a queue worker.
   Serverless functions wake on a request and sleep immediately after, so
   there is nothing left alive to run a scheduler or a worker. Module 6 would
   silently never fire.

Making Laravel fit Vercel would mean replacing the queue with an external
service and moving the scheduler to an external cron — a re-architecture of two
modules to suit the host, rather than picking a host that suits the
application. (Uploads are no longer part of that list: they are a
configuration change, see section 5.) **Use a container host instead.**

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
| 1 | **Object storage** | The `local` disk writes into the container. **Container disks are ephemeral** — every redeploy wipes them, even on Render. Students would lose their submissions. The code path is ready: set `FILESYSTEM_DISK=s3` and the credentials. | Cloudflare R2 (cheapest, no egress fees), Supabase Storage, AWS S3, Backblaze B2 |
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

See section 4 for the database, and section 5 for object storage — both are
configuration-only changes now, with the code already in place.

---

## 4. Choosing the database

### The options

| Option | Engine | Free tier | Catch |
|---|---|---|---|
| **Neon** | Postgres | 1 GB | Scales to zero when idle — first query is slow, but it wakes on connect |
| **Supabase** | Postgres | 1 GB | **Pauses after 7 days of low activity**; restore is a manual click |
| Render | Postgres | 1 GB | **Deleted 30 days after creation on the free tier** |
| Aiven | MySQL | 1 GB | Fewer regions, slower support |
| TiDB Cloud | MySQL-compatible | 5 GB | Compatible, not actually MySQL |

**Pick Neon or Supabase.** Both are Postgres and both work with this codebase
unchanged. The difference that matters is what happens when the project is idle:

- **Neon** scales to zero but **wakes automatically on the next connection**.
  A dormant semester costs one slow query, nothing more.
- **Supabase** pauses the whole project after a week of low *database* activity
  and needs a **manual "Resume project"** from the dashboard. The docs warn by
  email about a week ahead, and the restore window is a year, so nothing is
  lost — but the app is down until someone clicks.

That distinction shapes the advice on storage below: a Supabase project kept
alive only by file uploads may still be judged inactive, because the pause
heuristic counts *database* queries.

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
   section 7.

### Supabase specifics (verified against a live project)

Three things about Supabase are not obvious and each one costs an afternoon if
you meet it cold.

**1. The direct database host is IPv6-only.**

`db.<project-ref>.supabase.co` resolves to an **AAAA record only**. A container
host without a global IPv6 address cannot reach it — the connection simply
fails to resolve, which reads like a DNS problem rather than an addressing one.

Use the **pooler** instead, which has IPv4:

```
DB_HOST=aws-0-<region>.pooler.supabase.com
DB_PORT=5432
DB_USERNAME=postgres.<project-ref>      # the ref suffix is REQUIRED
DB_PASSWORD=<database password>
DB_SSLMODE=require
```

The username is the trap: bare `postgres` is **rejected** by the pooler. It has
to carry the project ref as a suffix. Find the exact string in the dashboard
under **Project Settings → Database → Connection string**.

Port `5432` is the session pooler, which is what migrations and long-lived
connections want; `6543` is the transaction pooler. Both were tested and both
run DDL inside a transaction and hold advisory locks, so either works here —
5432 is the safer default.

**2. The database password is not the S3 secret key.**

They are separate credentials shown in different places, and mixing them up
produces an authentication failure that looks like a wrong password. The
database password is under **Project Settings → Database**; the S3 keys are
under **Storage → S3**.

**3. A half-finished migration cannot simply be re-run.**

If `migrate` is interrupted, Postgres keeps the types it already created, and
the retry fails with:

```
SQLSTATE[23505]: duplicate key value violates unique constraint "pg_type_typname_nsp_index"
DETAIL: Key (typname, typnamespace)=(milestones, ...) already exists.
```

The schema is genuinely half-built at that point — table types exist for tables
that were never created. On an **empty** database the clean fix is to drop and
recreate the `public` schema and start again:

```sql
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
GRANT ALL ON SCHEMA public TO postgres;
GRANT ALL ON SCHEMA public TO public;
```

**Never run that against a database holding real data.** It is only safe on a
fresh project, which is the situation that produces the error.

**On connection pooling:** from a container host (Option A or B) pooling is not
required, because the container holds a small fixed set of long-lived
connections. It matters for serverless hosts, where every invocation opens a
new one.

### A note on latency

A remote database makes every screen feel slower, and it is worth measuring
before judging the app. Seeding this project against a project in
`ap-northeast-1` took roughly **11 minutes**, against well under a minute
locally — thousands of round trips at ~200 ms each. Ordinary page loads are
fine; bulk operations are not. **Choose the region closest to the people using
the system**, not the one closest to you.

---

## 5. Object storage for uploaded files

`local` writes into `storage/app/private` inside the container. That is correct
for development and **wrong for any container host**: the filesystem is
ephemeral, so a redeploy destroys every uploaded thesis while the database rows
still point at them.

Point it at an S3-compatible store by setting `FILESYSTEM_DISK=s3` plus the
`AWS_*` variables from section 6. R2 and Supabase Storage are both verified to
work with this driver; no application code changes.

### Migrating files already uploaded

`submission_files.disk` records the disk **per row**, so the move is
incremental and needs no downtime: existing files keep resolving from wherever
they are while new uploads go to the new disk.

A command does the work:

```sh
# See what would move, without moving it
php artisan psm:migrate-submission-files --to=s3 --dry-run

# Copy across, verifying each file
php artisan psm:migrate-submission-files --to=s3

# Only once downloads are confirmed working: reclaim the old disk
php artisan psm:migrate-submission-files --to=s3 --prune
```

It reads each copy back and compares **SHA-256** before flipping the row, skips
files already migrated (so an interrupted run is safe to repeat), and **never
deletes the source** unless you pass `--prune`. A copy that returns without
throwing is not proof the bytes landed — a truncated upload can succeed at the
HTTP level — so the first two properties are what make this safe. A bad
credential or a misconfigured bucket then costs you a no-op rather than data.

### Two provider details that are easy to get wrong

- **Region is not interchangeable.** R2 uses `auto`; Supabase wants the
  project's real region (`ap-northeast-1`). Sending `auto` to Supabase fails.
- **Supabase's endpoint is not the bare host.** It needs the S3 path suffix:
  `https://<project-ref>.storage.supabase.co/storage/v1/s3`.

### Why the disk is configured to throw

The `s3` disk sets `'throw' => true`, and `MilestoneController::submit()` also
checks the return value. With `throw => false`, Flysystem reports a failed write
by *returning* `false`, and the caller has to remember to check — so a failed
upload silently became a database row pointing at nothing: the student saw
"submission received", and the reviewer got a 404 weeks later. Both guards
together mean that can no longer happen.

`exists_on_disk` is **opt-in** (`?with_exists=1`). It asks the storage backend
once per file, which is free locally but a network round trip per file in a
list on object storage — and nothing in the SPA reads it.

---

## 6. Recommended environment variables for production

```sh
APP_NAME="PSM Management System"
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
APP_DEBUG=false               # never true in production
APP_URL=https://your-domain

DB_CONNECTION=pgsql           # or mysql
# Neon:
DB_HOST=<project>.neon.tech
# Supabase (IPv4 pooler — the direct host is IPv6-only, see section 4):
# DB_HOST=aws-0-<region>.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=psm_system        # Supabase names its database `postgres`
DB_USERNAME=...               # Supabase: postgres.<project-ref>
DB_PASSWORD=...
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=s3            # once uploads move off the local disk
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
# Region is per provider, NOT interchangeable:
#   Cloudflare R2  -> auto
#   Supabase       -> the project's real region, e.g. ap-northeast-1
AWS_DEFAULT_REGION=auto
AWS_BUCKET=psm-submissions
# Point at whichever provider you chose:
#   R2:       https://<account>.r2.cloudflarestorage.com
#   Supabase: https://<project-ref>.storage.supabase.co/storage/v1/s3
AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true

# Do NOT set AWS_URL. It makes Laravel hand out public object URLs, and
# submission files must only ever be served through the policy-checked,
# audited download route (/api/submissions/{id}/download).

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@your-domain
MAIL_FROM_NAME="${APP_NAME}"

# Do NOT set SANCTUM_STATEFUL_DOMAINS. The SPA uses bearer tokens, not cookies;
# naming a stateful host makes browser POST/DELETE fail with 419 CSRF mismatch.
SESSION_DOMAIN=.your-domain                       # Option B only

PSM_MILESTONE_BLEND_PERCENT=0
LEADERBOARD_TOP_N=3
LEADERBOARD_MIN_ASSESSORS=2
REMINDER_DAYS_BEFORE=7,3,1
SUPERVISOR_MAX_CAPACITY=8
SUBMISSION_MAX_MB=25
AUDIT_RETAIN_YEARS=7
```

---

## 7. Pre-flight checklist

Before pointing a real cohort at this:

- [ ] `composer.lock` and `package-lock.json` generated and committed
      (see `frontend/LOCKFILE.md`)
- [ ] **The deployed image was built after `composer.lock` last changed.** The
      S3 adapter is in the lock file but not in older images, so a stale image
      fails at `Storage::disk('s3')` with *"Class
      League\Flysystem\AwsS3V3\PortableVisibilityConverter not found"* — which
      reads like a missing package rather than a stale build. Rebuild with
      `docker compose build` (or redeploy) after any lock-file change.
- [ ] `APP_KEY` set, and **never regenerated** — doing so invalidates every
      session and encrypted value
- [ ] `APP_DEBUG=false`
- [ ] Uploads moved to object storage, and a test upload survives a redeploy
- [ ] **The storage bucket is private.** Fetch an object path anonymously and
      confirm it is refused (`403`); a public bucket exposes every student's
      thesis. Downloads must go through `/api/submissions/{id}/download`.
- [ ] Email provider configured, and a test notification actually arrives
- [ ] Queue worker running (`queue:work`), and a queued job is processed
- [ ] Scheduler running (`schedule:run` each minute), and `schedule:list` shows
      the expected tasks
- [ ] Database backups enabled and **a restore tested** — an untested backup is
      not a backup
- [ ] `php artisan migrate --force` run as a deploy step, not on every boot
- [ ] Seeder **not** run against production (it creates demo accounts with the
      password `password`)
- [ ] **If the host database is Supabase on the free plan, something keeps it
      awake.** It pauses after 7 days without *database* activity, and file
      uploads alone may not count. Point the app's database at it (so ordinary
      use counts) rather than using it purely as a file store, or expect a
      manual "Resume project" after quiet periods.
- [ ] HTTPS working, and `SANCTUM_STATEFUL_DOMAINS` left **unset**
- [ ] A CORS preflight from the real frontend origin returns
      `Access-Control-Allow-Origin` (see section 8)
- [ ] The public leaderboard loads without logging in
- [ ] One full journey tested end to end as a real student: register → upload →
      get marked → see the grade

---

## 8. Troubleshooting the failures that actually happened

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

## 9. Where to go next

- `WHAT_TO_DO_NEXT.md` — getting it running locally first, in plain language
- `docs/SETUP.md` — full setup, including the Render section
- `docs/ARCHITECTURE.md` — how the code is organised and why
- `frontend/LOCKFILE.md` — the lock file situation
- `GO_LIVE.md` — the step-by-step deployment runbook
