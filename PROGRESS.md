# PROGRESS — PSM Assessment System (thesis + code)

**Last updated:** 2026-10-25
**Owner:** yuehe
**Purpose:** the single file another agent (or you, next month) reads first to
know exactly where this project stands.

> This file is the handoff. The older `.workbuddy-ai/memory/` notes cover only
> the *codebase* and stop at 2026-10-05 — they know nothing about the thesis.
> If the two disagree about code, the code wins; this file wins on thesis.
>
> **The file covers two bodies of work and they are tracked separately:**
> §3 is the **thesis** (Chapters 1–5, diagrams). §3A is the **codebase** — what
> the system actually does today. Neither substitutes for the other.

---

## 1. What the project is

A web-based **PSM Assessment System** for FSKTM, UTHM: one system that runs the
whole *Projek Sarjana Muda* lifecycle for **PSM 1 and PSM 2 in parallel** —
title registration, supervisor pairing, milestone chain, rubric assessment,
report release, and notification.

- **Backend:** Laravel (API), **PostgreSQL**, Sanctum tokens, RBAC.
- **Frontend:** React SPA (Vite).
- **Runs in Docker** (`psm-app`, `psm-db`, `psm-mailpit`, `psm-frontend`).
  No PHP on the host — use `docker exec psm-app php artisan …`.

> **The database is PostgreSQL, not MySQL.** `backend/.env` sets
> `DB_CONNECTION=pgsql` against a **Supabase** pooler
> (`aws-0-ap-southeast-1.pooler.supabase.com`). `config/database.php` still
> defaults to `mysql`, which is only a fallback and has misled readers before.
> A few migrations branch on `pgsql` vs `mysql` explicitly — check the driver
> before writing raw SQL.

### The system's stated contribution (this is the defended gap)

No existing FSKTM system does these three things:

1. **Automated weighted marking** of the five Lampiran rubrics.
2. **Milestone chain gating** — a milestone cannot open before its predecessor
   is approved.
3. **Result release only after every assessor has submitted.**

Everything else (tracking, reminders, RBAC, supervisor matching) already exists
in FFMS / the PSM Supervision System / the Supervisor Selection System, so it is
*not* claimed as novel. See §3.

---

## 2. Where everything lives

