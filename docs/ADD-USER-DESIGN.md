# Design — Admin: adding a user with any role

**Status:** design, not built. The API largely exists; the admin screen does not,
and three gaps in the API need closing first.

---

## 1. What exists today

`POST /api/users` (`UserController::store`) is already role-aware:

| Already done | Detail |
|---|---|
| Authorisation | `UserPolicy::create` → **admin only**; route is behind `middleware('role:admin')` |
| Validation | `role` ∈ `Role::values()`; student/supervisor fields via `required_if` |
| Profile creation | `match ($user->role)` → `StudentProfile`, `SupervisorProfile` (supervisor only), `CoordinatorScope` |
| Credentials | random 32-char password, `must_change_password = true`, then `Password::sendResetLink()` |
| Audit | `AuditAction::UserCreated` with the created attributes |
| Response | `201` + `UserResource` |

**What is missing is the screen.** `UserListPage.jsx` is a filter-and-table view
(345 lines) with no create control — no `userApi.create` call anywhere. An admin
has no way to add a user through the UI at all.

So this design is mostly *UI*, plus three API gaps that the UI would otherwise
expose as bugs.

---

## 2. The role matrix

Every account is one `users` row plus an optional role profile. The profile is
what the rest of the system reads, so "which fields" is really "which profile".

| Field | Student | Supervisor | Coordinator | Admin |
|---|:--:|:--:|:--:|:--:|
| `name`, `email` | ● | ● | ● | ● |
| `phone`, `department` | ○ | ○ | ○ | ○ |
| `student_id`, `program`, `batch` | **●** | — | — | — |
| `program_code`, `faculty`, `thesis_title` | ○ | — | — | — |
| `academic_semester_id` | ○ *(defaults to the active term)* | — | — | — |
| `staff_no` (profile) | — | **●** | — | — |
| `academic_title` | — | ○ | — | — |
| `max_supervisees` | — | ○ | — | — |
| `expertise_area_ids` | — | ○ | — | — |
| `staff_id` (users) | — | *derived from `staff_no`* | ○ | ○ |
| `scopes[]` (batch/program) | — | — | ○ | — |

**● required · ○ optional · — not applicable**

**There is no Examiner column.** Sitting on a panel is a seating, not a role —
see §2b.

---

## 2a. Decisions taken

Three questions were settled before building:

- **`can_examine` is dropped, not wired up** (G4 → option b). Whether someone may
  examine is already expressed by their role and by the coordinator's seating
  decision; a third, unenforced switch only added a way to be surprised. Done —
  `2026_10_08_000003_drop_can_examine_from_supervisor_profiles`.
- **New accounts start on the default password `password`** (G5). The admin is
  entering an address they already hold — usually the student's institutional
  email — so there is nothing to deliver, and a random secret would leave the
  account unreachable behind a 201 that looks like success. The holder resets it
  themselves from the sign-in screen when they want to. Done — see §3 G5.
- **`examiner` is merged into `supervisor`** — see §2b.

---

## 2b. One staff role: the examiner merge

Being an examiner is a **seating**, not a job. A member of academic staff
supervises their own students and may additionally be appointed to the panel of
someone else's — and `examiner_assignments` already models exactly that (student,
project, panel role, pair). A separate role duplicated it and then had to be
reconciled with it:

- the panel pool had to accept `examiner` **or** `supervisor`, so the two roles
  were already interchangeable for the only thing the distinction gated;
- a supervisor appointed to a panel **could not read the project they were
  appointed to examine**, because the visibility rules branched on the role.

One staff role, `supervisor`, now covers both. That also removed the original G1
("an examiner created by an admin gets no profile") outright — there is no
examiner account to create.

What stayed: **`AssessorType`** still separates the *forms* (supervisor E/G/H vs
examiner I/J). Which one applies is a property of the evaluation, not the
account — the same person fills the supervisor's form for their own student and
the examiner's form for a panel student.

