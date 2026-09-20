# 2026-09-20 — Getting the system running end to end

Full day's work: the PSM system went from "never executed" to running with data.

## The project moved

`psm-system` now lives at **`C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system`**.
`Documents\AIProject` is **locked for every process** — my tools, my shell, and the
user's own Git Bash. `mkdir` there fails with `ENOENT` rather than `EACCES`, which
points at the wrong problem. Everything else on the machine is writable.

**Lesson:** when a write fails with ENOENT/EBADF on a path that reads fine, probe
writability at each level (`for d in ...; do mkdir -p "$d/.wprobe"; done`) before
assuming a typo. Then stop retrying and hand the user an exact change.

## Fixed, in the order they appeared

1. **`composer.json`** — three problems, one root cause: three unused packages.
   - `maatwebsite/excel` (CSV exports use `fputcsv`), `fruitcake/laravel-cors`
     (abandoned; cannot install on Laravel 11), `barryvdh/laravel-dompdf`
   - Composer 2.10 advisory blocking → `config.policy.advisories.block = false`
   - PHP 8.5 in the `composer:2` build image vs PHP 8.2 runtime →
     `config.platform.php = "8.2.0"`
2. **Missing Laravel skeleton files** — `artisan`, `public/index.php`,
   `bootstrap/providers.php`, `storage/` tree, `bootstrap/cache/`.
3. **`routes/console.php`** — all five scheduled events called `->name()` *after*
   `->withoutOverlapping()`. Laravel reads the name inside that call, so the
   order matters. A real runtime-only bug no static check would catch.
4. **Forward foreign keys** — `reminder_dispatches` (migration 2) referenced
   `milestones` (6); `examiner_assignments` (4) referenced `projects` (5).
   Moved both into migrations 12 and 13.
5. **Pivot without timestamps** — `supervisor_expertise` while both models
   declare `->withTimestamps()`.
6. **`ProjectSeeder`** — omitted the required `uploaded_by` on `submission_files`.
7. **Double-unwrap, 9 sites** — `endpoints.js` applies `unwrap` internally (105 of
   132 methods), so pages that unwrapped again got `null` and crashed.
8. **`assignmentApi.supervisors`** — pointed at a route I invented; the real one
   is `/users/options`.
9. **`/api/milestones` did not exist** — added `MilestoneController::all()`
   reusing `Project::scopeVisibleTo()`, plus the route.

## Scripts that found bugs reading by eye had missed

Both are worth keeping in the repo:

- **Forward-FK detector** — parses every `Schema::create`, records migration
  order, flags any `constrained()` whose target table is created later. Gotcha:
  the table name must be **pluralised** from the column, or it reports zero.
- **Pivot timestamp checker** — extracts create blocks by brace matching, then
  cross-references tables reached via `belongsToMany()->withTimestamps()`.
- **Double-unwrap detector** — parses `endpoints.js` by brace matching to learn
  which methods already unwrap, then scans pages. Must also follow *delegating*
  methods (`rubricApi.cloneTemplate` → `evaluationApi.cloneTemplate`).

## Mistakes I made, worth not repeating

- Wrote `Sanctum::ignoreMigrations()` — **does not exist in Sanctum 4.** The real
  cause was orphan tables from a crash-loop; `migrate:fresh` was the answer.
- Called a duplicate-migration conflict from the error text without checking the
  API existed first.
- Built the SPA into `backend/public/build` before reading `routes/web.php`,
  which says plainly the SPA is a separate deployment.
- Invented `assignmentApi.supervisors` when rewriting `endpoints.js` without
  checking `routes/api.php`.

## Verified working at end of session

13 migrations, 6 seeders, all five roles logging in, API returning 200 across
every module, SPA served by Vite on :5173.

## Next: go live

`GO_LIVE.md` at the project root is the step-by-step guide. Outstanding:
`backend/composer.lock` missing, `frontend/package-lock.json` is a stub, no git
repo yet, `/api/milestones` needs `route:clear` to go live.

## Note on my own memory

This file is written late: my usual memory directory is under `Documents\`, which
is locked. Session notes went to `psm-system/docs/SESSION-NOTES-2026-09-20.md`
instead.