| Thing | Path |
|---|---|
| Code + thesis | `C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system` |
| Thesis source (Word) | `C:\Users\yuehe\Desktop\study\fyp\thesis\` |
| Diagram PNGs | `thesis\diagrams\` (in the project folder) |
| Generated `.docx` deliverables | `thesis\*.docx` (in the project folder) |
| Build scripts I wrote | `.workbuddy-ai\*.py` |
| Older code memory | `.workbuddy-ai\memory\MEMORY.md` (to 2026-10-05) |
| Chat transcripts | `%USERPROFILE%\.dsh\sessions\--C-Users-yuehe-…--\` (zstd, not readable) |

**Important:** the thesis folder is *outside* the agent workspace, so an agent
with workspace-write can read it but **cannot write to it**. Deliverables are
written into `psm-system\thesis\` and copied over by hand.

---

## 3. Thesis status

### Chapter 1 — Introduction ✅ DONE (cited)

File: `thesis\Chapter1 - with citations.docx`

- Introduction + Problem Statement now carry **7 in-text citations** and a
  References section (author-date, APA-like).
- Sources, all AITCS (FSKTM's own journal), all verified:
  - **Nor Azim & Arbaiy (2022)** — FSKTM postgraduate assessment uses printed
    forms returned for manual analysis. Used for "manual admin" + "fragmented
    assessment".
  - **Chong & Arbaiy (2022)** — FSKTM pre-viva; *hand counting leads to
    calculation errors*. Used for "hand totalling" + the web-based approach.
  - **Arumugam & Abd Ishak (2025)** — FSKTM FYP; manual task tracking,
    inefficient communication. Used for "progress not visible".
  - **Tam & Abdullah (2025)** — FSKTM FYP; mismatched student research and
    supervisor expertise. Used for PSM structure + supervisor matching.

### Chapter 2 — Literature Review ✅ DONE

File: `thesis\Chapter2 - PSM Assessment System v2.docx` (current)

- Rewritten from scratch (the original draft was about **metaheuristic
  optimisation / CT** — a completely different project, discarded).
- Style matched to the user's example: TNR 12pt, 1.5 spacing, `Heading 2` with
  literal `2.x` numbering, bold system names, `< Name >` markers, ✔/✖ table.
- **Three compared systems, all FSKTM/AITCS and all about FYP:**
  FFMS (Arumugam & Abd Ishak, 2025), PSM Supervision System (Wan Fauzi &
  Alduais, 2025), Supervisor Selection System (Tam & Abdullah, 2025).
- ~1,670 words, 6 pages. Definitions cited: CIO Wiki (web app), NIST (RBAC).
- Table 2.1 compares 14 features; the three assessment rows are ✖ across all
  three existing systems → this is the evidence for the §1 contribution.

### Chapter 4 — Analysis & Design (use cases) 🔶 IN PROGRESS

Each use case gets: use-case specification table + **sequence diagram** +
**activity diagram**, as its own `.docx`.

| # | Use case | Status | Figures |
|---|---|---|---|
| UC-01 | Login and Authentication | ✅ | Fig 4.1 seq, 4.2 act |
| UC-02 | Manage Pairing | ✅ | Fig 4.3 seq, 4.4 act |
| UC-03 | Manage Milestone | ✅ | Fig 4.5 seq, 4.6 act |
| UC-04 | Generate Report | ✅ | Fig 4.7 seq, 4.8 act |
| UC-05 | Manage Alert and Notification | ✅ | Fig 4.9 seq, 4.10 + 4.11 act |
| UC-06 | **Manage Evaluation** | ⬜ NOT STARTED | — |
| UC-07 | **Manage Title and Registration** | ⬜ NOT STARTED | — |

The **use case diagram** (draw.io, `PSM.drawio.png`) shows: Student, Admin,
Supervisor, Lecturer, Panel, Coordinator, System Controller against login,
assignment, milestone, evaluation, report, alert, title/registration.

### Remaining thesis work

- [ ] UC-06 Manage Evaluation (spec + sequence + activity)
- [ ] UC-07 Manage Title and Registration
- [ ] Chapter 3 — Methodology (Waterfall per the template)
- [ ] Chapter 5 — Implementation and Testing (incl. UAT)
- [ ] Chapter 6 — Conclusion
- [ ] Front/back matter: abstract, TOC, list of figures/tables

---

## 3A. Codebase status (what the system actually does)

Companion to §3. §3 tracks the *thesis*; this tracks the *software*. Updated
2026-10-25 after a build session covering the lecturer role, coursework, the
programme rule and a set of correctness fixes.

### Assessment model — the numbers changed, know them

| Part | Components | Total |
|---|---|---|
| **PSM 1** | Coursework **35** + Lampiran E 35 + Lampiran I 30 | **100** |
| **PSM 2** | Lampiran G 50 + H1 5 + H2 5 + J 40 | **100** |

- PSM 1 used to total **65**, with the remainder "marked outside the system".
  Coursework closed that gap — see `config/psm.php` `assessment_weights` and
  `coursework`.
- Coursework = **Quiz 5, Assignment-C 12, Assignment-P 9, Assignment-A 9**.
  These are faculty-fixed constants, not settings. `LecturerMark::components()`
  is the single declaration; the form, validation and aggregate all read it.
- The aggregate **already rescales to 0–100** (`computeFinalGrade()`:
  `(weightedTotal / weightSum) * 100`). Adding coursework therefore *re-weights
  the blend*, it does not fix a denominator. Every PSM 1 mark moves.
- **A mark is a percentage, so a pass mark is meaningful again** →
  `psm.pass_mark` (default 40). Do **not** add letter grades: bands were
  deliberately refused and the faculty has published no cut-offs.

### Roles

`student`, `supervisor`, `coordinator`, `admin`, **`lecturer`**.

- **There is still no `examiner` role.** Examining is a *seating*
  (`examiner_assignments`). This was deliberate and must not be undone.
- **`lecturer` is a real role but a narrow one.** They key coursework for one
  teaching section and nothing else: `Role::worksOnPsm()` is false for them and
  `canAssess()` excludes them. Verified they cannot reach projects, milestones,
  evaluations, reports, assignments or the leaderboard.
- **A supervisor who lectures a section is supported**: entitlement comes from
  the *appointment* (`User::lecturerSection()`), not the role. They keep every
  supervision and panel power and gain the coursework screen. `users.role` holds
  one value, so no role list could express this —
  `User::extraCapabilities()` reports `['keyCoursework']` and the nav unions it.

### Sections and coursework

- `sections` is **per term**: `UNIQUE (academic_semester_id, code)`.
  `S1` exists once per term; the row **is** the lecturer appointment. Rotation
  needs no join table — verified that the same code in two terms is allowed,
  that the same code in one term is refused, and that editing one term does not
  touch the other.
- `lecturer_marks`: one row per (student, term). Null means **not entered**,
  never zero — an incomplete set is excluded from the aggregate rather than
  dragging a mark down.
- Entry screen: `/dashboard/lecturer`. Admin assigns sections and places
  students at `/admin/sections` (admin-only nav entry; coordinators may place
  students but not appoint lecturers).
- **Not built:** CSV import of `matric,section`. Placement is manual, one
  student at a time. The external registration portal is the system of record;
  this system only mirrors the result.

### Programme rule (FSKTM)

- Five programmes: **BIS, BIM, BIP, BIW, BIT**. `BIP` is Software Engineering
  (the enum once used `BIK`; a migration renamed the stored values and `BIK` is
  still accepted as an alias).
- A supervisor **and** an examiner must share the student's programme.
- The rule **fails open**: an unrecorded programme permits the pairing. That is
  intentional so legacy rows are not locked out — which means **a staff row with
  no programme enforces nothing**. Every programme now has **4 staff** for
  exactly this reason (a panel needs 2 same-programme examiners).
- Both candidate lists filter on `canSuperviseProgramme()` so the picker offers
  only what the save would accept. Earlier the picker offered everyone and the
  save refused afterwards — a trap the coordinator could not avoid.

### Recently fixed — do not regress these

- **Panel re-seating works.** A retired seat (`is_active = false`) is
  **reopened**, not refused. Both unique indexes ignore `is_active`, so
  re-inserting fails; and `assignPair()` retires the current panel first, so
  refusing made re-seating a previously-retired examiner impossible from any
  screen.
- **`open` on an assessment window is fast.** It walked every student with a
  full allocation; now it has a fast path, per-request caches, one shared rubric
  load, and a bulk timeout on the client. Worst case went 31s → ~10s.
- **The admin user list no longer times out.** Capacity getters each queried
  per row (16 queries per supervisor). They read the eager-loaded relation now;
  endpoints load the nested chain. 12.7s → 1.4s.
- **The term card distinguishes three states**: `X / Y submissions complete`,
  `N awaiting a form`, `M not yet allocated`. `Y` counts *submissions*, not
  students — a student with no assessment window is in neither figure.
- **A student sees their own mark and forms.** `/api/evaluations` used to 403
  every student (the list denied them while the row rule allowed a released
  form). The Mark tab read `final_mark`, which is withheld from students; it now
  uses the breakdown, the same source the dashboard uses.

### Removed / retired

- **Archive module is hidden from the UI** (sidebar + admin dashboard card).
  Routes, API and records remain so it can be restored. Project records already
  persist across terms without it: `Project::visibleTo()` filters on
  *membership*, not term or `archived_at`, so a student in PSM 2 still sees
  their PSM 1. Nothing in the system deletes a student's project.
- **No Archive button on the project page** — there never was one; the API
  exists but no UI calls it. Archiving is currently unreachable from the browser.

### Seeded demo accounts

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@psm.test` | |
| Coordinator | `coordinator@psm.test` | |
| Supervisor | `supervisor@psm.test` | 2 supervisees, 6 panel seats |
| Supervisor | `sup-002@psm.test` … `sup-008@psm.test` | |
| **Lecturer** | `lec-001@psm.test` … `lec-004@psm.test` | sections S1–S4 |
| **Supervisor (new)** | `sup-009@psm.test` … `sup-015@psm.test` | added to give every programme 4 staff; **zero load on purpose** |
| Student | `student@psm.test` | `AI240001` |

