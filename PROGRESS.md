# PROGRESS — PSM Assessment System (thesis + code)

**Last updated:** 2026-10-27
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

### End of a project — the mark decides, and closing the term applies it (2026-10-27)

**Read this before touching anything about "finishing" a project.** It replaces
the 2026-10-26 design, in which a committee gave each project a verdict from a
`/verdicts` board. That whole layer is now **deleted on purpose** — see §3A
"Removed / retired". The rule today, in one line:

> A project runs for a semester. When that semester closes, `final_mark >= 40`
> means passed, below it means failed. Nobody votes.

The written specification is `thesis/PSM-LIFECYCLE-SPEC.md` (signed off, §8
ticked) and the code follows it. Its five rules:

| # | Rule |
|---|---|
| R1 | A project is **running** until its semester closes. There is no status a human sets. |
| R2 | The **mark** decides: `final_mark >= pass_mark` (40). No committee verdict. |
| R3 | A title becomes the project's title **only when a panel accepts it** — a proposed title never overwrites an accepted one. |
| R4 | A refused title is **re-presented until approved** — same semester, **no cap**. A rejection is not a failure. |
| R5 | Only a **mark below 40** costs a semester. |

| Piece | Where | What it does |
|---|---|---|
| `TermCloseService::close()` | `backend/app/Services/` | Derives every project's `completed`/`failed` from its mark, narrows the supervision (both parts), releases the panel seats, and settles the enrolments — **in one pass**, because a student left `failed` while still occupying a supervisor's slot is the bug this exists to prevent. Called by `SemesterService::close()`. |
| `TermCloseService::closeBlockedReason()` | same | Refuses the close with a **named list** when an enrolled student has no project, or a project has no submitted mark. |
| `ProjectResolutionService::repeat()` | same | **The only survivor** of that class. `resolve()` is deleted. Marks a failed attempt repeatable and opens an unplaced `enrolling` enrolment. |
| `StudentEnrolment` + `/api/enrolments` | migration `2026_10_26_000001`, `EnrolmentController` | One row per student × part × semester with `attempt_no`. This is where a student's term lives. |
| `GET/POST /api/enrolments` | `EnrolmentController` | Cohort register; `/eligible`; batch enrol; `/place`; `/withdraw`. |
| `EnrolmentPage` (`/enrolments`) | `frontend/src/pages/admin/` | **Cohort** tab + **Awaiting a term** tab (unplaced repeats). |

**Non-attendance is a typed zero, never an assumption.** The assessor enters
`0`; the system never invents one, because "assessed and failed" and "never
assessed" are different facts and only the first is a fail.

**Why enrolments exist — the bug that forced them.** A student's term used to
live *only* on their project (`projects.academic_semester_id`); `student_profiles`
had no term at all. A repeat therefore had to *guess* a term and once wrote a
`null` one — observed, not theoretical — and attempts could not be counted.

**Enrolment states:** `enrolling` → `active` → `completed` | `failed` |
`withdrawn`. `withdrawn` is **not** `failed` — a deferral is not an academic
failure and must not read as one in a transcript or in the analytics.

**Two partial unique indexes, not `$table->unique()`:** placed rows are unique on
(student, term, part); unplaced repeats are unique on (student, part).

**Rules, all enforced server-side:**

- A verdict is gone; **only closing the term** writes an outcome.
- A repeat is refused until the failed term **is closed** (`isClosed()` =
  `closed_at` set, or `is_active` false — **not** `ends_at`), and goes into a
  **later** term.
- A part already **completed** cannot be repeated.
- PSM 1 repeat **creates no project** — the student re-files Lampiran A.
- Nothing is deleted: a failed attempt keeps its marks and its enrolment row.

**Worklists show blocked rows, never omit them.** `/enrolments/eligible` returns
every student with a `blocker` string. A student absent from a picker is
unpickable *and* undebuggable.

### The proposal — two signatures, two people, and a refused title re-presented (2026-10-27)

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

### Lampiran B is a record now, not a field (2026-10-27)

Before this, a title existed only as `projects.title` plus a milestone, so a
refused title had **nowhere to live** and the rejected title vanished when it
changed. `lampiran_b_forms` gives each filing a row.

