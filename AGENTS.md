# AGENTS.md

Instructions for any coding agent working in this repository.

## Read this first

**`PROGRESS.md`** is the project's memory. Read it before doing anything else.
It records what is finished, what is next, and the diagram conventions you must
follow. Update it at the end of each work session.

Older codebase notes: `.workbuddy-ai/memory/MEMORY.md` (covers the backend to
2026-10-05 — still accurate about the code, silent about the thesis).

## This repo is two things at once

1. **A Laravel + React PSM Assessment System** (`backend/`, `frontend/`,
   `docker/`). Runs in Docker. No PHP on the host.
2. **A final-year thesis** whose deliverables are generated as `.docx` files.

## Thesis rules

- **The thesis source folder is `C:\Users\yuehe\Desktop\study\fyp\thesis\`.**
  It is **outside this workspace**, so it is readable but **not writable**.
  Write generated documents into `./thesis/` and tell the user to copy them
  across. Never claim a file was written to the outside folder unless the write
  actually succeeded.
- Use `C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx` as the **style
  template** for generated chapters (Times New Roman 12pt, 1.5 spacing,
  `Heading 2` auto-numbering).
- Follow the style example the user supplied at
  `C:\Users\yuehe\Downloads\Chapter2.docx`.

## Diagram rules (the user rejected an earlier set — do not repeat)

- **No fills and no coloured borders.** White shapes, thin dark outline.
- **Arrows must land exactly on shape edges.** Never through a box; never
  starting in empty space.
- **Legible at print width.** Figures are placed at ~14.2–16.4 cm.
- If a flow has two independent halves, draw **two figures**.

### Diagrams are Mermaid now, not PIL (2026-11-04)

**The sequence and class diagrams are Mermaid sources in `thesis/diagrams/`**,
pasted into draw.io by the user. Do not add a figure to the old Pillow scripts.
`uml_kit.py` and the `act_*.py` / `seq_*.py` files still exist: the six **activity**
figures in Chapter 4 are still PIL PNGs and those scripts are live. Everything
else moved.

| Figure | Source | Check before claiming done |
|---|---|---|
| 4.2–4.12 sequence | `login.txt`, `title.txt`, `milestone.txt`, `evaluation.txt`, `report.txt`, `notification.txt` | `check_sequence.py`, `check_message_wording.py` |
| 4.14 class | `class_diagram.txt` **and** `class_diagram.drawio` | `check_class_diagram.py`, `verify_arrows_are_fks.py`, `check_drawio.py` |
| Activity figures | `act_*.py` → PNG | render the chapter and **look at the PNG** |
| Figure 4.1 use case | `usecase_diagram.py` | as above |

**Mermaid rules the user has set, each learnt by getting it wrong:**

- **UML lifeline shapes**: `actor`, `boundary` (a screen), `control` (a
  controller/service/model), `database`, `entity`. Not every part a rectangle.
- **Plain English, verb first.** `Queries the account by email`, never
  `SELECT …`, never `POST /api/auth/login`, never `Hash::check()`. Branch labels
  may name a condition instead; messages may not.
- **No `Note over A,B` as a flow step**, and **no `themeCSS`** — draw.io drops it
  silently, so anything it styles does nothing there.
- **Class diagrams take braces** (`class User{ … }`) and **quoted cardinalities**
  (`A "1" --> "0..*" B`). `class A [ … ]` and crow's-foot `A ||--o{ B` render as
  boxes with **no lines at all**.
- **Every arrow in the class diagram is a foreign key**, and every foreign key
  between two drawn classes is an arrow. No exceptions.

The checks in `.workbuddy-ai/` exist because each of those faults rendered
without an error. Run them; a diagram that parses is not a diagram that is right.


## Verification

Always render the document and **look at the PNG** before reporting done:

```
node <libreoffice-kit cli> render --input <docx> --output-dir <fresh-dir>
```

Paths for the kit are in the `office-render` skill. A structural check
(openpyxl / python-docx reopen) is necessary but not sufficient for
layout-heavy deliverables.

## Codebase cautions

- **The database is PostgreSQL on Supabase**, not MySQL — `DB_CONNECTION=pgsql`.
  `config/database.php` still defaults to `mysql`; that default is never used.
  Write PostgreSQL when you write SQL.
- `RUN_MIGRATIONS=true` runs `migrate:fresh --seed` — a container restart wipes
  the database.
- No test suite exists. Use `backend/tools/check_seeders.py` and
  `frontend/tools/check_frontend.py`, and `.workbuddy-ai/` for the diagram checks
  and the probe scripts.
- **A `php -l` pass is not verification.** A class referenced in the wrong
  namespace lints clean and then 500s every request that touches it. Run the
  thing, or make an HTTP call.
- The proposal milestone's verdict belongs to the panel, not a supervisor.
