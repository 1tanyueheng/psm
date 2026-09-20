# PSM Management System — Setup

Two ways to run this: Docker (recommended, matches production) or natively
(useful when you want a debugger attached).

---

## 1. Docker — the recommended path

Requires Docker Desktop or Docker Engine with Compose v2.

```bash
git clone <repo-url> psm-system
cd psm-system

# One-time configuration
cp backend/.env.example backend/.env
php -r "echo 'APP_KEY='.base64_encode(random_bytes(32)).PHP_EOL;" >> backend/.env

# Build and start
docker compose up -d --build
```

First start takes a few minutes while images are pulled and Composer and npm
install. Subsequent starts are fast.

Then, inside the application container:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

### What comes up

| Service | URL | Notes |
|---|---|---|
| API | http://localhost:8000 | Laravel |
| SPA | http://localhost:5173 | Vite dev server |
| Public leaderboard | http://localhost:8000/leaderboard | No login required |
| MySQL | `localhost:3306` | Credentials in `docker-compose.yml` |
| Mailpit (dev mail) | http://localhost:8025 | Catches all outgoing mail |

---

## 2. Native setup

Requires PHP 8.2+, Composer 2, MySQL 8, and Node 20+.

```bash
# --- Backend ---
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` for your database:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=psm_system
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

```bash
# --- Frontend (separate terminal) ---
cd frontend
npm install
cp .env.example .env
npm run dev
```

---

## 3. Demo accounts

`php artisan migrate --seed` creates a full cohort. Every account uses the
password `password`.

| Role | Email | What to look at |
|---|---|---|
| Admin | `admin@psm.test` | User management, audit log, archive |
| Coordinator | `coordinator@psm.test` | Assignments, reports, grade release, leaderboard |
| Supervisor | `supervisor@psm.test` | Supervisees, marking, milestone review |
| Examiner | `examiner@psm.test` | Assigned projects, marking |
| Student | `student@psm.test` | Milestones, submissions, results |

### What the seed data contains

| Entity | Count | Notes |
|---|---|---|
| Users | 38 | 1 admin, 1 coordinator, 8 supervisors, 4 examiners, 24 students |
| Expertise areas | 20 | Across 7 categories |
| Milestone templates | 4 | 2 categories × 2 PSM parts, 5 items each |
| Rubric templates | 6 | 2 categories × 3 assessor types |
| Projects | 22 | PSM2, 2025/2026 session |
| Milestones | ~110 | Instantiated through the real service |
| Evaluations | varies | Marked through the real service |
| Leaderboards | 2 | 1 published, 1 draft preview |
| Archived projects | several | Created by the real archive service |

The cohort is spread across five progress profiles so every dashboard has
something real to show, including an at-risk group and an unassigned pairing
queue for the coordinator.

**Important:** submission files are seeded as metadata only — the paths point
at files that do not exist on disk. Upload and download work normally for
freshly uploaded files; clicking download on a seeded row will 404 by design.

---

## 4. Scheduled tasks

In production, add the Laravel scheduler to cron:

```cron
* * * * * cd /var/www/psm-system/backend && php artisan schedule:run >> /dev/null 2>&1
```

In Docker this is handled by the `scheduler` service. For local development:

```bash
php artisan schedule:work
```

To run the jobs manually:

```bash
php artisan psm:send-deadline-reminders   # hourly
php artisan psm:prune-audit-log           # monthly
```

---

## 5. Tests

```bash
cd backend
php artisan test
```

---

## 6. Verification tools

Three scripts check things a linter cannot. All are safe to run at any time and
need no PHP or Docker installation.

```bash
# Backend — every seed column exists in the schema; every service and enum
# reference resolves
python backend/tools/check_seeders.py

# Backend — both rubric weight levels total 100 in every template
php backend/tools/audit_rubric_weights.php

# Frontend — every import resolves, every named import exists, every API
# method called from a page is defined in endpoints.js
python frontend/tools/check_frontend.py
```

`check_seeders.py` catches the class of bug that is otherwise only found at
runtime on a fresh database: a seeder writing a column that a later migration
renamed, or calling a service method that has since been removed.

`audit_rubric_weights.php` recomputes component, criterion, and `max_marks`
totals independently. An unbalanced rubric silently corrupts every mark
computed against it, so this is worth being able to verify quickly after any
edit to `RubricTemplateSeeder`.

`check_frontend.py` walks the ES module graph without needing `node_modules`.
It verifies that every relative import resolves, that every named import is
actually exported by its target, that no component calls a hook it forgot to
import, and — most usefully — that every `projectApi.foo()` style call made
from a page is a method that `api/endpoints.js` really defines. It also reports
exports in the shared modules that nothing consumes, which is how dead API
surface gets noticed.

Its last check validates link targets: every literal `<Link to="/...">` must
resolve against a `<Route path="...">` in `App.jsx`, with `:param` segments
matched positionally. This exists because a misdirected link is invisible to
every other tool — the module graph is consistent, ESLint is clean, the build
succeeds, and the user simply lands on the 404 page. The catch-all `path="*"`
route is excluded from the declared set on purpose: counting it would make the
check accept every path and so never fail.

### Verified build output

A production build has been run and passes:

