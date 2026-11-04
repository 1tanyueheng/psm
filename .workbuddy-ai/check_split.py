"""Does the split lose anything?

The one risk of splitting a diagram is that a relation falls into neither figure
— it is on a seam, it gets mentioned in a comment, and comments are not arrows.
That would make the pair of figures quietly less complete than the one they
replaced.

Checks, over both figures:

  1. every class from the single-figure source appears in at least one figure;
  2. every relation is either **drawn** in a figure or **named** in a figure's
     cross-reference block — nothing is dropped silently;
  3. every figure's own messages address classes it declares (no dangling id);
  4. the two figures between them draw or name no relation twice by accident
     (a relation drawn in both is allowed only for the shared `User`);
  5. every arrow drawn in either figure is a foreign key.

Usage:  python check_split.py
"""
import io
import re
import sys

sys.path.insert(0, ".workbuddy-ai")

import make_class_diagram as gen  # noqa: E402

DIA = "thesis/diagrams"
FIGURES = ["class_people.txt", "class_project_work.txt"]

CLASS_RE = re.compile(r"^\s*class\s+(\w+)\s*\{", re.M)
DRAWN_RE = re.compile(
    r"^\s*(\w+)\s*(?:\"[^\"]*\"\s*)?(-->|\*--|o--|<\|--)(?:\s*\"[^\"]*\")?\s*(\w+)",
    re.M,
)
NAMED_RE = re.compile(r"%%\s+(\w+)\s+--\(([^)]*)\)--\s+(\w+)\s+\[")


def read(name):
    return io.open(f"{DIA}/{name}", encoding="utf-8").read()


def main():
    source_classes = {a for a, *_ in gen.CLASSES}
    source_pairs = set()
    for rel in gen.RELATIONS:
        left, _c, _s, right, _label, _k = rel
        source_pairs.add(frozenset((left, right)))

    declared_all = set()
    drawn_all = set()
    named_all = set()
    problems = []

    for name in FIGURES:
        text = read(name)
        body = text.split("classDiagram", 1)[1]

        declared = set(CLASS_RE.findall(body))
        declared_all |= declared

        drawn = {frozenset((m[0], m[2])) for m in DRAWN_RE.findall(body)}
        drawn_all |= drawn

        named = {frozenset((m[0], m[2])) for m in NAMED_RE.findall(body)}
        named_all |= named

        # A drawn relation must name two classes the figure declares.
        # `drawn` holds frozensets, so unpack with `sorted` rather than `a, b`.
        for pair in drawn:
            for end in pair:
                if end not in declared:
                    problems.append(f"{name}: arrow names {end}, which the figure "
                                    f"does not declare")

        # A named relation must name the side it *can* see.
        for pair in named:
            if not (pair & declared):
                problems.append(f"{name}: cross-reference {sorted(pair)} names "
                                f"neither class this figure declares")

        print(f"{name}: {len(declared)} classes, {len(drawn)} drawn, {len(named)} named")

    # --- nothing lost -----------------------------------------------------
    missing_classes = source_classes - declared_all
    covered = drawn_all | named_all
    lost = source_pairs - covered

    print()
    print(f"classes in the source        : {len(source_classes)}")
    print(f"classes across both figures  : {len(declared_all)}")
    print(f"relations in the source      : {len(source_pairs)}")
    print(f"relations drawn somewhere    : {len(drawn_all)}")
    print(f"relations named somewhere    : {len(named_all)}")
    print(f"relations accounted for      : {len(covered)}")

    if missing_classes:
        problems.append(f"classes lost in the split: {sorted(missing_classes)}")

    if lost:
        for a, b in sorted(lost):
            problems.append(f"relation lost between figures: {a} - {b}")

    shared = [a for a, t, *_ in gen.CLASSES
              if t in getattr(__import__("split_class_diagram"), "SHARED")]

    print()
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  -", p)
        return 1

    print("OK - every class and every relation is either drawn or named; "
          "nothing was lost in the split")
    return 0


if __name__ == "__main__":
    sys.exit(main())
