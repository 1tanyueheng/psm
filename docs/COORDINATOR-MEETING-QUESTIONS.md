Streamlining PSM Assessment: A Centralized Milestone and Evaluation Platform For FSKTM

A web-based management ecosystem designed to eliminate administrative friction and digitize final year project workflows at FSKTM.


# Questions for the PSM Coordinator Meeting


## How to use this

The questions are ordered by how much they affect your FYP. The first group
decides whether your system is even pointed at the right problem. The later
groups are detail you can ask if time allows.

Each question says **why it matters**, so you can adapt it if the conversation
goes somewhere else. Bring the two or three in **Group 1** even if you ask
nothing else.

---

## Group 1 — The ones that decide your project's scope



### 1.3 What is the biggest administrative pain point in running PSM each semester?



## Group 2 — Form and rubric alignment


### 2.1 The page lists Forms A–K plus R1–R3. Does your system need to handle all of them, or only the marking ones?

The marking forms listed are:

| Form | Purpose | Assessor |
|---|---|---|
| E | PSM 1 Supervisor Evaluation | Supervisor |
| G | PSM 2 Supervisor Evaluation | Supervisor |
| H | Progress Report Evaluation (1 and 2) | Supervisor |
| I | Seminar 1 Examiner Evaluation | Examiner |
| J | Final Seminar Examiner Evaluation | Examiner |

**Why it matters:** Your system currently implements E, I, G, H and J. It does
**not** implement D (Supervisor Warning), K (Final Report Submission), or R1–R3
(Chapter/Proceeding/Prototype review, Report review confirmation, Web Server
account application). Confirming which are in scope protects you from being
asked to build three more modules late.

**Note:** Lampiran D — the Supervisor Warning Form — is interesting. It records
"failure to attend weekly meeting". That is a supervision-monitoring feature you
could offer cheaply, and it is explicitly `Tindakan Penyelia Sahaja` (supervisor
action only).

### 2.2 Are the weightings and rubrics on the Sharepoint the current official ones?

**Why it matters:** Your system seeds specific marks — E is 35, I is 30, G is
50, H is 5, J is 40. If the faculty changes a weighting, your rubric snapshots
would no longer match the official form, and marks would be computed against a
stale scheme. Ask whether these are revised each semester and who owns the
change.

### 2.3 For PSM 1, what makes up the marks the system does not assess — and is an assignment one of them?

**This is the most important question in this group.** The system scores PSM 1
from two forms only:

| Form | Assessor | Weight |
|---|---|---|
| Lampiran E | Supervisor | 35 |
| Lampiran I | Examiner (Seminar 1) | 30 |
| | **System total** | **65** |

**That leaves 35 marks unaccounted for.** Your own note says PSM 1 includes an
assignment worth **45%**, which matches neither figure — so at least one of the
three numbers is wrong, and the gap needs pinning down before the mark the
system releases can be trusted as a share of the real total.

**What to ask:**

1. **What is the full official breakdown for PSM 1?** Every component and its
   weight, adding to 100.
2. **Is the assignment marked by the lecturer in class, outside this system?**
   If so, the system's 65 is a correct *partial* share and only the labelling
   needs to be honest about it.
3. **Does the assignment form part of the PSM 1 grade, or is it separate
   coursework?** If separate, the 65 stands on its own and nothing is missing.
4. **If it is part of the same grade, who combines the two?** The coordinator,
   or the system? If the system is expected to, it needs a component it does
   not have — and the weights above would change.

**Why this matters beyond correctness:** the system deliberately refuses to
derive a letter grade, on the grounds that its share is not 100% of the
assessment. That is defensible *only if* the remaining share is genuinely
marked elsewhere. If the faculty expects one system to hold the whole mark,
the design needs revisiting — far better learned in this meeting than in a viva.

**How to phrase it without sounding like the system is broken:**

> "The system scores PSM 1 from Lampiran E and I, which is 65 of the 100 marks.
> I want to make sure I describe the remaining share correctly — is that marked
> by the lecturer, and is it part of the same grade?"

That invites the coordinator to confirm or correct the model, rather than
sounding like an accusation.

**Follow-up — the more useful half of the question.** If the assignment is
marked outside the system, ask **whether it should be brought in.** The options
are genuinely different in size:

| Option | What it means | Effort |
|---|---|---|
| **Record it** | The coordinator enters the lecturer's assignment mark, so the student sees one combined total | Small |
| **Track it** | Allocate the assignment to a lecturer, record submission and marks, like supervision | A module in its own right |
| **Leave it out** | The system stays the 65 and says so plainly | None |

Ask which, if either, is wanted. For a project this size, "record it, do not
run it" is usually the honest answer — and saying that yourself shows you are
judging scope rather than just adding features.


## Group 3 — Timeline and deadlines


### 3.2 When a deadline is missed, what should happen — automatic penalty, or coordinator discretion?

**Why it matters:** Your system has a "late window" concept
(`late_window_days`) and flags late assessments. The real policy may be simpler
or stricter. Getting this wrong means your system reports the wrong thing
about a student, which is the kind of error a coordinator will not forgive.


## Group 4 — Roles, panels and capacity

### 4.1 How are supervisors and panel allocated today, and who decides?

**Why it matters:** The system enforces a per-batch capacity (a default of 5
PSM 1 and 5 PSM 2 per supervisor) and refuses over-allocation. If the real
process is a negotiation rather than a hard rule, that enforcement could read
as obstructing rather than helping. Ask whether a coordinator ever *needs* to
exceed a cap deliberately, and whether that should require a recorded reason.

### 4.2 Is there a separate lecturer assignment, and should the system allocate it?

The system allocates **supervisors** and **panels**. If PSM 1 also carries an
assignment marked by a lecturer, that is a third kind of allocation it does not
currently handle — and it is worth knowing whether that is a gap or simply out
of scope.

**What to ask:**

1. **Is the assignment allocated to a lecturer at all**, or does the class
   lecturer mark everyone they teach? The two are different problems: one needs
   an allocation screen, the other does not.
2. **Is that allocation currently done in the system, a spreadsheet, or
   verbally?** If it is manual, it is a candidate for the same treatment as
   supervision.
3. **Does the lecturer need access to the system** to enter marks, or does the
   coordinator collect and enter them? This decides whether it is a role
   addition or a single form.

**Why this matters for scope:** adding a lecturer as an allocatable role is not
just another dropdown. It touches role permissions, capacity rules, and the
marking workflow. Worth knowing the answer before the question is asked of you
in a viva.

**A useful way to frame it:**

> "The system handles supervision and panel allocation. If the PSM 1 assignment
> is allocated to a lecturer too, should that live in the same place — or is it
> better left with the course lecturer?"

That way you are asking where the boundary belongs, not admitting a missing
feature.


