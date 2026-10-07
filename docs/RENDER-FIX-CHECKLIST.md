# Render Deployment Fix Checklist

**Service:** `https://psm-1-xcaz.onrender.com`
**Frontend:** `https://psm-hepy108ko-psm-proj.vercel.app`

---

## What is actually broken

Two separate faults, and the second one is not what it first appeared to be.

### Fault 1 — the download 404

Two things are wrong, and **both** must be fixed:

**(a) The deployment is reading a different database.** The live API returns
file ids up to **174**; your Supabase database's highest id is **76**. So Render
is not reading Supabase — it is reading the old Neon database from the env list.

**(b) Every file row says `disk = local`.** Verified across all 76 rows in
Supabase. A row's `disk` column decides *where the bytes are read from*, and
`local` means the container's own filesystem. Even with the correct database,
`download()` would look on Render's ephemeral disk and find nothing.

This is the more important discovery. Fixing only the database would not fix
the download — the rows would still point at the local disk.

**Why the rows say `local`:** the seeder creates them with
`config('filesystems.default')` at seed time. They were seeded while the disk
was still `local`, so they recorded `local`. Setting `FILESYSTEM_DISK=s3` now
changes where *new* uploads go, but does not rewrite existing rows.

**And there is a third problem underneath both:** of the 76 rows in Supabase,
**none has any bytes behind it.** The bucket contains zero objects under
`submissions/`. The seeded demo data describes files that were never uploaded —
they are names in a table, not documents. So even with the database and the
disk column both corrected, those 76 downloads will still 404.

That is not a bug to fix. It is what the seed data is. What you can verify is
that a **newly uploaded** file round-trips, because it records `disk = s3` and
writes real bytes.

### Fault 2 — the upload "Network Error"

"Network Error" with no status code is a browser-level failure, not a server
error. The API's own CORS is **verified working** from your Vercel origin:

```
Access-Control-Allow-Origin:  https://psm-hepy108ko-psm-proj.vercel.app
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: content-type,authorization
```

So the block is elsewhere. The most likely cause is the storage variables being
absent on Render, which makes `Storage::disk('s3')` throw — and an unhandled
exception during a multipart upload often reaches the browser without CORS
headers, which surfaces as a generic network error rather than a 500.

**Step 2 will confirm this.** If uploads still fail after the variables are set,
Step 6 tells you how to capture the real error.

---

## Step 1 — Fix the database

**Render → your service → Environment.**

Replace the `DB_*` values with these. These are the ones verified working
against the live Supabase database (44 users, 26 projects, 134 milestones).

```
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-northeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.szxyhboqbbrndozwburd
DB_PASSWORD=@Helloyh0971
DB_SSLMODE=require
```

- [ ] Set all seven values
- [ ] **Remove** the old Neon block (`ep-lucky-credit-...neon.tech`,
      `neondb`, `neondb_owner`) — leaving both invites confusion about which won

> The password begins with `@`. If Render's parser mis-handles it, wrap the
> value in double quotes: `DB_PASSWORD="@Helloyh0971"`

---

## Step 2 — Add the storage variables