Changed: `Role` (case removed), `AssessorType::Examiner->role()`,
`ExaminerPairingService::eligibleExaminers()` (now every active supervisor),
`AssignmentService::assignExaminer()`, `ProjectPolicy::view`,
`Project::scopeVisibleTo`, `FinalGradePolicy`, `ReportingService::examinerWorkload`,
`UserResource`, `UserController::options`, `config/psm.php`, `UserSeeder`,
`permissions.js`, `AppLayout`, `App.jsx`, `UserListPage`, `ProfilePage`.

Data migration: `2026_10_08_000004_merge_the_examiner_role_into_supervisor`
converts existing `role = 'examiner'` rows and gives those profiles the configured
supervision capacity, since they were seeded as pure examiners at
`max_supervisees = 0` and would otherwise never be able to take a student.

The panel view moved from `/examiner/dashboard` to `/panel/dashboard`
(`pages/panel/PanelWorkPage.jsx`) and is reachable by the same role as
`/supervisor/dashboard` — one account shows both "my supervisees" and "my panel
work".

---

## 3. Gaps to close before building the UI

### G1 — Examiners get no profile — **resolved: the role was merged away**

`store()` covered `Student`, `Supervisor`, `Coordinator`, then
`default => null`, so `Role::Examiner` fell through and an examiner created
through the admin API had **no `staff_no`, no `can_examine`, no expertise** —
while every seeded examiner did. The seeder-hides-the-bug pattern again.

Rather than add the missing branch, the role itself was removed (§2b): there is no
examiner account to create, so there is no profile to forget. The seeded cohort
that used to hold it is now ordinary academic staff with supervision capacity.

### G2 — A new student has no enrolment term — **done**

`store()` never sets `student_profiles.academic_semester_id` (nor
`current_semester`). That column is not decorative:

- `AssignmentService::assignSupervisor()` → `assertPartCapacity($supervisor, $part, $student->academic_semester_id)` — the capacity gate is scoped by it, so a null term measures the wrong bucket (and the memory records a previous bug in exactly this area).
- `StudentProfile::scopeInSemesterPart` / `forSemester` and `SemesterService` filter on it.

**Done:** `academic_semester_id` defaults to `AcademicSemester::current()?->id`
and can be overridden from the form. `current_semester` is still left alone — it
is a display figure for the student's own progress, not a scoping column.

### G3 — A soft-deleted user blocks their email — **was already handled; hardened**

**This diagnosis was wrong.** The concern was that `User` uses `SoftDeletes`
while validation is `unique:users,email`, which counts trashed rows — so
re-adding a deleted person would fail with "already taken" for a row the admin
cannot see.

It does not, because `UserController::destroy()` **anonymises the address first**:

```php
$user->forceFill(['email' => "deleted+{$user->id}@removed.local", ...])->save();
$user->delete();
```

The original address is freed, so re-adding works. Verified: create → delete →
re-add the same address returns 201.

The change was kept anyway, as a guard rather than a fix, because
`users_email_unique` **is** a real database constraint and a trashed row *can*
retain an address — anything deleted outside this endpoint, or rows deleted before
the anonymisation existed. In that case a plain `unique` rule would either block
the admin with an unfixable message or, with the rule relaxed, fail the insert on
the constraint with a 500. So:

- the rule is now `Rule::unique('users','email')->whereNull('deleted_at')`;
- a trashed match is caught explicitly and refused with a message that names the
  remedy — *"A deleted account already uses this address (deleted 2026-10-05).
  Restore that account instead of creating a second one."*

### G4 — `can_examine` was decorative — **resolved: dropped**

`can_examine` was stored, cast, exposed by `SupervisorProfileResource` and shown
on the profile page — but **no query read it**. `ExaminerPairingService::eligibleExaminers()`
selected by role alone (`whereIn('role', ['examiner','supervisor'])`), so an
examiner with `can_examine = false` was still seatable. The flag promised a
restriction the system did not enforce.

Dropped rather than wired up: whether someone may examine is already expressed by
their **role** and by whether the coordinator seats them, so the column only added
a way to be surprised.

- `2026_10_08_000003_drop_can_examine_from_supervisor_profiles` (round-trip tested).
- Removed from `SupervisorProfile` (fillable + cast), `SupervisorProfileResource`,
  `ProfilePage.jsx` and `UserSeeder`.