All passwords are `password`. Student matric numbers are `AI240001` upward.
Other students are `<name>@student.psm.test`.

### Verification (there is still no test suite)

No `backend/tests`. The working pattern for every change:

```powershell
# write PHP to $env:TEMP, copy in, run through tinker, delete
docker cp "$env:TEMP\probe.php" psm-app:/var/www/html/probe.php
docker exec -i psm-app php artisan tinker probe.php
docker exec -i psm-app rm -f /var/www/html/probe.php
```

- Wrap destructive probes in `DB::beginTransaction()` / `DB::rollBack()`.
- `php -l` every changed file; `npx eslint <files>`; `npm run build`.
- **After adding any class or route: rebuild the autoloader**, or you get
  `Class ... does not exist` / `Target class ... does not exist`:
  ```
  docker run --rm -v "<abs>/backend:/app" -v "psm-system_vendor-data:/app/vendor" \
    -w /app composer:2 dump-autoload --no-dev --optimize --classmap-authoritative
  ```
  and `php artisan route:clear` for new routes.
- **`config/psm.php` changes need `php artisan config:cache`** — the config is
  cached, and an edit without it silently does nothing.

---

## 4. Diagram conventions (MUST FOLLOW — user was unhappy once)

All diagrams are **generated PNGs**, drawn by Python + Pillow, in
`.workbuddy-ai/`. They are **not** editable in Word.

