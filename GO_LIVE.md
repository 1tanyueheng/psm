# GO LIVE — step by step

From "works on my laptop" to "works on the internet".

Do these in order. Each step says what you should see before moving on.

---

## Before you start: two things are still outstanding

| Thing                        | Status                             |
| ---------------------------- | ---------------------------------- |
| `backend/composer.lock`      | **missing** — step 1               |
| `frontend/package-lock.json` | present but a placeholder — step 1 |
| Git repository               | not created — step 3               |
| `/api/milestones` route      | added but not live yet — step 0    |

---

## Step 0 — Finish local validation

The new `/api/milestones` endpoint is in the code but the container cached the  
old route table at boot. Clear it:

```sh
cd /c/Users/yuehe/Desktop/study/fyp/AIProject/psm-system
docker compose exec app php artisan route:clear
docker compose exec app php artisan config:clear
docker compose restart app
```

Wait ~30 seconds, then confirm:

```sh
curl -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/milestones
```

**Expect `401`.** That means the route now exists and is correctly refusing an  
unauthenticated request. A `404` means the route still is not registered.

Then open the Milestones page at <http://localhost:5173/milestones> and confirm it  
renders a list rather than an error.

---


## Step 1 — Generate the lock files

Both hosts build from your repository, and without lock files they resolve  
dependency versions fresh each time. That means the version you tested is not  
necessarily the version that deploys.

**Composer** — run this in your own Git Bash, from the project root:

```sh
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "C:/Users/yuehe/Desktop/study/fyp/AIProject/psm-system/backend:/app" \
  -w /app composer:2 composer update --no-dev
```

That writes `backend/composer.lock`. Confirm it appeared:

```sh
ls -la backend/composer.lock
```

**npm** — the existing `package-lock.json` is a stub with no dependencies in it.  
Replace it with a real one:

```sh
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "C:/Users/yuehe/Desktop/study/fyp/AIProject/psm-system/frontend:/app" \
  -w /app node:20-alpine sh -c "npm install --package-lock-only"
```

Confirm it grew from ~500 bytes to a few hundred KB:

```sh
ls -la frontend/package-lock.json
```

**Expect:** two real lock files. Both should be committed in step 3.

---

## Step 2 — Check nothing secret is about to be committed

```sh
cd /c/Users/yuehe/Desktop/study/fyp/AIProject/psm-system
cat .gitignore | head -20
```

`backend/.env` and `.env` should both be listed. If you have a real `.env` file  
with real credentials anywhere, it must not be committed.

You can verify what git would actually add once the repo exists (step 3):

```sh
git status --porcelain | grep -i "\.env" || echo "no .env files staged - good"
```

---

## Step 3 — Put the code on GitHub

```sh
cd /c/Users/yuehe/Desktop/study/fyp/AIProject/psm-system
git init
git add .
git commit -m "PSM Management System"
```

Then create an **empty** repository on github.com (no README, no .gitignore —  
you already have both) and follow the two commands GitHub shows you:

```sh
git remote add origin https://github.com/YOUR-USERNAME/YOUR-REPO.git
git branch -M main
git push -u origin main
```

**Expect:** your code visible on github.com. Click into `backend/` and confirm  
`composer.lock` is there, and that `.env` is **not**.

---

## Step 4 — Create the database (Neon)

1. Sign up at **<https://neon.tech>**
2. Create a project — pick a region close to where you will host the backend
3. Copy the **connection string**; it looks like:

```
postgresql://user:password@ep-xxx.region.aws.neon.tech/psm_system?sslmode=require
```

Split it into the pieces you will need in step 5:

| From the string             | Env var       |
| --------------------------- | ------------- |
| host (`ep-xxx...neon.tech`) | `DB_HOST`     |
| `user`                      | `DB_USERNAME` |
| `password`                  | `DB_PASSWORD` |
| `psm_system`                | `DB_DATABASE` |
| `5432`                      | `DB_PORT`     |

`DB_CONNECTION=pgsql` and `DB_SSLMODE=require` — Neon rejects unencrypted  
connections.

**Note:** the codebase was deliberately written to run on either MySQL or  
PostgreSQL, so no code changes are needed. See `docs/DEPLOYMENT.md` section 4.

---


## Step 5 — Deploy the backend (Render)

1. Sign up at **<https://render.com>**
2. **New → Web Service**, connect your GitHub repo
3. Settings:
   - **Environment:** Docker
   - **Dockerfile path:** `./Dockerfile`
   - **Health check path:** `/up`
   - **Instance type:** Free to start
   - **Port:** `8080` (the Dockerfile `EXPOSE`s 8080 and nginx listens there;  
     Render auto-detects it, but set it explicitly if Render asks for a port)
