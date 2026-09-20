# PSM Management System — Architecture

Final Year Project (PSM) management for a university faculty. Covers the full
lifecycle: registration, supervision, milestones, assessment, reporting,
notification, archive, and public recognition.

---

## 1. Shape of the system

Decoupled single-page application talking to a stateless JSON API.

```
┌──────────────────────┐        HTTPS/JSON         ┌───────────────────────┐
│  React SPA (Vite)    │  ───────────────────────► │  Laravel 11 API       │
│  - role-based routes │  ◄─────────────────────── │  Sanctum tokens       │
│  - dashboards        │        Bearer token       │  policies + services  │
└──────────────────────┘                           └───────────┬───────────┘
                                                               │
                        ┌──────────────────────────────────────┼──────────────┐
                        │                                      │              │
                   ┌────▼─────┐                        ┌───────▼──────┐  ┌────▼─────┐
                   │  MySQL   │                        │  Scheduler   │  │ Storage  │
                   │  8.x     │                        │ (reminders,  │  │ (submis- │
                   └──────────┘                        │  overdue,    │  │  sions)  │
                                                       │  prune)      │  └──────────┘
                                                       └──────────────┘
```

**Why decoupled.** Module 8 requires a public page that works with no login.
If the UI were server-rendered, that page would have to be carved out as a
special case. As a separate API route it is simply a route group registered
before any auth middleware.

**Why token auth, not sessions.** The API holds no session state, so it scales
horizontally behind a load balancer and the SPA can be served from a CDN. A
token carries only the user id; every authorisation decision is re-derived
from the database on each request. Revoking access is a row delete.

---

## 2. Layer responsibilities

| Layer | Location | Responsibility |
|---|---|---|
| Routes | `routes/api.php` | URL shape, coarse `role:` middleware, throttle |
| Middleware | `app/Http/Middleware` | Cross-cutting gates (auth, active account, forced password change, audit) |
| Controllers | `app/Http/Controllers/Api` | Validate input, call a service, shape a resource. No business rules. |
| Form requests | `app/Http/Requests` | Field-level validation and authorisation |
| Policies | `app/Policies` | Per-resource "may this user do this to this row" |
| Services | `app/Services` | All business rules. The only place state machine transitions, grade arithmetic, and archive freezing happen. |
| Models | `app/Models` | Relations, casts, scopes, and small domain helpers |
| Enums | `app/Enums` | Domain vocabulary with labels, transitions, and defaults |
| Resources | `app/Http/Resources` | Explicit response shaping — no model serialisation |

The rule that keeps this maintainable: **business rules live in exactly one
place.** A controller never computes a grade; it asks `EvaluationService`. A
model never decides whether a transition is legal; it asks `MilestoneStatus`.

---

## 3. Domain vocabulary (enums as the source of truth)

Every controlled vocabulary is a PHP backed enum. Adding a milestone status,
a role, or a grade band is a one-file change, and the compiler-adjacent
`match` expressions fail loudly if a consumer is not updated.

| Enum | Cases | Notable behaviour |
|---|---|---|
| `Role` | student, supervisor, coordinator, examiner, admin | `canAssess()`, `canViewCohortAnalytics()`, `homeRoute()` |
| `MilestoneStatus` | pending, open, submitted, reviewed, approved, rejected, overdue | `allowedTransitions()`, `isSubmittable()`, `isProgressed()` |
| `ProjectCategory` | system, research | `defaultMilestones()` — the milestone chain per category |
| `AssessorType` | supervisor, examiner, coordinator | `defaultWeight()` → 60 / 40 / 0 |
| `EvaluationStatus` | draft, submitted, moderated, released, recused | `isEditable()`, `isLocked()`, `countsTowardAggregate()` |
| `NotificationType` | 17 cases | `defaultChannels()`, `isUrgent()`, `grouped()` |
| `AuditAction` | ~40 cases | `category()`, `severity()` |

`ProjectCategory::defaultMilestones()` is worth calling out: the milestone
chain, its weights, and its offsets are defined once in the enum and consumed
by both the seeder and the runtime template resolution. They cannot drift.

---

## 4. The milestone state machine (Module 3)

All status changes flow through one method:

```
MilestoneService::transitionTo($milestone, $target, $actor, $comment)
```

