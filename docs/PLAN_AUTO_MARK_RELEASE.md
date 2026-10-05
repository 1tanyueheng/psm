# Plan â€” automatic mark visibility, and the assessment-window scope fix

> **STATUS â€” implemented and verified, commit `fe210aa`.** Restore point is
> `e3b253f`. See "As-built notes" at the end for what diverged from this plan,
> what the verification actually proved, and what is still outstanding.

Scope agreed with the user: **the three marking/window items only.** The
semester-close gate, the PSM 1 â†’ PSM 2 rollover and the admin-add-student
intake are deliberately deferred to a later pass.

Decisions taken (six questions answered):

| # | Decision |
|---|---|
| 1 | Marks become visible **as soon as each form is submitted** â€” no coordinator release step anywhere. |
| 2 | The mark submission **auto-locks** by itself once every expected form is in; the lock becomes a system-recorded fact, not a button. |
| 3 | `academic_semesters.is_marks_released` is **left in the schema but stops being read**. The "marks live" indicator is derived from the locks. No migration. |
| 4 | PSM 1 â†’ PSM 2 progression becomes an **admin tick-list** â€” the admin manually chooses which students move. (Deferred, but the gate is designed here so it is ready.) |
| 5 | Marks are shown **in the Lampiran's own units** (PSM 1: 35 supervisor + 30 examiner = 65). **No 0-100 conversion** anywhere a student looks. |
| 6 | Coordinator screens become **read-only oversight**: lists stay, release buttons go. |
| 7 | Partial marks: the supervisor's half is shown **as soon as it lands**; the examiner component is withheld until the full panel has returned its forms. |
| 8 | Window creation is restricted to the **current (active) semester**, which is also the fix for the mixed-batch roster bug. |

---

## 1. Root causes

### 1.1 Why students see a percentage instead of "35 / 65"

`EvaluationService::computeFinalGrade()` step 4 (L499-519) deliberately
**rescales** the weighted total onto a 0-100 scale:

```php
$aggregate = $weightSum > 0 ? ($weightedTotal / $weightSum) * 100 : 0.0;
```

The comment explains why: the scheme's weights only sum to 65 (PSM 1) or 95
(PSM 2), because the rest of the official assessment is marked outside this
system. So the stored `aggregate_percent` / `final_mark` is a *rescaled
percentage*, while `config/psm.php` L204-207 holds the real marks:

```php
'PSM1' => ['E' => 35.0, 'I' => 30.0],
'PSM2' => ['G' => 50.0, 'H' => 5.0, 'J' => 40.0],
```

`MarkTotal` in `frontend/src/components/MarkBreakdown.jsx` **already** renders
the correct raw figure (`breakdown.total_marks / total_max`), and
`EvaluationController::markBreakdown()` already sums it correctly at L414-423.
The student dashboard simply does not use it â€” `StudentDashboard.jsx` L340-357
gates on `final_grade.status` and passes `finalMark` around instead.

**So this is mostly a display-layer fix, not a scoring rewrite.** The rescaled
figure stays for the leaderboard and coordinator analytics, where a comparable
number is actually wanted.

### 1.2 Why the PSM 2 window lists PSM 1 students

`AssessmentWindowService::projects()` (L182-191) filters on **both** part and
term, and the DB carries a unique key on `(academic_semester_id, psm_part)`.
The filter is therefore correct â€” which means the window row's own `psm_part`
is not what its name claims.

The cause is `CreateWindowForm` (`AssessmentWindowPage.jsx` L364-392): the
**Term dropdown offers every semester**, and `AssessmentWindowController::store()`
accepts any `academic_semester_id` with no active-term check. A coordinator can
create a window called "PSM 2" against a term that only contains PSM 1
projects, and the roster will faithfully list those PSM 1 students.

Two independent faults, one shape:
- no active-term restriction on creation;
- the term the window was actually created against is not prominent in the UI,
  so a mis-picked term is invisible.

---

## 2. Backend changes

### 2.1 New â€” `App\Services\MarkVisibilityService`

A single deep module answering "is this student's mark out, and what is it?".
Both the auto-release path and the coordinator's completeness checklist go
through it, so there is one definition rather than two.

