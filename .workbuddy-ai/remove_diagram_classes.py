"""Remove the seven classes the user deleted on 2026-11-04.

Leaderboard, LeaderboardEntry, MilestoneTemplate, MilestoneTemplateItem,
MilestoneSchedule, SubmissionEvent, ReminderDispatch.

Their attribute blocks and every relation that names them go; the diagram is
regenerated and re-checked afterwards. Written as a file so the removal is
exact and reviewable, rather than seven hand edits with a chance of leaving one
dangling reference that the generator would happily draw.
"""
import io
import re

PATH = r".workbuddy-ai/make_class_diagram.py"

REMOVED = {
    "Leaderboard", "LeaderboardEntry",
    "MilestoneTemplate", "MilestoneTemplateItem", "MilestoneSchedule",
    "SubmissionEvent", "ReminderDispatch",
}

src = io.open(PATH, encoding="utf-8").read()

# 1. Attribute entries: `"Name": [ ... ],` spanning one or more lines.
for name in REMOVED:
    pattern = re.compile(
        r'^\s*' + re.escape(f'"{name}"') + r':\s*\[[^\]]*\],\s*\n',
        re.M | re.S,
    )
    src, n = pattern.subn("", src)
    print(f"attributes {name:24} removed: {n}")

# 2. Relation tuples naming the class at either end.
lines = src.splitlines(keepends=True)
kept, dropped = [], 0
for line in lines:
    s = line.strip()
    if s.startswith("(") and s.count(",") >= 5:
        parts = re.findall(r'"([^"]*)"', s)
        if parts and (parts[0] in REMOVED or (len(parts) > 3 and parts[3] in REMOVED)):
            dropped += 1
            continue
    kept.append(line)

print(f"relations dropped: {dropped}")
io.open(PATH, "w", encoding="utf-8", newline="\n").write("".join(kept))

# 3. Report anything still mentioning them.
leftovers = [
    f"{i}: {l.strip()}" for i, l in enumerate(kept, 1)
    if any(f'"{n}"' in l for n in REMOVED)
]
print("leftover references:", leftovers or "none")
