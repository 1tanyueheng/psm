"""How readable is the combined figure? Measure, do not assert.

The point of redirecting four arrows was to take pressure off `User`. This prints
what the hub looks like now, and what still points at it — so "it is more
readable" is a number rather than a hope.
"""
import io
import re
from collections import Counter

PATH = "thesis/diagrams/class_diagram.txt"

text = io.open(PATH, encoding="utf-8").read()
body = text.split("classDiagram", 1)[1]

classes = re.findall(r"^\s*class\s+(\w+)\{", body, re.M)

ARROW = re.compile(
    r"^\s*(\w+)\s*(?:\"([^\"]*)\"\s*)?(-->|\*--|o--|<\|--)(?:\s*\"([^\"]*)\")?\s*(\w+)\s*:\s*(.+)$"
)

degree = Counter()
arrows = 0

for line in body.splitlines():
    s = line.strip()
    if not s or s.startswith("%%"):
        continue
    m = ARROW.match(s)
    if not m:
        continue
    arrows += 1
    degree[m.group(1)] += 1
    degree[m.group(5)] += 1

print(f"classes : {len(classes)}")
print(f"arrows  : {arrows}")
print(f"average degree : {sum(degree.values()) / len(degree):.1f}")
print()
print("most connected:")
for name, n in degree.most_common(10):
    bar = "#" * n
    print(f"   {name:22} {n:2} {bar}")

print()
on_user = len(re.findall(r'^\s*User\s+"', body, re.M))
to_user = len(re.findall(r'"\s+User\b', body))
print(f"arrows leaving User : {on_user}")
print(f"arrows entering User: {to_user}")

# Which classes still link to User, and is that number going down?
print()
print("classes still drawing an arrow to/from User:")
for name, n in degree.most_common():
    if name == "User":
        continue
    if re.search(rf'^\s*(User\s+"[^"]*"\s*-->\s*"[^"]*"\s+{name}|{name}\s+"[^"]*"\s*-->\s*"[^"]*"\s+User)\b',
                 body, re.M):
        print(f"   {name}")
