# Plan — Correcting the project registration flow

> **Status — the flow has moved on twice since §1.**
>
> §1–§8 describe the **title-defence** design as it was built. §9 removed the
> defence and moved the panel's review onto the Lampiran A agreement. **§10
> supersedes both**: the decision now lives on the project's **proposal
> milestone**, and the agreement is plain paperwork again. Read §10 first; §1–§9
> are kept as the record of how the flow got here.
>
> The current flow is described in `MODULES.md` (Module 3) and `ERD.md`.

## 1. The problem in one line

Lampiran B creates the project, and the title defence is built on top of
projects. The dependency is upside down: a student must currently register a
title *before* they can defend it.

```
Today:     A → approve → B → project created → title defence
Correct:   A → title defence → B → title confirmed → project created
```

---

## 2. The corrected flow

Six steps, in order. Each one is a gate on the next.

### Step 1 — Lampiran A: candidate titles
**Actor:** student → supervisor → coordinator (JKPSM)

The student files **three** candidate titles. The supervisor acknowledges. The
coordinator receives it.

*Unchanged.* Already works: `pending_supervisor → pending_jkpsm → approved`.

### Step 2 — Title defence
**Actor:** panel (two examiners), started by the coordinator

The panel sits with the student and records **one collective decision** against
the candidate titles. The record is the sheet already built — matric no, student,
supervisor, proposed title, accepted title, decision, reason, project type,
project area, comments, both panel names.

**This is the change.** The defence is driven by the **Lampiran A agreement**,
not by a project. It becomes available as soon as Lampiran A is approved.

Three outcomes, which branch:

