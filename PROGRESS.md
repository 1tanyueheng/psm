# PROGRESS — PSM Assessment System (thesis + code)

**Last updated:** 2026-10-26
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
- **CSV import exists**: `POST /api/sections/import` takes `matric,section` as
  an uploaded file *or* pasted text, and returns a per-row report —
  `placed` / `unchanged` / `failed`, each with a line number and a reason. The
  external registration portal is the system of record; this only mirrors the
  result, so an unrecognised matric is reported rather than invented.
  It must name a term when uploading, because section codes repeat per term.
- Also available: `POST /api/sections/copy` (provision a term by copying the
  previous term's sections in one click) and `GET /api/sections/lecturers`.

### Marking — 0–5 entry, converted to the rubric weight

Every Lampiran is keyed as a **0–5 select per criterion**, not as raw rubric
marks. The system converts that to the form's own weight. Lampiran J still
totals 40; the *entry scale* changed, the weighting did not.

- Do not "fix" a 0–5 entry by printing it as a mark. `add_item_score_to_evaluation_scores`
  stores the item score **beside** the converted marks, which is why both exist.
- Lampiran **R1** is the supervisor's per-milestone response (Chapters 1–4 in
  PSM 1 only; Chapters 5–6 in PSM 2 have none). Its two checkboxes decide
  whether the milestone settles or goes back. **Its scores are evidence only and
  never reach a grade** — the R1 is the review, not a document the student files.
- Chapter milestones stay **open**; a student may resubmit at any time and
  nothing closes at the deadline. The due date drives the at-risk report only.

### Digital forms

Lampiran **A, B, C** are first-class documents rendered in the app — not HTML
files, not uploads. A signature is drawn in the browser (`SignaturePad`) and
stored with the form, so the evidence attached to assessment is the form itself.

- **OSHE** sits *after* Lampiran C on the milestone chain, not after the
  proposal. Order matters: OSHE → Lampiran C → Chapters.
- The panel's proposal verdict remains separate from the supervisor's R1.

### Storage

Uploads go to **Supabase Storage via its S3 endpoint**
(`qvivcqtvaiambvlzlwts.storage.supabase.co/storage/v1/s3`, bucket `PSM`), not to
local disk. `FILESYSTEM_DISK=s3`.

- The disk is configured to **throw** rather than silently fall back to local —
  a missing S3 config should fail loudly, not scatter files across a container
  that is rebuilt on every deploy.
- **Two Supabase projects exist and were confused once.** Production is the
  **Singapore** project `qvivcqtvaiambvlzlwts` (`aws-0-ap-southeast-1`). An older
  **Tokyo** project (`szxyhboqbbrndozwburd`) appears in early sessions. Check the
  host in `.env` before debugging "wrong data" — you may be looking at the other
  database. The demo cohorts differ (Singapore has 26 projects).

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

### End of a project — verdicts, repeats, enrolments (2026-10-26)

**This is the newest part of the model and the part most likely to be
misunderstood. Read it before touching anything about "finishing" a project.**

A project used to have no way of *ending*. `archived_at` was the closest thing,
but archiving only hid a project — it never said whether the student passed, so
nothing downstream could act on the result. `resolve()` supplies the missing
step, and `student_enrolments` supplies the missing record.

**A project has three end states.** `in_progress` / `submitted` = running;
`completed` = passed; `failed` = repeat required. `archived_at` is **not** a
lifecycle marker — it is not used for anything.

| Piece | Where | What it does |
|---|---|---|
| `ProjectResolutionService::resolve()` | `backend/app/Services/` | Records pass/fail on one project, stores who/when/why in `metadata`, closes the supervision (if no other part is running) and frees the panel seats. **Refuses until the term's `MarkSubmission` is `locked`.** |
| `ProjectResolutionService::repeat()` | same | Marks a failed attempt repeatable. Creates **nothing** and **guesses no term** — it opens an unplaced `enrolling` enrolment. |
| `EnrolmentService` | `backend/app/Services/` | `enrol()` places a student into a part for a term; `openRepeat()` opens an attempt with no term; `placeRepeat()` gives it one; `withdraw()` is a deferral, **not** a failure. |
| `student_enrolments` | migration `2026_10_26_000001` | One row per student × PSM part × semester, with `attempt_no`. |
| `GET /api/projects/verdicts` | `ProjectController` | The committee's worklist — **both** parts, per project, with a `blocker` on every row that cannot be ruled on yet. |
| `VerdictPage` (`/verdicts`) | `frontend/src/pages/coordinator/` | Filters (batch / outcome / free text), row actions **Pass** · **Fail…** (reason required) · **Start repeat**. |
| `GET/POST /api/enrolments` | `EnrolmentController` | Cohort register; `/eligible` lists every student with a blocker; batch enrol; `/{id}/place`; `/{id}/withdraw`. |
| `EnrolmentPage` (`/enrolments`) | `frontend/src/pages/admin/` | **Cohort** tab + **Awaiting a term** tab (unplaced repeats). |