| Column | Meaning |
|---|---|
| `project_id` | the same project, never restarted |
| `revision_no` | which filing this is — 1, 2, 3… |
| `title` | the title proposed **on this form** |
| `status` | `draft` \| `submitted` \| `endorsed` \| `accepted` \| `rejected` |
| `rejected_reason`, `rejected_at`, `rejected_by` | the panel's objection, kept forever |
| `superseded_by_id` | the next filing |
| `milestone_id` | the proposal milestone that carries it |
| `project_type`, `field_study`, `requirements`, `origin` | the real Lampiran B fields, lifted out of `projects.metadata` |

**`projects.title` is the last title a panel ACCEPTED** (R3). A proposed title
never overwrites it — through `LampiranBController::file()` or anywhere else.

**The refused-title loop (spec §3.2, R4), verified end to end:**

```
panel refuses  →  the form is marked `rejected` with the reason
              →  Lampiran B #n+1 opens as a `draft`, pre-filled, supervisor locked
              →  supervisor signs again (a signature on one title does not cover another)
              →  a NEW proposal milestone is inserted after the refused one
              →  supervisor endorses, panel sits again
              →  repeat as needed — no cap, the attempt count is shown
```

**Nothing is overwritten.** The refused proposal stays `rejected`, the refused
form stays `rejected` with its reason, and the new attempt is a new row. Each
attempt adds **two** milestones (a Lampiran B, a proposal), so a coordinator
sees a student on their fourth attempt in the chain itself rather than reading a
counter that someone has to cap.

| Piece | Where |
|---|---|
| `LampiranBForm` model (`scopeCurrent()`, `isSeededSignature()`, `nextRevisionFor()`) | `backend/app/Models/` |
| `LampiranBController` — `index`, `file`, `sign`, `requestTitleChange`, `openProposalFor` | `backend/app/Http/Controllers/Api/` |
| `LampiranBFormPolicy` — `file()` requires owner + draft/submitted; `sign()` requires `submitted` first, then supervisor/admin/coordinator | `backend/app/Policies/` |
| `ProposalReviewService::openRefileRound()` | creates the new B draft **and** the new proposal at `attempt = max+1` |
| `LampiranBCard` | on the proposal milestone: refused titles with reasons, revise form, sign / send back, filing history |
| `milestones.attempt` + migration `2026_10_27_000003` | milestone codes repeat per attempt, so the unique key is `(project_id, code, attempt)` |

**`changeTitle()` is deleted.** It let any student retitle their project with no
supervisor involved; a retitle is now a new Lampiran B, which carries a
signature. Verified: `file()` refuses the exact title the panel refused.

**Demo signatures.** Eight supervisor signatures exist at
`backend/storage/app/private/signatures/lampiran-b/*.png`, generated by
`.workbuddy-ai/make_demo_signatures.py`. Each carries a red **DEMO** watermark, is
stored with `signature_type = 'seeded'`, and `isSeededSignature()` is the third
marker. **They live in the Docker `psm-system_storage-data` volume, not on the
host** — the volume is mounted over `backend/storage`, so committing the PNGs
does not put them in the container. Copy them in after a volume reset (§6).

### Deadlines are labels, not locks (2026-10-27)

| Rule | |
|---|---|
| Every student shares the **same** deadline per milestone | no per-student dates |
| Late submission is **never blocked** | `Milestone::acceptsSubmission()` no longer reads the deadline |
| Lateness is **flagged, not prevented** | `Milestone::lateness()` → `['days' => int, 'due_at' => Carbon]`, shown to the reviewer as "6 days late" |
| A re-filed Lampiran B / proposal **inherits the shared deadline** | no reset on rejection |

The old refusal — *"The deadline for this milestone has passed and late
submission is not permitted"* — is gone. The title defence is early in the
semester, so a re-presentation has room; that is why the user accepted no
extension.

### Changing title at the PSM 2 boundary (2026-10-27)

A student who passed PSM 1 may take a **different title** in PSM 2. The cost is
theirs and it is large, which is why it is optional and one-way.

