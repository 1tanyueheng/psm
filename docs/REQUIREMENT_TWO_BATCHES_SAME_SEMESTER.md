# Requirement: Two Student Batches (PSM 1 & PSM 2) in One Semester

> **Status: BACKEND COMPLETE — all 10 acceptance criteria verified against seeded data.**
> Last verified: 2026-10-03 · Laravel 11.56.1 · PHP 8.2.33 · `migrate:fresh --seed` green.
> Sections 1–2 are the original brief. Sections 3–11 now record **what was actually built**,
> where it deviated from the original design, and why. See §12 for outstanding work.

---

## 1. Context & Problem Statement

The Faculty of Computer Science and Information Technology (FSKTM) at UTHM runs **both PSM 1 and PSM 2 concurrently within the same academic semester**. The same pool of supervisors and coordinators oversees both batches. The system was modelled around a single cohort per session, which created the following gaps:

| Gap | Impact |
|-----|--------|
| No explicit cohort/semester entity | Cannot distinguish "2025/2026 Semester I — PSM 1" from "2025/2026 Semester I — PSM 2" in reports and dashboards |
| Supervisor capacity counted globally | A supervisor with 5 PSM 1 students + 3 PSM 2 students appears as "8/8" and is blocked from new allocations even though the two batches should have independent caps |
| Title defence session has no batch scope | A sitting created for PSM 2 could accidentally include PSM 1 students (or vice versa) because filtering is only by `academic_session` |
| Examiner pairing is per-PSM-part but UI does not surface it | The auto-assign panel pairs students but the coordinator cannot see at a glance which batch each pair belongs to |
| Grade release is session-wide | Releasing grades for PSM 1 would also flip the release flag for PSM 2 if they share the same `academic_session` |
| Milestone templates are not batch-scoped | PSM 1 milestones (Chapter 1–4) and PSM 2 milestones (Chapter 5–7 + Final Report) would collide if both batches are active |
| The dashboard cohort overview shows aggregate numbers only | Cannot answer "How many PSM 1 students are at risk?" vs "How many PSM 2 students are at risk?" |

## 2. Scope

Covers the **backend data model**, **API**, **coordinator dashboard**, **supervisor view**, **student view**, **examiner workflow**, and **reporting** needed to run two batches in parallel under one semester.

Out of scope:
- Changes to the individual Lampiran forms (E/I/G/H/J) — rubrics already correct.
- Changes to grading arithmetic — per-form weights already handle PSM 1 vs PSM 2.
- Historical data migration — existing PSM2-only data stays as-is.

---

## 3. Data Model — As Built

### 3.1 New table: `academic_semesters`

A first-class semester entity that owns both batches. Migration:
`2026_10_04_000001_create_academic_semesters_table.php`

```
id                   bigint PK
name                 varchar(64)    e.g. "2025/2026 Semester I"
academic_session     varchar(32)    e.g. "2025/2026" — the parent session
semester_number      tinyint        1 or 2
starts_at            date           nullable
ends_at              date           nullable
is_active            boolean        default true   (indexed)
is_registration_open boolean        default false
is_grades_released   boolean        default false
grades_released_at   timestamp      nullable   ← ADDED beyond spec
registration_opened_at timestamp    nullable   ← ADDED beyond spec
closed_at            timestamp      nullable   ← ADDED beyond spec
metadata             json           nullable   e.g. { "coordinator_id": 7 }
created_at / updated_at
```

**Unique constraint:** `(academic_session, semester_number)`.
**Additional index:** `(is_active, starts_at)` — `academic_semester_active_idx`, added because
"which semester is active?" is queried on nearly every page load.

**Deviation — three audit timestamps added.** The spec listed only three boolean flags. A flag
with no timestamp cannot answer "when did the coordinator open registration?", which auditors ask
regularly. `registration_opened_at`, `grades_released_at`, and `closed_at` are set by
`SemesterService` whenever the corresponding flag flips.

### 3.2 `projects`

Added `academic_semester_id` (nullable FK), plus composite index
`(academic_semester_id, psm_part)` — `projects_semester_part_idx`.

