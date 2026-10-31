# PROGRESS — PSM Assessment System (thesis + code)

**Last updated:** 2026-11-01
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

### Use cases — the list is fixed at six (2026-10-27)

The user's list, and it is now the only list:

| # | Use case | Table | Figures | Deliverable |
|---|---|---|---|---|
| UC-01 | Login and Authentication | 4.1 | 4.1 seq, 4.2 act | `Chapter4 - Login …` |
| UC-02 | **Manage Title and Registration** | 4.2 | 4.3 seq, 4.4 act | `Chapter4 - Manage Title and Registration …` |
| UC-03 | Manage Milestone | 4.3 | 4.5 seq, 4.6 act | `Chapter4 - Manage Milestone …` |
| UC-04 | **Manage Evaluation** | 4.4 | 4.7 seq, 4.8 act | `Chapter4 - Manage Evaluation …` |
| UC-05 | Generate Report | 4.5 | 4.9 seq, 4.10 act | `Chapter4 - Generate Report …` |
| UC-06 | Manage Alert and Notification | 4.6 | 4.11 seq, 4.12–4.13 act | `Chapter4 - Manage Alert and Notification …` |

**Manage Pairing is no longer a use case.** Its pair of figures, its scripts and
`Chapter4 - Manage Pairing …docx` were deleted on 2026-10-27 at the user's
instruction. Pairing is still described *inside* UC-02, because acknowledgement
is the step that creates the supervision — but it is not its own case and there
is no Figure 4.3/4.4 for it any more.

**Every figure is a generated PNG from a `.py` in `.workbuddy-ai/`:**

| Use case | Sequence | Activity |
|---|---|---|
| Login | `seq_login.py` | `act_login.py` |
| Title and Registration | `seq_title.py` | `act_title.py` |
| Milestone | `seq_milestone.py` | `act_milestone.py` |
| Evaluation | `seq_evaluation.py` | `act_evaluation.py` |
| Report | `seq_report.py` | `act_report.py` |
| Notification | `seq_notification.py` | `act_notification.py` |

**All ten are redrawn (2026-10-27) through two shared kits** — `seqkit.py` for
sequence, `actkit.py` for activity — so the type scale and the lifeline style are
set in one place. Message counts are now 11–18 and shapes 9–14. `uml_kit.py`
and the older `make_*.py` draughts are superseded; do not add a figure to them.

**Style the user asked for, after showing a reference chapter:**

- **Type about a quarter larger.** 22 px in activity boxes, 17 px in sequence
  labels, 20 px on edges; every figure placed at **16.4 cm**, not 12.6–14.2.
- **Lifelines are dotted**, not solid.
- **Only the flow.** Per-condition self-calls collapse to one "Validate" or
  "Check" message; a run of aggregations becomes one message with what it
  returns; anything already a shape in the activity diagram is not repeated in
  the sequence diagram.
- **Keep the guards and the controller→service call.** They were almost cut and
  should not be: they say where a rule lives. A guard is worth one labelled
  message, not one message per condition.

> **The `build_ch4_*.py` scripts still carry the OLD numbers.** Only
> `build_ch4_title_evaluation.py` uses the new table and figure numbers. The
> login, milestone, report and notification documents say 4.2–4.10 and need
> renumbering to the table above before the chapter is assembled. The pairing
> script is gone.

### Chapter 1 — Introduction ✅ DONE (cited)

File: `thesis\Chapter1 - Introduction (cited).docx` — built by
`.workbuddy-ai/build_ch1_cited.py`, **this is the current one**. Regenerate it
with `& python .workbuddy-ai/build_ch1_cited.py`; do not edit the `.docx` by
hand, or the next rebuild discards the edit.

