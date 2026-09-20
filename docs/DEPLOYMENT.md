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
- [ ] The public leaderboard loads without logging in
- [ ] One full journey tested end to end as a real student: register → upload →
      get marked → see the grade

---

## 7. Where to go next

- `WHAT_TO_DO_NEXT.md` — getting it running locally first, in plain language
- `docs/SETUP.md` — full setup, including the Render section
- `docs/ARCHITECTURE.md` — how the code is organised and why
- `frontend/LOCKFILE.md` — the lock file situation
