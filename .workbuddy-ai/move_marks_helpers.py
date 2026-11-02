"""Move the three marks helpers out of the lecturer dashboard.

`formFromMarks`, `payloadFromForm` and `clientError` belonged to the marking row
that moved to its own page on 2026-11-02. They are deleted here and re-added in
`LecturerMarkStudentPage.jsx`; this script only removes them from the dashboard
so the two files cannot drift while both define them.

Written as a file rather than `python -c` because a one-liner carrying JSX and
apostrophes is mangled on the way through PowerShell (PROGRESS.md §5).
"""
import io
import re

PATH = r"frontend/src/pages/lecturer/LecturerDashboard.jsx"

text = io.open(PATH, encoding="utf-8").read()
lines = text.splitlines(keepends=True)

start = next(i for i, l in enumerate(lines)
             if l.startswith("function formFromMarks"))
# Walk back over the docblock above it, so the comment goes with the function.
while start > 0 and lines[start - 1].strip() in ("*/", "*", ""):
    start -= 1
    if lines[start].lstrip().startswith("/**"):
        break
end = next(i for i, l in enumerate(lines) if l.startswith("export default function LecturerDashboard"))

removed = end - start
remaining = lines[:start] + lines[end:]
io.open(PATH, "w", encoding="utf-8", newline="\n").write("".join(remaining))

print(f"removed lines {start + 1}-{end} ({removed} lines: the three helpers)")
print(f"file is now {len(remaining)} lines")
