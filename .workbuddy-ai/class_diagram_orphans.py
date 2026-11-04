"""Find classes in class_diagram.txt that no relation touches.

The user's report is "so many single class with no relation" — a class drawn as
an island in draw.io. This prints every such class so the cause is data, not a
guess.

Written as a file: a one-liner with Mermaid's `|o--o{` in a regex is mangled by
PowerShell on the way in (PROGRESS.md §5).
"""
import collections
import io
import re

PATH = r"thesis/diagrams/class_diagram.txt"

text = io.open(PATH, encoding="utf-8").read()
body = text.split("classDiagram", 1)[1]

declared = re.findall(r"^\s*class\s+(\w+)", body, re.M)

# Classes are declared `class Name{` now, not `class Name ... [`; and a relation
# is Mermaid's documented `A "1" *-- "0..*" B : label`, or `Base <|-- Sub`.
ARROW = r"(?:\"[-0-9.*n]+\"\s*)?(?:-->|\*--|o--|--\||>|\.\.>|\.\.\||<\|--|--o|\|--|--)(?:\s*\"[-0-9.*n]+\")?"
REL = re.compile(r"^\s*(\w+)\s*" + ARROW + r"\s*(\w+)\s*:\s*(.+)$")

linked = collections.Counter()
unparsed = []

for line in body.splitlines():
    s = line.strip()
    if not s or s.startswith("%%") or s.startswith("class ") or s == "}":
        continue
    if "--" not in s and ".." not in s:
        continue
    m = REL.match(s)
    if not m:
        unparsed.append(s)
        continue
    linked[m.group(1)] += 1
    linked[m.group(2)] += 1

print(f"classes declared : {len(declared)}")
print(f"relations        : {sum(1 for l in body.splitlines() if '--' in l and not l.strip().startswith('%%'))}")
print(f"classes linked   : {len(linked)}")
print()

orphans = sorted(set(declared) - set(linked))
print(f"classes with NO relation: {len(orphans)}")
for o in orphans:
    print("   ", o)

if unparsed:
    print()
    print("relation lines the regex did not understand:")
    for u in unparsed:
        print("   ", u)

print()
print("relation count per class:")
for name, n in sorted(linked.items(), key=lambda kv: (-kv[1], kv[0])):
    print(f"    {name:26} {n}")
