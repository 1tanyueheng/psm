# PSM Management System — Modules

Eight modules, one per requirement. Each section states what the module must
do, how it is built, and the decisions worth defending.

---

## Module 1 — Authentication and role-based access control

**Requirement.** Five roles (student, supervisor, coordinator, examiner,
admin), role-appropriate dashboards, password reset, session management.

**Built.** Laravel Sanctum token auth against a stateless API. `Role` is a
backed enum with `homeRoute()` so the SPA's post-login redirect is data, not a
switch statement. `AuthController` provides login, logout, password change,
forgot-password, and reset. `must_change_password` drives the `first.login`
middleware, so an admin-issued temporary password cannot be used indefinitely.

**Screens.** `pages/auth/` — login (with one-tap demo accounts and `?next=`
handling), forgot-password, reset-password, change-password. The last one
switches to a full-screen layout when the change is forced, because a user who
cannot navigate away should not be shown a navigation they cannot use.
`components/guards.jsx` supplies `RequireAuth`, `RequireRole`, and
`RequireCapability`; `context/AuthContext.jsx` restores the session from `/me`
on boot and exposes `can()` / `isAtLeast()` for UI shaping.

**Decisions.**

- *Tokens, not sessions.* The API holds no state, so it scales horizontally and
  the SPA can live on a CDN. Revoking a user is a row delete.
- *Login throttled at 10/min, password endpoints at 5/min.* Credential stuffing
  is the realistic attack on a public faculty system.
- *Failed logins back off exponentially* via `registerFailedLogin()`; after the
  threshold the account is locked until `locked_until`.
- *`Gate::before` admin bypass.* An administrator is never locked out of a
  resource. Every other check is a policy.
- *Changing your own password does not log you out.* The implementation deletes
  all tokens **except the current one** — the naive `tokens()->delete()` logs
  the user out of the session they are actively using.

---

## Module 2 — User and profile management

**Requirement.** Student profiles (ID, program, batch, thesis title),
supervisor profiles (expertise, max capacity), coordinator assigns
supervisor↔student pairs.

**Built.** `student_profiles` and `supervisor_profiles` hang off `users`.
`expertise_areas` is a shared vocabulary with a `supervisor_expertise` pivot
carrying a proficiency rating. `AssignmentService` owns every pairing
operation. `Project::scopeVisibleTo()` expresses what each role may list.

**Decisions.**

- *Examiners are supervisors with `can_examine = true` and
  `max_supervisees = 0`.* They need a `staff_no` and expertise tags; giving
  them a second profile table would duplicate all of it for one flag.
- *One capacity guard.* `SupervisorProfile::hasCapacity()` is the single check
  every assignment path consults. Spreading that rule across controllers is
  how a system ends up with a supervisor holding 14 students.
- *Pairings are ended, never deleted.* `SupervisionAssignment::end()` sets
  `is_active = false` and stamps `ended_at`, so "who supervised whom, and when"
  remains answerable.
- *`suggestSupervisors()`* ranks by expertise overlap against remaining
  capacity — the coordinator still decides, but starts from a shortlist rather
  than an alphabetical list of eight names.
- *Co-supervisors carry 30% responsibility.* `responsibility_percent` is
  recorded per assignment so a weighted aggregation can honour it.

**Screens.** `pages/assignments/AssignmentPage.jsx` — the unassigned queue
beside a live capacity panel. A supervisor at capacity is not offered in the
picker at all, so the coordinator cannot select a pairing the server would
reject. `pages/account/ProfilePage.jsx` renders enrolment and supervision
fields as read-only, because those are coordinator-owned.

`pages/admin/UserListPage.jsx` is the account-lifecycle screen. It offers
suspend, reset-password and unlock, and deliberately does **not** offer delete
or a password field: `DELETE /users/{id}` soft-deletes, which hides a
student's history from the archive and the audit trail, while an admin setting
a password breaks the attribution story — a reset must be traceable to the
account holder. Suspending yourself is blocked in the UI even though the API
permits it, because it would lock you out of the screen that undoes it.

---

## Module 3 — Project and milestone management

**Requirement.** PSM1/PSM2 tracking, project registration (title, abstract,
category), milestone templates per category, submission with file uploads,
automatic status updates.

