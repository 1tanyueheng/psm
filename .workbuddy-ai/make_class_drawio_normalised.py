"""Write the native .drawio for the normalised Figure 4.14.

`make_class_drawio.py` reads the *implementation* modules (`gen.CLASSES`,
`gen.ATTRIBUTES`, `gen.C`). The normalised design is a separate declaration in
`make_class_diagram_normalised.py`, and its relation tuples are five-wide rather
than six — no spare slot.

Rather than bend the design to match the writer, this gives the writer a small
shim object that answers the same three attributes. That keeps one `.drawio`
code path and one geometry model instead of a second copy that would drift.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import make_class_diagram_normalised as design  # noqa: E402
import make_class_drawio as writer  # noqa: E402

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
DIA = os.path.join(BASE, "thesis", "diagrams")


class NormalisedModel:
    """The three attributes `make_class_drawio` reads, from the design module."""

    CLASSES = design.CLASSES
    ATTRIBUTES = design.ATTRIBUTES
    C = design.CARD
    RELATIONS = []


def six_wide(relations):
    """The design's five-wide tuples in the writer's six-wide shape."""
    return [
        (left, card, "", right, label, kind)
        for left, card, right, label, kind in relations
    ]


def main():
    original = writer.gen
    writer.gen = NormalisedModel

    try:
        writer.ROW_GAP = 50
        writer.main(
            classes=design.CLASSES,
            relations=six_wide(design.RELATIONS),
            out=os.path.join(DIA, "class_diagram.drawio"),
            title="4.14 Class diagram - normalised design",
        )
    finally:
        writer.gen = original


if __name__ == "__main__":
    main()
