"""Record the coordinator-scope change in PROGRESS.md."""

import io

PATH = "PROGRESS.md"
text = io.open(PATH, encoding="utf-8").read()

ANCHOR = "- **One section per teacher per course per term**, not per term."

SECTION = """### Coordinator scope — programme, not intake (2026-11-04)

`coordinator_scopes` used to limit a coordinator by **batch** as well as
programme, and `batch` was doing a job it had no business doing: a coordinator
scoped to `2026` could see **only** 2026 projects, on every screen, because
`Project::visibleTo()` filtered on it before anything else. The report screen
already offers `?batch=2026` for narrowing a *view* — the intake is a filter, not
a permission. The column is **dropped**.

`is_primary` had been in the table since the first migration and **nothing ever
read it**. It now means what it should have: *this coordinator heads the cohort*.

| Rows | `is_primary` | Sees |
|---|---|---|
| any | **true** | **every programme** — the head coordinator |
| one+ | false | only the programmes on their rows |
| none | — | every programme (**fail-open**, see below) |

- `CoordinatorScope::visibleProgrammes()` is the **single rule**. It returns
  `null` for "no restriction" and a list for "these programmes" — null rather
  than `[]`, because an empty array would mean *match nothing* and turn a head
  coordinator into one who sees nothing.
- `Project::visibleTo()` and `ProjectPolicy::coordinatorCovers()` both call it.
  They have to: a screen that lists a project and then refuses to open it is the
  bug that comes from two implementations of one rule.
- It matches on the **student's programme** (`members.studentProfile`), not on
  `projects.batch`. A project carries a batch because it belongs to an intake;
  a scope is about a programme.
- The seeded `coordinator@psm.test` is the **head**: `is_primary = true`, no
  programme.

**Verified over HTTP, not just in a transaction:** head coordinator → 28 of 28
projects; the same account switched to `program = BIP` → **9 of 28**; restored to
28. The transaction probe covered the other three states (head, program-limited,
no rows, `is_primary` overriding a programme on the same row).

> **The fail-open case is still there and is still the wrong way round.** A
> coordinator with **no scope rows** sees the whole faculty. That is deliberate —
> every coordinator created through the user form (which has no scope field)
> would otherwise be locked out — but it means *forgetting* to scope somebody
> grants more access, not less. Fixing it needs the scope editor that does not
> exist yet; do not flip it with a migration alone.
>
> **A 500 caught this change mid-flight:** `ProjectPolicy` lives in
> `App\\Policies`, so an unqualified `CoordinatorScope` resolved to
> `App\\Policies\\CoordinatorScope` and every project list answered 500. `php -l`
> passes such a file. It was found by making an HTTP request, which is exactly
> why linting is not verification.

"""

assert ANCHOR in text, "anchor not found"
text = text.replace(ANCHOR, SECTION + ANCHOR)

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