```
formsOutstanding(MarkSubmission): array        // [] when every form is in
isComplete(MarkSubmission): bool
publishableComponents(Project): array{supervisor: bool, examiner: bool}
syncFor(Project, ?int $studentProfileId, ?User $actor): FinalGrade
```

- `formsOutstanding()` wraps the existing `MarkSubmission::readiness()`, which
  already reads live evaluation rows (so a stood-down examiner drops out
  automatically). No new readiness arithmetic.
- `syncFor()` is the new orchestrator: compute the grade, set `status` /
  `released_at` when the supervisor form is in, and mirror onto the evaluation
  rows.
- `?User $actor` is nullable because **nobody** attests an automatic release â€”
  that has to be visible in the payload, so `released_by` stays null and the
  resource says "released automatically".

### 2.2 `EvaluationService::submit()` â€” the trigger

Today, L325-328 recomputes the aggregate for every student on the project.
Replace that loop with a call to `MarkVisibilityService::syncFor()`, which:

1. recomputes the aggregate, passing the **expected panel size** so the
   examiner component is withheld until the full panel is in. The `?int
   $expectedPanelSize` parameter and its documented rule already exist on
   `computeFinalGrade()` (L391-394) but are **never passed** by any caller â€”
   switching it on is what delivers decision #7;
2. releases the grade (`status = 'released'`, `released_at = now()`,
   `released_by = null`) as soon as the supervisor form is present;
3. mirrors `EvaluationStatus::Released` onto the submitted evaluation rows,
   preserving the existing `final_grades` â†’ `evaluations` coupling at L639-642;
4. asks `autoLockIfReady()`, so the submission locks itself the moment the last
   expected form lands.

### 2.3 `MarkSubmissionService`

- Add `autoLockIfReady(MarkSubmission): ?MarkSubmission` â€” locks **only** when
  `isReadyToLock()`, and never throws. The manual `lock()` keeps its throwing
  contract for the API route.
- `lock()` stops being the only write path, so the audit action needs to
  distinguish a system lock from an attested one. Add
  `AuditAction::MarkSubmissionAutoLocked` (or reuse the existing lock action
  with `actor = null` and a description saying so â€” whichever the enum's shape
  makes cleaner). **Decision recorded:** prefer a distinct audit action; "who
  locked this and why" is exactly the question an audit trail exists to answer.
- `EvaluationService` and `MarkSubmissionService` would otherwise be circular
  (`MarkSubmissionService` already depends on `EvaluationService`). Resolve by
  having `submit()` call `MarkVisibilityService` and `MarkVisibilityService`
  depend on **`MarkSubmissionService`**, not the reverse. Laravel resolves this
  fine; it must not become a two-way constructor dependency.

### 2.4 Stop reading the semester release flag

