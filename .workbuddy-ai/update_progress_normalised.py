"""Record the normalised design in PROGRESS.md."""

import io

PATH = "PROGRESS.md"
text = io.open(PATH, encoding="utf-8").read()

ANCHOR = "##### Figure 4.14 is one figure again, with role arrows on the profiles (2026-11-04)"

SECTION = """##### Figure 4.14 is now a **normalised design**, not a mirror (2026-11-04)

At the user's instruction: "create the normalised table for each role — the real
system will be considered later." **This is a design model. The database has not
changed**, and the difference is stated in both the diagram's header and here,
because a figure that claims to be the schema and is not is worse than no figure.

`User` now holds **identity and authentication only**. Every role has its own
table joined 1:1:

| Table | Holds |
|---|---|
| `User` | name, email, password, `role_id`, status, phone, login state |
| `Role` | the role vocabulary as data, not a string column |
| `AdminProfile` | `staff_id`, `department`, `scope` |
| `CoordinatorProfile` | `staff_id`, `department`, `is_head` |
| `LecturerProfile` | `staff_id`, `department`, `course_code` |
| `SupervisorProfile` | `staff_id`, `department`, programme, capacities |
| `StudentProfile` | matric, programme, batch, section, term |

| | implemented | normalised |
|---|---|---|
| classes | 28 | **33** |
| arrows into `User` | 18 | **21** (five are the role joins) |

**The arrow count went UP and that is correct.** `staff_id`, `department`,
`programme`, `capacity*`, `matric_no` and `batch` all leave `User` — that is what
normalising means — and five 1:1 joins arrive to replace them. The count was never
the readability metric; the composition of the `User` block is.

**The `*_by` columns still point at `User`, and should.** `created_by`,
`approved_by`, `locked_by` are about *an actor*, and an actor may hold any role or
none. They are not role-specific, so they belong on the identity table. Anyone
tempted to re-point them should answer "can this column hold an admin?" first.

> **Two diagrams now exist and they describe different things:**
>
> | File | Describes |
> |---|---|
> | `class_diagram.txt` / `.drawio` | **the normalised design** — what the chapter presents |
> | `class_diagram_implemented.txt` / `.drawio` | **what runs** — `users` with staff columns, two profile tables, no role table |
>
> The implemented one is kept so the gap is auditable rather than forgotten. If
> the code is ever brought across, that file is the "before".

**`check_combined_diagram.py` cannot validate this file** — it compares arrows to
live foreign keys and the role tables do not exist. `check_normalised_diagram.py`
replaces it and checks the design's own invariants instead: each role joins `User`
exactly once and 1:1, **no role attribute survives on `User`**, every class has
members and at least one relation, and the rendered file matches the module's
declarations.

"""

assert ANCHOR in text, "anchor not found"
text = text.replace(ANCHOR, SECTION + ANCHOR)

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