```
vite v6.4.3 building for production...
✓ 127 modules transformed.
../backend/public/build/index.html                 0.77 kB │ gzip:  0.44 kB
../backend/public/build/assets/index-*.css        28.68 kB │ gzip:  5.88 kB
../backend/public/build/assets/react-*.js        165.66 kB │ gzip: 54.20 kB
../backend/public/build/assets/index-*.js         95.60 kB │ gzip: 31.58 kB
…29 further lazy route chunks, largest 12.27 kB
✓ built in 4.25s
```

Every screen in `App.jsx` is emitted as its own chunk, which confirms the lazy
route table resolves and that code splitting works as intended. The main bundle
is the shell plus the router; React is split separately so it can be cached
across deploys.

ESLint reports **0 errors and 1 warning**, the warning being
`react-refresh/only-export-components` on `AuthContext.jsx`, which exports
`useAuth` alongside `AuthProvider`. That is accepted rather than worked around:
splitting the hook into its own file would break the natural import for every
consumer in exchange for a hot-reload nicety that only affects development.

---

## 7. Deployment (Render)

The repository is Docker-first so Render can build it directly.

1. Create a **PostgreSQL or MySQL** managed database, or use Render's MySQL.
2. Create a **Web Service** from the repo, environment `Docker`.
3. Set the environment variables below.
4. Render runs the health check at `/up` (registered in `bootstrap/app.php`).
5. Run `php artisan migrate --force` as a deploy command.

**Before the first deploy, generate the lock files.** Neither
`backend/composer.lock` nor `frontend/package-lock.json` is committed in the
scaffold (see `frontend/LOCKFILE.md` for why). The `Dockerfile` detects this
and falls back to `composer update` and `npm install`, so the build will
succeed — but it resolves dependency ranges at build time instead of pinning
them. Run these once and commit the result:

```sh
cd backend  && composer update --no-dev
cd frontend && npm install
```

### What the image actually does

Stage 1 installs PHP dependencies, stage 2 compiles the SPA, and stage 3 is the
runtime: PHP-FPM plus nginx plus a queue worker plus the scheduler, all under
`supervisord`, listening on **8080**. Notable details:

- The SPA is built with `--outDir dist` rather than the `vite.config.js`
  default. That config points at `../backend/public/build`, which is correct
  for a developer building from `frontend/` in a checkout but wrong inside the
  image, where the backend source is not a sibling of the frontend directory.
- The `assets` stage will only use `npm ci` if `package-lock.json` contains a
  resolved dependency tree (`grep -q '"node_modules/'`). A lock file that is
  present but empty would otherwise satisfy `npm ci` and install nothing.

### Required environment variables

```
APP_NAME="PSM Management System"
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://your-app.onrender.com
FRONTEND_URL=https://your-app.onrender.com

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=psm_system
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@your-domain.edu
MAIL_FROM_NAME="${APP_NAME}"

SANCTUM_STATEFUL_DOMAINS=your-app.onrender.com

# --- PSM domain policy ---
PSM_MILESTONE_BLEND_PERCENT=20
LEADERBOARD_TOP_N=3
LEADERBOARD_MIN_ASSESSORS=2
REMINDER_DAYS_BEFORE=7,3,1
SUPERVISOR_MAX_CAPACITY=8
SUBMISSION_MAX_MB=25
AUDIT_RETAIN_YEARS=7
```

### Production notes

- **Do not run `--seed` on production.** The seeder wipes nothing but it does
  create a cohort of demo accounts with the password `password`. Create the
  first administrator with a one-off `php artisan db:seed --class=UserSeeder`
  only if you then change every password, or write a dedicated production
  seeder.
- **`APP_DEBUG=false`.** With it on, a stack trace can expose environment
  variables to an unauthenticated visitor.
- **`storage:link` must run** or submission downloads and posters will 404.
- **File storage is ephemeral on most container hosts.** Mount a persistent
  disk at `backend/storage/app` or configure S3 in `config/filesystems.php`,
  or uploaded submissions will vanish on the next deploy.
- **HTTPS is forced in production** by `AppServiceProvider`.
- **Trust the proxy.** If TLS terminates at Render's edge, ensure
  `TrustProxies` is configured or Laravel will generate `http://` URLs.

---

## 8. Troubleshooting

**`SQLSTATE[HY000] [1045] Access denied`**
Database credentials in `.env` are wrong, or the MySQL container is not yet
healthy. Check `docker compose logs db`.

**`No application encryption key`**
`php artisan key:generate`. Do this before migrating; changing the key later
invalidates existing sessions and encrypted values.

**Uploads fail with 413**
PHP's `upload_max_filesize` and `post_max_size` must exceed
`SUBMISSION_MAX_MB`. Both are set in the Docker PHP config; for native setups
edit `php.ini`.

**Reminders never arrive**
Check `php artisan schedule:list` to confirm the tasks are registered, and that
`queue:work` is running if you use a queue connection. In development, mail
lands in Mailpit at http://localhost:8025 rather than an inbox.

**Leaderboard is empty**
Publishing requires released grades with at least `LEADERBOARD_MIN_ASSESSORS`
assessors each. If the seeder reported "Leaderboard left as draft", the cohort
did not produce enough released grades — check `final_grades` for
`status = 'released'`, and that `leaderboard_settings.module_enabled` is true.

**`Public leaderboard: /leaderboard (no login required)` printed but the page is blank**
Confirm the board's `status` is `published`, not `draft`. A draft board is
previewable by staff only.