### G5 — Password delivery — **resolved: a known default**

The old flow generated a random 32-character password and emailed a set-password
link. The password was never shown, so with no mailer configured the account was
created **and unusable**, behind a 201 that looked like success.

Now the account starts on `psm.default_user_password` (**`password`** by default,
overridable with `PSM_DEFAULT_PASSWORD`) and **no email is sent**:

- Nothing to deliver — the admin is entering an address they already hold.
- `must_change_password` is **false**. Flagging it would bar the new account from
  every route but the change form (`ForcePasswordChange` answers 409), which is the
  opposite of "sign in and look around".
- The holder resets it themselves via `POST /api/auth/forgot-password` when they
  want to — which is the point of the default being well known rather than secret.

Trade-off, stated plainly: anyone who knows an address can sign in until that
person changes their password. That is the cost of "no invite email", and it is
acceptable here because the addresses are institutional and the data is not
sensitive to an unauthenticated guess — but if the faculty wants first-login
rotation, flip `must_change_password` back to `true` in `UserController::store()`.

Verified over HTTP: a created student signs in with `password` and reaches
`GET /api/projects` (200, not 409).

### G6 — Two staff identifiers — **done**

`users.staff_id` and `supervisor_profiles.staff_no` are both staff numbers, set to
the same value by the seeder, and the form would have to ask for both. Confusing
for the admin and a chance for them to disagree.

**Done:** the form asks for `staff_no` once for academic staff, and the controller
writes it to both columns. Coordinators and admins have no profile, so their
`staff_id` stands alone. Verified: a supervisor created with `staff_no = GAP-001`
lands as `users.staff_id = GAP-001` **and** `supervisor_profiles.staff_no = GAP-001`.

---

## 4. API contract

```
POST /api/users            (admin)
```

**Common**

```jsonc
{
  "role": "student|supervisor|examiner|coordinator|admin",
  "name": "…",                 // required
  "email": "…",                // required, unique among live users
  "phone": "…",                // optional
  "department": "…",           // optional
  "status": "active"           // optional, default "active"
}
```

No password field. The account starts on `psm.default_user_password` (G5) — the
admin never chooses it, and there is no invite flag because nothing is sent.

**Per role — required**

| role | extra required | extra optional |
|---|---|---|
| student | `student_id`, `program`, `batch` | `program_code`, `faculty`, `thesis_title`, `academic_semester_id` |
| supervisor | `staff_no` | `academic_title`, `max_supervisees`, `max_supervisees_psm1`, `max_supervisees_psm2`, `expertise_area_ids[]` |
| examiner | `staff_no` | `academic_title`, `expertise_area_ids[]` |
| coordinator | — | `scopes[]{batch, program}` |
| admin | — | — |

**Responses**

| Code | When | Body |
|---|---|---|
| 201 | created | `UserResource` |
| 403 | not an admin | standard envelope |
| 422 | validation, including a trashed-email match | `errors` keyed by field |

Validation messages should name the role's requirement — e.g.
`"A supervisor needs a staff number (staff_no)."` — rather than Laravel's generic
`required_if` text, which the current `store()` relies on.

**No new follow-up endpoint is needed.** `POST /api/users/{user}/reset-password`
already exists (admin), which is the "send them a link" action when someone loses
access. It sits alongside *deactivate*, *reactivate* and *unlock*.

---

## 5. UI design

`/users`, admin-only. The UI kit has no modal (`ui.jsx` offers `Card`,
`SegmentedControl`, `Field`, `FieldErrors`, `DataTable`), so follow the pattern
`SemesterListPage` already uses: an **inline panel** toggled by a button in the
`PageHeader`.