It validates against `MilestoneStatus::allowedTransitions()`, stamps the
timestamps that belong to the target state, records a `SubmissionEvent`,
writes an `AuditLog`, and — on approval — opens the next milestone.

```
pending ──► open ──► submitted ──► reviewed ──► approved
   │          │          │            │
   │          │          │            └──► rejected ──┐
   │          └──► overdue ──► submitted              │
   │                             │                    │
   └─────────────────────────────┴──────► open ◄──────┘
```

Why a single entry point matters: Module 7 promises a trustworthy audit
trail. If any controller could `$milestone->update(['status' => ...])`, the
trail would record *that* a status changed but never whether the change was
legal. Routing everything through one validated method makes an illegal
transition impossible rather than merely discouraged.

**Instantiation.** `MilestoneService::instantiateFor($project)` resolves the
template via `MilestoneTemplate::resolveFor($category, $psmPart)`, computes
each `opens_at`/`due_at` from the template item's `offset_days` and
`duration_days`, opens the first milestone, and leaves the rest `pending`.
The seeder calls this same method — so seeded data is data the real system
could have produced.

---

## 5. The assessment engine (Module 4)

### 5.1 Rubric freezing

When a form is created, `RubricTemplate::snapshot()` is written into
`evaluations.rubric_snapshot` as JSON. Subsequent edits to the rubric template
cannot reinterpret a mark already given. Without this, renaming a criterion or
reweighting a component would silently change what a historical score means —
unacceptable when a grade can be appealed.

### 5.2 Weighted arithmetic

Two independent weight levels must both total 100:

```
RubricTemplate      → components           = 100%
RubricComponent     → criteria             = 100%
```

A criterion's absolute cap is derived and stored:

```
max_marks = template.total_marks × (component.weight/100) × (criterion.weight/100)
```

Storing rather than deriving means a later template edit cannot change the
cap an old assessment was marked against.

Each criterion's contribution to the rubric total:

```
weighted_contribution = marks × (component.weight/100) × (criterion.weight/100)
```

### 5.3 The aggregate

`EvaluationService::computeFinalGrade()` is explicit and persists its own
arithmetic so a result can always be explained:

1. Collect evaluations whose status counts toward the aggregate
2. Group by `AssessorType`; within each group combine percentages using the
   scheme's rule (mean / weighted_mean / max / min), optionally discarding
   extremes when ≥3 assessors reported
3. Weight each group's subtotal by `GradeScheme::weightFor($type)`
4. Sum. **If the configured weights do not total 100** — e.g. only an examiner
   marked, and supervisors carry 60% — rescale by the weight actually present
   so the result stays on a 0–100 scale rather than silently capping at 40
5. Blend with milestone completion:
   `final = aggregate × (100−b)/100 + milestoneScore × b/100`, where
   `b = psm.milestone_blend_percent` (default 20)
6. Apply `FinalGrade::bandFor($final)` and persist the full breakdown

The milestone blend is a **gate**, not a large share of the mark: a student who
never submitted cannot score 100 overall. It is configurable, and setting it to
0 grades on assessor marks alone.

### 5.4 Grade scheme per project

The weighting lives on `grade_schemes`, one row per project, resolved through
`GradeScheme::ensureFor()`. A faculty-wide default change therefore does not
retroactively invalidate already-released grades.

---

## 6. Recognition and the public page (Module 8)

Two-phase publication, and the reason is worth stating plainly.

```
build()   → ranks eligible grades into a DRAFT snapshot (previewable)
publish() → flips the snapshot live
```

A published page is a **frozen snapshot**. If it rendered live grades, the
public ranking could change under a visitor's feet, and any late moderation
would silently rewrite a result the student had already been congratulated for.
`build()` refuses to run on a published board for the same reason.

**Eligibility** (`LeaderboardService::eligibleGrades()`), all conditions required:

- grade `status = released`
- `is_publishable = true`
- `assessor_count >= min_assessors` (default 2) — one marker is not evidence
- `final_mark` not null
- project has `leaderboard_opt_out = false`
- project matches the board's `psm_part` / batch / session filters

**Privacy.** The seeder and publisher write frozen rosters into
`leaderboard_entries.students` / `.supervisors` containing only
`{name, student_id}` and `{name}`. No email, phone, or user id reaches the
public row. `LeaderboardService::publicEntry()` then whitelists every field
explicitly — the public payload is assembled, never serialised from a model.
The public route is registered before any auth middleware and rate-limited at
60 requests/minute, because there is no authenticated user to throttle against.

