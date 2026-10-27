# PSM Lifecycle — Design Specification

**Status:** for review. Nothing here is implemented yet.
**Date:** 2026-10-27
**Supersedes:** the verdict board, `changeTitle()`, and the four-value project status.

This document specifies the registration, title and outcome rules for the PSM
Assessment System. It exists because the current code and the faculty's actual
process disagree in five places, and three of those disagreements have already
produced bugs.

Answer Y/N at the end of each section, or correct anything that reads wrong.

---

## 1. The five rules everything else follows from

| # | Rule | Consequence |
|---|---|---|
| R1 | A project run for a semester. It is **running** until that semester closes. | No status a human sets. |
| R2 | The **mark** decides pass or fail: `final_mark >= 40`. | No committee verdict. |
| R3 | A title becomes the project's title **only when a panel accepts it**. | A proposed title never overwrites an accepted one. |
| R4 | A refused title is **re-presented until approved** — same semester, no limit. | A rejection is not a failure. |
| R5 | Only a **mark below 40** costs a semester. | Fail → repeat the part next semester. |

**R1 replaces** `draft`, `submitted`, `approved`, `in_progress`, `rejected`,
`completed`, `failed` with three values, two of which are derived:

```
status = term not closed        → running
         final_mark >= 40       → passed
         final_mark <  40       → failed
```

---

## 2. Lampiran B becomes a record, not a field

Today Lampiran B exists only as a milestone plus `projects.title`, so a refused
title has nowhere to live and the old title is lost when it changes.

```
lampiran_b_forms
  id
  project_id                  → the same project, never restarted
  revision_no                 1, 2, 3…  (which filing this is)
  title                       the title proposed on THIS form
  project_type                system | research
  field_study                 one of the five faculty options
  requirements                software / hardware / technology
  origin                      own idea | supervisor's
  supervisor_signature_path
  supervisor_signed_at
  status                      draft | submitted | endorsed | accepted | rejected
  rejected_reason
  rejected_at
  superseded_by_id            → the next filing
  milestone_id                → the milestone that carries it / NULL for #1
  timestamps
```

`projects.title` holds **the last accepted title** (R3). Every previous title
survives on its own row with its rejection reason.

**Panel view:** the panel always sees the title under consideration, labelled
**undetermined**, alongside the accepted one when they differ. A submitted
Lampiran B is visible while undecided — it just never claims to be agreed.

---

## 3. The four flows

### 3.1 Normal — title unchanged

```
Lampiran A acknowledged
Lampiran B #1 → supervisor signs → project created, title set
proposal → supervisor endorses → panel accepts title
chain: OSHE, chapters 1–4, demo, final report        (PSM 1)
       chapters 5–7, final report, Form K            (PSM 2)
term closes → mark entered → passed or failed
```

Nothing here changes from today.

### 3.2 Refused title — re-present until approved (R4)

```
panel refuses title Y at the defence, with a reason
        ↓
Lampiran B #2 opened, pre-filled from #1, supervisor locked
  · rejected reason pinned at the top of the form
  · supervisor signs again (a signature on Y does not cover Z)
        ↓
new proposal milestone inserted after the refused one
  · old proposal milestone stays `rejected` as the record
        ↓
supervisor endorses → panel sits again → accept or refuse
        ↓  (repeat as needed — no cap, count is shown)
panel accepts → projects.title becomes the accepted title
```

**What is never restarted:** the project, its code, its supervisor, its Lampiran
A, the earlier Lampiran B rows, the enrolment, the term.

**Each attempt adds two milestones** (a Lampiran B, a proposal). The chain grows
visibly, which is how a coordinator sees a student on their fourth attempt
without the system capping anything.

> **Worked example.** Student files B#1 "Flood Prediction". Panel refuses: "too
> broad — narrow to one district." B#2 proposes "Flood Prediction for Batu
> Pahat". Supervisor signs. Proposal #2 written for the narrow scope. Panel
> accepts.
>
> Chain: A ✓ · B#1 ✓ · Proposal#1 ✗ · B#2 ✓ · Proposal#2 ✓ · OSHE · Chapters…
> The old title and the objection remain readable forever.

### 3.3 Title change at the PSM 2 boundary (your Q33b)

A student who **passed PSM 1** may choose a **different title** at the start of
PSM 2. They then redo the PSM 1 documentation inside PSM 2, in addition to the
normal PSM 2 chain.

```
PSM 2 starts
   ├── title unchanged → chapters 5–7, report, Form K
   └── title changed   → Lampiran B (new title) → supervisor signs
                         → proposal → full defence (panel)
                         → Chapters 1–4 REFILED for the new title
                         → THEN chapters 5–7, report, Form K
```

- **Optional**, chosen **at the start of the PSM 2 chain, before Chapter 5
  opens** (Q34, Q42).