- Existing rows: backfilled to the semester matching their `academic_session`, else NULL.
- New rows: **should** supply it; the column stays nullable so pre-semester history keeps working.
- `academic_session` retained as a denormalised string for quick filtering.
- Model scopes added: `scopeForSemester()`, `scopeForPart()`, `scopeForSemesterPart()`,
  `scopeLive()`. `scopeForSemester(null)` is a deliberate no-op so legacy rows remain visible.

### 3.3 `student_profiles` — DEVIATION

Added `academic_semester_id` (nullable FK) as specified.

**However, the spec's own two options are mutually exclusive and only one is possible.**
`2026_01_01_000003_create_profile_tables.php` declares:

```php
$table->foreignId('user_id')->unique()->constrained();   // UNIQUE
$table->string('student_id', 32)->unique();              // UNIQUE
```

A user can therefore hold **at most one** student profile row, ever. The spec's suggestion to
"get a **new** student profile row" when a student progresses to PSM 2 cannot be executed — it
violates the unique index. This was confirmed by attempting the insert; MySQL rejected it with
`Duplicate entry '15' for key 'student_profiles.student_profiles_user_id_unique'`.

**Decision — profile = current enrolment, history = projects.** One profile per user, with
`academic_semester_id` tracking the student's *current* term. A student's history lives on
`project_members` + `projects.academic_semester_id`, which correctly retains the PSM 1 record while
the profile moves forward. Verified by AC#9. Unlocking a true per-term profile history would
require dropping both unique indexes — a schema change with wide blast radius, deliberately **not**
made under this requirement. Recorded as an open item in §11.

### 3.4 `supervision_assignments`

Added `psm_part` and **independent per-part capacity columns** on `supervisor_profiles`
(migration `2026_10_04_000002_add_per_part_supervisor_capacity.php`):

```
max_supervisees_psm1   int  nullable   ← per-part cap
max_supervisees_psm2   int  nullable   ← per-part cap
```

Capacity is therefore two-dimensional: `(term, psm_part)` → count. See §7.1 for the aggregate rule.

### 3.5 `title_defence_sessions`

Added `academic_semester_id` FK plus composite index
`(academic_semester_id, psm_part)` — `title_defence_session_semester_part_idx`.
Roster query as specified:

```sql
WHERE p.psm_part = session.psm_part
  AND p.academic_semester_id = session.academic_semester_id
  AND p.archived_at IS NULL
```

### 3.6 `milestone_templates`

Part-scoped resolution is achieved **without adding a `psm_part` column**, which was the spec's
proposal. The migration deliberately does *not* add a third copy of a fact already reachable:
every template reaches a part through the project it resolves against, and every project now
carries `psm_part`. Adding the column would have created a second source of truth needing its own
backfill and its own drift bug. Milestone chains are verified per part by AC#6.

### 3.7 `examiner_pairs`

Added `academic_semester_id` (nullable FK, `nullOnDelete`). **The unique index was widened**, which
is the part that actually matters:

```
before:  UNIQUE (psm_part, examiner_1_id, examiner_2_id)
after:   UNIQUE (psm_part, academic_semester_id, examiner_1_id, examiner_2_id)
         └─ examiner_pair_unique
```

Without widening, the same two examiners could never be seated in both parts of one term, and
`autoAssign` for PSM 2 would fail on a uniqueness collision. Backfill derives each existing pair's
term from the dominant term across its assigned projects, falling back to the then-current term.

---

## 4. API — As Built

