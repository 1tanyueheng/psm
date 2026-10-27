"""Sequence diagram - Manage Evaluation.

The assessor does two things the system has to keep apart: **save** a mark
(editable, repeatable) and **submit** the form (final, locked). Those two are the
two bands that end in a round trip.

Guards are one labelled message each - "validate" before saving, "check complete"
before submitting - rather than a self-call per condition. The controller-to-
service call is kept, because it is where the rule about windows and editable
status lives, and that is a fact about the design, not a detail.

Twelve messages. The conditions themselves are in Table 4.4 and the branches are
shapes in Figure 4.8.

Grounded in `EvaluationController::saveMarks` / `submit`,
`EvaluationService::saveMarks` / `submit` / `computeFinalGrade` and
`MarkVisibilityService::syncProject`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_evaluation.png"

XS = [160, 520, 900, 1290, 1690]
HEADS = [
    "Assessor",
    "Evaluation UI\n(React SPA)",
    "Evaluation\nController",
    "Evaluation\nService",
    "Rubric, Scores,\nVisibility + Audit",
]

STEPS = [
    {"kind": "section", "label": "1. Open a form and mark it  ·  one 0-5 score per criterion"},

    {"a": 0, "b": 1, "kind": "call", "label": "1. Open the allocated form"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. GET /api/evaluations/{id}"},
    {"a": 2, "b": 3, "kind": "call",
     "label": "3. Load the rubric and the existing scores"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "4. SELECT evaluation_scores + weights"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "5. One 0-5 select per criterion"},

    {"kind": "section", "label": "2. Save the marks  ·  the item score becomes the rubric's weight"},

    {"a": 0, "b": 1, "kind": "call", "label": "6. Choose scores and a comment"},
    {"a": 1, "b": 2, "kind": "call", "label": "7. PUT /api/evaluations/{id}/marks"},
    {"a": 2, "b": 3, "kind": "call", "label": "8. saveMarks(evaluation, marks)"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "9. Validate: the form is editable\nand its own window is open"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "10. Save the weighted mark\nand the item score"},
    {"a": 2, "b": 1, "kind": "return", "label": "11. Marks saved"},

    {"kind": "section", "label": "3. Submit  ·  the form locks and the mark is published in one step"},

    {"a": 0, "b": 1, "kind": "call", "label": "12. Submit the form"},
    {"a": 1, "b": 2, "kind": "call", "label": "13. POST /api/evaluations/{id}/submit"},
    {"a": 2, "b": 3, "kind": "call", "label": "14. submit(evaluation)"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "15. Check complete: every criterion\nmarked, milestones approved"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "16. Lock the form, recompute and\npublish the final mark"},
    {"a": 2, "b": 1, "kind": "return", "label": "17. Marks locked"},
    {"a": 1, "b": 0, "kind": "return", "label": "18. The student sees the mark"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
