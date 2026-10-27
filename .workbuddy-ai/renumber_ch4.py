"""Rebuild every Chapter 4 document from the simplified figures.

The figures changed. Their message numbers changed with them, so the prose that
walks through "message 18 confirms..." is now wrong in every document, even
where the flow it describes is still right. This script:

  1. renumbers the four older builders from the old seven-use-case scheme to the
     six-use-case one (Login 01, Title 02, Milestone 03, Evaluation 04, Report
     05, Notification 06);
  2. rewrites the sequence-diagram commentary for the messages that now exist;
  3. widens every figure, because the drawings are now about 4:3 and the type in
     them is bigger — a wider placement is what lets the reader see it.

Run it once, then render and READ the pages; the build scripts emit usable
`.docx` but nothing here substitutes for looking.
"""
import re
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent

# Old -> new numbering. The table is the chapter-wide table number and the two
# figure numbers; the login pair is unchanged, everything after shifts by one
# because Pairing is gone.
RENUMBER = {
    "build_ch4_login.py": [],
    "build_ch4_milestone.py": [
        ("Table 4.3", "Table 4.3"),
    ],
    "build_ch4_report.py": [
        ("Table 4.4", "Table 4.5"),
        ("Figure 4.7", "Figure 4.9"),
        ("Figure 4.8", "Figure 4.10"),
    ],
    "build_ch4_notif.py": [
        ("Table 4.5", "Table 4.6"),
        ("Figure 4.9", "Figure 4.11"),
        ("Figure 4.10", "Figure 4.12"),
        ("Figure 4.11", "Figure 4.13"),
    ],
}

# The four documents that still carry "sixth/fourth use case" style ordinals.
ORDINALS = {
    "build_ch4_report.py": [
        ("the fourth use case", "the fifth use case"),
    ],
    "build_ch4_notif.py": [
        ("the fifth use case", "the sixth use case"),
    ],
}


def patch(name, pairs):
    path = HERE / name
    text = path.read_text(encoding="utf-8")
    before = text
    for old, new in pairs:
        if old != new:
            text = text.replace(old, new)
    if text != before:
        path.write_text(text, encoding="utf-8")
        print(f"patched {name}: {len(pairs)} rule(s)")
    else:
        print(f"{name}: nothing to do")


if __name__ == "__main__":
    for name, pairs in RENUMBER.items():
        patch(name, pairs + ORDINALS.get(name, []))
