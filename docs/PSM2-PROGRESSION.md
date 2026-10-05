# PSM 1 → PSM 2 progression

## The rule

PSM 1 and PSM 2 are **one project run across two continuous semesters on one
title**:

| | PSM 1 | PSM 2 |
|---|---|---|
| Title | registered here | inherited — no second registration |
| Deliverables | Proposal, Chapters 1–4 | Chapters 5–7, consolidated report |
| Supervisor | allocated here | carried over |
| Examiner panel | allocated here | carried over |
| Ends with | project, marking complete, semester closed | final report, examination |

**Only PSM 1 registers a title.** There is no second Lampiran A, no second title
defence and no re-allocation: PSM 2 continues the project the student was already
examined on.

## How the system implements it

Two routes, one rule set:

- `POST /api/projects/{project}/progress-to-psm2` — one student, from the
  **Progress to PSM 2** button on a PSM 1 project's detail page.
- `GET|POST /api/projects/rollover` — the **batch** form, driven by the
  `/rollover` screen (admin/coordinator). The admin ticks who moves;
  `ProgressionService::progressBatch()` applies the same per-student rules one at
  a time and reports who did not qualify rather than refusing the whole batch.
  Partial success is the expected outcome, and re-running is safe — a student who
  already moved is refused rather than duplicated.

**Preconditions**, each with its own refusal message:

1. The project is `psm_part = PSM1` and not already archived.
2. It has a student on record.
3. It carries an academic term, so the next term can be derived.
4. **The source term is closed.** Progression is a rollover *between* terms, so
   moving a student while their PSM 1 term is still running would place them in
   two live terms at once and leave the PSM 1 mark they were progressed on still
   being written.
5. **Every PSM 1 mark submission in that term is locked.** This is the same
   condition `SemesterService::close()` enforces, read from
   `SemesterService::marksState()`, so "PSM 1 is finished" has one definition.
   It replaced a test of the term's `is_marks_released` flag, which no longer
   exists — marks publish themselves now, so there is no term-level release to
   wait for.
6. The student has no other live PSM 2 project.

**In one transaction it:**

1. Creates the PSM 2 project — same title, abstract, objectives, scope and
   category, `psm_part = PSM2`, `status = in_progress`, in the target term. Its
   `metadata` records `progressed_from_project_id` and `progressed_from_code`, so
   the pair is traceable in both directions.
2. Instantiates the **PSM 2 milestone chain** from the template (Chapters 5–7 +
   final report).
3. **Advances `student_profiles.academic_semester_id`** to the target term.
4. **Widens the standing supervision pairing to `BOTH`** rather than registering
   a second one — the duplicate check would refuse it anyway, and the pairing is
   the same relationship.
5. **Carries the panel across** by creating PSM 2-scoped `examiner_assignments`
   rows for the same examiners and the same `examiner_pair_id`. No re-allocation,
   so the conflict-of-interest check cannot refuse the people who examined PSM 1.
   The PSM 1 rows stay with the archived project as its history.
6. **Archives the PSM 1 project** with the note "Progressed to PSM 2 in …", so
   exactly one live project remains and the awarded mark is preserved in the
   archive record.
7. Writes a `project.progressed` audit entry naming both projects and both terms.

**Which term?** `psm.progression_term_gap`, counted over the recorded semesters
in chronological order. The default is **0** — the term immediately after PSM 1,
which is what "two continuous semesters" means. Raise it for a faculty that gives
students a term between the parts. If no later term exists, the action refuses
and says to create one.

## What was missing before

Three things, all now fixed:

- **`progression_term_gap` was dead configuration.** It was declared and
  documented for exactly this feature, and never read anywhere. Nothing moved a
  student between parts.
- **`academic_semester_id` was never advanced by any application code.** Because
  `AssignmentService::assignSupervisor()` scopes the supervisor capacity gate by
  that column, a PSM 2 registration in a later term was capacity-checked against
  the *earlier* term — the same supervisor measured as both full and not full
  depending only on which term was passed. Advancing the enrolment in step 3
  above is what closes that.
- **PSM 2's milestone chain opened with `chapter_4`**, carrying the same title,
  weight and description as PSM 1's. A student running PSM 1 → PSM 2 on one title
  was asked to submit the identical Chapter 4 milestone twice. The chain is now
  Chapters 5–7 + final report, as template **version 4** — version 3 is left in
  place so projects already in flight keep the milestones and weights they were
  given at registration (`MilestoneTemplate::resolveFor()` picks the highest
  version for every new project).

Weights on the new chain: Chapter 5 = 30, Chapter 6 = 20, Chapter 7 = 20, Final
Report = 30 (total 100). The 20 points freed by dropping Chapter 4 went to
Chapter 5, which now carries the testing work that was previously split across
two chapters. **These are a faculty weighting decision and should be confirmed.**

## Not covered

- `SemesterService::currentFor($student)` returns the student's own term when it
  is active. Now that the enrolment advances, this resolves correctly for a
  progressed student — but a student whose term is *inactive* still falls back to
  the faculty's active term, which may not be theirs.
- **A batch can only be rolled over once its term is closed and fully marked.**
  That is deliberate and was the explicit requirement, but it means the tick-list
  is unusable in the seeded demo data: term 2 holds 21 submissions and 0 are
  locked. Marking the cohort through the normal assessor flow is what unblocks
  it. There is no bypass.
- The target term is derived, not chosen: `ProgressionService` takes the next
  recorded semester (`psm.progression_term_gap`, default 0). A faculty that skips
  a term raises the gap rather than picking a target per run.