4. Add these environment variables:

```
APP_NAME="PSM Management System"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                       # see below
APP_URL=https://YOUR-APP.onrender.com
FRONTEND_URL=https://YOUR-APP.vercel.app

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=psm_system
DB_USERNAME=...
DB_PASSWORD=...
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@your-domain
MAIL_FROM_NAME="${APP_NAME}"

SANCTUM_STATEFUL_DOMAINS=YOUR-APP.vercel.app
CORS_ALLOWED_ORIGIN_PATTERNS=          # optional; for Vercel preview URLs
PSM_MILESTONE_BLEND_PERCENT=20
LEADERBOARD_TOP_N=3
LEADERBOARD_MIN_ASSESSORS=2
REMINDER_DAYS_BEFORE=7,3,1
SUPERVISOR_MAX_CAPACITY=8
SUBMISSION_MAX_MB=25
AUDIT_RETAIN_YEARS=7

RUN_MIGRATIONS=true
```

> `RUN_MIGRATIONS=true` makes the container run `php artisan migrate --force` on  
> every boot. The migration is **idempotent** (it only applies migrations that  
> have not run yet) and the entrypoint is written so a failed migration does not  
> crash the container — so this is safe even on the free tier, where the service  
> sleeps and wakes. This removes the need for Render's paid **Shell** tab, which  
> Step 6 previously relied on. Leave `RUN_SEED` **off** in production.

**Generate `APP_KEY`** — run this locally and paste the output:

```sh
MSYS_NO_PATHCONV=1 docker run --rm \
  -v "C:/Users/yuehe/Desktop/study/fyp/AIProject/psm-system/backend:/app" \
  -w /app composer:2 php artisan key:generate --show
```

It prints something like `base64:abc123...=`. Copy the whole thing including  
`base64:`.

**Add a persistent disk** — this is the step people skip and regret:

- **Disks → Add Disk**
- **Mount path:** `/var/www/html/storage/app`
- Size: 1 GB is plenty

Without it, every uploaded thesis file disappears on the next deploy.

1. Click **Create Web Service** and watch the build.

**Expect:** the build succeeds and the service reports healthy. If it fails,  
paste the last 20 lines and check `docs/SETUP.md` section 7.

---

## Step 6 — Create the database tables

Once the service is live, run the migrations. Render's **Shell** tab (paid) or a  
one-off job:

```sh
php artisan migrate --force
```

**Do not run `--seed`.** The seeder creates demo accounts with the password  
`password`. If you want demo data for a presentation, seed and then change every  
password immediately.

Confirm the API is alive:

```sh
curl https://YOUR-APP.onrender.com/up
```

**Expect:** HTTP 200.


### Running artisan commands without the Shell tab (free tier)

Render's **Shell** is paid-only. You don't need it:

- **Migrations** run automatically because `RUN_MIGRATIONS=true` (step 5) — every  
  container boot applies any new migrations, so the free tier's sleep/wake cycle  
  just re-runs them harmlessly.
- **Any other artisan command** (`migrate:status`, `db:seed`, `config:clear`,  
  `queue:retry`, …) can be run from your laptop against the *same Neon database*,  
  because Neon is an external host reachable from anywhere. Point a throwaway  
  container at it (no `db` service needed):
  ```sh
  MSYS_NO_PATHCONV=1 docker compose run --rm --no-deps \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=ep-jolly-night-b3klornn.c-4.ap-southeast-1.aws.neon.tech \
    -e DB_PORT=5432 -e DB_DATABASE=neondb \
    -e DB_USERNAME=neondb_owner -e DB_PASSWORD=YOUR_NEON_PASSWORD \
    -e DB_SSLMODE=require \
    app php artisan migrate:status
  ```
  Swap the last argument for whatever you need. For a presentation seed, run  
  `db:seed --force` locally this way, then change **every** password immediately.

---

## Step 7 — Deploy the frontend (Vercel)

1. Sign up at **<https://vercel.com>**
2. **Add New → Project**, import the same GitHub repo
3. **Root Directory:** `frontend` ← important, or Vercel will not find it
4. Framework preset: Vite (usually auto-detected)
5. Add an environment variable:

```
VITE_API_URL=https://YOUR-APP.onrender.com/api
```

1. Deploy.

