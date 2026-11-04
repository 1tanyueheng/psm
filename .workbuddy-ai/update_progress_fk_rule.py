"""Record the foreign-key rule change in PROGRESS.md.

Written as a file rather than `python -c`: the replacement text is long, contains
apostrophes and backticks, and a heredoc through PowerShell mangles it
(PROGRESS.md section 5).
"""
import io

PATH = "PROGRESS.md"

text = io.open(PATH, encoding="utf-8").read()

text = text.replace(
    "**Figure 4.14** — **29 classes and 59 relations**",
    "**Figure 4.14** — **28 classes and 68 relations**",
)
text = text.replace(
    "then trimmed to 29 at the user's request",
    "then trimmed at the user's request and reduced to the foreign keys alone",
)
text = text.replace(
    "**Figure 4.14, 29 classes and 59 relations**",
    "**Figure 4.14, 28 classes and 68 relations**",
)
text = text.replace(
    "**the native file to open in draw.io**, 29 vertices and 59 anchored edges",
    "**the native file to open in draw.io**, 28 vertices and 68 anchored edges",
)

ANCHOR = (
    "The diagram is now **29 classes and 59 relations**, and every arrow is still\n"
    "backed by a live foreign key (`check_class_diagram.py`: 0 unsupported, 0\n"
    "missing), with no class left as an island."
)

REPLACEMENT = """**The arrows are now exactly the foreign keys (2026-11-04).** The user's rule:
*connect the relation if a foreign key exists; if there is none, do not; if one
exists and is missing, draw it.* Three corrections followed:

| Was | Now | Why |
|---|---|---|
| `User - DatabaseNotification` drawn | **class removed** | `notifications` has no foreign key to `users` (polymorphic `notifiable_id`), so there was nothing to connect |
| `student_enrolments.enrolled_by` excluded as "an audit trail" | **drawn** | it is a real foreign key; the exclusion was my opinion, not the schema's |
| 17 `*_by` columns excused by an `*_by` pattern in the checker | **drawn**, one arrow each to `User` | the checker was hiding real constraints |

The exception lists (`NOT_DRAWN`, `DECLARED_WITHOUT_FK`) are **gone**. Nothing is
exempt except that **two foreign keys between the same pair collapse to one
arrow** — `examiner_pairs.examiner_1_id` and `examiner_2_id` are both to `users`,
and Mermaid cannot draw the same pair twice.

Result: **28 classes, 68 relations**, verified in both directions:

```
arrows with a foreign key behind them : 68
arrows with NO foreign key            : 0
foreign keys between drawn classes, NOT drawn: 0
```

`verify_arrows_are_fks.py` is the independent check. It had its own bug — it
skipped inheritance edges, so it reported `student_profiles.user_id` as undrawn
when `User <|-- StudentProfile` *is* that foreign key. **Two checkers disagreeing
is how that was found**, which is the argument for keeping both.

> **Known consequence:** `User` now holds about 22 relations and the diagram is
> dense around it. That is what the schema looks like; a reader who wants fewer
> arrows is asking for fewer constraints, not a tidier picture."""

assert ANCHOR in text, "anchor not found — PROGRESS.md has moved on"
text = text.replace(ANCHOR, REPLACEMENT)

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
