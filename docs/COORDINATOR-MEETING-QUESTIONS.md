# Questions for the PSM Coordinator Meeting

**Prepared for:** TAN YUE HENG
**Coordinator:** Norfaradilla Binti Wahid (FSKTM PSM Coordinator)
**Source:** `[FSKTM] PSM - Student Sharepoint - Home` (saved 8 Oct 2026)

---

## How to use this

The questions are ordered by how much they affect your FYP. The first group
decides whether your system is even pointed at the right problem. The later
groups are detail you can ask if time allows.

Each question says **why it matters**, so you can adapt it if the conversation
goes somewhere else. Bring the two or three in **Group 1** even if you ask
nothing else.

---

## Group 1 — The ones that decide your project's scope

### 1.1 The Sharepoint says forms go into three other systems (Author, AITCS, eReport). Where would a PSM management system fit?

The page states:

> Initial submission of Form A and Form B will be done via the links provided on
> this platform; while the rest of the forms and documents submission will go
> into (i) Author system (all forms and PSM documents), (ii) AITCS system
> proceeding paper template... (iii) eReport system for Final Report submission

**Why it matters:** This is the single most important question. If Author
already holds all forms and documents, a coordinator may reasonably ask what
your system adds. The honest answer is that yours manages the *workflow around*
the documents — supervision allocation, capacity, milestone tracking, marking
windows, panels — rather than storing the final artefacts. Knowing this shapes
whether you position your FYP as a **replacement**, an **overlay**, or an
**internal tool** for the parts Author does not cover.

**Follow-up:** Which of Author's functions are painful today? Something like
supervisor allocation or progress tracking is likely manual, and that is your
opening.

### 1.2 Does the faculty intend to replace any of Author / AITCS / eReport, or work alongside them?

**Why it matters:** It tells you whether to build imports/exports or a
standalone system. If the answer is "alongside", you should ask about data
exchange early, because that is the integration work.

### 1.3 What is the biggest administrative pain point in running PSM each semester?

**Why it matters:** An open question, and the most valuable one in the meeting.
You have built milestone tracking, capacity enforcement, marking windows and
archive features — but you inferred those needs. Hearing the actual pain tells
you whether you solved a real problem or an imagined one, and gives you a
defensible answer to "why did you build this?" in your final defence.

**Listen for:** allocations, chasing supervisors, spreadsheet versioning, late
marks, or students not knowing their status. You have a feature for most of these.

### 1.4 Which parts of the current process are still done on paper, WhatsApp, or spreadsheets?