---

## 7. Archive and audit (Module 7)

### 7.1 Audit trail

Append-only. The model sets `const UPDATED_AT = null` and nothing in the
application updates or deletes a row; the only permitted removal is the
retention prune by the scheduled `audit:prune` command.

Two layers:

- **Service-level** `AuditLogger::log()` — knows *why*. Called with before/after
  attribute snapshots, records actor name and role as a snapshot so a later
  user deletion does not orphan the trail, scrubs secrets per
  `psm.audit.excluded_fields`, and writes a field-level diff.
- **Middleware** `RecordAuditTrail` — the safety net for anything a service
  forgot to log.

`SubmissionEvent` is the separate narrative layer: where `audit_logs` answers
"what did this *user* do", `submission_events` answers "what happened to this
*milestone*". Students and supervisors read the second; auditors read the first.

### 7.2 Archive

Archiving is fully denormalised on purpose. An `archived_projects` row must
remain readable for years even after users are deleted, rubrics retired, and
grades recomputed — so it copies the student roster, supervisor roster,
examiner roster, milestone summary, grade breakdown, and a document manifest at
archive time. Nothing in the archive depends on a live row as its sole source.

Only **released** grades can be archived: a provisional mark would become
permanently wrong the moment it changed.

---

## 8. Notifications and reminders (Module 6)

Email plus in-app, dispatched through one `NotificationDispatcher`. The
dispatcher is the only thing that knows the channels; callers pass a
`NotificationType` and the dispatcher resolves `defaultChannels()`.

**Idempotent reminders.** `reminder_dispatches` carries a unique constraint on
`(milestone_id, user_id, days_before, notification_type)`. The hourly reminder
job can therefore run as often as the scheduler likes without ever sending the
same reminder twice — a retry after a partial failure is safe.

Reminder days come from `REMINDER_DAYS_BEFORE` (default 7, 3, 1). Deadline
scopes use `DATE(COALESCE(extended_until, due_at))` so a granted extension
genuinely moves the reminder, not just the displayed date.

---

## 9. Authorisation

Two layers, and the split is intentional:

- **`role:` middleware** for coarse groups — fail fast, and keep the route file
  self-documenting. It answers "may this *kind* of user reach this endpoint".
- **Policies** for per-resource rules — authoritative. They answer "may this
  user do this to *this row*".

`AppServiceProvider` registers a `Gate::before` admin bypass so an
administrator is never locked out of a resource, and binds the services as
singletons.

Visibility is enforced at query level, not by filtering results after the fact:
`Project::scopeVisibleTo($user)` expresses what a student, supervisor,
examiner, or coordinator may see, and every list endpoint builds on it.

---

## 10. Configuration

Domain constants live in `config/psm.php` and are read through `config()`,
never hardcoded. Every key read by the application is declared there; this is
verified rather than assumed.

| Key | Default | Meaning |
|---|---|---|
| `psm.grade_bands` | 10 bands, A–F | Percentage → grade letter and point |
| `psm.milestone_blend_percent` | 20 | Milestone share of the final mark |
| `psm.leaderboard.top_n` | 3 | Podium size |
| `psm.leaderboard.min_assessors` | 2 | Assessors required before public display |
| `psm.leaderboard.base_path` | `/leaderboard` | Public page path |
| `psm.reminder_days_before` | 7, 3, 1 | Reminder waves |
| `psm.supervisor_max_capacity` | 8 | Default supervision cap |
| `psm.submission.max_mb` | 25 | Upload size limit |
| `psm.audit.retain_years` | 7 | Retention before prune |

---

## 11. Scheduled work

Defined in `routes/console.php`:

| Schedule | Task |
|---|---|
| hourly | Send deadline reminders for the configured day-offsets |
| daily 00:05 | Open milestones whose `opens_at` has arrived |
| daily 00:15 | Flag milestones overdue |
| daily 07:30 | Coordinator deadline digest |
| monthly | Prune audit logs past retention |
| daily | Housekeeping |

All are idempotent, so a missed or repeated run is harmless.

---

## 12. Data model at a glance