**Built.** `projects` with `project_members` for group work. Templates are
versioned and resolved by category. `MilestoneService::instantiateFor()`
creates the chain at registration from the template. `transitionTo()` is the
single validated path for status changes. Uploads are stored as revisions.

**Decisions.**

- *Templates are versioned, not edited.* Next semester's wording change creates
  version 2 and leaves version 1 intact — an in-flight cohort keeps the
  deadlines and weights it was told about.
- *The milestone chain is defined once*, in
  `ProjectCategory::defaultMilestones()`. The seeder and the runtime both read
  it, so they cannot drift.
- *Deadlines are dates, not datetimes.* An academic deadline is "end of
  Friday"; the cut-off hour is policy applied by `effectiveDueAt()`, not a
  per-row value.
- *Files are never hard-deleted.* A new revision sets `is_current = false` on
  the previous one, so Module 7 can prove what was submitted and when.
- *Milestones open on approval of the predecessor.* The chain enforces
  sequence rather than trusting students to work in order.
- *The final report cannot be submitted before the build is approved*, because
  `Pending` milestones reject submissions outright.
- *`overrideDeadline()` requires a reason.* This is the single most contested
  administrative action in the system, so it is impossible to move a deadline
  silently.
- *Late windows are opt-in per milestone* (`allow_late_submission`,
  `late_window_days`) rather than a global toggle, because a proposal deadline
  and a final-report deadline deserve different tolerance.

**Screens.** `pages/projects/` — list (filter-driven, since every role arrives
wanting a specific slice), detail (progress, people, abstract, then milestones
/ assessments / grade as tabs), and register. The registration form explains
what a category *means* rather than listing four bare options, because the
category fixes the milestone set for the whole project.
`pages/milestones/MilestoneDetailPage.jsx` carries both sides: a student stages
files and submits, a reviewer approves or requests a revision. Which controls
are live is derived from `MilestoneStatus`, so the UI never offers an illegal
transition.

---

## Module 4 — Evaluation and rubric grading

**Requirement.** Rubric-based forms with weighted components, online marks
submission by supervisors and examiners, automatic final grade computation.

**Built.** Three-level rubrics (template → component → criterion) scoped to
category, PSM part, and assessor type. `EvaluationService` creates forms,
records marks, handles submission and moderation, and computes the aggregate.
`GradeScheme` stores the per-project weighting.

**Decisions.**

- *Rubric snapshots.* `evaluations.rubric_snapshot` is written once, at form
  creation. Renaming or reweighting a criterion later cannot change what a
  historical mark means. Without this, a grade appeal becomes unwinnable.
- *Two weight levels, both enforced.* Components total 100; criteria within a
  component total 100. The seeder asserts both and refuses to run otherwise.
- *`max_marks` is stored, not derived.* A criterion's cap is computed at seed
  time from the template total and the two weights, then persisted, so a later
  template edit cannot change the cap an old assessment was marked against.
- *Grades are rescaled when weights do not total 100.* If only an examiner
  marked and supervisors carry 60%, a naive weighted sum would cap the result
  at 40. Rescaling by the weight actually present keeps the number on the
  0–100 scale it claims to be on.
- *The milestone blend is a gate, not a share.* Default 20%, so a student who
  never submitted cannot score 100 overall. Configurable to 0 for
  marks-only grading.
- *`computation_breakdown` persists the arithmetic.* Every result can be
  explained to an examiner or appealed by a student, because the system
  records how it got there rather than only where it landed.
- *Moderation preserves the original.* `raw_score` survives and
  `moderation_delta` records the movement, with a mandatory reason.
- *Extremes are trimmed only at ≥3 assessors.* With two marks, discarding both
  extremes leaves nothing.
- *Conflict of interest is declarable* (`coi_declaration`, `recused` status),
  so a supervisor who cannot mark a project is not forced to.

**Screens.** `pages/evaluations/EvaluationFormPage.jsx` is the most intricate
screen in the app: it renders the frozen `rubric_snapshot`, computes a live
weighted running total that matches the server's algorithm, and autosaves 1.5s
after the last edit. A number input is used rather than a slider because marks
are often transcribed from a paper marking sheet. `GradeListPage.jsx` leads
with the pending-release count and offers bulk release, since releasing across
a cohort one at a time is what stops a process being followed.
`RubricListPage.jsx` never offers "edit" — only "new version", because editing
a used template would rewrite historic marks.