> **Superseded in part.** The two release routes below were removed when marks
> began publishing themselves — see `PLAN_AUTO_MARK_RELEASE.md`. Kept here as the
> record of what this requirement built; the current surface is:
>
> ```
> GET    /api/semesters                      list semesters
> POST   /api/semesters                      create a semester
> GET    /api/semesters/current              the active semester
> GET    /api/semesters/{id}                 detail with cohort stats
> GET    /api/semesters/{id}/students        who this term enrolled   ← ADDED
> PATCH  /api/semesters/{id}                 update dates/flags
> POST   /api/semesters/{id}/close           close the term (refused while marks are outstanding)
> POST   /api/semesters/{id}/registration    open/close registration
> ```
>
> `POST /{id}/release-marks` is **gone**: a mark publishes itself the moment its
> supervisor's form arrives, so a term has nothing to release. `POST
> /{id}/close` also now refuses while any mark submission in the term is
> incomplete, because closing freezes the term.

### 4.1 New endpoints (`SemesterController`, `routes/api.php`) — as originally built

```
GET    /api/semesters                      list semesters
POST   /api/semesters                      create a semester
GET    /api/semesters/current              the active semester
GET    /api/semesters/{id}                 detail with cohort stats
PATCH  /api/semesters/{id}                 update dates/flags
POST   /api/semesters/{id}/close           close registration + is_active=false
POST   /api/semesters/{id}/registration    open/close registration   ← ADDED
POST   /api/semesters/{id}/release-grades  set per-term grade release ← ADDED
```

**Deviation — two extra routes.** The spec's `PATCH /{id}` could carry the flags, but toggling
registration and grade release are *gates* with side effects (stamping timestamps, bulk-releasing
`final_grades`). Routing them through explicit POST actions keeps those side effects out of a
generic update path where a stray `{"is_grades_released": true}` would silently publish a cohort.

### 4.2 Modified endpoints

| Endpoint | Change | Status |
|----------|--------|--------|
| `GET /api/projects` | `?semester_id=`, `?psm_part=`, `?role=supervisor\|examiner`; defaults to active term | Done |
| `POST /api/projects` | Persists `academic_semester_id`; one live project per (student, term, part) | Done |
| `GET /api/dashboard` | Adds `by_part.{PSM1,PSM2} = {label, cohort, grades, at_risk}` | Done |
| `POST /api/assignments/supervisors` | Accepts `psm_part`; enforces per-part capacity in the student's term | Done |
| `POST /api/assignments/examiner-pairs/auto-assign` | Accepts `semester_id` + `psm_part`; **refuses** a run with no term | Done |
| `POST /api/title-defences` | Requires `academic_semester_id`; roster scoped to term + part | Done |
| `GET /api/milestones` | Part-aware via the owning project; coordinator filter by term | Done |
| `GET /api/grades` | `semester_id` / `psm_part` / `batch` / status filters; defaults to active term | Done |
| `GET /api/reports/*` | All 8 reports + CSV exports accept `semester_id` and `psm_part` | Done |
| `POST /api/registrations/lampiran-a` | Gated on active term's `is_registration_open`; session derived server-side | Done |

**Filter defaults, decided deliberately:**
- Missing `semester_id` → resolves to the **active** term via `AcademicSemester::resolveFilterId()`.
- Missing `psm_part` → **both parts**. It does *not* default to PSM2. Defaulting to PSM2 would
  silently hide the PSM 1 half of a concurrent term — the exact bug this requirement exists to fix.

**`ApiController::paginated()`** gained an optional third `$extra` argument so list endpoints can
return filter context. The grade list uses it to expose `semester_id` and `semester_released`,
which the release UI needs to decide whether to show a Release button. Backward compatible — all
existing 2-argument call sites are unchanged.

---

## 5. UI — COMPLETE (8 of 8 screens built)

> Backend is complete and verified. **All 8 screens implemented and building.** The API shapes are
> final and verified.

### 5.1 Coordinator Dashboard — ✓ (AC#1)
Cohort Overview segmented `[ PSM 1 | PSM 2 ]`, driven by `dashboardSummary.by_part`.
Default tab = larger cohort, or the coordinator's last view persisted in `psm.dashboard.lastPart`.
"Manage Semesters" action → `/semesters` (create term, open/close registration, set grade
release, archive).

### 5.2 Project List — ✓
Filter bar: `[Semester ▼] [Batch: All | PSM 1 | PSM 2 ▼] [Status ▼] [Search]`.
Semester options from `GET /api/projects/options` (`registrationMeta.semesters`).
Table shows Part badge, Semester, progress from `milestone_progress`, next milestone
from `next_milestone.{title,due_at}`.

### 5.3 Supervisor View — ✓ (AC#3)
"My Students" grouped by part, each group with its own progress summary.
Capacity shows two independent bars: PSM 1 (n/5) and PSM 2 (n/5), sourced from
`profileApi.workload(...).supervisor.by_part`. Term-scoped via semester filter.

### 5.4 Student View — ✓
No structural change. `psm_part` badge + semester shown in project header on Student Dashboard.

### 5.5 Examiner View — ✓
"Pending Evaluations" grouped by part via segmented control `[ All | PSM 1 | PSM 2 ]`.
Each group shows its own draft/submitted lists. Part badge on every row.
Term-scoped via evaluation form `psm_part` field.

### 5.6 Title Defence Page — ✓
Semester dropdown filters sitting list; "New sitting" pre-fills the selected term.
Sitting row shows `psm_part` badge + label; roster scoped to that term + part.

### 5.7 Rubric Page — ✓
No change needed — templates are global, not term- or part-scoped.

### 5.8 Reports — ✓
Filter bar: `[Semester ▼] [Batch type: All | PSM 1 | PSM 2 ▼] [Cohort year ▼]`.
All report endpoints receive `semester_id`, `psm_part`, `batch`. Default = active term + both parts.

---

## 6. Process Flow

### 6.1 Semester Lifecycle — implemented as specified

```
Coordinator creates semester
        ▼
Registration opens (is_registration_open = true, registration_opened_at stamped)
        ├──► Students submit Lampiran A   ← gated by assertRegistrationOpen()
        ├──► Coordinator allocates supervisors  ← per (term, part) capacity
        ├──► Title defence session (PSM 1)
        ├──► Approved students submit Lampiran B → project created
        └──► Milestones begin (part-aware templates)
        ▼
Mid-semester: progress reports (Lampiran H for PSM 2)
        ▼
Seminar / evaluation period
        ├──► PSM 1: Lampiran E (supervisor) + Lampiran I (examiner)
        └──► PSM 2: Lampiran G (supervisor) + Lampiran H + Lampiran J
        ▼
Coordinator releases grades (per semester)
        ▼
Semester closes (is_active = false, closed_at stamped)
```

### 6.2 Student Progression — CORRECTED

The spec's integrity rule read:

> *"A student cannot have two active (non-archived) projects in the same semester. They can have
> one PSM 1 project and one PSM 2 project if those projects belong to different semesters."*

**This contradicted §1 and the rest of the document.** It permitted PSM1+PSM2 only in *different*
semesters, which is precisely the single-cohort-per-session model being replaced. Enforced
literally it would have blocked the feature.

**Implemented rule — one live project per (student, term, part):**

```
UNIQUE in effect: (student, academic_semester_id, psm_part) among non-archived projects
```

A student **may** hold a PSM 1 *and* a PSM 2 project in the same term. A student **may not** hold
two PSM 2 projects in the same term. Enforced in `ProjectController` via
`StudentProfile::hasActiveProjectInSemester()` and covered by AC#1 and AC#9.

---

## 7. Capacity & Assignment Rules

### 7.1 Supervisor Capacity

Per-part caps, configurable in `config/psm.php` via `PSM_SUPERVISOR_CAPACITY_PSM1/PSM2`.

**Deviation — aggregate capacity is `max`, not `min`.** A supervisor with 4 PSM 1 + 5 PSM 2 = 9
total is within limits for both parts, per the spec. But the legacy `supervisor_profiles.max_supervisees`
column ships at **8** while the migration backfills it to the **sum** (10). A coordinator who never
re-saved the profile would be blocked at 9 students by the aggregate check even though both part
caps allowed it — the spec's own worked example would fail.

**Decision — `SupervisorProfile::effectiveTotalCapacity()`:**

```php
return max((int) $this->max_supervisees, $psm1Cap + $psm2Cap);
```

`max` honours a coordinator who *deliberately* raised the aggregate above the part sum, while never
letting a stale legacy value impose a cap the parts don't. Verified across 6 cases: 4+5=9 allowed,
a 6th student in either part refused, deliberate headroom honoured, zero-cap respected.

**All capacity checks are term-scoped.** A supervisor carrying last term's finished cohort must not
count against this term. `remainingCapacityForPartInSemester()` and `isFullInSemester()` derive the
term from `$student->academic_semester_id` in `assignSupervisor()`, `suggestSupervisors()`, and
`registrationMeta()`.

### 7.2 Examiner Pairing

Scoped to `(semester_id, psm_part)`:
1. Filter students by term + part.
2. Build pairs from eligible examiners who are **not** supervisors of anyone in that filtered set.
3. Assign the pair across the batch.

**A run with no `semester_id` is refused, not guessed** — it would otherwise draw on every term's
panels at once. Verified by AC#10.

### 7.3 Coordinator Scope

One active semester at a time. "Active" = `is_active = true`, resolved via
`AcademicSemester::active()` (most recent `starts_at` among active rows).

---

## 8. Configuration — As Built (`config/psm.php`)

```php
'supervisor_capacity' => [
    'PSM1' => (int) env('PSM_SUPERVISOR_CAPACITY_PSM1', 5),
    'PSM2' => (int) env('PSM_SUPERVISOR_CAPACITY_PSM2', 5),
],
'examiner_capacity' => [
    'PSM1' => (int) env('PSM_EXAMINER_CAPACITY_PSM1', 10),
    'PSM2' => (int) env('PSM_EXAMINER_CAPACITY_PSM2', 10),
],
'title_defence_batch_size' => (int) env('PSM_DEFENCE_BATCH_SIZE', 25),
```

---

## 9. Migration & Seed — As Built

| Migration | Purpose |
|---|---|
| `2026_10_04_000001_create_academic_semesters_table.php` | Creates `academic_semesters`; adds `academic_semester_id` to `projects`, `student_profiles`, `title_defence_sessions`, `examiner_pairs`; widens `examiner_pair_unique`; backfills existing rows |
| `2026_10_04_000002_add_per_part_supervisor_capacity.php` | Adds `max_supervisees_psm1/2`; backfills from `max_supervisees` |

Rather than a raw `INSERT ... SELECT` (§9 Step 2 of the spec), backfill runs inside the migration
so schema and data move together and cannot drift across environments.

**Seed data (`AcademicSemesterSeeder`, registered before project seeding):**

| Term | State | Purpose |
|---|---|---|
| 2025/2026 Semester I | archived, grades released | historical reference |
| **2025/2026 Semester II** | **active**, registration open | live cohort — 11 PSM1 + 11 PSM2 |
| 2026/2027 Semester I | planned | progression target |

`ProjectSeeder` interleaves both parts in the active term with distinct per-part milestone
progress profiles. Result of `migrate:fresh --seed`: 24 students, 22 projects (11/11),
121 milestones, 17 evaluations, 266 scores, 10 final grades, 5 archived projects.

---

## 10. Acceptance Criteria — Verified

All 10 criteria pass against seeded data. There is no automated test suite in this repo, so each
was verified with a purpose-built script run inside the container (transactional, rolled back).

| # | Criteria | Status | Evidence |
|---|----------|--------|----------|
| 1 | Coordinator can create a semester and set its date range | **PASS** | `SemesterService::create()` validates the range; concurrent PSM1+PSM2 in one term confirmed (11 + 11) |
| 2 | Lampiran A only when registration is open for the active term | **PASS** | Closed gate refuses with 422; open gate accepts and stamps the semester's own session name, ignoring any client-supplied session |
| 3 | PSM 1 and PSM 2 in separate groups on the supervisor dashboard | **PASS (API)** | `by_part` rollup present and correct · UI not built |
| 4 | Supervisor capacity enforced per PSM part, not globally | **PASS** | 4+5=9 allowed; 6th in a part refused; `effectiveTotalCapacity()`; term-scoped counts |
| 5 | Title defence roster only includes selected term + part | **PASS** | `TitleDefenceService` roster filtered by term + part + `archived_at IS NULL` |
| 6 | Milestones match the student's PSM part | **PASS** | PSM1 chain and PSM2 chain distinct; per-part seeded progress |
| 7 | Grade release toggles per semester, not globally | **PASS** | Term flag is authoritative; per-grade release blocked while the term is withheld; legacy NULL-semester rows still releasable |
| 8 | Cohort report shows PSM 1 and PSM 2 side by side | **PASS** | `by_part` on dashboard + cohort-progress; all 8 reports accept `psm_part` |
| 9 | Student can have a PSM 1 project in one term and PSM 2 in the next | **PASS** | One profile spans projects in 2 terms; earlier project retained; same-term same-part duplicate refused. *Subject to the §3.3 deviation.* |
| 10 | Examiner auto-assign creates separate pairs per part within one term | **PASS** | Widened 4-column unique index; PSM1 pairs `{9,10,11,12}` disjoint from PSM2 `{13,14,15,16}`; term-less run refused |

### Verification also performed
- `php artisan migrate:fresh --seed --force` — green.
- `php -l` on all 11 touched services/controllers — clean.
- All 11 services resolve through the container; all 135 API routes load.
- Eloquent scopes and every changed method signature confirmed present.
- No regression on legacy data (semester-less rows still queryable).

---

## 11. Defects Found & Fixed During Implementation

These were real bugs in existing code, not wiring gaps:

1. **Per-grade release bypassed the per-term gate.** `releaseGrade()` was an independent path from
   the semester release switch, so a coordinator could publish a single result from a cohort the
   faculty had withheld. Added `assertTermAllowsRelease()`; the term flag is now authoritative.

2. **Business-rule rejections returned HTTP 500.** `InvalidArgumentException` fell through to the
   `default` branch in `bootstrap/app.php`, so "registration is closed" surfaced as "unexpected
   server error". Now mapped to **422** along with `DomainException`.

3. **Capacity refused the spec's own 4+5=9 example** when `max_supervisees` held a stale 8 against
   backfilled part caps summing to 10. See §7.1.

4. **`hasCapacityForInSemester()` was dead code.** The allocation path called `hasCapacityFor()`,
   which counts across all terms, so a supervisor carrying a finished prior-term cohort was
   permanently over cap. Now term-scoped.

5. **Lampiran A accepted any invented `session` string.** `submitAgreement` never consulted the
   calendar. Now gated by `assertRegistrationOpen()` and the session is stamped server-side.

6. **Seeding aborted on the new PSM 1 cohort** — two separate defects:
   `EvaluationSeeder::isMarkable()` applied the PSM2 bar to Lampiran E (the *final* PSM1 evaluation,
   which requires all milestones approved), and `ProjectSeeder` had PSM2-only progress profiles, so
   PSM1 projects stalled at 2/6 approved.

---

## 12. Open Questions — Resolved & Outstanding

### Resolved
1. **PSM 1 defence timing** — kept same-semester, per the spec's stated assumption.
4. **Auto-promotion PSM 1 → PSM 2** — **manual**. A passing student is not silently promoted;
   the coordinator creates the next-term enrolment. This is correct for edge cases (leave of
   absence, supervisor change).

### Outstanding — need a decision
2. **Same-semester PSM 1 retake** — the schema permits it (new project, same term, new
   supervision). No UI exposes it. Allowed by `hasActiveProjectInSemester()` but never exercised.
3. **Dedicated vs shared examiner pools** — `autoAssign` draws from one pool of
   `examiner`/`supervisor` users and honours a per-part capacity. If PSM 1 requires a dedicated
   panel, that constraint is not yet expressible.
5. **True per-term student profile history** — blocked by the unique indexes discussed in §3.3.
   Needs a schema decision, not a code change.
6. **Title defence batch splitting** — `assertRosterFits()` treats the selected term + part as a
   single sitting and refuses when the count exceeds `title_defence_batch_size`. It cannot split a
   remainder across a second sitting; the error message implies a split is possible. Either add
   explicit session/student allocation or reword the message.

### Known limitation (pre-existing, out of scope)
- **Seeded leaderboard is empty** ("no visible entries"). Reproduced identically before this work.
  Grades are released and eligible, so this is a leaderboard-seeding gap unrelated to concurrent
  batches. Worth a separate ticket.

---

## 13. Remaining Work

| Area | State |
|---|---|
| Backend data model, API, services, seed | **Complete, verified** |
| Frontend §5.1–5.8 (all screens) | **Complete, builds** |
| Automated test suite | Does not exist in this repo; verification was script-based |