| Decision | What happens next |
|---|---|
| **Approved** | Go to Step 3. The defended title (or the panel's `accepted_title`) is the title. |
| **Conditional Approve** | Go to Step 2b. |
| **Rejected** | The student takes **Title 2 or Title 3** from Lampiran A back to a re-defence. Loops to Step 2. |

### Step 2b — Lampiran C: corrections *(conditional branch only)*
**Actor:** student → supervisor → coordinator

Lampiran C records the **previous title**, the **new title**, and a table of the
examiners' comments against the correction action taken. When it is accepted,
the new title becomes the confirmed title and the student proceeds to Step 3.

### Step 3 — Lampiran B: register the confirmed title
**Actor:** student → supervisor

The student files the **one** confirmed title, plus project type, the field-of-study
declaration, and the project requirements (software / hardware / technology).

**The project is created here** — as it is today — but now it is created *after*
the defence, carrying the confirmed title.

### Step 4 — Title confirmed
**Actor:** coordinator

The coordinator confirms the registration. The project moves to active and the
supervision, milestones and assessment all begin from it.

---

## 3. What has to change

### 3.1 The defence must hang off the agreement, not the project

`title_defences` currently has a required `project_id`. It already has
`student_profile_id`, so the student is known — but the *agreement* is what the
defence is actually about, and the agreement exists before any project does.

**Migration:**

```
title_defences
  + supervisor_agreement_id   FK, NOT NULL, unique
  ~ project_id                nullable  (set only once Lampiran B creates it)
```

`roster()` changes from querying `Project` to querying `SupervisorAgreement`
where `status = approved` for the sitting's term and part. Everything it currently
derives from the project — student, supervisor, panel — is derivable from the
agreement plus the examiner allocations.

**Consequence to accept:** examiner pairing currently runs over *projects*. If the
defence happens before the project exists, the panel must be paired against the
**student**, not the project. That is a change to `ExaminerPairingService`, and
it is the largest single piece of work in this plan.

**Confirmed:** the same two examiners sit the defence *and* give the final mark.
So the pair is allocated once, against the student, from Lampiran A onward, and
covers both events. That is what makes this change necessary rather than
optional — the pair has to exist before either event, and the project does not.

### 3.2 Lampiran B becomes gated on the defence

`RegistrationService::submitTitleProposal()` currently checks one thing:

```php
if (! $agreement->isApproved()) {
    throw new InvalidArgumentException('Lampiran A must be approved before Lampiran B can be submitted.');
}
```

It must additionally require a defence that has cleared:

- a `title_defences` row exists for this agreement, **and**
- `decision = approved`, **or** `decision = conditional_approve` with Lampiran C accepted

…and the title it carries must match the confirmed title. If the student submits
a different title, refuse — otherwise the defence decision is decorative, which
is exactly the state today.

### 3.3 The confirmed title becomes the source of truth

Today `recordDecision()` writes `accepted_title` onto the defence row and stops.
Nothing reads it. After this change:

```
confirmed title = defence.accepted_title   (when the panel agreed a different one)
                = defence.proposed_title   (otherwise)
                = lampiran_c.new_title     (conditional branch, once accepted)
```

`Project::create()` uses that, not whatever the student types into Lampiran B.
Lampiran B's title field becomes a confirmation of a value already fixed, and a
mismatch is a validation error rather than a silent divergence.

### 3.4 Lampiran C needs to exist

It does not, in any form. Minimum viable:

```
title_defences
  + resolution          enum: pending | corrections_required | cleared | rejected
  + lampiran_c_title    string, nullable   (Tajuk Baharu)
  + lampiran_c_actions  json,   nullable   (the comment/action table)
  + lampiran_c_at       timestamp, nullable
  + lampiran_c_by       FK users, nullable
```

A `conditional_approve` decision sets `resolution = corrections_required`. The
student's supervisor records Lampiran C, which sets `cleared` and fixes the
confirmed title. Until then, Step 3 is blocked.

### 3.5 Re-defence

A `rejected` decision must let the student return with Title 2 or Title 3. The
defence sheet already carries a `proposed_title`; the re-defence is a **new
`title_defences` row** against the same agreement, so the history of both
attempts survives. The current unique key on
`(session, student)` would forbid that and has to become
`(session, student, attempt)` or be dropped in favour of an explicit `attempt`
column.

---

## 4. Files this touches

| Area | File | Change |
|---|---|---|
| Schema | new migration | agreement FK, nullable project, attempt, resolution, Lampiran C fields |
| Defence | `TitleDefenceService` | roster from agreements; re-defence; Lampiran C |
| Defence | `TitleDefence` model | agreement relation, confirmed-title accessor |
| Registration | `RegistrationService::submitTitleProposal` | gate on the defence; use the confirmed title |
| Registration | `RegistrationController` | surface the new refusals |
| Pairing | `ExaminerPairingService` | pair against students, not projects |
| UI | `TitleDefencePage` | roster of agreements; conditional/rejection paths |
| UI | `RegistrationDetailPage` | show defence state; block B until cleared |
| UI | new | Lampiran C form |

---

## 5. Decisions taken

| # | Decision | Effect on the plan |
|---|---|---|
| 1 | **The panel's confirmed title is authoritative.** Lampiran B confirms it; a mismatch is refused. | §3.3 as written. `Project::create()` reads the confirmed title, not the student's entry. |
| 2 | **The same two examiners sit the defence and give the final mark.** The pair is allocated against the student from Lampiran A. | Makes §3.1's pairing change **required**, not optional. It moves from step 7 to step 2 in the build order — the pair must exist before the defence. |
| 3 | **The coordinator confirms after Lampiran B.** | Step 4 is the existing project-approval step, relabelled "Confirm title". No new entity. |
| 4 | **The 22 seeded projects are grandfathered.** | No backfill migration. The new gates apply to agreements created from now on; existing projects keep working and are not forced through a defence they never had. |

Because of decision 4, the Lampiran B gate must read: *if this agreement has a
defence, require it to be cleared; if it has none and the agreement predates the
change, allow it.* Otherwise grandfathering is impossible. The clean way is to
gate on the agreement having been created after the cutover, or on the project
already existing.

## 6. Build order

Each step is independently verifiable.

1. **Schema** — additive migration: agreement FK, nullable project, attempt,
   resolution, Lampiran C fields. No behaviour change.
2. **Pair against the student** — `ExaminerPairingService` allocates to the
   student/agreement. *Promoted to step 2* because the defence needs a panel.
3. **Defence roster from agreements** — the defence works before a project exists.
4. **Confirmed title** — `accepted_title` becomes *the* title, readable everywhere.
5. **Gate Lampiran B** — the defence actually gates registration, with the
   grandfather rule from decision 4.
6. **Lampiran C** — the conditional branch.
7. **Re-defence** — the rejected branch.
8. **Coordinator confirm** — relabel the project approval.

## 7. One question still open

**Does a student file a fresh Lampiran A for PSM 2, and defend again?**

The agreement carries a `psm_part`. If PSM 1 and PSM 2 are separate registrations,
the student runs this whole flow twice — a second Lampiran A with three new
candidate titles, a second defence, a second pair. If PSM 2 inherits the PSM 1
title, the PSM 2 agreement is a formality and there is no second defence.

This matters because it decides whether `supervisor_agreements` needs a unique
key on `(student, psm_part, session)` and whether the defence is once per student
or once per registration. It does not block steps 1–5, which are the same either
way.

---

## 8. Implemented — decisions taken and where they deviate

**Answer to §7: PSM 2 inherits the PSM 1 title.** The defence is once per
student. A PSM 2 agreement is a formality: it files Lampiran B against the title
the PSM 1 defence already confirmed, and runs no second defence.

That answer changes three things in the plan above.

**8.1 `supervisor_agreement_id` is nullable, and is not unique.**

The plan asked for `FK, NOT NULL, unique`. Two reasons that cannot hold:

- *Not unique* — a rejected title is re-defended, and the plan itself (§3.5) asks
  for that as a second row against the same agreement. A unique key on the
  agreement would forbid exactly the history the plan wants to keep. Uniqueness
  moved to `(session, student, attempt)`.
- *Nullable* — a defence recorded under the old project-keyed flow whose project
  was created directly (no Lampiran A) has no agreement to point at. Refusing to
  migrate that row would lose a real record.

Every defence recorded from now on sets it.

**8.2 The Lampiran B gate resolves the *student's* defence, not the agreement's.**

§3.2 says the gate requires "a `title_defences` row … for this agreement". With
PSM 2 inheriting, the PSM 2 agreement has no defence of its own — so the gate
would refuse every PSM 2 registration. Instead
`SupervisorAgreement::resolvedDefence()` returns the agreement's own latest
attempt, and failing that the student's own defence, preferring a cleared one.
That is what makes the PSM 2 inheritance work, and it is the single place the
gate reads from.

**8.3 The grandfather rule is a configured cutover.**

Decision 4 needed something to distinguish "no defence because grandfathered"
from "no defence because not yet defended". `psm.title_defence_gate_from`
(`PSM_DEFENCE_GATE_FROM`) marks the cutover; an agreement created before it is
exempt, as is one that already produced a project. Null means the gate applies to
every agreement — the right state for a fresh install.

**8.4 The panel is anchored on the student.**

As §3.1 anticipated, `ExaminerPairingService` now allocates against the student.
`examiner_assignments` gained `student_profile_id` and `project_id` became
nullable; the project is stamped onto the allocation when Lampiran B creates it,
which is what keeps the evaluation path — which reads `project_id` — unchanged.
`ExaminerPair::projectCount()` now counts *students* covered, since that is the
unit a pair is allocated in.

**8.5 Lampiran C is recorded by the supervisor.**

§3.4 said the supervisor records it; the policy (`recordCorrections`) checks that
the actor is the supervisor on the agreement the defence belongs to, with a
coordinator able to act on their behalf.

**8.6 Two defects surfaced while verifying the flow, and are fixed.**

Running the flow end-to-end (in a rolled-back transaction, since there is no test
suite) found two problems that would have blocked real use:

- *`supervisor_agreements.session` held the term's display name, not the session
  string.* `submitAgreement()` wrote `$semester->name` ("2025/2026 Semester II")
  while `projects.academic_session` and `title_defence_sessions.academic_session`
  hold `$semester->academic_session` ("2025/2026"). `Project::nextCode()` counts
  codes within `(psm_part, academic_session)`, so the first Lampiran B in a term
  that already held projects counted zero and generated a code that was already
  taken — `1062 Duplicate entry 'PSM1-2025-CS2-001'`. Fixed in the service, plus
  migration `2026_10_06_000002` to normalise existing rows.
- *`SupervisorAgreement::resolvedDefence()` returned the first attempt, not the
  latest.* `defences()` carries `orderBy('attempt')`, so appending
  `orderByDesc('attempt')` produced `ORDER BY attempt ASC, attempt DESC` — the
  ascending clause wins. A successful re-defence was still refused for the
  original rejection. Fixed with `reorder()`.

### Files changed

| Area | File |
|---|---|
| Schema | `database/migrations/2026_10_06_000001_rebase_title_defence_on_agreement.php` |
| Schema | `database/migrations/2026_10_06_000002_normalise_agreement_session_labels.php` |
| Enum | `app/Enums/TitleDefenceResolution.php` |
| Defence | `app/Services/TitleDefenceService.php`, `app/Models/TitleDefence.php` |
| Registration | `app/Services/RegistrationService.php`, `app/Http/Controllers/Api/RegistrationController.php` |
| Pairing | `app/Services/ExaminerPairingService.php`, `app/Services/AssignmentService.php`, `app/Http/Controllers/Api/AssignmentController.php` |
| Agreement | `app/Models/SupervisorAgreement.php`, `app/Http/Resources/SupervisorAgreementResource.php` |
| Examiner | `app/Models/ExaminerAssignment.php`, `app/Models/ExaminerPair.php` |
| Policy / routes / config | `app/Policies/TitleDefencePolicy.php`, `routes/api.php`, `config/psm.php` |
| UI | `frontend/src/pages/title-defence/TitleDefencePage.jsx`, `frontend/src/pages/registrations/RegistrationDetailPage.jsx`, `frontend/src/api/endpoints.js` |

### Build order — completed

1. Schema ✔ 2. Pair against the student ✔ 3. Defence roster from agreements ✔
4. Confirmed title ✔ 5. Gate Lampiran B ✔ 6. Lampiran C ✔ 7. Re-defence ✔
8. Coordinator confirm (the existing project approval, relabelled) ✔

---

## 9. The title defence is removed — the review happens on the proposal

§1–§8 built the defence as an event hanging off the Lampiran A agreement. This
section supersedes them: the defence is **gone**, and the panel reviews the
proposal directly.

### Why

The defence and the proposal asked the same two examiners to judge the same three
candidate titles. The defence added a second event — its own table, its own
sitting to schedule, its own roster, its own sheet — and a coordinator step that
only relayed a decision that was not the coordinator's to make. The whole flow is
shorter without it.

```
before:  Lampiran A → supervisor → JKPSM approves → title defence → Lampiran B
after:   Lampiran A → supervisor → panel reviews  → Lampiran B
```

### What changed

- **`title_defences` and `title_defence_sessions` are dropped**, and the
  migrations that created and altered them are deleted — a fresh install does not
  build tables for a module that no longer exists. Their rows are removed from the
  `migrations` table so `migrate:status` does not list files that are not on disk.
- **The review columns move onto `supervisor_agreements`**: `panel_decision`,
  `confirmed_title`, `panel_reason`, `panel_comments`, `project_type`,
  `project_area`, `panel_1_name`, `panel_2_name`, `panel_decided_at/by`, `attempt`,
  and the Lampiran C set (`corrections_title/actions/at/by`) plus `resubmitted_at`.
- **`app/Enums/PanelDecision`** replaces `TitleDefenceResolution`. Three cases:
  `Approved`, `ConditionalApprove`, `Rejected`.
- **`app/Services/ProposalReviewService`** replaces `TitleDefenceService`:
  `review()`, `recordCorrections()`, `resubmit()`, `panel()`, `panelNames()`.
- **No coordinator approval step.** `RegistrationService::approve()` and
  `reject()` are removed with the routes; the supervisor's acknowledgement
  registers the pairing and puts the proposal in front of the panel.
- **The panel is anchored on the student** and must exist before the project does,
  so `examiner_assignments.student_profile_id` is required from allocation onward
  and `project_id` is stamped on when Lampiran B creates the project. This half of
  the earlier migration is *not* defence-specific, so it is kept on its own as
  `2026_10_07_000002_anchor_examiner_assignments_to_students`.
- **The `title-defences` API and the `TitleDefencePage` screen are removed.** The
  review now happens on the registration detail page, where each role already acts
  on the agreement.
- **`config/psm.php`** loses `title_defence_batch_size` and
  `title_defence_gate_from` (no sittings to size, no gate to grandfather). The
  term-close guard now checks open **assessment windows** instead of open sittings.

### The gate

Lampiran B is allowed only when the proposal is `approved` **and** a title is
fixed. The reasoning is unchanged from §3.2/§3.3; only the storage moved:

```
approved             → confirmed_title set → Lampiran B allowed
conditional_approve  → Lampiran C accepted → corrections_title becomes the title
rejected             → resubmit another candidate → back to the supervisor
```

`SupervisorAgreement::canSubmitLampiranB()` and `lampiranBBlockedReason()` are the
single source for the screen and the service, so they cannot disagree.

### Files changed

| Area | File |
|---|---|
| Schema | `database/migrations/2026_10_07_000001_move_proposal_review_onto_agreement.php` |
| Schema | `database/migrations/2026_10_07_000002_anchor_examiner_assignments_to_students.php` |
| Enum | `app/Enums/PanelDecision.php` (replaces `TitleDefenceResolution`) |
| Service | `app/Services/ProposalReviewService.php` (replaces `TitleDefenceService`) |
| Registration | `app/Services/RegistrationService.php`, `app/Http/Controllers/Api/RegistrationController.php` |
| Agreement | `app/Models/SupervisorAgreement.php`, `app/Http/Resources/SupervisorAgreementResource.php` |
| Pairing | `app/Http/Controllers/Api/AssignmentController.php` |
| Semester | `app/Services/SemesterService.php`, `app/Policies/SemesterPolicy.php` |
| Policy / routes / config | `app/Policies/SupervisorAgreementPolicy.php`, `routes/api.php`, `config/psm.php` |
| Seeder | `database/seeders/RegistrationDemoSeeder.php` (replaces `TitleDefenceDemoSeeder`) |
| UI | `frontend/src/pages/registrations/RegistrationDetailPage.jsx`, `RegistrationListPage.jsx`, `frontend/src/api/endpoints.js`, `frontend/src/App.jsx`, `frontend/src/components/AppLayout.jsx` |

Deleted: `app/Models/TitleDefence.php`, `app/Models/TitleDefenceSession.php`,
`app/Services/TitleDefenceService.php`, `app/Enums/TitleDefenceResolution.php`,
`app/Http/Controllers/Api/TitleDefenceController.php`,
`app/Policies/TitleDefencePolicy.php`,
`frontend/src/pages/title-defence/TitleDefencePage.jsx`.

### Verification

No test suite, so the flow was exercised end-to-end inside a rolled-back
transaction — 27 assertions covering the approved, conditional→Lampiran C and
rejected→resubmit branches, the title-mismatch refusal, the panel being seated
before any project exists, and the project being stamped onto the allocations —
plus the seeder (8 assertions, idempotent). Over HTTP: the list/show payloads, the
422 guards, the 404 on the removed `approve` route, and examiner/student scoping
(403 for a non-seated examiner and for a student attempting a review).

---

## 10. The decision moves onto the proposal milestone

§9 put the panel's review on the **Lampiran A agreement**, which still left it
*between* registration and the project: nothing could be registered until the
panel had ruled, and the project — the thing the student actually works on — did
not exist while the title was being decided.

The decision now lives on the project's **proposal milestone**, which is the first
milestone of every chain. Nothing is decided before the project exists.

```
before:  Lampiran A → supervisor → panel reviews the AGREEMENT → Lampiran B → project
after:   Lampiran A → supervisor → Lampiran B → project → PROPOSAL MILESTONE (panel) → …
```

### The three outcomes

The proposal milestone is filed by the student and decided by the **seated panel**.
Its verdict *is* its status, and it is what gates the rest of the chain:

| Panel decision | Milestone status | What happens |
|---|---|---|
| Approved | `approved` | `activateNext()` opens the next chapter — the rest of the chain starts |
| Conditional approve | `conditional_approve` | the student files **Lampiran C**, which approves the milestone |
| Rejected | `rejected` | the student **changes the title**; it is written to `projects.title`, and the milestone reopens for a fresh decision |

`MilestoneStatus::Conditional` is new. It is deliberately distinct from
`rejected`: a rejection means the *title* is wrong, whereas a conditional approval
means the title stands and the work around it needs fixing.

### What changed

- **`milestones` gained the Lampiran C columns** — `lampiran_c_title`,
  `lampiran_c_actions` (JSON), `lampiran_c_at`, `lampiran_c_by`. Nothing else was
  needed: the panel's verdict is the milestone's `status`, and its reason and
  author reuse `review_comment` / `reviewed_at` / `reviewed_by`.
- **`supervisor_agreements` lost the whole review surface** — `panel_decision`,
  `confirmed_title`, `panel_reason`, `panel_comments`, `project_type`,
  `project_area`, `panel_1_name/2_name`, `panel_decided_at/by`, the `corrections_*`
  set, `attempt` and `resubmitted_at`. Rows in `pending_panel` /
  `pending_corrections` were normalised to `approved`, because in both the
  supervisor had already acknowledged.
- **Acknowledgement is now the end of registration.** `acknowledgeBySupervisor()`
  sets `approved` directly; there is no panel phase on the agreement, so Lampiran
  B is gated on the acknowledgement alone.
- **`RegistrationService` writes `projects.agreement_id`.** It used to record the
  agreement only inside `metadata->agreement_id`, which left both
  `Project::agreement()` and `SupervisorAgreement::project()` resolving to null for
  every project registered through Lampiran B. The duplicate-Lampiran-B check now
  reads the column; the migration backfills any row that only had the metadata key.
- **`ProposalReviewService` was repurposed** to own the milestone:
  `recordDecision()`, `fileLampiranC()`, `changeTitle()`, `panel()`.
- **`MilestoneService` refuses the generic review actions on the proposal** —
  `approve()` and `requestRevision()` throw, pointing at the decision endpoint, so
  the milestone cannot be fixed without the title ever being decided.
- **`MilestoneService::activateNext()` was fixed.** It only selected `pending`
  successors, so a chapter whose deadline passed while the proposal was still
  being decided was flipped to `overdue` by the nightly job and then skipped
  forever — the chain jumped over it and its work could never be opened. It now
  selects the next `pending`/`overdue`/`rejected` milestone. The proposal gate
  makes this scenario likely rather than theoretical.
- **Panel checks key on the allocation, not the role.** The panel is drawn from
  the same pool as the supervisors (`ExaminerPairingService` seats `examiner` *or*
  `supervisor`), so requiring `isExaminer()` locked a supervisor off a panel they
  had been appointed to — they could not even read the milestone. Fixed in
  `MilestonePolicy::isSeatedPanel()`, `ProjectPolicy::view()` and
  `Project::scopeVisibleTo()`, which must agree with each other.
- **New endpoints**: `POST /milestones/{id}/title-decision`, `/lampiran-c`,
  `/change-title`. The registration routes for `review`, `corrections` and
  `resubmit` are gone.

### Files changed

| Area | File |
|---|---|
| Schema | `database/migrations/2026_10_08_000001_move_title_decision_onto_proposal_milestone.php` |
| Enum | `app/Enums/MilestoneStatus.php` (adds `Conditional`) |
| Milestone | `app/Models/Milestone.php`, `app/Services/MilestoneService.php`, `app/Http/Controllers/Api/MilestoneController.php`, `app/Http/Resources/MilestoneResource.php` |
| Review | `app/Services/ProposalReviewService.php` |
| Registration | `app/Services/RegistrationService.php`, `app/Http/Controllers/Api/RegistrationController.php`, `app/Models/SupervisorAgreement.php`, `app/Http/Resources/SupervisorAgreementResource.php` |
| Policies | `app/Policies/MilestonePolicy.php`, `app/Policies/ProjectPolicy.php`, `app/Policies/SupervisorAgreementPolicy.php` |
| Visibility | `app/Models/Project.php` (`scopeVisibleTo`) |
| Pairing | `app/Http/Controllers/Api/AssignmentController.php` (the `awaiting_panel` roster is now "has a project, has no panel") |
| Seeder | `database/seeders/RegistrationDemoSeeder.php` — three students, one per outcome |
| Routes | `routes/api.php` |
| UI | `frontend/src/pages/milestones/MilestoneDetailPage.jsx`, `frontend/src/pages/registrations/RegistrationDetailPage.jsx`, `RegistrationListPage.jsx`, `frontend/src/api/endpoints.js`, `frontend/src/lib/format.js`, `frontend/src/components/AppLayout.jsx` |

### Verification

- 36 assertions in a rolled-back transaction: all three outcomes, the chain
  opening only on approval, Lampiran C writing the corrected title to the project,
  the title change rewriting `projects.title` and reopening the milestone, and
  every refusal (a decision before submission, a reason-less non-approval, a plain
  `approve` on the proposal, a title decision on a chapter, a second decision on a
  settled proposal).
- 14 assertions on the seeder, including idempotency and the `agreement_id` link.
- Over HTTP: the seated supervisor-examiner reading and deciding the proposal,
  403 for a student / a non-seated examiner / a non-owner, the 422 guards, and the
  **404 on the removed registration review routes**.
- `migrate:fresh --seed` completes; the migration's `down()` was round-trip tested.
- Both static checkers clean; every changed frontend module transforms.

