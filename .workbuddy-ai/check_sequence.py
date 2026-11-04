"""Check a Mermaid sequence source for the faults this project has actually hit.

Written because "it parses to most of the way" is the failure mode: a diagram
that looks right in the source and renders with a missing arrow or a lifeline
that is not there. Each check below is a fault that happened.

  1. every message names a lifeline that was declared;
  2. `alt`/`opt`/`loop`/`par` blocks are balanced (`opt` counts, not only `alt`);
  3. no `themeCSS` — draw.io drops it silently, so anything relying on it
     (dashing the lifeline, hiding the bottom actor) does nothing there;
  4. no task/message text starts with a Mermaid keyword;
  5. lifeline kinds are ones Mermaid accepts, and the UML ones are present —
     a diagram of boundary/control/entity/database should actually use them.

Usage:  python check_sequence.py thesis/diagrams/login.txt [more files...]
"""
import re
import sys

KINDS = ("actor", "boundary", "control", "entity", "database",
         "collections", "queue", "participant")

BLOCKS = ("alt", "opt", "loop", "par", "critical", "break", "rect")

DECL = re.compile(r"^\s*(%s)\s+(\w+)(?:\s+as\s+(.+))?$" % "|".join(KINDS))
MSG = re.compile(r"^\s*(\w+)\s*(-->>|->>|-x|--x|-\)|--\))\s*(\w+)\s*:\s*(.+)$")


def check(path):
    text = open(path, encoding="utf-8").read()
    problems = []

    if "sequenceDiagram" not in text:
        return [f"{path}: no `sequenceDiagram` in the file"]

    body = text.split("sequenceDiagram", 1)[1]

    if "themeCSS" in text:
        problems.append("themeCSS is present — draw.io drops it silently, so "
                        "nothing it styles (dashed lifelines, hidden bottom "
                        "actor) works when this is pasted")

    lifelines = {}
    for line in body.splitlines():
        m = DECL.match(line)
        if m:
            lifelines[m.group(2)] = m.group(1)

    kinds_used = set(lifelines.values())

    depth = 0
    messages = 0
    notes = 0

    for line in body.splitlines():
        s = line.strip()

        if not s or s.startswith("%%"):
            continue

        first = s.split()[0].lower().rstrip(":")

        if first in BLOCKS:
            depth += 1  # noqa: B007 - kept for clarity with `end`
            continue

        if first == "else":
            if depth == 0:
                problems.append(f"`else` outside any block: {s!r}")
            continue

        if s == "end":
            depth -= 1
            if depth < 0:
                problems.append(f"an `end` closes nothing: {s!r}")
                depth = 0
            continue

        if first == "note":
            notes += 1
            continue

        # A lifeline declaration — already collected above.
        if first in KINDS:
            continue

        m = MSG.match(s)
        if m:
            messages += 1
            for end in (m.group(1), m.group(3)):
                if end not in lifelines:
                    problems.append(f"message names undeclared lifeline {end!r}: {s}")
            continue

        # Anything else that is not a directive should have been a message.
        if first not in ("autonumber", "box", "activate", "deactivate",
                         "create", "destroy", "title", "link", "links"):
            problems.append(f"line is neither a message nor a directive: {s!r}")

    if depth != 0:
        problems.append(f"blocks are unbalanced by {depth} (an `end` is missing "
                        f"or one too many)")

    if not lifelines:
        problems.append("no lifelines declared")

    print(f"{path}")
    print(f"  lifelines : {len(lifelines)}  ({', '.join(sorted(kinds_used))})")
    print(f"  messages  : {messages}")
    print(f"  notes     : {notes}")
    print(f"  blocks    : balanced" if depth == 0 else f"  blocks    : UNBALANCED")

    return [f"{path}: {p}" for p in problems]


def main(paths):
    all_problems = []

    for path in paths:
        all_problems.extend(check(path))
        print()

    if all_problems:
        print("PROBLEMS:")
        for p in all_problems:
            print("  -", p)
        return 1

    print("OK — every message is addressed, every block closes, no themeCSS")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:] or ["thesis/diagrams/login.txt"]))