```
PSM 2 starts
   ├── title unchanged → chapters 5–7, final report, Form K
   └── title changed   → Lampiran B (new title) → supervisor signs
                         → install(): projects.title = the new title,
                           proposal + Chapters 1–4 PREPENDED at weight 0
                         → full defence (the panel must see a different project)
                         → THEN chapters 5–7, final report, Form K
```

| Rule | Where |
|---|---|
| `POST /api/projects/{project}/title-change` | `Psm2TitleChangeService::request()` |
| Four guards, each named in the 422: it is a PSM 2 project; the title has not changed before; **Chapter 5 has not opened**; nothing submitted yet | `Psm2TitleChangeService::blockedReason()` |
| Refiled Chapters 1–4 carry **weight 0** — the PSM 1 mark was awarded and cannot be re-awarded, and PSM 2 must still total 100.00 | `install()` |
| `projects.title` moves here **only because** a panel then sits on a proposal *about* that title | the one exception to R3 |

The decision must be made **before Chapter 5 opens**. A student who changes their
mind in week 8 cannot take this path — late submission is unblocked, but the
boundary is not a deadline to extend.

### Registration on the fields the forms actually have (2026-10-27)

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
- The session on Lampiran A is a **dropdown** of the term list, not a free-text
  box, and `IsTitle` refuses a placeholder title (min 10 chars, ≥3 words, at
  least one real word).
- A student may **withdraw** a submitted registration
  (`RegistrationService::withdrawAgreement()`) — it cancels the agreement,
  retires the supervision, and clears the notification.


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
- **The verdict board is deleted** (2026-10-27), not hidden:
  `frontend/src/pages/coordinator/VerdictPage.jsx` is gone, `GET
  /api/projects/verdicts` is gone, and `ProjectResolutionService::resolve()` is
  gone. **Do not reintroduce it.** A committee could only agree with the
  arithmetic or contradict it; either way the mark was the answer. The panel and
  supervision release that `resolve()` performed now live in
  `TermCloseService::close()`.
- **`ProposalReviewService::changeTitle()` is deleted** (2026-10-27). It let a
  student retitle a project with no supervisor signature. A retitle is a new
  Lampiran B.
- **The four-value project status is no longer set by a human.** `projects.status`
  still exists as a column and still holds `in_progress`/`completed`
  (`finished()`/`active()` read it), but its value is **derived at term close**.
  `draft`, `submitted`, `approved` and `rejected` are legacy values; a project
  row can still be written with `status = 'rejected'` by
  `ProjectController::reject()` / `POST /api/projects/{project}/reject`, which is
  **retained as a coordinator's registration refusal** (it returns a registration
  to the student before a project starts). It is not a lifecycle outcome and
  writes no verdict. **No frontend page calls approve or reject** — both are
  API-only today.

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
| *(this session)* | 10-27 | **PSM lifecycle rewritten to a signed-off spec** (`thesis/PSM-LIFECYCLE-SPEC.md`): verdict board, `changeTitle()` and the four-value status **deleted**; `TermCloseService` derives pass/fail from the mark at term close; **Lampiran B becomes a table** with `revision_no` and `rejected_reason`; refused titles **re-presented, no cap**; only a mark below 40 repeats; deadlines become labels (`lateness()`), not locks; **PSM 2 title change** at the chain start with Chapters 1–4 refiled at weight 0; milestone `attempt` column; two diagrams redrawn |

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

**Accounts that are useful for the new flows specifically:**

| Account | Why |
|---|---|
| `rd.student1@psm.test` / `rd.supervisor@psm.test` | Haziq bin Ramli — own project `PSM1-2025-BIP-012` (id 23), the one used for re-file testing |
| `sup-002@psm.test` | Dr. Tan Wei Ming, BIS, 5 free PSM 1 slots — the positive case for allocation |
| `test.student@psm.test` | Nur Aina binti Test, `AI240199`, BIS — a clean student for registration |
| `coordinator@psm.test` | Everything a coordinator can do, including closing a term |

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
- **`backend/storage` is masked by a Docker volume.** The container mounts
  `backend/` at `/var/www/html` **and** the named volume `psm-system_storage-data`
  at `/var/www/html/storage`. Anything written to `backend/storage/...` on the
  host is **invisible inside the container**. This silently broke the demo
  signatures. Check with
  `docker exec psm-app ls /var/www/html/storage/app/private/...` and copy the
  file in with `docker cp` when it is missing.
