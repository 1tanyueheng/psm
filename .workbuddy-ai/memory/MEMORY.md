# PSM System — project notes

Postgraduate/FYP project management system. **Laravel 11 API + React (Vite) SPA**,
run as a Docker stack (`psm-app`, `psm-db` MySQL 8, `psm-mailpit`, `psm-frontend`).

## Environment

- **No PHP on the host.** Everything runs through the containers:
  `docker exec psm-app php artisan ...`, `docker exec psm-db mysql ...`.
- Ports: API `:8000`, SPA `:5173`, phpMyAdmin `:8081`, Mailpit `:8025`.
- DB: `psm` / `secret` @ `psm_system`; root is `root` / `root`. Every seeded
  account uses the password `password`.
- `backend/` is bind-mounted into `psm-app`, so edits are live — no rebuild.
- Migrations run **at container start** (`docker/supervisord.conf` →
  `[program:migrate]`). With `RUN_MIGRATIONS=true` that is
  **`migrate:fresh --seed`** — a restart wipes and reseeds the database.
- **No test suite** (`backend/tests` does not exist). Verify behaviour with a
  rolled-back transaction harness and the two static checkers:
  `python backend/tools/check_seeders.py`,
  `python frontend/tools/check_frontend.py`. Both are currently clean.

## Registration flow (Module 3) — current architecture

```
Lampiran A   three candidate titles   student → supervisor
             the supervisor acknowledges → pairing registered, agreed title fixed
Lampiran B   register the agreed title → project created, milestone chain built
Proposal     the student files the proposal; the SEATED PANEL rules
milestone    ├ approved            → activateNext() opens the rest of the chain
             ├ conditional_approve → student files Lampiran C → approved
             └ rejected            → student changes the title (projects.title) → re-decide
```

- **The title is decided at the proposal milestone** (sequence 1, `code = 'proposal'`),
  by the panel. Nothing is decided before the project exists. There is **no
  title-defence sitting** and **no coordinator approval step** — both were removed.
- The panel's verdict **is** the milestone's `status`: `approved` /
  `conditional_approve` / `rejected`. Reason in `review_comment`, author in
  `reviewed_by`. `MilestoneStatus::Conditional` exists for the middle case.
- `app/Services/ProposalReviewService` owns it: `recordDecision()`,
  `fileLampiranC()`, `changeTitle()`, `panel()`. Routes:
  `POST /milestones/{id}/title-decision|/lampiran-c|/change-title`.
- `MilestoneService::approve()` / `requestRevision()` **refuse** the proposal
  milestone — use the decision endpoint.
- **`supervisor_agreements` carries no review.** `pending_supervisor → approved`;
  acknowledgement is the gate for Lampiran B. `projects.agreement_id` is the link
  (written by `submitTitleProposal()`; it used to be metadata-only, which left the
  relation null everywhere).
- **`supervisor_agreements.academic_semester_id` records the term**, and Lampiran B
  copies it onto the project. `session` is only the session string ("2025/2026")
  and the faculty runs **two terms per session**, so it cannot identify the term.
- **The examiner pair is anchored on the student** (`examiner_assignments.student_profile_id`,
  `project_id` stamped by Lampiran B) because the panel must exist before the project.
- **There is NO `examiner` role** — merged into `supervisor` (2026-10-08). Being an
  examiner is a *seating*, modelled by `examiner_assignments`. `AssessorType` still
  separates the *forms* (supervisor E/G/H vs examiner I/J) — that is a property of
  the evaluation, not the account. So panel checks key on the **allocation**, never
  on the role: `MilestonePolicy::isSeatedPanel()`, `ProjectPolicy::view()` and
  `Project::scopeVisibleTo()` must agree.
- `ExaminerPairingService::eligibleExaminers()` = **every active supervisor**.
  Capacity (`max_supervisees`) is deliberately not consulted — it limits
  supervising, not examining.
- The panel view is `/panel/dashboard` (`pages/panel/PanelWorkPage.jsx`), reachable
  by the same role as `/supervisor/dashboard`.
- **The coordinator seats a panel at `/assignments/panels`**
  (`pages/assignments/PanelAssignmentPage.jsx`) — a *pair against a student*, first
  id is the **chair**, own supervisor excluded. Endpoints:
  `GET|POST /assignments/students/{student}/panel`, plus
  `GET /assignments/panel-matching` for the cohort-wide matching table. Before
  this there was **no coordinator-side view of the panel at all** — the endpoints
  existed, no page called them, so the allocation looked like it had vanished.
- **A panel member finds their proposals at `GET /panel/proposals`**
  (`MilestoneController::panelProposals`), rendered on `/panel/dashboard`. The
  milestone screen had the decision form but nothing pointed a panel member at it.