> `frontend/vite.config.js` builds to `frontend/dist` (Vercel's expected  
> output) and `frontend/vercel.json` rewrites every route to `index.html`, so  
> deep links like `/login` and `/milestones` work without a 404. Leave both in  
> place — do **not** point the Vite `outDir` back at `backend/public/build`,  
> or the Vercel build will fail with "No Output Directory named dist".

**Expect:** Vercel gives you a URL like `https://your-app.vercel.app`.

---

## Step 8 — Connect the two halves

This step is easy to forget and produces confusing login failures.

Go back to **Render → Environment** and update:

```
APP_URL=https://YOUR-APP.onrender.com
FRONTEND_URL=https://your-app.vercel.app
SANCTUM_STATEFUL_DOMAINS=your-app.vercel.app
```

Render redeploys automatically. The backend must be *told* which frontend  
origin is allowed to talk to it — until it knows, browser requests are refused  
even though the API works fine in curl.

`FRONTEND_URL` drives CORS as well as email links, and it must match the
Vercel origin **exactly**:

- **No trailing slash.** `https://x.vercel.app/` will not match
  `https://x.vercel.app` in an `Origin` header, and the preflight fails.
- **No path.** Scheme + host only.
- **The real subdomain.** Vercel preview deployments get their own hostnames
  (`your-app-git-branch-team.vercel.app`). Those are rejected unless you add
  them to `CORS_ALLOWED_ORIGIN_PATTERNS`, e.g.
  `https://your-app-*.vercel.app`. Testing a preview URL and getting a network
  error on login is almost always this.

`SANCTUM_STATEFUL_DOMAINS` is the host only — no scheme, no trailing slash.

### If login still fails after this

Open the browser's **Network** tab and submit the form. Look for the `OPTIONS`
request to `/api/auth/login`:

| What you see | Meaning |
|---|---|
| No `OPTIONS` request; `POST` shows `(failed) net::ERR_FAILED` | Preflight blocked. `FRONTEND_URL` does not match the origin exactly. |
| `OPTIONS` returns 404 or 405 | CORS paths are not matching — confirm `backend/config/cors.php` is in the deployed image. |
| `OPTIONS` 204, but `POST` blocked and the console names `Access-Control-Allow-Origin` | The response carries no allow-origin header, so this origin is not allowlisted. |
| `POST` returns 401 or 422 | **CORS is fine.** This is now an application issue — wrong password, or the seeder was never run (step 6). |

Reach for the last row first. A 401 means the request got through, and the
problem is credentials or seed data, not the network.

After any CORS change, hard-reload (`Ctrl+Shift+R`). Preflight responses are
cached for `CORS_MAX_AGE` seconds (24 hours by default), and a cached failure
makes a correct fix look like it did nothing.

---

## Step 9 — Test the live system

Open your Vercel URL and check, in order:

- [ ] The login page renders
- [ ] Signing in as `admin@psm.test` / `password` works
- [ ] The dashboard shows real numbers
- [ ] Navigating between pages does not log you out
- [ ] **`https://your-app.vercel.app/leaderboard` loads with no login** — this is  
  your public Pixel-It page and your best demo feature
- [ ] Uploading a file works, then **redeploy and check the file is still there**  
  (this proves the persistent disk is wired up)

---

## Two things that will surprise you

### Render's free tier sleeps

After **15 minutes with no visitors**, Render shuts the free service down. The  
next visitor waits **30–60 seconds**.

For a demo this is genuinely awkward. Either:

1. Pay $7/month for an always-on instance during demo week — what I would do
2. Set up a free uptime monitor to ping it every 5 minutes
3. Open the site yourself 5 minutes before, so it is already awake

### Neon scales to zero

The free tier suspends your database when idle. The first query after that takes  
a second or two. Harmless, but worth expecting.

---


## Where to look if something breaks