**Identity & pairing** — `users`, `student_profiles`, `supervisor_profiles`,
`expertise_areas` (+ `supervisor_expertise` pivot), `coordinator_scopes`,
`supervision_assignments`, `examiner_assignments`

**Delivery** — `projects`, `project_members`, `milestone_templates`,
`milestone_template_items`, `milestones`, `submission_files`,
`submission_events`

**Assessment** — `rubric_templates`, `rubric_components`, `rubric_criteria`,
`grade_schemes`, `evaluations`, `evaluation_scores`, `final_grades`

**Voice** — `notifications`, `reminder_dispatches`

**Oversight** — `audit_logs`, `archived_projects`

**Recognition** — `leaderboards`, `leaderboard_entries`, `leaderboard_settings`

See `ERD.md` for column-level detail and relationship cardinality.

---

## 13. Frontend structure

The SPA is organised so that each file has one job, and so that adding a screen
means editing two files rather than five.

```
frontend/src/
├── main.jsx                 StrictMode → Router → AuthProvider → routes
├── App.jsx                  route table; every screen below the shell is lazy
├── api/
│   ├── client.js            one axios instance, token store, 401 handling
│   ├── auth.js              login / logout / me / password flows
│   └── endpoints.js         the whole API surface, grouped by module
├── lib/
│   ├── permissions.js       role vocabulary and capability map (UI shaping only)
│   └── format.js            dates, marks, status metadata, grade tones
├── context/AuthContext.jsx  session restore, login, logout, capability helpers
├── components/
│   ├── ui.jsx               the design system — Card, Button, Badge, Field, …
│   ├── AppLayout.jsx        sidebar + mobile drawer + notification badge
│   └── guards.jsx           RequireAuth, RequireRole, RequireCapability
└── pages/
    ├── auth/                login, forgot, reset, change password
    ├── student/ supervisor/ coordinator/ examiner/ admin/    role dashboards,
    │                                                        plus user accounts
    ├── projects/            list, detail, register
    ├── milestones/          list, detail (submission + review)
    ├── evaluations/         list, marking form, grades, rubric templates
    ├── assignments/         supervisor assignment and capacity
    ├── reports/             cohort analytics
    ├── archive/             archive list, detail, audit log
    ├── leaderboards/        award admin and board builder
    ├── account/             notifications, profile
    └── public/              the no-login Pixel-It leaderboard
```

### Route groups

| Group | Guard | Notes |
|---|---|---|
| `/leaderboard`, `/leaderboard/:slug` | none | Outside the shell entirely — no sidebar, no auth probe |
| `/login`, `/forgot-password`, `/reset-password` | `RedirectIfAuthenticated` | Signed-in users bounce to their dashboard |
| everything else | `RequireAuth` | Wrapped in `AppLayout` |
| `/dashboard` | `RequireAuth` | Redirects to the role's home route |
| `/assignments` | `RequireCapability assignSupervisors` | Coordinator + admin |
| `/reports` | `RequireCapability viewCohortAnalytics` | Coordinator + admin |
| `/users`, `/audit` | `RequireCapability manageUsers` / `viewAuditLog` | Admin-led; `/audit` is also readable by coordinators |
| `/leaderboards/*` | `RequireCapability manageLeaderboard` | Coordinator + admin |

### Why capabilities rather than roles in the guards

Route guards ask for a **capability** (`viewCohortAnalytics`), not a role. A
capability maps to one or more roles in `lib/permissions.js`. The reason is
that the backend authorises by policy, and policies sometimes grant one
capability to several roles — for instance a coordinator and an admin can both
release grades. Guarding by capability keeps the frontend in step with that
without hardcoding role lists into the route table.

### The public route is deliberately outside the shell

Module 8's requirement is that the winner display is publicly accessible. That
is satisfied structurally, not just by skipping a check: the route sits outside
`RequireAuth`, outside `AppLayout`, and `AuthProvider` never calls `/me` for a
visitor because the page makes no authenticated request. The public page reads
only frozen `leaderboard_entries` columns, so no draft or unmoderated data can
reach it.

### Autosave in the marking form

The rubric form saves explicitly ("Save draft") and implicitly, 1.5 seconds
after the last edit. Assessors mark a dozen criteria over several minutes, and
losing that to a closed tab is the single most costly failure in the app. The
debounced save is silent — it does not clear the form or steal focus.