- **§1.1 Introduction is three paragraphs, every one of them carrying at least
  two citations** (2026-10-28, user's instruction: *"do the introduction 1.1 in
  three paragraph only and each paragraph two or more cite"*). The Problem
  Statement was strengthened at the same time.
- The section list, in order: Introduction · Problem Statement · Objectives ·
  Research Scope · Project Significance · Thesis Outline · References.
- **11 distinct citations in the prose** (author-date, APA-like) — up from the
  original seven. Counted from the document itself, not from memory:
  **§1.1 Introduction has 7**, **Problem Statement has 7**, Research Scope 1,
  Project Significance 2. `Chong Er Juan & Wahid (2021)` is cited in Chapters 1
  and 3; `Chong & Arbaiy (2022)`, `Arumugam & Abd Ishak (2025)` and
  `Tam & Abdullah (2025)` recur across all three chapters.
- The four sources that carry most of the argument, all AITCS (FSKTM's own
  journal) and all checked against the published paper rather than carried over
  on trust:
  - **Nor Azim & Arbaiy (2022)** — FSKTM postgraduate assessment uses printed
    forms returned for manual analysis. Used for "manual admin" + "fragmented
    assessment".
  - **Chong & Arbaiy (2022)** — FSKTM pre-viva; *hand counting leads to
    calculation errors*. Used for "hand totalling" + the web-based approach.
  - **Arumugam & Abd Ishak (2025)** — FSKTM FYP; manual task tracking,
    inefficient communication. Used for "progress not visible".
  - **Tam & Abdullah (2025)** — FSKTM FYP; mismatched student research and
    supervisor expertise. Used for PSM structure + supervisor matching.
  - The rest (`Borhanuddin & Mohamed`, `Che Adnan & Hafit`, `Tee & Mahdin`,
    `Marzele & Abdul`, `Chong Er Juan & Wahid`, and UTHM's own PSM guide) are
    listed with them in the document's reference list.
- `.workbuddy-ai/count_citations.py <docx>…` recounts all of this per section —
  run it instead of trusting a number written here.
- `Chapter1 - with citations.docx` is the **earlier seven-citation version** and
  is superseded. There is also a copy named `Chapter1 - ...` from before a
  render-tool accident (`5ec566b` restored it); the `(cited)` file is the one to
  use.

### Chapter 2 — Literature Review ✅ DONE (cited, v3)

Current file: **`thesis\Chapter2 - PSM Assessment System v2.docx`** — this is
the newest copy on disk (2026-10-28 14:32) and carries the 2.2/2.3 merge.
`Chapter2 - Literature Review (v3).docx` (14:05) is the cited draft it was
merged from. Everything else named `Chapter2 - Literature Review*.docx` or
`Chapter2 - PSM Assessment System.docx` is **older and stale**. Build with
`.workbuddy-ai/cite_ch2.py` (citations) over the v2 document; do not rebuild
from the original draft.

- Rewritten from scratch — the original draft was about **metaheuristic
  optimisation / CT**, a completely different project, and was discarded.
- Style matched to the user's example: TNR 12pt, 1.5 spacing, `Heading 2` with
  literal `2.x` numbering, bold system names, `< Name >` markers, ✔/✖ table.
- **Section layout after the 2026-10-28 merge** (the user asked to combine the
  two sections and move the numbering forward, *"move forward numbering only"*):

  | § | Title |
  |---|---|
  | 2.1 | Introduction |
  | 2.2 | **Domain Case Study and Web Based System** — the two former sections, merged |
  | 2.3 | Problem Study of Existing Related Systems |
  | 2.4 | Your Proposed System |
  | 2.5 | Comparison with the Existing Systems |
  | 2.6 | Chapter Summary |

- **§2.2 and §2.3 both carry citations now** (2026-10-28 request: *"add
  citation in 2.2 and 2.3 too"*) — §2.2 has 7 distinct sources, §2.3 has 3, 8 in
  the chapter. Recount with `.workbuddy-ai/count_citations.py`.
- **Three compared systems, all FSKTM/AITCS and all about FYP:**
  FFMS (Arumugam & Abd Ishak, 2025), PSM Supervision System (Wan Fauzi &
  Alduais, 2025), Supervisor Selection System (Tam & Abdullah, 2025).
- ~1,990 words, 6 pages. Definitions cited: CIO Wiki (web app), NIST (RBAC).
- Table 2.1 compares 14 features; the three assessment rows are ✖ across all
  three existing systems → this is the evidence for the §1 contribution.

### Chapter 3 — Methodology ✅ DONE (2026-10-28)

File: **`thesis\Chapter3 - Methodology.docx`** — ~1,100 words, built by
`.workbuddy-ai/build_ch3.py`.

- The model is **Prototyping**, not Waterfall. The user's instruction was
  *"create the chapter 3 with prototyping model"*, and the reasoning is in the
  chapter: the faculty's administrative rules live in the coordinator's
  practice rather than in a document, so a model that expects to discover them
  by showing someone a working version fits where Waterfall does not.
- **Figure 3.1 — Prototype model** is `thesis\diagrams\prototype_model.png`,
  drawn by `.workbuddy-ai/prototype_model.py` and embedded by `build_ch3.py`
  (it replaces the picture that came with the template). Five phases:
  Requirement Gathering and Analysis → Quick Design → Build a Prototype →
  Client Evaluation → Refining the Prototype → Implementation and Maintenance,
  with the loop back.
- Each phase paragraph names the **objective it satisfies**; **Table 3.1 maps
  RO1–RO3 to an evaluation focus** (RO3 is the User Acceptance Testing that
  Chapter 5 still has to report).
- Citations: Chong Er Juan & Wahid (2021), Chong & Arbaiy (2022), Nor Azim &
  Arbaiy (2022), Che Adnan & Hafit (2025), Arumugam & Abd Ishak (2025), Wan
  Fauzi & Alduais (2025), Tam & Abdullah (2025), UTHM (2025), CIO Wiki (2023),
  NIST (2022).

### Chapter 4 — Analysis & Design (use cases)

**Superseded — kept for history only.** Each use case was once its own `.docx`
(specification table + sequence diagram + activity diagram). The six files are
still in `thesis\`, dated 2026-10-28, but **Chapter 4 is now one document** — see
the next section. Do not add to the per-use-case files.

The numbering they used: UC-01 Login · UC-02 Title and Registration · UC-03
Milestone · UC-04 Evaluation · UC-05 Report · UC-06 Alert and Notification.

The old **draw.io** use case diagram (`PSM.drawio.png`) drew **pairing as a use
case of its own**. That is wrong now — pairing is a step inside Manage Title and
Registration, and its activity is inside UC-02. `usecase_diagram.py` draws the
correct Figure 4.1; the drawio file must not be used again.

### Chapter 4 — Analysis and Design ✅ one document (2026-10-28)

**`thesis\Chapter 4 - Analysis and Design.docx`** — 29 pages, in the format the
user supplied as `chapter4part1/2/3.docx` in Downloads. The six per-use-case
files are **superseded**; do not add to them.

| Section | Content |
|---|---|
| 4.1 | System's Modules (Table 4.1), functional (4.2) and non-functional (4.3) requirements |
| 4.2 | Use case diagram (Figure 4.1), then UC-01…UC-06. Each has a specification table with **History Log, Version, Actors, Description, Preconditions, Post conditions, Normal Flow, Alternative Flow, Exceptions** and its sequence and activity diagrams immediately beneath. |
| 4.3 | 4.3.1 architecture · 4.3.2 database design: schema summary + **data dictionary for the 8 core tables** (Tables 4.11–4.18) · 4.3.3 interface design (three screen sketches) · 4.3.4 class diagram (Figure 4.14) |

**No ER diagram** — the user asked for the class diagram and explicitly not the
ERD.

**The data dictionary is generated, not typed.** `.workbuddy-ai/dump_schema.php`
dumps `information_schema` from the live database to
`.workbuddy-ai/schema.json`; `build_chapter4.py` reads it. Re-run the probe after
a migration or the tables will describe a database that no longer exists.

| Script | Makes |
|---|---|
| `build_chapter4.py` | the whole chapter |
| `usecase_diagram.py` | Figure 4.1 — replaces the old draw.io file, which still drew pairing as a use case |
| `class_diagram.py` | Figure 4.14 — twelve classes, hand-placed corridors |
| `dump_schema.php` | `schema.json`, for the data dictionary |

### Which script builds which chapter (2026-10-30)

| Chapter | Build with | Figures from |
|---|---|---|
| 1 | `build_ch1_cited.py` | — |
| 2 | `cite_ch2.py` — citations only; the document itself is `Chapter2 - PSM Assessment System v2.docx` | — |
| 3 | `build_ch3.py` | `prototype_model.py` → `thesis\diagrams\prototype_model.png` |
| 4 | `build_chapter4.py` | `usecase_diagram.py`, `class_diagram.py`, `seq_*.py`, `act_*.py` |

Read-only helpers, safe to run at any time: `outline_docx.py` (headings, words
and table counts of a `.docx`) and `dump_docx.py` (every paragraph with its
style). They exist because a structural reopen is not a read, and they are how a
chapter gets checked without opening Word — but **neither is sufficient for
layout; still render and look** (§4 verification loop).

**The class diagram was the hardest figure in the project.** Three attempts
failed before one worked, all for the same reason: routing every connector to
the midpoint of the gap it was given put eleven relations through one bundle.
The working version gives each relation an explicit corridor and its own entry
point on a shared edge. If it has to be redrawn, keep that property.

### Remaining thesis work

- [ ] **Fix the use case diagram** so pairing is not drawn as its own case.
      (`usecase_diagram.py` already redraws it without pairing — it was the old
      **draw.io** `PSM.drawio.png` that still drew it, and that file must not be
      used again.)
- [ ] Chapter 5 — Implementation and Testing (including the UAT that Table 3.1's
      RO3 promises)
- [ ] Chapter 6 — Conclusion
- [ ] Front/back matter: abstract, TOC, list of figures/tables
- [ ] **Assemble the full thesis.** Each chapter is its own `.docx` today. They
      all use the c2 template's styles, so they can be appended into one file,
      but the figure and table numbers are literal text — check them after the
      merge.
- [ ] **One merged References list.** Each chapter carries its own list so far;
      the final document needs the union, de-duplicated. Watch the name forms:
      `Chong Er Juan & Wahid (2021)` in Chapter 3 and `Chong & Wahid (2021)` in
      §3 of this file are probably the same source. Pick one and use it in both.

---

## 3A. Codebase status (what the system actually does)

Companion to §3. §3 tracks the *thesis*; this tracks the *software*. **Updated
2026-11-01.** The newest block is §3A.0 below; the rest was written 2026-10-25
to 10-27 and still holds except where a later section says otherwise.

### 3A.0 — The last sessions (2026-10-28 → 11-01)

Read this first. Everything here was verified against the live database and over
HTTP, and the commits are named so the detail can be found again.

| Date | What changed | Commit |
|---|---|---|
| 10-28 | Chapter 1 §1.1 rewritten to three well-cited paragraphs; Ch. 2 §2.2+2.3 merged and cited; **Chapter 3 written (Prototyping)** | `30006e9` `881b240` `3c30dab` |
| 10-30 | **Acknowledging Lampiran A now creates the enrolment** — the capacity gate counts a registered student for the first time | `b654a9c` |
| 10-30 | **PSM 2 boundary prompt** on the project page: keep or change the title, asked once | `b654a9c` |
| 10-30 | **PSM 2 Rollover screen retired** — a PSM 2 placement is an enrolment like any other | `b654a9c` |
| 10-30 | Add-user form: department removed, **faculty fixed to FSKTM** (and enforced server-side) | `b654a9c` `9eeeb1f` |
| 10-30 | **Delete an enrolment** — undo a misoperation, four guards, audited as `enrolment.deleted` | `eeced3b` |
| 11-01 | **One part per student per term, enforced** — PSM 1 and PSM 2 never run together (the user's rule) | see below |
| 11-01 | **The supervision queue is scoped to one term and one part**, and lists only students who can actually be assigned | see below |
| 11-01 | **The assignment page timed out** — a missing eager load made the supervisor list run 203 queries; now 7 | see below |
| 11-01 | **Mojibake repaired across the frontend** — the `Choose supervisor…` dropdown placeholder had been mangled; 92 sequences in 6 files, plus a reusable repair script | see §5 |

#### One part per student per term — the rule (2026-11-01)

> **"Strictly no permit of PSM 1 and 2 in same term."** — the user, 2026-11-01.

`EnrolmentService::enrol()` refuses a second part for the same student in the
same term, in both directions, naming the other part and the term. It lives in
the **service**, so every path inherits it: the enrol dialog, the CSV import,
acknowledgement, and repeat placement.

Verified in a rolled-back probe:

| Case | Result |
|---|---|
| PSM 1 in term 2, then PSM 2 in term 2 | **REFUSED** |
| PSM 1 in an earlier term, then PSM 2 in term 2 | allowed — the normal progression |
| PSM 1 **failed** in term 2, then PSM 2 in term 2 | allowed — the attempt is settled |

The check is **per term**, not a global sequence: it is the term boundary that is
enforced. A settled attempt in the same term does not block, because it is
history — which is what lets a failed student's `repeat()` work.

**Why it had to be a rule and not just a screen.** The per-part unique indexes
would happily accept both enrolments. The damage was silent: the student
dashboard picks one project and hides the other, and a supervisor's capacity is
consumed twice for a student who can only be working on one thing.

#### The supervision queue — what was wrong with `/assignments` (2026-11-01)

The user reported newly created, not-yet-enrolled students sitting in the
unassigned list. They were — along with students from every other term and both
parts. Four faults, all in `AssignmentController::unassignedStudents()`:

| Fault | Effect |
|---|---|
| Term filter applied only when `psm_part` was absent, and the page sent **neither** | the queue listed **every term at once** |
| No part filter | PSM 2 students sat in the PSM 1 queue |
| No enrolment requirement | **un-enrolled students were offered and then refused**, failing on capacity — a message about supervisors for a problem about enrolment |
| `academicSemester` not loaded | the resource emits it `whenLoaded`, so **the term column was blank on every row** |

All four are fixed. The endpoint now requires a **(term, part) scope** and draws
its population from a live enrolment in that scope, so the queue and
`AssignmentService::assignSupervisor()` agree about who is assignable.

- Students in the term with **no enrolment** no longer appear in the queue; they
  come back as `meta.needs_enrolment { count, students[] }` and the page prints
  them with a **"Go to enrolment →"** link. Nobody silently vanishes.
- Each row carries its `enrolment` (part, attempt, status, has_project); the page
  prints a **PSM 1 / PSM 2 badge** and an **Attempt n** badge.
- The page follows the **global term selector** like every other screen, and has
  its own **PSM 1 / PSM 2 switch**.

**A bug found on the way: the page assigned with `psm_part: 'BOTH'`, hard-coded.**
`BOTH` consumes a capacity slot in *both* parts. With the new rule a student can
only be in one part, so the assignment now sends the part of the queue it came
from. Verified end to end: enrol → appears in the PSM 1 queue with the right
badge → assign → **201** → queue drops them.

> **If the supervision queue is changed again, keep this property:** *the queue
> must list exactly what the assign call would accept.* A row that is offered and
> then refused is worse than no row, because the coordinator goes looking for a
> cause that is not there.

#### The assignment page timed out — a 203-query supervisor list (2026-11-01)

The user saw **"timeout of 30000ms exceeded"** on `/assignments`. The page awaits
three calls together, so the slowest one is the page:

| Call | Before | After |
|---|---|---|
| `GET /api/assignments/supervisors?per_page=100` | **17.6 s** | **1.7 s** |
| `GET /api/assignments/students/unassigned` | 1.2 s | 1.5 s |
| `GET /api/assignments/supervisions` | 1.7 s | 1.4 s |
| **Page load (the slowest of the three)** | **~20 s** | **1.7 s** |

**Cause: a missing eager load, not a slow query.** `SupervisorProfileResource`
computes six capacity figures per row, and each falls back to its own query when
`activeSupervisions` is not loaded — `currentLoad()`,
`remainingCapacity()`, `utilisationPercent()`, `isFull()`, `isOverloaded()`,
and `has_capacity` → `isFullInSemester()`. Measured on 20 supervisors:
**203 queries, 179 of them the identical `supervision_assignments` lookup,
14.7 s of database time**.

The in-memory path already existed — `computeLoadByPartInSemester()` checks
`relationLoaded('activeSupervisions')` — so the fix is one `->with([...])` in
`AssignmentController::indexSupervisors()`, adding
`activeSupervisions.studentProfile.enrolments` (the in-memory branch asks each
supervision's student which terms they are enrolled in). **203 → 7 queries.**

> **The general trap: a capacity getter is a query unless the relation is
> loaded.** Any screen that lists supervisors and shows load must eager-load
> `activeSupervisions.studentProfile.enrolments` or it costs one round trip per
> getter per row. Against the hosted Supabase database (~60–500 ms per round
> trip) that is the difference between a page and a timeout. The measurement
> recipe is in `.workbuddy-ai/` — wrap the model calls in `DB::listen()` and
> count, as `probe_sup_queries2.php` did; do not eyeball the SQL.

**Verified after the fix:** the figures are unchanged — 20 supervisors, 19 with
capacity, Dr. Ahmad Faizal correctly `is_full` at 2/8 and withheld from the
picker. A fast wrong number would be worse than a slow right one.

#### Acknowledging Lampiran A now enrols the student — this closed a real hole

**Before 2026-10-30 the capacity gate counted nothing for a registered student.**
A supervision is created on acknowledgement, and capacity is counted from
`student_enrolments` — but registration never created an enrolment row. A
student who came in through Lampiran A therefore held a supervisor's slot and
consumed none, so a supervisor could be over-filled while every screen reported
them within capacity.

`RegistrationService::acknowledgeBySupervisor()` now calls
`EnrolmentService::enrol()` in the same transaction as the pairing.
`enrol()` returns an existing live row rather than duplicating, so an admin who
placed the student by hand first is not punished for it. Verified: enrolments
0 → 1, supervisor load 0 → 1 on the same acknowledgement.

**What creating an account still does and does not do.** It records
`student_profiles.academic_semester_id`, places the student in that term's
cohort, and gates Lampiran A. It does **not** register them in PSM 1: the
student still files Lampiran A and the supervisor still acknowledges it. The
enrolment arrives *at acknowledgement*, not at account creation. This was asked
directly twice, so it is written down here.

#### The PSM 2 boundary question

A student who passed PSM 1 may take a different title in PSM 2. The decision is
offered **once**, on their first visit to the PSM 2 project page, as a card at
the top of the page (`frontend/src/components/Psm2BoundaryCard.jsx`).

| Piece | Where |
|---|---|
| `GET /api/projects/{project}/title-change/status` | `LampiranBController::titleChangeStatus()` — answers `eligible`, `can_change`, `decided`, and the reason when not eligible |
| `POST /api/projects/{project}/title-decision` | records `keep` or `change` in `projects.metadata.title_decision` |
| `Psm2TitleChangeService::decision()` / `recordDecision()` | the service side |
| `Psm2BoundaryCard` | the card itself; renders nothing for anyone the question does not concern |

- **`keep` is recorded, not merely dismissed.** Without the stored answer the
  prompt would reappear on every visit.
- **`change` is recorded at the moment the button is pressed**, before the
  supervisor signs anything. Pressing it and walking away locks the choice in.
  The card says so. Moving the write to "when the form is submitted" is a
  one-line change if that turns out to be the wrong moment.
- The `change` path reuses `Psm2TitleChangeService` unchanged: new Lampiran B →
  supervisor signs → `install()` sets the title and prepends Chapters 1–4 at
  weight 0 → full defence.
- **Demo account:** `psm2.demo@psm.test` / `password`, project
  `PSM2-2025-BIP-901` ("Attendance tracking using face recognition"), decision
  cleared. Created by `.workbuddy-ai/make_psm2_demo.php`; re-running it resets
  the decision so the prompt can be seen again.

#### The PSM 2 Rollover screen is retired

`/rollover` is gone: the route, the nav entry and the lazy import. The page file
`pages/admin/RolloverPage.jsx` is left on disk, unimported, for reference. A PSM 1
→ PSM 2 move is now **a placement on `/enrolments`**, like every other move into a
part, because two screens doing the same thing had already been a source of
disagreement.

**Consequence worth knowing:** a 40-student cohort rollover is now 40 ticks. If
that is too slow in practice the fix is a "place all finished PSM 1 students"
action on the enrolment page — **not** a second screen.

#### Enrolments can now be deleted (undoing a misoperation)

Withdrawing was the wrong tool for a mis-entry: a withdrawn row is a deferral, it
stays on the student's record, and it reads as an academic event. The new
`DELETE /api/enrolments/{enrolment}` removes the row and leaves the audit log as
the only trace.

Four guards, each with its own message (`EnrolmentService::remove()`), verified
over HTTP:

| Guard | Refusal |
|---|---|
| settled attempt | "A settled attempt is not deleted — withdraw it, or close the term that ends it." |
| attempt has a project | "This enrolment already has a project, so the attempt has started…" |
| attempt > 1 | a repeat exists *because* an earlier attempt failed |
| a repeat points at it | the same rule seen from the other side |

- Policy: `StudentEnrolmentPolicy::delete()` — admin and coordinator only.
- Audit action **`enrolment.deleted`**, separate from `enrolment.withdrawn`,
  because "the student deferred" and "we recorded the wrong student" are
  opposite facts. Its category is **Semesters** (as are the other `enrolment.*`
  actions) — they used to fall through to "Other" in the audit filter.
- The Delete button only appears on rows the server would accept, so it never
  produces a refusal the admin cannot act on.

#### The faculty is FSKTM, and the server says so

The create-user form had FSKTM locked in the browser while `UserController`
still accepted any string. `faculty` is now `prohibited` in the create rules and
written by the server from `UserController::FACULTY`. Posting one gets
`{"faculty":["The faculty field is prohibited."]}`.

**The database still carries the old spellings**: 29 students say
`Faculty of Computing`, 6 say `FSKTM`, the rest are null (and both seeded
coordinators say `Faculty of Computing` in `users.department`). Nothing
downstream reads the value, so it is cosmetic — but **do not write a query that
groups by faculty** until it is normalised.

The add-user form also had the **enrolling term** half-wired: the field displayed
the active term as a fallback while the form state stayed empty until the admin
touched it. `changeSemester` now records the real choice.

#### Two projects at once — now impossible, and why it mattered

**Since 2026-11-01 a student cannot hold PSM 1 and PSM 2 in the same term** (the
rule is in §3A.0). This section describes what the screens did **before** that
rule, because it is the reason the rule exists:

- **The student dashboard showed only one project.** `StudentDashboard.jsx`
  loaded `projectApi.list({ per_page: 10 })` with no term filter and then took
  `items.find(p => p.psm_part === 'PSM2') ?? items[0]`. With both parts live, the
  **PSM 2 project replaced the PSM 1 one on the home page** and the PSM 1 work
  was invisible there — including its milestones and its progress bar.
- **The projects list showed both**, because `Project::visibleTo()` filters on
  membership, not on term or part — but it defaults to the active term, so a
  PSM 1 project from an earlier term dropped off that list too.
- **The project page was whichever project you opened** — there is no combined
  page. The PSM 2 one carries the boundary card; the PSM 1 one carries "Progress
  to PSM 2".

**What remains true:** progression to PSM 2 is still per-project and per-term.
`TermCloseService` settles a PSM 1 in its term, and the PSM 2 enrolment goes into
a **later** term — which is what the new rule leaves untouched.

> **The dashboard still prefers PSM 2 and falls back to `items[0]`.** That is
> correct only while a student holds one part at a time, which the rule now
> guarantees. If the rule is ever relaxed, `StudentDashboard.jsx` must stop
> guessing and show a chooser — do not relax one without changing the other.

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
| `EnrolmentPage` (`/enrolments`) | `frontend/src/pages/admin/` | **Cohort** tab + **Awaiting a term** tab (unplaced repeats), and the **Delete** action for a mis-entry (see §3A.0). |

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
| *(this session)* | 10-28 | **Chapter 4 rebuilt as one document** in the faculty's format (class diagram, no ERD); Chapter 1 §1.1 → three cited paragraphs; Chapter 2 §2.2+2.3 merged and cited; **Chapter 3 written around the Prototyping model** with Figure 3.1 |
| *(this session)* | 10-30 | **Acknowledgement enrols the student** (the capacity hole); **PSM 2 boundary prompt**; rollover screen retired; department removed and **faculty fixed to FSKTM server-side**; **enrolment Delete** with four guards and its own audit action |

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
| **`psm2.demo@psm.test`** | **Not seeded — created by `.workbuddy-ai/make_psm2_demo.php`.** PSM 2 project `PSM2-2025-BIP-901` with the boundary decision **cleared**, so the PSM 2 keep/change prompt can be seen. Re-run the script to reset the decision. |

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

### Mojibake — it has now happened three times, and the frontend is the costly one

| Occurrence | Where | How it showed |
|---|---|---|
| earlier | 9 backend files | mangled prose in comments; nothing user-facing |
| 2026-10-30 | `UserListPage.jsx` | `â€”` in a comment |
| **2026-11-01** | **6 frontend files, 92 sequences** | **`Choose supervisorâ€¦` in a live dropdown** — the user sent a screenshot |

The third one matters because **in JSX the strings are the interface**. The
backend repair script guards by repairing comments only — correct there, wrong
here, because in a component the mangled string *is* the defect. Use the sibling
script for the frontend:

```
python .workbuddy-ai/fix_mojibake_frontend.py         # dry run, prints every line
python .workbuddy-ai/fix_mojibake_frontend.py --write
```

It repairs every occurrence (not just comments), applies double-encoded patterns
before single ones, and **exits non-zero if anything is still mangled** so a
partial repair cannot pass for a complete one. It found 92 sequences in 6 files
on 2026-11-01 and was clean afterwards.

**Its one limitation, learnt the hard way:** a character mangled *twice* whose
last byte was CP1252-undefined leaves a **truncated** run that no byte pattern
can recover — the `✓` in `MilestoneDetailPage.jsx` came out as `Ã¢œœ`, matching
nothing in the table. The script reports it rather than guessing, which is the
right behaviour: judge it from context (that one sat in the green "Approved"
badge) and fix the line by hand, then add the observed sequence to `PATTERNS`
so the next run recognises it.

**Check the repair changed nothing else:** `git diff` then compare the ASCII
skeleton of the `+` and `-` lines. On 2026-11-01 the diff was 91 for 91 with
**zero** lines whose ASCII changed — proof it was character repair and not a
rewrite.

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
| **A label lying flat on the line it labels** | "refuse" read as "refu▬se" | the label's y equalled the connector's y — and in a sequence diagram, a `LEAD` smaller than the line height puts the label's LAST line on the arrow |
| **A sequence figure too tall for its page** | the caption on a page of its own, then a blank page | the figure's aspect was 2.7:1; the fix is at the source — wrap labels to make it wider, tighten the gaps — not in the .docx |
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

### Putting a figure in Word — the part that fights back

`build_ch4_title_evaluation.py` settles the pattern; copy it rather than
rediscovering these four faults.

1. **Fit, don't split.** A sequence diagram drawn at 2.7:1 has to be placed at
   14 cm on a landscape page, and its labels shrink with it. Wrapping the labels
   so the figure reaches about 4:3 lets it be placed at 17 cm instead — the
   labels end up roughly twice the size. Splitting a tall figure in two does not
   help: each half is still too tall and the caption still lands on its own page.
2. **Draw one message per thing that changes hands**, not one per method call.
   A request and its reply are one row; a controller delegating to a service is
   the same row as the service answering. A use case needs 15–20 messages, not
   35 — and a reader can hold 20.
3. **A landscape section must be opened and closed by paragraph `sectPr`s, and
   the following section must write its own `pgSz`.** A `sectPr` carries only
   the page size of the section it *closes*, so restoring it without an explicit
   `pgSz` leaves the next section inheriting landscape.
4. **The last paragraph of a section is the break.** Leave the closing break on
   the last paragraph *of the content*, not on one of its own; a bare paragraph
   holding a `sectPr` renders as a blank page of its own.
5. **`w:sectPr` must be the last child of `w:pPr`, after `w:spacing`.**
   python-docx's `paragraph_format` setters insert in schema order, but a raw
   `pPr.append(sectPr)` then `paragraph_format.space_before = …` puts spacing
   *after* it and Word refuses the file.

`diagnose_docx_pages.py` lists every body paragraph with its page size, spacing
and whether it holds a drawing — use it instead of guessing.


Every `build_ch4_*.py` follows the same pattern: load
`thesis\c2\Chapter2.docx` as the style template, clear the body, write the
document, keep the trailing `sectPr`, save.

> **The six `build_ch4_<usecase>.py` files and their `.docx` outputs are
> superseded** (2026-10-28). Chapter 4 is one document, built by
> `build_chapter4.py`. Do not add a figure or a section to the per-use-case
> files — they exist only as the history of how the chapter was assembled.
> `build_ch3.py` is now the better starting point for a *new* chapter anyway: it
> is smaller and it shows how a figure from `thesis\diagrams\` is replaced in
> place.

Read-only helpers, safe to run at any time: `outline_docx.py` (headings, words,
tables of a `.docx`) and `dump_docx.py` (every paragraph with its style). They
exist because a structural reopen is not a read — the two together are how a
chapter gets checked without opening Word. **Neither is sufficient for layout;
still render and look** (§4 verification loop).

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
- Supervision capacity is enforced **per PSM part**, not globally.
- **Do not pair a student with `psm_part = 'BOTH'`** (2026-11-01). A `BOTH`
  pairing consumes a slot in **both** parts, but a student may only be enrolled
  in one part per term, so it charges the supervisor twice for one student. The
  assignment screen sent `BOTH` unconditionally until this was found; it now
  sends the part of the queue it came from. `BOTH` survives only on legacy rows
  (`ProgressionService` used to rewrite a pairing `PSM1 → BOTH`), so read it but
  never write it.
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

### Codebase (2026-10-30)

- [ ] **Nothing in the new lifecycle has been clicked through in a browser.**
      `/enrolments` (including the new Delete), the withdraw button, the Lampiran
      B card on the proposal milestone, the term close, the **PSM 2 boundary
      card** and the add-user form are all verified over the API and all build —
      but **no human has seen any of them**. This is the single biggest gap
      before a demo. Walk them in this order: register → supervisor acknowledges
      → file Lampiran B → sign → upload proposal → endorse → panel refuses →
      re-file → panel accepts → close the term.
- [x] ~~The PSM 2 title change has an API but no screen.~~ **Closed 2026-10-30** —
      `Psm2BoundaryCard` asks the question on the PSM 2 project page and records
      the answer. See §3A.0.
- [ ] **A bulk PSM 1 → PSM 2 move no longer exists.** The rollover screen is
      retired and a placement is one student at a time. Fine for a demo, slow for
      a real cohort — see §3A.0 for the intended fix.
- [ ] **The faculty column carries three spellings.** Cosmetic today (nothing
      reads it) but it will bite the first report that groups by it. New accounts
      are always `FSKTM`.
- [ ] **`projects.metadata.title_decision` is a JSON value, not a column.** It
      works and it is recorded, but a real query ("who changed their title") has
      to read into JSON. Promote it to a column when the reporting needs it.
- [ ] **The close guard names one real student today.** `AI240001` is enrolled in
      term 2 with no project, so closing term 2 is refused until they are given
      one or withdrawn. That is the guard working, not a bug — but it will stop a
      demo that ends with "close the term". (With enrolment Delete now available,
      it can finally be cleared without inventing history.)
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