These are **completely absent** from the list you sent, which is why uploads
fail and why nothing can be downloaded.

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=262c5d6887ecf311f1a39505932ec096
AWS_SECRET_ACCESS_KEY=a9bb235809417b25b4586055467e1c8c71d1b5c59b157507c7943d0b778d1470
AWS_DEFAULT_REGION=ap-northeast-1
AWS_BUCKET=PSM
AWS_ENDPOINT=https://szxyhboqbbrndozwburd.storage.supabase.co/storage/v1/s3
AWS_USE_PATH_STYLE_ENDPOINT=true
```

- [ ] Add all seven

**Two values that are easy to get wrong:**

- `AWS_DEFAULT_REGION` is `ap-northeast-1` — **not** `auto`. `auto` is
  Cloudflare R2's convention and is rejected by Supabase.
- `AWS_ENDPOINT` needs the `/storage/v1/s3` suffix. The bare host will not work.

I verified this bucket is live minutes ago: write and delete both succeed.

---

## Step 3 — Understand what can and cannot be downloaded

This is the step that prevents a frustrating afternoon.

**The 76 seeded rows cannot be made downloadable.** They are names in a table
with no bytes anywhere — not in the bucket, not on any disk. No configuration
change creates those files. A download button on them will 404, correctly.

So do not spend time trying to fix them. Instead, separate the two cases:

| Case | Expected result |
|---|---|
| A **seeded** demo file (ids 1–76) | `404` — the bytes never existed |
| A **newly uploaded** file | `200` with real bytes |

- [ ] Open **Render → Shell** and confirm the position:

```
php artisan psm:migrate-submission-files --to=s3 --dry-run
```

**Expect:** it lists rows it would move, then reports each as *missing*,
because there is no source object to copy. That output is the evidence that the
seed data has no files behind it — not a sign the command is broken.

- [ ] **The real test:** log in as a student, upload any file to a milestone
      that accepts submissions, then download it.

**Expect:** the upload succeeds and the download returns the file. A new upload
records `disk = s3` and writes real bytes to Supabase Storage, so it exercises
the whole path end to end.

Do **not** run `psm:migrate-submission-files` for real. There is nothing to
move, and it would only rewrite `disk` on rows whose bytes do not exist —
making a broken row look correct.

---

## Step 4 — Confirm the database switched

- [ ] Redeploy (Render does **not** apply env changes to a running container)
- [ ] From a terminal:

```
curl -s -o /dev/null -w "%{http_code}\n" https://psm-1-xcaz.onrender.com/up
```

**Expect:** `200`. The first call after idle may take ~28s — that is Render's
free tier waking up, not a fault.

- [ ] Log in as `student@psm.test` / `password`, open a project, note the
      highest file id.

**Expect:** nothing above **76**. If you still see 169+, Step 1 did not take
effect — check for a duplicate `DB_*` block or a stale deploy.

---

## Step 5 — Confirm the frontend points at the API

**Vercel → your project → Settings → Environment Variables:**

```
VITE_API_URL=https://psm-1-xcaz.onrender.com/api
```

- [ ] Set it
- [ ] **Redeploy Vercel** — Vite inlines this at build time, so saving the
      variable alone changes nothing. This is the usual cause of a blank page.
- [ ] The trailing `/api` is required

---

## Step 6 — If uploads still fail

Get the real error instead of guessing.

- [ ] Open the deployed site, press **F12** → **Network** tab
- [ ] Attempt an upload
- [ ] Find the failing request and report:

| What to look for | Why it matters |
|---|---|
| The **status code** (or "CORS error") | 500 = server fault; CORS = header problem |
| The **URL** | `/api/milestones/...` = our API; `supabase.co` = direct-to-storage |
| The **Response** body | The actual exception message |

Also check **Render → Logs** for the exception stack trace at the same moment.

---

## Step 7 — Security, before this is public

- [ ] **`RUN_SEED=false`** — it runs `db:seed` on *every* boot, creating
      `admin@psm.test` with the password `password`. On a public URL that is an
      open admin login.
- [ ] **Remove `SANCTUM_STATEFUL_DOMAINS`** entirely. The app does not register
      `EnsureFrontendRequestsAreStateful`, so it does nothing — and when it *was*
      honoured, every browser write returned `419 CSRF token mismatch` while
      GETs kept working.
- [ ] **Rotate every credential in this document once testing is done.** The
      database password, the S3 keys and the `APP_KEY` have all been shared in
      plain text. Supabase → Project Settings → Database for the password;
      Storage → S3 for the keys.
- [ ] Confirm `APP_DEBUG=false` (set) so stack traces are not shown publicly

---

## Why it feels slow

Measured from here, for context — not a fault to fix:

| Request | Time |
|---|---|
| First request after idle | **~28s** (free-tier cold start) |
| Subsequent requests | ~0.2s |
| Login | ~2.6s |
| Project list | ~1.0s |

The 28s is Render's free tier spinning the container down after ~15 minutes
idle. Unavoidable without a paid plan; warm the URL a minute before demoing.

The 1–2.6s on warm requests is **network distance**. Your database is Supabase
in Tokyo (`ap-northeast-1`). If Render runs in a US region, every query crosses
the Pacific twice. **Check Render → Settings → Region.** If it is US, moving the
service to Singapore is the single biggest speed improvement available — and
would pair well with the Neon database, which is already in Singapore.

---

## Quick reference — expected values after the fix

| Check | Expected |
|---|---|
| `/up` | `200` |
| Highest file id visible | ≤ 76 |
| A **newly uploaded** file download | `200` with bytes |
| A **seeded** file download | `404` — expected, the bytes never existed |
| Upload from the browser | no network error |
| Render logs at boot | migrations applied, **no** seeding |

---

## A note on the two local databases

Worth knowing so you do not compare the wrong numbers:

| | `psm-db` container (local MySQL) | Supabase (what the app uses now) |
|---|---|---|
| Rows | 77 | **76** |
| Highest id | 77 | **76** |
| Row 77 | a real HIRARC PDF, 251 KB | does not exist |

The local MySQL container still holds an older copy from before the cutover,
including one genuinely uploaded file. It is **not** what the app reads any
more. If you check a count and see 77, you are looking at the old container.