### Hard rules the user gave (2026-10-23)

1. **No fills, no coloured borders.** Plain white shapes, thin dark outline
   (`#1A1A1A`). Colour reduces legibility.
2. **Arrows must start and end exactly on shape edges.** Never through a box,
   never starting in empty space. (The first generation had arrows overlapping
   text and arrows from nowhere — this was rejected.)
3. **Type must be legible at print width.** Base 17pt at source, 14pt for edge
   labels. Figures sit at **14.2 cm** wide (full text width) where possible.
4. One figure per idea. If a flow has two independent halves, split it into two
   figures (that is why UC-05 has 4.10 and 4.11).

### Scripts

| Script | Makes |
|---|---|
| `uml_kit.py` | **Shared drawing library** — shapes, arrows, `connect()`, `route()`, `label()`. Start here. |
| `act_login_v2.py`, `act_pairing_v2.py`, `act_ms_v2.py`, `act_report.py`, `act_notif.py` | Activity diagrams |
| `seq_report.py`, `seq_notif.py` | Sequence diagrams (later ones) |
| `make_*_seq.py`, `make_*_act.py` | Earlier sequence/activity diagrams |
| `build_ch4_*.py` | Build the `.docx` for each use case |
| `build_xlsx.py` | The HIRARC form (unrelated side task) |
| `cite_ch1.py` | Added the Chapter 1 citations |

Every `build_ch4_*.py` follows the same pattern: load
`thesis\c2\Chapter2.docx` as the style template, clear the body, write the
document, keep the trailing `sectPr`, save. **Reuse one of them as the template
for UC-06.**

### Verification loop (do this every time)

```powershell
& python build_ch4_<X>.py
& node <libreofficeKit.cli> render --input <docx> --output-dir <fresh dir>
```

Then *read the rendered PNG* and check arrows before declaring done. The
LibreOffice Kit paths are in the `office-render` skill.

---

## 5. Codebase gotchas (from `.workbuddy-ai/memory/MEMORY.md`, still valid)

- **Title is decided at the proposal milestone by the PANEL**, not by a
  supervisor. `MilestoneService::approve()` / `requestRevision()` **refuse**
  the proposal milestone — use `ProposalReviewService::recordDecision()`.
- Pairing is **coordinator/admin only**; supervisor and student can view only.
- Supervision capacity is enforced **per PSM part**, not globally; a `BOTH`
  pairing consumes a slot in both parts.
- `AssignmentService::assignSupervisor()` runs 6 ordered guards; programme
  check comes **before** capacity on purpose (a "full" message would mislead).
- Milestones: `activateNext()` only runs on **Approved**. A revision request
  reopens the same milestone and does **not** advance the chain.
- Reminders are de-duplicated by the `reminder_dispatches` ledger, so the
  scheduled command is safe to re-run.