---

## Module 5 — Reporting and analytics

**Requirement.** Cohort progress overview, workload statistics, grade
distribution.

**Built.** `ReportingService` provides cohort progress, at-risk students,
supervisor and examiner workload, grade distribution, and a dashboard summary.
Endpoints are restricted to admin and coordinator, with CSV export.

**Decisions.**

- *Milestone progress uses weights with partial credit.* `Reviewed` counts
  0.75 and `Submitted` 0.5 of a milestone's weight, so the progress bar moves
  when work is handed in rather than only when it is approved. Approved counts
  1.0.
- *At-risk is a query, not a flag.* A student is at risk when they hold an
  overdue or rejected milestone. Computing it on read means it cannot go stale.
- *Workload reports show capacity alongside load*, so an overloaded supervisor
  is visible rather than merely a high number.
- *Distribution includes median and standard deviation*, which is what a
  faculty committee actually asks for — an average alone hides a bimodal cohort.
- *Exports are CSV.* Excel and PDF are available, but CSV opens anywhere and
  survives a version change.

**Screens.** `pages/reports/ReportPage.jsx` — a dashboard rather than a set of
tables: six headline figures, then grade distribution, milestone timeliness,
supervision load, and a category breakdown. CSV exports open the API URL
directly so the browser streams the file instead of axios buffering a large
export in memory. `pages/coordinator/CoordinatorDashboard.jsx` condenses the
same data into a bottleneck view: which milestones the cohort is collectively
stuck on.

---

## Module 6 — Notification and reminder system

**Requirement.** Email and in-app notifications, deadline reminders, status
change alerts.

**Built.** One `NotificationDispatcher` resolves channels per
`NotificationType`; callers pass a type and payload. 17 notification types
covering milestone, evaluation, grade, assignment, and leaderboard events.
Reminders run hourly from `routes/console.php`.

**Decisions.**

- *Idempotency by database constraint.* `reminder_dispatches` is unique on
  `(milestone_id, user_id, days_before, notification_type)`. The job can be
  retried after a partial failure without double-sending. This is the single
  most important detail in the module.
- *`REMINDER_DAYS_BEFORE` is configuration* (default 7, 3, 1), not code.
- *Extensions genuinely move reminders.* Deadline scopes use
  `DATE(COALESCE(extended_until, due_at))`, so a granted extension moves the
  reminder rather than only the displayed date — a mismatch students notice
  immediately and reasonably resent.
- *Per-user, per-type preferences.* `notification_preferences` and
  `wantsNotification()` mean an opt-out is honoured; and a suspended account is
  never mailed.
- *The dispatcher never wakes an inactive account* — `shouldReceive()` checks
  status before channel preference.

**Screens.** `pages/account/NotificationPage.jsx` groups by read state rather
than by type, because the only decision a reader makes is "is there something I
still need to deal with". Each row links straight to its subject, so the list
works as a to-do queue. The unread count in the sidebar is fetched on pathname
change rather than polled, which keeps a quiet tab quiet.

---

## Module 7 — Archive and audit log

**Requirement.** Central repository of past projects, full audit trail,
search and retrieval.

**Built.** `audit_logs` (append-only) and `archived_projects` (fully
denormalised). `AuditLogger` writes precise entries from services;
`RecordAuditTrail` middleware is the safety net. `ArchiveService` handles
archival, search, and restore.

**Decisions.**

- *Actor name and role are snapshotted.* `actor_name` / `actor_role` are copied
  onto each row, so deleting a user does not orphan the trail that names them.
- *Secrets are scrubbed* per `psm.audit.excluded_fields` before writing.
- *Field-level diffs, not whole-row dumps.* `changes` holds what actually
  changed, which is what an auditor reads.
- *A second, narrative layer.* `audit_logs` answers "what did this *user* do";
  `submission_events` answers "what happened to this *milestone*". Students and
  supervisors read the second, auditors the first.
- *Archives are denormalised deliberately.* The row must stay readable for
  years after users, rubrics, and grades are gone, so it copies the rosters,
  milestone summary, grade breakdown, and document manifest at archive time.
- *Only released grades may be archived.* A provisional mark would become
  permanently wrong the moment it changed.
- *`UPDATED_AT = null` on the audit model* — a trailing `updated_at` column
  invites someone to update a row "just to fix a typo".
