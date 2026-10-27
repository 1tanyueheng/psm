"""Raise every figure placed in the Chapter 4 documents to fill the text width.

All the drawings are now about 4:3 with 20-22 px type, so a `width=Cm(...)` of
12.6 or 14.2 leaves them small on the page for no reason. A portrait A4 page
with Word's default margins has 16.51 cm of text width; a figure placed at 16.4
keeps a small gutter.

Only the `figure(...)` calls are touched — the pictures are placed from these
numbers and nowhere else — so this cannot disturb the prose or the tables.
"""
from pathlib import Path

HERE = Path(__file__).resolve().parent
TARGET = "16.4"

BUILDERS = [
    "build_ch4_login.py",
    "build_ch4_milestone.py",
    "build_ch4_report.py",
    "build_ch4_notif.py",
    "build_ch4_title_evaluation.py",
]

OLD_WIDTHS = ["10.0", "12.6", "12.8", "14.2"]


def patch(name):
    path = HERE / name
    text = path.read_text(encoding="utf-8")
    before = text
    for w in OLD_WIDTHS:
        text = text.replace(f'figure(os.path.join(DIA, "', f'figure(os.path.join(DIA, "')
        # width appears as  ..., 14.2,\n       "Figure
        text = text.replace(f"), {w},\n", f"), {TARGET},\n")
    if text != before:
        path.write_text(text, encoding="utf-8")
        print(f"{name}: widths raised")
    else:
        print(f"{name}: no figure widths found")


if __name__ == "__main__":
    for b in BUILDERS:
        patch(b)
