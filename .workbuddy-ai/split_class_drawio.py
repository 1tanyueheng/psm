"""Write a native .drawio for each half of Figure 4.14.

`split_class_diagram.py` writes the two Mermaid sources; this writes the two
`.drawio` files from the **same** partition, by calling `make_class_drawio.main()`
with the subset. One partition, two formats, so they cannot disagree.

Output:
  thesis/diagrams/class_people.drawio        (4.14a)
  thesis/diagrams/class_project_work.drawio  (4.14b)
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import make_class_diagram as gen  # noqa: E402
import make_class_drawio  # noqa: E402
import split_class_diagram as splitter  # noqa: E402

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
DIA = os.path.join(BASE, "thesis", "diagrams")


def partition():
    """The same partition `split_class_diagram.py` uses.

    Kept here as a function rather than imported state so the two scripts cannot
    drift: both call `split_class_diagram`'s constants.
    """
    table_of = {a: t for a, t, _uc, _g in gen.CLASSES}

    shared = {a for a, t in table_of.items() if t in splitter.SHARED}
    people = {a for a, t in table_of.items() if t in splitter.PEOPLE_TABLES} | shared
    work = (set(table_of) - people) | shared

    people_classes = [c for c in gen.CLASSES if c[0] in people]
    work_classes = [c for c in gen.CLASSES if c[0] in work]

    people_rels = [r for r in gen.RELATIONS if r[0] in people and r[3] in people]
    work_rels = [r for r in gen.RELATIONS if r[0] in work and r[3] in work]

    return people_classes, people_rels, work_classes, work_rels


def main():
    pc, pr, wc, wr = partition()

    print("4.14a people")
    make_class_drawio.main(
        classes=pc,
        relations=pr,
        out=os.path.join(DIA, "class_people.drawio"),
        title="4.14a People and access",
    )

    print()
    print("4.14b project work")
    make_class_drawio.main(
        classes=wc,
        relations=wr,
        out=os.path.join(DIA, "class_project_work.drawio"),
        title="4.14b Project work and assessment",
    )


if __name__ == "__main__":
    main()