- **`RUN_MIGRATIONS=true` runs `migrate:fresh --seed`** — restarting the
  container wipes the database.
- No test suite exists (`backend/tests` missing). Verify with
  `python backend/tools/check_seeders.py` and
  `python frontend/tools/check_frontend.py`.

---

## 6. How to hand this to another agent

Do **not** rely on the chat transcript — it is zstd-compressed under
`%USERPROFILE%\.dsh\sessions\` and a new agent cannot read it. Do this instead:

1. Keep **this file** (`PROGRESS.md`) in the repo root and keep it current.
   It is the memory.
2. Keep `.workbuddy-ai/` (scripts + `memory/`) in the repo. It is gitignored-
   free but currently untracked — see §7.
3. Open the new agent **with this folder as the working directory** so it sees
   the same workspace.
4. First message to the new agent — **pick the one that matches the work**:

   *Thesis work (diagrams, chapters):*

   > Read `PROGRESS.md` §3 and §4, then `.workbuddy-ai/memory/MEMORY.md`.
   > Continue with UC-06 Manage Evaluation (spec + sequence + activity),
   > following the diagram rules in §4. Reuse `build_ch4_notif.py` as the
   > template.

   *Code work:*

   > Read `PROGRESS.md` §1, §3A, §5 and §7. The database is **PostgreSQL on
   > Supabase**, not MySQL. There is no test suite — verify by writing a probe
   > to `/tmp`, `docker cp` it into `psm-app`, and running it through `tinker`
   > inside a transaction you roll back. Rebuild the autoloader after adding
   > classes, and `config:cache` after editing `config/psm.php`.

5. Update `PROGRESS.md` at the end of every work session. One line per
   completed item; move it from ⬜ to ✅.
   **One session updates it, not every chat.** Two sessions writing the same
   file concurrently overwrite each other, and a file that is current in one
   area and stale in another is harder to trust than one honest date. If several
   chats are open, have the one that *finished last* write the update.

### Suggested AGENTS.md stub

If the harness supports `AGENTS.md` (it reads it automatically when present),
create one containing:

```
See PROGRESS.md for project state and diagram conventions.
Thesis deliverables go to ../thesis/ — that folder is read-only to you;
write into ./thesis/ and tell the user to copy across.
```

---

## 7. Open issues / risks

### Codebase (2026-10-25)

- [ ] **CSV import of `matric,section` is not built.** Placement is manual, one
      student at a time on `/admin/sections`. At real cohort size this is the
      main gap: a missed student silently never appears on any lecturer's roster.
- [ ] **Section placement is seeded round-robin by matric order**, so the demo
      rosters are arbitrary — not a real allocation.
- [ ] **`RUN_SEED=true` must be turned off before the cohort is real.**
      `DatabaseSeeder` creates `admin@psm.test` on the password `password`.
- [ ] **The Vercel URL in use may be a frozen deployment URL.** Use
      `psm-git-main-psm-proj.vercel.app` (follows `main`), not
      `psm-<hash>-…`, which never updates. See `docs/DEPLOYMENT.md` §8.
- [ ] **`sections` has no unique constraint on `lecturer_id`.** "One section per
      teacher per term" is enforced only in `LecturerMarkController::update()`.
      A direct DB write could break it.

### Thesis

- [ ] `thesis/` and `.workbuddy-ai/` are **untracked in git**. The deliverables
      exist only on disk. Either commit them or back them up.
- [ ] The old `Chapter2 - Literature Review*.docx` files (4 stale copies) are
      still in `thesis\`. Only `Chapter2 - PSM Assessment System v2.docx` is
      current. Same for the earlier `Chapter1` — only
      `Chapter1 - with citations.docx` is current.
- [ ] Citation style is **author-date**. If the faculty requires IEEE numbered,
      all chapters must be converted together.
- [ ] Chapter 2 compares only 3 systems (template expects 3). Two more PSM
      systems were found but not used: e-PSM (Fang & Darman, 2022) and the
      FSKTM Supervision System (Chong & Wahid, 2021 — the coordinator's own
      paper).
- [ ] The HIRARC form (`HIRARC FORM_…xlsx`) was built but the user's personal
      fields were deliberately left blank.