- *Retention is 7 years by default*, matching typical institutional
  requirements, and pruned by a scheduled command.

**Screens.** `pages/archive/AuditLogPage.jsx` renders before/after diffs inline
— "edited marks" is useless without the values — and surfaces flagged events
with their own filter rather than burying them. `ArchivePage.jsx` is
search-first (title, abstract, keywords, student) with session/category/part
filters. `ArchiveDetailPage.jsx` reads only snapshot columns, never live joins,
which is what makes a record quotable years later.

---

## Module 8 — Recognition and leaderboard (public)

**Requirement.** Pixel-it winner display, aggregate marks to rank projects,
Top 3 configurable, publicly accessible with no login.

**Built.** `LeaderboardService` builds and publishes boards.
`leaderboard_entries` holds the frozen snapshot. Public routes are registered
before any auth middleware and rate-limited.

**Decisions.**

- *Two-phase: build then publish.* `build()` writes a draft that staff can
  preview; `publish()` flips it live. `build()` refuses to run on a published
  board, so a live ranking never changes underneath a visitor.
- *The public page reads frozen columns only.* A published ranking cannot drift
  because a late moderation changed a grade — the ranking is a snapshot, not a
  view.
- *Privacy is enforced at write time.* The seeder and publisher write rosters
  containing only `{name, student_id}` and `{name}`. `publicEntry()` then
  whitelists every field explicitly. The public payload is assembled, never
  serialised from a model, so adding a column to `projects` cannot leak it.
- *Eligibility requires ≥2 assessors.* A single marker is not enough evidence
  to rank a student publicly. Configurable via `LEADERBOARD_MIN_ASSESSORS`.
- *Consent is honoured.* `leaderboard_opt_out` on the project removes it from
  every board. The seeder exercises this path with real data (every 11th
  student) rather than leaving it to a unit test.
- *The podium is Top N, with the rest as runners-up*, so the page is not
  empty when a cohort is large.
- *Rate-limited at 60/min.* There is no authenticated user to throttle
  against, so the limit is per-IP and deliberately modest.
- *`require_approval` means publishing needs coordinator or admin rights*, so a
  board cannot go live by accident.
- *The module can be switched off entirely* via
  `leaderboard_settings.module_enabled` — useful if a faculty wants the feature
  built but not yet announced.

---

## Cross-module design rules

These are enforced consistently and are the reason the system hangs together:

1. **Business rules live in services.** No controller computes a grade, and no
   model decides whether a transition is legal.
2. **Every status change to a milestone goes through one validated method.**
   This is what makes the audit trail trustworthy rather than merely present.
3. **Enums are the vocabulary.** Adding a status or role is a one-file change
   and `match` expressions fail loudly if a consumer is not updated.
4. **Authorisation is two-layered.** `role:` middleware fails fast; policies
   are authoritative and per-resource.
5. **Visibility is enforced at query level** via scopes, not by filtering
   results after fetching them.
6. **Historical records are frozen at the moment they are created** — rubric
   snapshots, published leaderboards, archived projects. The past does not
   change because the present did.
7. **Nothing is hard-deleted where it carries history.** Supervision
   assignments end, submission files are superseded, users are soft-deleted.
8. **Seeded data is produced by the real services**, so a demo cannot show a
   state the running system could not have reached.
9. **One file owns the API contract.** Every network call in the SPA goes
   through `frontend/src/api/endpoints.js`, and its method names follow
   `routes/api.php` so a route and its client are easy to match up. A screen
   never touches Axios directly, and `frontend/tools/check_frontend.py` fails
   if a page calls a method that does not exist.
10. **The public surface reads frozen data only.** The no-login leaderboard is
    structurally incapable of reaching a draft or an unmoderated mark, because
    it queries `leaderboard_entries` rather than joining back to live tables.
11. **Shared primitives accept semantic tone names, not raw Tailwind classes.**
    `Badge`, `StatCard` and `ProgressBar` all resolve `tone="danger"` through
    an internal map. A caller may still pass a class string for a genuine
    one-off, but the named form is the default because it keeps a "danger"
    badge identical in every corner of the app. Passing a class where a name
    is expected fails silently — the prop ends up as an invalid class and the
    element renders unstyled — so prefer the names.