- **Full defence** — a different title is a different project; a panel must see
  it (Q35).
- **Chapters 1–4 are filed, not re-marked** (Q36). The PSM 1 mark was awarded
  and cannot be re-awarded; the PSM 2 assessors need the new chapters as
  evidence, so they are **required deliverables with weight 0**.
- **One mark for the whole project** (Q17). Nothing about assessment changes.

> **Worked example.** Student passed PSM 1 on "Attendance via face recognition".
> At PSM 2 they change to "Attendance via RFID". They file a new Lampiran B,
> defend it, refile Chapters 1–4 written for RFID (no marks), then do Chapters
> 5–7, the report and Form K. They receive **one** PSM 2 mark.

### 3.4 Failed part

```
term closes → assessor enters the mark
   final_mark >= 40 → passed
   final_mark <  40 → failed → may repeat the part next semester
```

- Repeat is refused until the failed term is **closed**.
- PSM 1 repeat: **restarts from Lampiran A**.
- PSM 2 repeat: new project next semester, title carried or changed.
- **A refused title is never a fail** (R5). The two are different outcomes.

---

## 4. Deadlines and late work

| Rule | |
|---|---|
| Every student shares the **same** deadline per milestone | no per-student dates |
| Late submission is **never blocked** | `acceptsSubmission()` stops gating on the deadline |
| Lateness is **flagged, not prevented** | the reviewer sees "6 days late" |
| A re-filed Lampiran B / proposal **inherits the shared deadline** | no reset on rejection |

`overdue` remains as **information** about a milestone; it stops being a lock.
This removes the current refusal: *"The deadline for this milestone has passed
and late submission is not permitted."*

---

## 5. Closing a term

**The term cannot close until every enrolled student has a submitted mark**
(confirmed). Since a mark keys off a project, two guards are needed:

1. **No project.** If an enrolled student has no approved title, the close is
   **refused with a named list**. The coordinator chases them or withdraws the
   enrolment. *(You said this never happens in the real world; the guard exists
   so the software agrees with you rather than hanging. It does not fire on the
   happy path.)*
2. **No mark.** If a project exists but no mark was entered, the close is
   refused, naming the student.

**Non-attendance is an explicit zero**, entered by the assessor — never assumed
by the system (Q21). A zero produces `failed`, which is correct: the student was
assessed and did not meet the bar.

Closing the term then derives every project's status (R1, R2) in one pass.

---

## 6. What this deletes

| Removed | Why |
|---|---|
| **Verdict board** (`/verdicts`, `ProjectResolutionService::resolve()`) | The mark decides. A committee adding a second opinion can only agree or contradict. |
| **`changeTitle()`** | Replaced by a new Lampiran B — which carries a supervisor signature. Today any student can retitle their project with no supervisor involved. |
| **Four-value project status** | Derived from term + mark. |
| **`rejected` project status** | A refused *title* is a state of a Lampiran B, not of a project. |

**Kept:** `repeat()`, enrolments, `resolve()`'s supervision and panel release
(moved into term close), the audit trail.

---

## 7. Consequences you should see before approving

1. **PSM 2 with a changed title is roughly double the work in one semester** —
   a new Lampiran B, a defence, and Chapters 1–4 rewritten, *before* Chapters
   5–7. Late submission is unblocked, so it is possible; it is not comfortable.
2. **Every refusal grows the chain.** Three attempts means six extra milestones.
   Visible, not capped — as agreed.
3. **A term close now depends on marks for every enrolled student.** If an
   assessor forgets one, the close stops. That is the guard working.
4. **The PSM 2 boundary decision must be made before Chapter 5 opens.** A
   student who changes their mind in week 8 cannot take the path.
5. **Chapters 1–4 refiled in PSM 2 carry no weight.** They appear in the chain as
   required work, which will read oddly to anyone expecting marks on them — the
   deliverable should be labelled "evidence for the panel, not assessed".

---

## 8. Sign-off

Tick or correct each:

- [ ] **§1** five rules — correct
- [ ] **§2** Lampiran B as a record with `revision_no` and `rejected_reason`
- [ ] **§3.2** refused title → new Lampiran B + new proposal milestone, old ones kept
- [ ] **§3.3** PSM 2 title change: optional, at chain start, full defence, chapters refiled unmarked
- [ ] **§3.4** refused title ≠ fail; only a mark below 40 fails
- [ ] **§4** deadlines shared, late never blocked, lateness flagged
- [ ] **§5** term close requires a mark for every enrolled student; zero is manual
- [ ] **§6** delete verdict board, `changeTitle()`, four-value status, `rejected`

Once this is signed off I will implement it in this order: Lampiran B records →
re-file flow → status derivation → term close → PSM 2 title change → diagrams.