- **Never round-trip a file through PowerShell's `Get-Content -Raw` +
  `Set-Content`.** It reads UTF-8 as CP1252 and re-encodes it, so every em dash,
  section sign and ellipsis in the file becomes mojibake — in an earlier session
  this mangled nine backend files. It does not fail, it does not warn, and
  `php -l` still passes. Use the `edit` tool, or a Python script that reads and
  writes with explicit `encoding="utf-8"`. If it has already happened,
  `.workbuddy-ai/fix_mojibake.py [--write]` repairs the comments and lists
  anything it will not touch.
- **A one-liner passed through `& python -c "..."` is itself mangled** the same
  way. Put the script in a file with the `write` tool, then run the file.

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
   figures (that is why UC-05 has 4.10 and 4.11). **The converse matters too** —
   if the two halves are the same process, keep them in one figure. The PSM 2
   title change is a branch of the milestone chain, not a process of its own.

### Faults that have actually happened (check every figure for these)

Each of these was found by *looking at the rendered PNG*, never by reading the
script. The script produced no error for any of them.

| Fault | How it looked | Cause |
|---|---|---|
| **`action()` returns the BOTTOM, not the top** | a box drawn on top of the one below it | `y = dg.action(...)` was then passed to the next `action()` as its `top` |
| **A label lying flat on the line it labels** | "refuse" read as "refu▬se" | the label's y equalled the connector's y |
| **A label inside the box below** | text struck through a box edge | the gap was `G` but the label needed two lines of air |
| **A connector through a shape** | a line crossing a box | the route reused an x that the boxes occupied further down |
| **Two opposite labels reading as one line** | "Yes — proposal No — other" | one label above the line, one below, both on the same side |
| **A stale word in a box** | "the chain has not opened it yet *(late work is accepted)*" beside a "Refuse" label | the box was written before deadlines stopped blocking |
| **Content cropped** | the end circle missing | canvas height fixed at `3600` while the drawing ran past it — always pass `crop_bottom=` |

**Read the rendered PNG at full size, crop by crop.** A 40 % zoom-down view hides
exactly the overlaps this table lists.

### Scripts

| Script | Makes |
|---|---|
| `uml_kit.py` | **Shared drawing library** — shapes, arrows, `connect()`, `route()`, `label()`. Start here. |
| `act_login_v2.py`, `act_pairing_v2.py`, `act_ms_v3.py`, `act_report.py`, `act_notif.py` | Activity diagrams |
| `seq_report.py`, `seq_notif.py` | Sequence diagrams (later ones) |
| `make_*_seq.py`, `make_*_act.py` | Earlier sequence/activity diagrams |
| `build_ch4_*.py` | Build the `.docx` for each use case |
| `build_xlsx.py` | The HIRARC form (unrelated side task) |
| `cite_ch1.py` | Added the Chapter 1 citations |
| `build_ch1_cited.py` | Builds `Chapter1 - Introduction (cited).docx` from the Chapter 2 style template |
| `make_demo_signatures.py` | The eight watermarked demo supervisor signatures |
| `fix_mojibake.py`, `fix_mojibake_strings.py`, `fix_mojibake_last.py` | One-off repairs for text a PowerShell round-trip double-encoded — see §6 |

**`act_ms_v3.py` carries the PSM 2 title change as a branch**, not as a second
figure. It is drawn up the right-hand column *before* the chain exists, because a
boundary change **prepends** a proposal and Chapters 1–4 to the chain and is
refused once the chain exists. There is deliberately **no separate PSM 2 title
figure** — the branch rejoins the same chain, and two pictures made the reader
hold both at once. (A standalone `act_psm2_title.py` existed briefly on
2026-10-27 and was removed for that reason; do not recreate it.)


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

### Codebase (2026-10-27)