Remove these reads; leave the columns and the model casts alone (decision #3):

| Site | Change |
|---|---|
| `EvaluationService::assertTermAllowsRelease()` L930-942 | Delete the method and its call in `releaseGrade()`. |
| `SemesterService::setMarkRelease()` L331-378 | Delete. |
| `SemesterService::update()` L174-176 | Drop the `is_marks_released` branch. |
| `SemesterService::create()` L100 | Drop the `is_marks_released => false` initialiser. |
| `SemesterController::releaseMarks()` L264-284 | Delete. |
| `SemesterController::update()` L143-147, L157, L315-319 | Drop the `releaseMarks` authorisation branch and the message branch. |
| `routes/api.php` L162-163 | Delete the `release-marks` route. |
| `SemesterController::changeMessage()` | Drop the release branch. |

Replaced by a **derived** indicator in `SemesterService`:

```
marksState(AcademicSemester): array{
    complete: int, total: int, outstanding: int, is_live: bool
}
```

`is_live` is true when every live project's submissions are locked. This is what
the semesters screen shows instead of the Release/Withhold column.

### 2.5 Restrict window creation to the current term (decision #8)

`AssessmentWindowController::store()`:

```php
if (! $semester->is_active) {
    return $this->fail(
        "Marking can only be opened for the current semester. "
        ."{$semester->name} is not the active semester.",
        422
    );
}
```

Plus the unique-key check already there (L93-102) is retained â€” it is what makes
"one window per (term, part)" real.

Consider also returning the resulting window's part explicitly in the create
message so a mis-selection is impossible to miss:

> `PSM 2 assessment window created for 2025/2026 Semester I.`

### 2.6 Student notification (decision #5, #7)

`EvaluationService::submit()` currently notifies **coordinators** (L331-345).
Add the project's students to that dispatch, reusing
`NotificationType::GradeReleased`, worded for a mark that arrives by itself:

> "Your mark is available â€” Lampiran E has been filed. It appears on your
> dashboard now and will update as your examiners submit."

Notification fires on **every** submission that changes the visible mark
(decision #1), not once.

---

## 3. Frontend changes

### 3.1 Student-facing â€” show the Lampiran's own marks

| File | Change |
|---|---|
| `pages/student/StudentDashboard.jsx` L42, L71-85, L338-359 | Fetch the breakdown whenever a grade exists (not only when `status === 'released'`, since release is now automatic). Render `MarkTotal` from `breakdown.total_marks / total_max`. Change the subtitle from "Released by the coordinator" to state it is automatic. |
| `pages/projects/ProjectDetailPage.jsx` L425-431 | Same treatment â€” it already uses `MarkTotal`. |
| `components/MarkBreakdown.jsx` | No arithmetic change needed; it is already in Lampiran units. Add a "showing the supervisor's mark â€” the examiner half is still to come" note when `total_max` is short of the configured total. |

`formatMark` / `finalMark` must **stop** being what a student is shown. Worth
grepping for `final_mark` and `aggregate_percent` in student-visible code paths
before finishing, because a leak here is exactly the bug being fixed.

### 3.2 Coordinator-facing â€” read-only oversight (decision #6)

| File | Change |
|---|---|
| `pages/evaluations/MarkListPage.jsx` | Drop `release()`, `releaseAll()`, the Release buttons, the `pending` banner and the "awaiting release" badge. The status column becomes `complete` / `awaiting forms`, read from the lock. Title changes from "Marks & Release" to "Marks". |
| `pages/admin/SemesterListPage.jsx` L329-353 | Replace the Release/Withhold cell with the derived `marksState` figures (`n/m complete`). |
| `pages/coordinator/CoordinatorDashboard.jsx` L197 | "Marks released" tile becomes "Marks complete". |
| `api/endpoints.js` L438-439 | Remove `gradeApi.release` and `releaseAllGrades` (L258) if nothing else uses them. |
| `lib/permissions.js` | Remove the now-dead `releaseMarks` capability if no other screen reads it. |

### 3.3 Assessment window page

| File | Change |
|---|---|
| `pages/coordinator/AssessmentWindowPage.jsx` L364-392 | Replace the Term `<Select>` with the **current term shown as fixed text**. Keep the Batch select. Disable creation with an explanatory message when no term is active. |
| Same file, Students table L249-291 | Header becomes e.g. "Students â€” PSM 2 only", with the part badge beside it and the count, so a mis-scoped window is obvious at a glance rather than needing a click into each student. |
| Same file, `load()` L59-80 | The list is already filtered by `semester_id`; when the term switcher is pointed at a *different* term than the active one, say so on the page rather than silently showing nothing. |

---

## 4. Consequences to handle, not discover later

1. **`ProgressionService` L85** refuses to progress a PSM 1 student unless
   `$sourceTerm->is_marks_released`. With the flag no longer written, this
   becomes `false` for every term created after this change and **blocks all
   progression**. Decision #4 settles it: the gate becomes the admin's tick
   list, so this check is replaced rather than patched. If the deferred pass
   slips, this check must at minimum be swapped for "every PSM 1 submission is
   locked" *before* shipping this one.
2. **`lib/permissions.js` / `GradePolicy::release`** â€” the ability becomes
   meaningless. Remove it or it becomes a hook nobody remembers is dead.
3. **Existing `final_grades` rows** in the live database are `provisional` with
   `is_marks_released = true` on the term today. They will not auto-flip until
   something next touches them. Needs a one-off backfill (or "recompute" from
   the coordinator screen) so the coordinator's list does not show last
   semester's marks as incomplete. **Flagging this as the one item that may need
   a data fix rather than a code fix.**
4. **`docs/`** â€” `MODULES.md`, `API.md` and
   `docs/REQUIREMENT_TWO_BATCHES_SAME_SEMESTER.md` all document the
   release-marks endpoint and `is_marks_released`. They go stale the moment this
   ships.
5. **Seeder** â€” `AcademicSemesterSeeder` / whatever seeds a released term will
   need to follow, since the repo's stated convention is that seeders drive the
   real services and must not be able to display a state the app cannot produce.

---

## 5. Verification

I cannot run the stack from this session: `php` and `mysql` are not on PATH,
`backend/.env` does not exist, Docker's named pipe is blocked by the sandbox,
and the 401 from `localhost:8000/api/semesters` confirms the API is up but
needs a token. So verification will be:

1. **Static, and locally runnable** â€” both repo checkers are pure Python and
   need no runtime:
   ```
   python backend/tools/check_seeders.py
   python frontend/tools/check_frontend.py
   ```
   The frontend checker validates every relative import, every named import and
   every `fooApi.bar()` call against `api/endpoints.js` â€” which is exactly the
   surface being edited.
2. **Route table consistency** â€” grep for `release-marks`, `setMarkRelease`,
   `assertTermAllowsRelease`, `is_marks_released`, `releaseMarks`,
   `releaseAllGrades` and `markApi.release` afterwards to prove no dangling
   reference survives.
3. **`php artisan test`** â€” you run it, or I do if you give me a working PHP
   path. Existing Module 4 tests will assert the old release behaviour and need
   updating as part of this change, not after it.
4. **Manual, in the browser at `localhost:5173`** â€” the only real proof:
   submit a supervisor form as PSM 1 and confirm the student's own dashboard
   shows `x / 35` immediately with no Release click anywhere; submit an examiner
   form and confirm the total moves to `x / 65`; create a PSM 2 window and
   confirm the roster holds only PSM 2 students.

---

## 6. Open items for the user

1. **Item 4.3 (existing rows).** Do you want me to add a backfill migration for
   existing `final_grades`, or will you recompute from the coordinator screen?
2. **Item 4.5 (seeder).** Confirm the seeder should also stop producing a
   "released" term, or whether you want the demo data left showing a released
   state.
3. **Verification.** If you can tell me how the backend is being served (it is
   answering on `:8000`), I can try to run `php artisan test` myself instead of
   handing that step to you.

---

## As-built notes

Written after implementation. Where this diverges from the plan above, the
divergence is stated rather than quietly absorbed.

### What the plan got wrong

1. **`MarkSubmission::expectedAssessors()` is missing a `psm_part` filter**
   (L132-136). It resolves examiners by `project_id` alone, while
   `MarkSubmissionService::resolvePanel()` filters by part as well. In this data
   it happens to agree — every `examiner_assignments` row matches its project's
   part, checked explicitly — but it is a latent mixed-batch bug sitting directly
   under the window bug that was reported. **Not fixed in this pass**; it is a
   behavioural change to a readiness rule and deserves its own verification.

2. **`readiness()` reports more outstanding entries than there are people.** A
   panel of 2 with nothing filed returns 4 entries: one per missing form plus a
   summary "2 panel forms are still outstanding". Correct behaviour, confusing
   count. Left alone.

3. **The plan under-called the `released` vs `locked` trap.** `FinalGrade::
   isReleased()` tested the literal string `'released'`, and there were four
   call sites. Turning auto-lock on meant a grade flipped to `locked` the moment
   the batch completed — which made the student's mark *disappear* at exactly the
   moment it became final, and made the next sync re-publish it and drop the
   lock. Both directions were caught by the end-to-end probe, not by reading.

4. **The plan assumed a backfill would be needed.** It is not: all 10 existing
   grades reconcile to `no change`, and 0 of 21 submissions are ready to lock.
   Verified read-only before running anything, which is why nothing was run.

### What verification actually proved

No test suite exists in this repo — `backend/tests/` is absent and `phpunit` is
not installed, so the README's `php artisan test` cannot run. Verification was:

- **Both static checkers**, which caught three real breakages the plan did not
  anticipate: a stale `useAuth()` import, `SemesterListPage` still calling the
  removed `setMarkRelease`, and `EvaluationSeeder` calling the removed
  `SemesterService::setMarkRelease`.
- **`vite build`** in the container, to prove the JSX parses (the checker
  validates structure, not syntax).
- **A transaction-rolled-back probe** driving the real services through the whole
  sequence. Result: supervisor submits ? mark visible, examiner half `null`;
  first examiner ? still `null`; second examiner ? auto-locked and still visible;
  student reads `45.52 / 65`, `by_assessor {supervisor: 24.52, examiner: 21}`
  while the internal `final_mark` reads `70.03`.
- **Live HTTP payloads** as a student and as a coordinator, which is what exposed
  the rescaled `80.8` still being sent to a student beside a `40.4 / 95`
  breakdown.

### Still outstanding

Updated after the second pass (commit `a29bb56`).

1. ~~Two broken windows in the live database.~~ **Repaired.** Window 2 renamed to
   "PSM 2" — it held PSM 2 under a PSM 1 name — and window 3 re-pointed from term
   1 (which has no projects) to `2026/2027 Semester I`, where PSM 2 will actually
   run once students roll over. Verified through the API afterwards: window 2
   lists 9 PSM 2 students, window 1 lists 12 PSM 1 students, and every window's
   name now matches its `psm_part` and its roster.
2. ~~Docs are stale.~~ **Mostly fixed.** `MODULES.md`, `PSM2-PROGRESSION.md` and
   `REQUIREMENT_TWO_BATCHES_SAME_SEMESTER.md` now describe the current rules.
   `docs/API.md` turned out never to have documented semesters at all, so the
   earlier claim that it was stale was simply wrong.
3. ~~`expectedAssessors()`~~ **Fixed.** Now scoped to the submission's part,
   matching `resolvePanel()` on the service side. Verified as a no-op on the
   current data — 0 of 21 submissions changed — so it is a defensive fix for a
   latent mixed-batch bug rather than a behaviour change.
4. ~~The deferred original request.~~ **Implemented** in `a29bb56`. See the two
   new sub-sections below.
5. ~~`SemesterFilterBar`'s marks badge.~~ **Fixed.** The list endpoint now attaches
   both submission counts per row, so the flag is a real boolean for every caller
   instead of `null` for a student. The badge reads "Marks complete" /
   "Marking in progress", which is what it should say now that nothing is
   released by hand.
6. **Browser confirmation of the rendered screens.** Every API payload is verified
   end to end as admin, coordinator and student, and the frontend compiles — but
   nobody has clicked through the new `/rollover` or roster screens in a real
   session. This is the one gap that cannot be closed from here.
7. **The rollover cannot be demonstrated on the seeded data.** Term 2 holds 21
   submissions and none are locked, so the term cannot close and nothing can roll
   over until the cohort is genuinely marked. That is the requested rule working
   as designed — progression only after the semester closes, term-wide — and there
   is deliberately no bypass. Worth knowing before a demo.

### The original request, as built

| # | Asked for | Where it lives |
|---|---|---|
| 1 | Closing a semester requires all marks submitted | `SemesterService::assertMarkingIsComplete()`, called from `close()`. Names up to five students and what each is waiting on; a student with no submission opened counts as outstanding. |
| 2 | New semester manages new students, added by admin after account creation | "Enrolling semester" field on the add-user form (the API always accepted `academic_semester_id`; no screen ever sent it). `GET /api/semesters/{id}/students` plus an expanding roster on the semester screen shows the result. |
| 3 | PSM 1 students proceed to PSM 2 with the title carried over | `ProgressionService::progressBatch()`, the `/api/projects/rollover` endpoint, and the `/rollover` tick-list. The admin chooses; titles, supervisor and panel carry over; PSM 1 is archived. |
| 4 | The admin picks who moves | The tick-list. Students who cannot move are listed with the reason rather than hidden, so nobody disappears silently. |