**Why enrolments exist — the bug that forced them.** A student's term used to
live *only* on their project (`projects.academic_semester_id`); `student_profiles`
had no term at all. Three consequences:

1. **A repeat had to guess its term.** PSM 2 repeat created the new project in
   `AcademicSemester::current()` or the next term by start date. When neither
   resolved, the project was written with a **null** `academic_semester_id` — a
   repeat recorded with no term behind it. *Observed, not theoretical.*
2. **Attempts could not be counted.** Only repeats carried
   `metadata.repeat_number`; the first attempt carried nothing.
3. **Registration had nothing to attach to.** "May this student register?" was
   answered by the *absence* of an active project — which is why removing the
   archive module forced seven queries off `archived_at IS NULL` onto
   `->active()`.

Now the committee's decision opens an enrolment with **no term**, and the admin
chooses the term on `/enrolments`. There is no code path that can invent one.

**Enrolment states:** `enrolling` → `active` → `completed` | `failed` |
`withdrawn`. `withdrawn` is **not** `failed` — a deferral is not an academic
failure and must not read as one in a transcript or the analytics.

**Two partial unique indexes, not `$table->unique()`:** placed rows are unique on
(student, term, part); unplaced repeats are unique on (student, part), so a
student cannot hold two waiting repeats of the same part.

**Rules, all enforced server-side:**

- A verdict is refused until marks are `locked` (a verdict commits a grade).
- A repeat is refused until the failed term **is closed** (`isClosed()` =
  `closed_at` set, or `is_active` false — **not** `ends_at`).
- A repeat goes into a **later** term than the one failed.
- A part already **completed** cannot be repeated.
- Resolving twice is refused; a verdict is not overwritten silently.
- PSM 1 repeat **creates no project** — the student re-files Lampiran A.
- Nothing is deleted: a failed attempt keeps its marks and its enrolment row.

**Worklists show blocked rows, never omit them.** Both `/verdicts` and
`/enrolments/eligible` return every student with a `blocker` string. A student
absent from a picker is unpickable *and* undebuggable.

### The proposal — two signatures, two people (2026-10-27)

**Read this before touching anything about the proposal milestone or title
decisions.**

The faculty's sequence is: the student **uploads the proposal**, the
**supervisor reads and endorses it**, and only then does the **panel** sit at the
title defence and settle the title. The code used to let the panel decide a
merely-submitted proposal, with no supervisor stage at all.

```
open ──upload──> submitted ──supervisor endorses──> reviewed ──panel──> approved / conditional / rejected
              └──supervisor sends back──> rejected ──re-upload──> submitted
```

| Rule | Where |
|---|---|
| `Submitted → Reviewed` only — no direct panel verdict | `MilestoneStatus::allowedTransitions()` |
| Supervisor endorses: `endorseProposal()`, `POST /milestones/{id}/endorse` | `MilestoneService`, `MilestoneController::endorse` |
| Panel refuses unless `reviewed` | `ProposalReviewService::assertAwaitingDecision()` |
| Supervisor sends back → `Rejected`, `revision_count + 1` | `requestRevision()` — **no longer refused for the proposal** |
| Endorsement does **not** open the chain | `endorseProposal()` never calls `activateNext()` |
| `approve()` still refused for the proposal | `assertNotProposal()` |

**The proposal is a document, not text fields.** A file is required to submit any
milestone (`MilestoneController::submit` — `files` is `required`), and
`MilestoneStatus::isSubmittable()` returns true in **every** status, so a student
may withdraw and re-upload freely, including after approval. `SubmissionFile`
marks the old version superseded rather than deleting it.