- **A route cache exists** (`bootstrap/cache/routes-v7.php`) and survives edits to
  `routes/api.php`: a new route is absent from `route:list` and a changed
  `role:` middleware keeps its old value. **Run `php artisan route:clear` after any
  route change** — otherwise you verify nothing and ship the same bug.
- **`AssignmentService::assignExaminerToStudent()` is the single enforcement point**
  for the examiner rules (conflict of interest, duplicate seat, panel cap), anchored
  on the *student* because the panel must exist before the project. The
  project-shaped `assignExaminer()` **delegates** to it.
  `ExaminerPairingService::assignPair()` retires the previous panel first, so
  re-pairing replaces rather than being refused by the cap.
- `SupervisorAgreement::canSubmitLampiranB()` / `lampiranBBlockedReason()` are the
  single source for the Lampiran B gate and its wording.
- `SupervisorAgreementResource` is the payload both registration pages read —
  `student.name` / `supervisor.name`, and `agreed_title` (not `confirmed_title`).
- **PSM 1 → PSM 2**: one project across two continuous terms on one title. Only
  PSM 1 registers a title; PSM 2 is reached by `ProgressionService` (coordinator
  action). No second Lampiran A, no second review.

## Demo accounts (`RegistrationDemoSeeder`, password `password`)

`rd.supervisor@psm.test` (the supervisor on every demo agreement), and one
student per proposal state:

| account | proposal milestone state |
|---|---|
| `rd.student1@psm.test` | `approved` — the rest of the chain is open |
| `rd.student2@psm.test` | `conditional_approve` — owes Lampiran C |
| `rd.student3@psm.test` | `rejected` — the title must be changed |
| `rd.student4@psm.test` | `submitted` — **awaiting the panel's decision** |
| `rd.fresh@psm.test` | clean slate: nothing filed, walk from Lampiran A |

`rd.student4` exists specifically so the decision form has something to act on
after a fresh seed. To demo the decision, sign in as a **seated panel member** —
the panel is `supervisor@psm.test` (chair) + `sup-002@psm.test` (member), both
role `supervisor`, which is why the panel checks key on the allocation and not the
role (see the gotcha below).

## Gotchas

- `php -l` does **not** autoload — a file importing a deleted class lints clean and
  dies at runtime. After any rename/delete, grep for the old names and run
  `php artisan route:list`.
- `supervisor_agreements.session` stores the **session string** ("2025/2026"),
  never the term's display name. `Project::nextCode()` counts within
  `(psm_part, academic_session)`.
- A relation with a baked-in `orderBy` silently wins over an appended
  `orderByDesc` — use `reorder()`.
- The `psm` MySQL user cannot create databases; use root for a scratch DB.
- A policy that gates on `role` when the capability actually comes from an
  **allocation** silently locks people out. Verify with the *seeded* fixtures, not
  a hand-picked actor.
- **`projects.academic_semester_id` must be set by every creation path.** Almost
  every screen scopes by term, and `GET /projects` defaults to the *current* term
  — so a project with a null term matches no term and is invisible to its own
  student. `ProjectSeeder` force-fills it, which is exactly how a missing writer
  stays hidden.
- **`AcademicSemester::isClosed()` is `closed_at !== null || ! is_active`** — so a
  merely *inactive* term also reads as closed. Term lifecycle: `close()` →
  `reopen()` (`POST /semesters/{id}/reopen`, restores `is_active` and clears
  `closed_at`, but **leaves registration closed** on purpose). One live term per
  faculty, enforced by `deactivateOthers()`.
- **Every lifecycle action needs a counterpart.** `close` without `reopen` is the
  same shape as the earlier closed-defence-sitting bug: the UI hides the control
  rather than failing loudly, so the dead end is invisible until a user reports it.
- **An academic account needs a `supervisor_profiles` row** (for `staff_no`,
  capacity and expertise) — there is no separate examiner profile, and no examiner
  role. `UserController::store()` creates it for `Role::Supervisor` with
  `staff_no` written to **both** `users.staff_id` and the profile.
- **New accounts start on `config('psm.default_user_password')`** = `password`
  (`PSM_DEFAULT_PASSWORD`), with **no invite email** and
  **`must_change_password = false`** — the admin only holds the address, so the
  holder resets it from the sign-in screen. Flagging `must_change_password` would
  instead bar them from everything but the change form (`ForcePasswordChange` → 409).
- **`UserController::destroy()` anonymises the email** (`deleted+{id}@removed.local`)
  before soft-deleting, so the address is freed. `users.email` is still uniquely
  constrained at the DB level, so the create path guards a trashed match explicitly.
- The admin **add-user panel** lives on `/users` (`UserListPage.jsx`): role-first,
  section swaps, 422s mapped per field. `POST /api/users` is **admin-only**.
- **`supervisor_profiles.can_examine` no longer exists** (dropped 2026-10-08) — it
  was never read by any query.
