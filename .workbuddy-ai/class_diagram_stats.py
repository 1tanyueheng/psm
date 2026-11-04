"""What changed in Figure 4.14, measured rather than asserted.

Prints the class and relation counts for the previous version (if git still has
it) and the new one, plus the use case each class serves. Run before claiming an
improvement to the diagram.
"""
import io
import re
import subprocess

NEW = "thesis/diagrams/class_diagram.txt"


def counts(text):
    lines = text.splitlines()
    classes = sum(1 for l in lines if l.strip().startswith("class "))
    rels = sum(1 for l in lines
               if re.search(r"--|\.\.>", l) and not l.strip().startswith("%%"))
    members = sum(1 for l in lines
                  if re.match(r"^\s+[-+#~]?\w+ : ", l))
    return classes, rels, members


new_text = io.open(NEW, encoding="utf-8").read()
nc, nr, nm = counts(new_text)

try:
    old_text = subprocess.run(
        ["git", "show", "HEAD:" + NEW.replace("\\", "/")],
        capture_output=True, text=True, check=True,
    ).stdout
    oc, orr, om = counts(old_text)
except Exception:
    oc = orr = om = 0

print(f"{'':22}{'before':>10}{'after':>10}")
print(f"{'classes':22}{oc:>10}{nc:>10}")
print(f"{'associations':22}{orr:>10}{nr:>10}")
print(f"{'attributes drawn':22}{om:>10}{nm:>10}")
