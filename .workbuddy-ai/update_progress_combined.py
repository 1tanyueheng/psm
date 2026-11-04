"""Rewrite the split section of PROGRESS.md for the combined figure."""

import io

PATH = "PROGRESS.md"
text = io.open(PATH, encoding="utf-8").read()

START = "##### Figure 4.14 is now **two** figures (2026-11-04)"
END = '##### The two faults that produced "so many single class with no relation"'

i = text.index(START)
j = text.index(END)

REPLACEMENT = """##### Figure 4.14 is one figure again, with role arrows on the profiles (2026-11-04)

Two attempts, in order, because the first was rejected:

1. **Split into two figures** (4.14a people, 4.14b work). Readable, and it lost
   nothing — but **the thesis does not permit two class diagrams**, so it was
   undone.
2. **One figure, with role-specific associations re-pointed** to `StudentProfile`
   / `SupervisorProfile` instead of `User`.

**Only four arrows could move**, and the rule is what makes the rest legitimate:

| Moved to `SupervisorProfile` | Foreign key |
|---|---|
| `lectures` | `sections.lecturer_id` |
| `assesses` | `evaluations.assessor_id` |
| `examines` | `examiner_assignments.examiner_id` |
| `examiner 1 / 2` | `examiner_pairs.examiner_1_id`, `examiner_2_id` |

Each is a foreign key to `users` that **can only ever hold a supervisor**. The
other 16 arrows into `User` are `created_by`, `approved_by`, `locked_by`,
`rejected_by` and friends — **the actor may be an admin**, so drawing them on a
profile class would state something false in a figure whose whole claim is to
describe the schema. They stay.

**`User` still carries 18 arrows** (down from 20). The diagram is not untangled
and saying otherwise would be wrong; it is 28 classes, 70 arrows, average degree
5.0, and `User` is still the most connected class at 20. The measurable gain is
that the **assessment chain no longer runs through the identity table** — the
rubric, the evaluations and the panel now attach to the people who actually hold
those roles.

**The redirect found two arrows that had never been drawn.** `examiner_pairs` and
`examiner_assignments` each had ONE arrow to `User` standing for two columns: the
examiner (a supervisor) and the actor (`created_by` / `assigned_by`, anyone).
Re-pointing that single arrow to the profile left the actor undrawn. Each table
now has two arrows, one to each class. The same check found
`final_grades.locked_by` drawn nowhere — the arrow said `releases` and meant only
`released_by`.

`check_combined_diagram.py` is the guard, in both directions:

- an arrow may sit on `SupervisorProfile` **only** if the column behind it is
  supervisor-only;
- every actor column must **still** land on `User`.

> **If a future edit wants to move more arrows off `User`, the question to answer
> first is: can this column hold an admin?** If yes, it belongs on `User`, and
> moving it makes the diagram prettier and wrong.

The split scripts (`split_class_diagram.py`, `split_class_drawio.py`,
`check_split.py`) and the four split files were **deleted** — one figure is the
requirement and two sets of files describing the same model is how they drift.

"""

text = text[:i] + REPLACEMENT + text[j:]

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
