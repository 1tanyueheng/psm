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


## Group 3 — Timeline and deadlines


### 3.2 When a deadline is missed, what should happen — automatic penalty, or coordinator discretion?

**Why it matters:** Your system has a "late window" concept
(`late_window_days`) and flags late assessments. The real policy may be simpler
or stricter. Getting this wrong means your system reports the wrong thing
about a student, which is the kind of error a coordinator will not forgive.


## Group 4 — Roles, panels and capacity

### 4.1 How are supervisors and panel allocated today, and who decides?


