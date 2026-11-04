"""Record the scope editor and the doc corrections in PROGRESS.md."""

import io

PATH = "PROGRESS.md"
text = io.open(PATH, encoding="utf-8").read()

ANCHOR = "> **The fail-open case is still there and is still the wrong way round.**"

SECTION = """#### The scope editor now exists (2026-11-04)

The three states above were unreachable by a human: `store()` accepted a `scopes`
array, `update()` ignored the field, and the user form never sent it. So every
coordinator created through the UI silently got zero rows — and zero rows means
*the whole faculty*. A permission that can only be granted by accident and never
corrected is not a permission.

| Piece | Where | What it does |
|---|---|---|
| `PUT /api/users/{user}/scopes` | `UserController::updateScopes()` | **replaces** the scope list; `[]` clears it, `[{is_primary:true}]` makes a head |
| `UserPolicy::manageScopes()` | `app/Policies` | **admin only, never your own scope** |
| `AccessCell` | `UserListPage.jsx` | a column in `/users` showing head / programmes / **All cohorts — unrestricted** (amber), with an inline editor |
| `userApi.updateScopes()` | `frontend/src/api/endpoints.js` | the call behind it |

**`manageScopes()` is deliberately *not* `update()`.** `update()` returns true
when the actor edits themselves, so reusing it would have let a coordinator widen
their own scope — the one thing a scope exists to prevent.

The unrestricted state is an **amber badge, not a blank cell**: blank reads as
"nothing", and that state actually means "everything". It is what an
administrator gets by forgetting.

Verified over HTTP (`.workbuddy-ai/check_scope_endpoint.py`, 9/9): two
programmes written; the list **replaces** rather than merges; a student refused
(422); two head rows refused (422); **a coordinator widening their own scope
refused (403)**; an empty list clears to fail-open; the seeded head scope
restored at the end.

**The fail-open case is still there** and is still the wrong way round —
a coordinator with no rows sees the whole faculty. Now that the editor exists the
fix is a UI change rather than a migration: warn at creation, or require a
programme. It was left alone in this change so the two were not confounded.

"""

assert ANCHOR in text, "anchor not found"
text = text.replace(ANCHOR, SECTION + ANCHOR)

DOCS = """### Documentation corrected (2026-11-04)

Two files told the next reader the wrong database.

| File | Was | Now |
|---|---|---|
| `README.md` | "MySQL 8" in the stack line | PostgreSQL (Supabase), with the `config/database.php` default explained |
| `docs/ARCHITECTURE.md` | a `MySQL 8.x` box in the system diagram | `PostgreSQL (Supabase)` and `Supabase Storage (S3 endpoint)` |
| `docs/SETUP.md` | "Requires MySQL 8", `localhost:3306`, `DB_CONNECTION=mysql`, "the MySQL container" | PostgreSQL, port 5432, `DB_CONNECTION=pgsql` |
| `AGENTS.md` | "diagrams are generated PNGs, start from `uml_kit.py`" | the Mermaid workflow, the UML lifeline shapes, the plain-English message rules, and which checker to run per figure |

`docs/DEPLOYMENT.md` and `GO_LIVE.md` still mention MySQL: **those are history,
not instructions** — they record that the project ran on MySQL early and moved.
They were left as written.

**`WHAT_TO_DO_NEXT.md` is stale and was not corrected.** It says the code has
never run and that Docker is not installed, neither of which has been true for
weeks. It is your own plain-English note rather than a reference document, so
rewriting it for you seemed presumptuous — say the word and it becomes an
accurate "what to do next".

"""

text = text.replace("---\n\n## 4. Diagram conventions", DOCS + "---\n\n## 4. Diagram conventions", 1)

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print("PROGRESS.md updated")