- [ ] **Nothing in the new lifecycle has been clicked through in a browser.**
      `/enrolments`, the withdraw button, the Lampiran B card on the proposal
      milestone, the term close, and the PSM 2 title change are all verified over
      the API and all build — but **no human has seen any of them**. This is the
      single biggest gap before a demo. Walk them in this order: register →
      supervisor acknowledges → file Lampiran B → sign → upload proposal →
      endorse → panel refuses → re-file → panel accepts → close the term.
- [ ] **The PSM 2 title change has an API but no screen.**
      `POST /api/projects/{project}/title-change` works and is verified; nothing
      in `frontend/` calls it, so a student cannot take the path from the UI.
- [ ] **The close guard names one real student today.** `AI240001` is enrolled in
      term 2 with no project, so closing term 2 is refused until they are given
      one or withdrawn. That is the guard working, not a bug — but it will stop a
      demo that ends with "close the term".
- [ ] **The demo signatures are not in the repository's reach.** They are PNGs
      under `backend/storage/app/private/signatures/lampiran-b/`, but
      `backend/storage` is masked by the `psm-system_storage-data` volume, so the
      container cannot see files committed there. They were copied in manually on
      2026-10-27. **After any `docker compose down -v`, copy them again** —
      otherwise every seeded signature is a broken image.
- [ ] **The exposed Supabase storage key has not been revoked.** Session
      `5e9874c3` contains it in plain text on disk. Revoke it at Supabase →
      Settings → Storage → S3 Access Keys. It is not the key `.env` uses, but it
      is still valid until revoked.
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
      against capacity. The backfill used `is_leader = true` deliberately.
- [ ] **Some Indonesian/Malay prose in comments is unrecoverable.** A PowerShell
      round-trip double-encoded UTF-8 in nine backend files in an earlier session.
      Everything in a **comment** was repaired on 2026-10-27 by
      `.workbuddy-ai/fix_mojibake*.py` (141 sequences; the em dashes, section
      signs and ellipses all read correctly now). No mangled text remains in any
      user-facing string.
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

### Resolved since the last update (2026-10-27)

- [x] **A project can no longer end without a decision.** It used to have no way
      of ending at all; the verdict board was the first answer and is now
      deleted. Closing the term derives pass/fail from the mark in one pass. See
      §3A.
- [x] **A refused title no longer disappears.** `lampiran_b_forms` keeps every
      filing with its reason. See §3A.
- [x] **A student can no longer retitle their own project.** `changeTitle()` is
      deleted; a retitle needs a supervisor's signature on a new Lampiran B.
- [x] **A passed deadline no longer blocks an upload.** Late work is accepted and
      flagged. See §3A.
- [x] **`Milestone` codes could not repeat.** The unique key is now
      `(project_id, code, attempt)`, which the re-file round needs. See §3A.

### Resolved earlier (2026-10-26)

- [x] **Archive is no longer offered anywhere in the UI.** Not an open issue any
      more, it is a decision: sidebar entry commented out, admin dashboard card
      gone, no project button. Routes, API and records stay dormant. See §3A.
- [x] **A finished project no longer consumes a supervisor's capacity for ever.**
      Capacity counts live enrolments.
- [x] **Duplicate supervision rows.** Seeder key fixed; partial unique index on
      `(student_profile_id, supervisor_profile_id) WHERE is_active`.
- [x] **A repeat can no longer be written with a null term.** `repeat()` opens an
      unplaced enrolment; the admin chooses the term.

### Thesis

- [ ] The old `Chapter2 - Literature Review*.docx` files (4 stale copies) are
      still in `thesis\`. Only `Chapter2 - PSM Assessment System v2.docx` is
      current. For Chapter 1, **`Chapter1 - Introduction (cited).docx`** is the
      current one (ten verified citations, built by `build_ch1_cited.py`);
      `Chapter1 - with citations.docx` is the earlier seven-citation version.
- [ ] Citation style is **author-date**. If the faculty requires IEEE numbered,
      all chapters must be converted together.
- [ ] Chapter 2 compares only 3 systems (template expects 3). Two more PSM
      systems were found but not used: e-PSM (Fang & Darman, 2022) and the
      FSKTM Supervision System (Chong & Wahid, 2021 — the coordinator's own
      paper).
- [ ] The HIRARC form (`HIRARC FORM_…xlsx`) was built but the user's personal
      fields were deliberately left blank.
