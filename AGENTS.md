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
- **Legible at print width.** Figures are placed at ~14.2 cm.
- If a flow has two independent halves, draw **two figures**.
- Diagrams are **generated PNGs** from Python/Pillow scripts in
  `.workbuddy-ai/`. Start from `uml_kit.py`; reuse the matching
  `act_*.py` / `seq_*.py` / `build_ch4_*.py`.

## Verification

Always render the document and **look at the PNG** before reporting done:

```
node <libreoffice-kit cli> render --input <docx> --output-dir <fresh-dir>
```

Paths for the kit are in the `office-render` skill. A structural check
(openpyxl / python-docx reopen) is necessary but not sufficient for
layout-heavy deliverables.

## Codebase cautions

- `RUN_MIGRATIONS=true` runs `migrate:fresh --seed` — a container restart wipes
  the database.
- No test suite exists. Use `backend/tools/check_seeders.py` and
  `frontend/tools/check_frontend.py`.
- The proposal milestone's verdict belongs to the panel, not a supervisor.
