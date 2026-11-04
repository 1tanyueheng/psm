"""Record the two-figure split in PROGRESS.md."""

import io

PATH = "PROGRESS.md"
text = io.open(PATH, encoding="utf-8").read()

ANCHOR = "##### The two faults that produced \"so many single class with no relation\""

SECTION = """##### Figure 4.14 is now **two** figures (2026-11-04)

The single figure was unreadable because `users` sat at the centre of **22
foreign keys** — more than the next three classes combined (Project 12,
StudentProfile 11, AcademicSemester 9). That is the schema, not a drawing fault,
and no amount of layout fixes it.

**The user asked whether to normalise `users` instead, and the answer given was
no.** Splitting a user row into per-role profile tables would tidy the *table*
and leave the *diagram* exactly as messy: every `created_by`, `approved_by` and
`locked_by` is a foreign key to a person, which is what `users` is for. Moving
those columns elsewhere changes where a column lives, not how many arrows there
are. So the figure was split, and nothing in the database changed.

| Figure | Classes | Relations | What it shows |
|---|---|---|---|
| **4.14a People and access** | 7 | 11 | Identity, the two roles with data of their own, sections, coursework |
| **4.14b Project work and assessment** | 22 | 43 | Registration, the chain, the rubric, the result |

**`User` is drawn in both figures.** That is the seam, and it is deliberate: 16
of the 22 arrows into `User` come from the work side, so keeping it only in the
people figure would have meant 30 relations reduced to prose ("see the other
figure") — two figures each needing the other open, which defeats the split.

**Nothing is lost.** `check_split.py` proves it: every class and every relation
from the single-figure source is either **drawn** in a figure or **named** in its
cross-reference block.

```
classes in the source        : 28      relations in the source   : 68
classes across both figures  : 28      relations drawn somewhere : 54
                                       relations named somewhere : 14
relations accounted for      : 68
```

| File | Format |
|---|---|
| `class_people.txt`, `class_project_work.txt` | Mermaid — paste into draw.io |
| `class_people.drawio`, `class_project_work.drawio` | **native draw.io** — open the file |
| `class_diagram.txt`, `class_diagram.drawio` | the single figure, still generated, **superseded** |

Both `.drawio` files come from the *same* partition as the Mermaid, so the two
formats cannot disagree. That partition lives in `split_class_diagram.py`; the
`.drawio` writer calls into it rather than re-deciding.

**`CoordinatorScope` was also corrected while here**: it still showed `psmPart`
and `programme`, which the 2026-11-04 migration had replaced with `program` and
`is_primary`. The diagram had been describing a column that no longer existed.

"""

assert ANCHOR in text, "anchor not found"
text = text.replace(ANCHOR, SECTION + ANCHOR)

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