**Rejected-title loop (verified end to end):** panel rejects → `changeTitle()`
rewrites the project title and reopens the milestone to `Open` → supervisor
re-endorses → panel decides again, `revision_count` incremented. A rejection does
**not** create Lampiran C — only `conditional_approve` does.

### What registration collects — per the official forms (2026-10-27)

Checked against `form required/lampiran A-B/`. **Lampiran A has no abstract,
objectives or scope. Neither does Lampiran B.**

| Form | Real fields |
|---|---|
| **Lampiran A** | Session · Name, Matric, **Year of study**, Programme, **IC**, **Passport**, **Mobile**, Email · Titles 1–3 · English tick · Supervisor · **agreed title** · Part D for JKPSM |
| **Lampiran B** | Student particulars · Supervisor, Title, **Project type**, **Field of study** · **Requirements** (software / hardware / technology) · Origin (own idea / supervisor's) |

Lampiran B then says, in as many words: *"attach a proposal containing at least
Introduction, Problem Statement, Objective, Scope."* **That attached document is
the proposal milestone.**

- `projects.abstract` is **no longer collected at registration** and is now
  **nullable** (migration `2026_10_27_000001`). It was required, so students wrote
  one before the title was settled, and nothing downstream read it.
- The real Lampiran B fields live in `projects.metadata` — `field_study`,
  `origin`, `requirements.software/hardware/technology` — and are lifted flat in
  `SupervisorAgreementResource`.
- `RegistrationDocumentCard` prints **Bidang Pengajian** and the three
  **Keperluan** rows plus the **PENTING** attachment note. It no longer prints
  abstract/objectives/scope — those were never on the form.

### Recently fixed — do not regress these

- **Panel re-seating works.** A retired seat (`is_active = false`) is
  **reopened**, not refused. Both unique indexes ignore `is_active`, so
  re-inserting fails; and `assignPair()` retires the current panel first, so
  refusing made re-seating a previously-retired examiner impossible from any
  screen.
- **`open` on an assessment window is fast.** It walked every student with a
  full allocation; now it has a fast path, per-request caches, one shared rubric
  load, and a bulk timeout on the client. Worst case went 31s → ~10s.
- **Duplicate supervision rows are gone.** The seeder's `updateOrCreate` keyed on
  `psm_part`, but `ProgressionService::progress()` rewrites that column
  `PSM1 → BOTH` — so every re-seed inserted another active pairing (22 duplicate
  groups, 48 active rows). Now keyed on the pair only, plus a partial unique
  index on `(student_profile_id, supervisor_profile_id) WHERE is_active`. Loads
  halved for affected supervisors.
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

### Session history — how this got built

Seven sessions built the system between 2026-10-05 and 2026-10-25. Each resumed
the previous one from a session zip, so the thread is continuous. **This table is
provenance only** — for detail read the commit messages, and for the current
design read the sections above.

| Session | Date | Delivered |
|---|---|---|
| `b4752247` | 10-05 | HIRARC Excel form; Chapter 2 literature review; **all activity + sequence diagrams**; system renamed "PSM Assessment System" |
| `cc9c882f` | 10-05 | Semester **close** function; marking rework so marks appear on submit; PSM 2 inherits the title |
| `5e941af5` | 10-06 | Assessment / mark-release access, window selection, supervisor list in registration |
| `5e9874c3` | 10-06 | **Storage → Supabase** (via R2 first); **database → Supabase**; file management off local disk |
| `68331978` | 10-11 | Supervisee dashboard fix; **marking hidden until the coordinator opens the window**; file-management advice |
| `ccd5a141` | 10-13 | **Vercel + Render deployment**; per-form assessment windows; export-403 fix; Supabase corrections |
| `0ac8f6d1` | 10-19 → 23 | Digital Lampiran **A/B/C**; **Lampiran R1**; **0–5 mark entry**; **OSHE** placement; milestone-chain restructure |
| *(this session)* | 10-25 | Lecturer role + coursework (PSM 1 → 100); programme rule actually enforced; performance fixes; pass rate; PROGRESS.md |
| *(this session)* | 10-26 | Archive **removed** from the UI; duplicate supervision fixed; **verdict + repeat** lifecycle; **Form K** milestone; **student enrolments** (a student finally has a term of their own) |

The transcripts are zstd-compressed and unreadable to a new agent. They are not
a substitute for this file — read `git log --oneline` instead.

> **⚠️ A Supabase storage key was pasted in plain text** in session `5e9874c3`
> and is stored unencrypted in `~\.dsh\sessions\`. It is **not** the key
> `backend/.env` uses today, but a valid key is a valid key: revoke it in
> Supabase → Settings → Storage → S3 Access Keys. Never paste credentials into
> chat; put them in `.env`.

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
- **`tinker probe.php` boots a different kernel to an HTTP request — say what
  you mean.** A probe that writes inside a transaction is not visible to a
  separate `curl` call. If a probe has to set state up *and* call the API, write
  the setup for real and undo it afterwards, or you will test against the old
  row and conclude the feature is broken. (This cost an hour on the verdict
  work: the probe locked a mark, the API still reported `open`, and the "bug"
  did not exist.)
- **HTTP probes: log in with a real account and call with `curl`** — a 403 and a
  422 are the fastest way to prove a gate works. `student@psm.test` for the
  negative case, `coordinator@psm.test` for the positive one.
- `php -l` every changed file; `npx eslint <files>`; `npm run build`.
- Python checks: `backend/tools/check_seeders.py` and
  `frontend/tools/check_frontend.py` (module-graph and unused-export checks).
- **After adding any class or route: rebuild the autoloader**, or you get
  `Class ... does not exist` / `Target class ... does not exist`:
  ```
  docker run --rm -v "<abs>/backend:/app" -v "psm-system_vendor-data:/app/vendor" \
    -w /app composer:2 dump-autoload --no-dev --optimize --classmap-authoritative
  ```
  and `php artisan route:clear` for new routes.
- **Check `route:list` on a new route, not just that it registered.** A route
  added in the wrong `Route::prefix()` group registers happily and lands on the
  wrong URL — `/api/projects/enrolments` instead of `/api/enrolments`.
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

### Codebase (2026-10-26)

- [ ] **The exposed Supabase storage key has not been revoked.** Session
      `5e9874c3` contains it in plain text on disk. Revoke it at Supabase →
      Settings → Storage → S3 Access Keys. It is not the key `.env` uses, but it
      is still valid until revoked.
- [ ] **`/enrolments` has never been exercised end to end in the browser.** The
      API is verified over HTTP (cohort list, `/eligible`, 403 for a student),
      and the page builds and passes `check_frontend.py`, but no click-through
      has happened. Verify before demonstrating it.
- [ ] **Form K is a milestone, not a digital form.** `form_k` sits at the end of
      the PSM 2 chain and accepts a document upload; it is **not** the signed
      digital form that Lampiran C is (`LampiranCCard`, `SignaturePad`). The user
      asked for it to work like the other Lampiran and keep the original format —
      the PDF is at `form required/lampiran K/`. Not started.
- [ ] **No progress evaluation milestones for PSM 2.** The faculty timetable puts
      "Progress Evaluation 1 & 2" at weeks 5 and 11. The user said these are
      marking activities, not deliverables, so they were not modelled. If a
      marker needs a scheduled slot, they have to be added.
- [ ] **An enrolment belongs to the project leader only.** If a project ever
      gains a second member, that member has no enrolment row and does not count
      against capacity. The backfill used `is_leader = true` deliberately. The
      per-student-vs-per-project question for verdicts is also still unanswered.
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

### Resolved since the last update (2026-10-26)

- [x] **Archive is no longer offered anywhere in the UI.** Not an open issue any
      more, it is a decision: sidebar entry commented out, admin dashboard card
      gone, no project button. Routes, API and records stay dormant. See §3A.
- [x] **A finished project no longer consumes a supervisor's capacity for ever.**
      Capacity counts live enrolments now. See §3A.
- [x] **Duplicate supervision rows.** Seeder key fixed; partial unique index on
      `(student_profile_id, supervisor_profile_id) WHERE is_active`.
- [x] **A repeat can no longer be written with a null term.** `repeat()` opens an
      unplaced enrolment; the admin chooses the term. See §3A.

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