| Symptom                                                             | Look at                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Build fails                                                         | `docs/SETUP.md` section 7                                                                                                                                                                                                                     |
| 502 from Render                                                     | Render → Logs                                                                                                                                                                                                                                 |
| **`relation "cache" does not exist`** + `queue-worker` WARN exit loop | **Migrations never ran** — check `RUN_MIGRATIONS=true` on Render, then restart. The worker crash-looping is a symptom, not the cause; the API is unaffected. See "Why the queue workers crash-loop" below.                                   |
| Login fails from the browser, but the API works in `curl`            | **CORS.** `backend/config/cors.php` allowlists `FRONTEND_URL` + `APP_URL`. Check `FRONTEND_URL` on Render matches the Vercel origin **exactly** — no trailing slash, correct subdomain. A preview URL will not match the production domain.  |
| Login fails, and the browser shows a *network error* with no status  | Almost always the same CORS cause — a blocked preflight is indistinguishable from a dead server to axios. Open DevTools → Network and look for a failed `OPTIONS` request.                                                                     |
| Uploads vanish                                                      | the persistent disk (step 5)                                                                                                                                                                                                                  |
| Blank page on Vercel                                                | browser console — usually `VITE_API_URL`                                                                                                                                                                                                      |
| CORS errors                                                         | `FRONTEND_URL` on Render                                                                                                                                                                                                                      |
| `Driver [database] is not supported` (500 on `/`)                   | A store-driver env var has a **stray trailing space** (e.g. `SESSION_DRIVER=database `) or `LOG_CHANNEL=database` is set. Re-type the value with no spaces; unset `LOG_CHANNEL` (defaults to `stack`).                                          |
| `SQLSTATE[25P02]` `current transaction is aborted` during `migrate` | A migration used `enum()` columns, which **PostgreSQL rejects** (MySQL accepts them). All migrations were converted to `string()` columns — Postgres-compatible. Re-run `migrate --force`; the DB enum constraint is enforced in PHP instead. |

---

## Why the queue workers crash-loop (and the login fails)

Two failures that look unrelated but were both caused by the split
architecture. Both are now fixed in the repository.

### 1. `relation "cache" does not exist` — a startup race, not a missing migration

The Render log shows this, repeating every couple of seconds:

```
SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "cache" does not exist
LINE 1: select * from "cache" where "key" in ($1)
WARN exited: queue-worker_00 (exit status 1; not expected)
```

It is natural to read that as "the migration did not run". It usually did.
The real cause is ordering.

A Laravel queue worker's very first action is to read
`illuminate:queue:restart` from the **cache store** — which here is the
database, in the `cache` table. Migrations create that table. If the worker
starts before `migrate` has finished, it dies instantly, and supervisord
restarts it, forever.

The trap is that `priority` in supervisord does **not** fix this. It orders
when each program is *forked*, not when each *finishes*. Setting
`priority = 15` on migrate and `priority = 20` on the workers still lets the
workers reach `RUNNING` while `php artisan migrate` is opening its connection —
verified by experiment, not assumption.

The fix, now in `docker/supervisord.conf`:

- Migrations run as their own supervised program (`[program:migrate]`).
- The queue workers and scheduler have `autostart = false`, so they stay
  `STOPPED`.
- An event listener (`[eventlistener:gate]`, implemented in
  `docker/wait-for-migrations.py`) watches for the migrate program to exit and
  then starts them via `supervisorctl`.

Result, from a controlled test: **no crashes**, and the workers log their
first poll against an existing table.

> The API was never affected by this. That is why the site loaded and the
> database "looked empty" at the same time — the error was two workers
> crash-looping, not a failed migration. `php artisan migrate:status` against
> Neon is the quickest way to see what actually applied.

### 2. Login failed with a network error — missing CORS config

`backend/config/cors.php` **did not exist**. Laravel 11 registers
`HandleCors` globally by default, so the middleware was running — but it reads
its allowed paths from `config('cors.paths')`, and with no config file that is
an empty array. Every path check failed, so the middleware did nothing.

Because the SPA is on Vercel and the API is on Render, every request is
cross-origin and needs an `OPTIONS` preflight. With no
`Access-Control-Allow-Origin` header the browser blocks the request before it
is sent, and axios reports a bare **network error** — no status code, no
message. That is what the red banner on the login form was.

The fix: `backend/config/cors.php` now exists, allowlists `FRONTEND_URL` and
`APP_URL` (plus localhost for development), and sets
`supports_credentials = true` so the `Authorization` header passes.

The middleware being installed is not the same as it being configured. After
changing CORS settings, hard-reload the page — the browser caches preflight
responses for `max_age` seconds and a stale one will make a fix look like it
did nothing.



---

## Checklist

- [ ] Step 0 — `/api/milestones` returns 401, Milestones page renders
- [ ] Step 1 — both lock files real
- [ ] Step 2 — no `.env` about to be committed
- [ ] Step 3 — code on GitHub
- [ ] Step 4 — Neon database created, connection details copied
- [ ] Step 5 — Render service live, `APP_KEY` set, disk mounted
- [ ] Step 6 — migrations run
- [ ] Step 7 — Vercel frontend live
- [ ] Step 8 — `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` set
- [ ] Step 9 — logged in on the live site, public leaderboard loads