```
┌ Add user ───────────────────────────────────────────────┐
│ Role   [ Student | Supervisor | Coordinator | Admin ]              ← Select
│                                                          │
│ ── Account ──────────────────────────────────────────    │
│ Full name *        Email *                               │
│ Phone              Department                            │
│                                                          │
│ ── Student ──────────────────────────────────────────    │   ← section swaps with role
│ Matric no *        Programme *                           │
│ Batch *            Programme code                        │
│ Faculty                                                  │
│                                                          │
│ ── Supervisor ───────────────────────────────────────    │
│ Staff number *     Academic title                        │
│ Supervision capacity                                     │
│                                                          │
│ ── Sign-in ───────────────────────────────────────────    │
│ The account starts on the system default password.        │
│ Tell the holder to change it from the sign-in screen.     │
│                                                          │
│                          [ Cancel ]  [ Create account ]  │
└──────────────────────────────────────────────────────────┘
```

Behaviour:

- **Role first.** The section below it swaps, so the admin is never asked for a
  matric number while creating a supervisor. Switching roles resets the
  role-specific fields, so a value typed for one role cannot be submitted for
  another.
- **One staff number.** For academic staff the field is labelled *"Staff number"*;
  the two columns behind it are an implementation detail (G6).
- **Server is authoritative.** Client-side `required` mirrors the matrix for
  feedback only; 422 errors are mapped back per field with `FieldErrors`, so the
  screen cannot claim a field is fine when the API refused it.
- **After success.** Close the panel, prepend the new row to the table, and show a
  banner naming the address and the starting password — *"Account created for
  aisyah@… It starts on the default password; they can reset it from the sign-in
  screen."* Stating the password in the UI is deliberate: it is not a secret, and
  the admin needs to pass it on.
- **Row action:** *Send password reset* (`POST /api/users/{user}/reset-password`),
  for when someone loses access.

---

## 6. Test plan

Rolled-back transaction (no test suite in this project):

1. One assertion per role that the right profile exists — and for examiner, that
   `can_examine = true` and `max_supervisees = 0` (G1).
2. A student is created with `academic_semester_id` set to the active term (G2).
3. A soft-deleted user's email is reusable (G3).
4. A created account **signs in with the default password** and is not stopped by
   `ForcePasswordChange` — `must_change_password` is false (G5).

Over HTTP:

5. `POST /api/users` as **coordinator → 403** (only admin creates accounts, even
   though coordinators hold `canManageUsers`).
6. As admin → 201 for each role; 422 naming the missing role-specific field.
7. The new account reaches a normal endpoint (`GET /api/projects` → 200), not the
   409 the forced-change middleware returns.

Already checked against the current build: a created student has
`must_change_password: false`, signs in with `password`, and gets 200 from
`GET /api/projects`. Creating an **examiner** currently returns
`supervisor_profile: MISSING` — G1, still open.

Checkers: `python backend/tools/check_seeders.py`,
`python frontend/tools/check_frontend.py`.

---

## 7. Phasing

**Done — Phase 1 is complete.**

| | What shipped |
|---|---|
| §2b | The `examiner` role merged into `supervisor` (enum, migration, policies, pool, reporting, seeders, frontend) |
| G1 | Gone with the merge — there is no examiner account to create |
| G2 | A new student's `academic_semester_id` defaults to the active term |
| G3 | Soft-delete-aware email rule + a named refusal for a trashed match |
| G4 | `can_examine` dropped |
| G5 | Accounts start on `psm.default_user_password` = `password`, no invite email |
| G6 | One staff number, written to both columns |
| §5 | The role-aware create panel on `/users` |

**Phase 2 — remaining polish.**

- A *Restore account* action for a trashed user (the G3 refusal names it; nothing
  offers it yet).
- Surface the enrolment term on the student section of the form, rather than
  relying on the server default.
- Decide whether `ReportingService::examinerWorkload` should list every academic
  staff member or only those with panel allocations — it now lists all, which is
  useful for seating but duplicates part of `supervisorWorkload()`.

**Phase 3 — bulk.** CSV import for a cohort intake. Worth designing separately:
it needs a dry-run/preview step and per-row error reporting, and it is the only
sane way to onboard ~100 students per term.

**Non-goals.** Changing a user's role after creation (a separate, riskier
operation — it strands the old profile); editing another user's profile from this
screen (the profile page owns that); SSO/LDAP.