**Why it matters:** The page itself is organised around a WhatsApp group
("link available in this WA group's description", "will be announced in this WA
group"). That is a strong signal that coordination is manual. The gap between
the Sharepoint and the WhatsApp group is very likely where your system is most
valuable — and it is a concrete, quotable finding for your report.

---

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

### 2.3 Who owns the official rubric documents, and how would a change be communicated?

**Why it matters:** Your system freezes a rubric snapshot per form so marks can
always be reproduced. That is the right design, but it means a rubric change is
a deliberate, versioned act. Knowing the owner tells you who to build the
"publish new rubric version" workflow for.

---

## Group 3 — Timeline and deadlines

### 3.1 Are the dates in your system expected to come from the faculty calendar, or be set by the coordinator?

The page shows specific windows, e.g.:

- Form A re-open: **07.09.2026 – 11.09.2026, 11.59pm**
- Form B and proposal: **16.09.2026 – 23.09.2026 11.59pm ONLY (until Wednesday of WEEK 1)**
- PSM 1 Title Defence: **28.09.26 – 02.10.26 (online), WEEK 2**
- PSM 1 2nd Briefing (compulsory): **Friday 18 September, 9:00–10:00am**

**Why it matters:** Your system has separate concepts for term dates, milestone
deadlines, and assessment windows. The page shows these are announced
incrementally and sometimes re-opened (Form A says "Re-open"). Ask whether a
coordinator needs to edit deadlines after students have seen them, and whether
that change should be visible to students — you already support this, but
confirming it is wanted strengthens your justification.

### 3.2 When a deadline is missed, what should happen — automatic penalty, or coordinator discretion?

**Why it matters:** Your system has a "late window" concept
(`late_window_days`) and flags late assessments. The real policy may be simpler
or stricter. Getting this wrong means your system reports the wrong thing
about a student, which is the kind of error a coordinator will not forgive.

### 3.3 Is "WEEK 1" of the semester tied to the academic calendar you would expect?

**Why it matters:** Your system resolves the current term and defaults screens
to it. If the faculty counts weeks from a date that differs from the term's
`starts_at`, your "week" calculations would drift. This is worth pinning down
before you claim week-based features work.

---

## Group 4 — Roles, panels and capacity

### 4.1 How are supervisors and examiners allocated today, and who decides?

**Why it matters:** Your system enforces a per-batch capacity (a default of 5
PSM 1 and 5 PSM 2 per supervisor) and refuses over-allocation. If the real
process is a negotiation rather than a hard rule, your enforcement could be
seen as obstructing rather than helping. Ask whether a coordinator ever *needs*
to exceed a cap deliberately, and whether that should require a recorded reason.

### 4.2 Can one person be both supervisor and examiner for the same student?

**Why it matters:** Your system actively prevents a supervisor from examining
their own student (a conflict-of-interest rule). Confirm this matches faculty
policy, and ask how a declared conflict should be handled — your system records
a "recused" state, but the *process* after recusal is a policy question.

### 4.3 The page mentions a "Letter of Authorization to Obtain Information from an External Organization". How does a student request one?

**Why it matters:** This is a real workflow your system does **not** have. It
involves an application, presumably an approval, and a document issued to a
third party. If this is common, it is a concrete gap you could either build or
explicitly scope out. It is also a good example of showing you read the page.

---

## Group 5 — Documents, reports and the archive

### 5.1 The faculty uses eReport for final reports and AITCS for proceedings. Should your system store copies?

**Why it matters:** Your system has an archive module that preserves the
submitted project record for a retention period (configured at 7 years). If the
official repository is eReport, your archive may duplicate it. Ask what the
faculty is required to retain, and for how long — that turns "we chose 7 years"
into "we match the faculty retention policy", which is a much stronger claim.

### 5.2 What happens to a student's documents after they graduate?

**Why it matters:** Directly relevant to your archive design. You keep
superseded file revisions so an appeal can be answered with what was actually
submitted. Confirm the faculty wants that, because it has storage and privacy
implications.

### 5.3 Is there any requirement to produce reports for accreditation or audits?

**Why it matters:** Your system can generate CSVs and workload reports. If the
faculty already must produce something each semester — a pass rate, a workload
summary, an accreditation table — matching that format is high-value, low-effort
work and an easy win.

---

## Group 6 — Access, privacy and rules

### 6.1 The page says "RULE RELATED TO AI INDEX CHECKING IS CURRENTLY DEFERRED UNTIL FURTHER NOTICE". When it resumes, would a PSM system be involved?

**Why it matters:** This is an explicit, current, unresolved policy. If AI
index checking returns, it likely needs a submission step and a stored result
per document. Your system already stores files and a SHA-256 checksum — so the
hook exists. Asking shows you read the notice and are thinking ahead.

### 6.2 Who is allowed to see a student's marks, and when?

**Why it matters:** Your system releases marks automatically once all assessors
submit, and only then shows them to the student. Confirm this matches policy —
some faculties deliberately withhold marks until a moderation or exam-board
step. This is a correctness question, not a UI one.

### 6.3 What should happen to the data when a semester closes?

**Why it matters:** Your system blocks closing a term until marking is complete,
and archives completed projects. Confirm this matches the faculty's expectation
of what "semester closed" means, especially whether PSM 1 students should
automatically carry their title into PSM 2 (your system does this via rollover).

---

## Group 7 — Practical and demo questions

### 7.1 Would the faculty be willing to trial this with one batch next semester?

**Why it matters:** A pilot converts your FYP from "a system I built" into "a
system that was used", which is a far stronger outcome. Ask what the smallest
useful trial would be.

### 7.2 If we trialled it, whose data would we use, and what approvals would be needed?

**Why it matters:** Student data is personal data. If the answer involves a
faculty or university approval process, you need to know now, not in the week
before your demo.

### 7.3 What are the actual PSM 1 and PSM 2 cohort sizes?

**Why it matters:** Your storage and capacity defaults were guesses. A real
cohort size tells you whether your design holds up — and if the cohort is large,
whether the free-tier storage you are using would be adequate.

### 7.4 Is there an existing PSM system the faculty has tried before?

**Why it matters:** If a previous attempt failed or was abandoned, knowing why
is extremely valuable. You would avoid repeating a known mistake, and it is a
genuinely impressive question to ask.

---

## Things to mention about your system

Briefly, so the coordinator knows what you have — but only if they ask, or if
it fits naturally:

- **Supervision allocation with enforced per-batch capacity**, so a supervisor
  cannot be over-allocated for PSM 1 and PSM 2 independently.
- **Milestone tracking per project**, with the proposal milestone deciding the
  title and gating the rest of the chain.
- **Marking windows the coordinator opens and closes**, so assessors cannot file
  marks before marking officially starts.
- **Marks released to students automatically** once all assessors have filed.
- **Archive of completed projects**, with the submitted files preserved and
  checksummed.
- **Audit log** of who did what — including every file download.

---

## What to listen for

Signals worth writing down during the meeting:

1. **Any manual step** — a spreadsheet, a WhatsApp message, a paper form. Each
   one is either a feature or a validation that you solved the right problem.
2. **Any exception to a rule** — "usually 5 students, but sometimes...". Your
   system enforces rules; knowing where exceptions are legitimate tells you
   where to add an override rather than a block.
3. **Any mention of another system** — Author, AITCS, eReport, SMAP, or a
   university-wide system. Each is an integration question.
4. **Any date or policy marked "deferred" or "until further notice"** — these
   are live changes, and a system that adapts to them is more useful than one
   that hardcodes the current rules.

---

*Two details worth noting: the page names the coordinator as
**NORFARADILLA BINTI WAHID**, and it references **SMAP** (for ePSM/eLogbook)
and **PPA UTHM** as sources of templates — both worth asking about by name.*
