"""Sequence diagram - Manage Evaluation.

Simplified for the page. **One message per thing that changes hands**, not one
per method call: a request and its reply are a single row, and the controller
handing work to the service is the same row as the service answering.

The use case has one human who does two things - marks and submits:

    1  the assessor opens a form            -> the rubric comes back
    2  the assessor saves marks             -> weighted marks are stored
    3  the assessor submits the form        -> the form locks, the mark publishes

Nine numbered messages, five lifelines, three bands. The gates are written on
the message that enforces them instead of being drawn as separate calls, because
Table 4.4 and Figure 4.8 already spell them out.

Grounded in `EvaluationController::saveMarks` / `submit`,
`EvaluationService::saveMarks` / `submit` and `MarkVisibilityService`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_evaluation.png"

XS = [150, 500, 880, 1290, 1700]
HEADS = [
    "Assessor",
    "Evaluation UI\n(React SPA)",
    "Evaluation\nController",
    "Evaluation\nService",
    "Rubric, Scores,\nVisibility + Audit",
]

STEPS = [
    {"kind": "section", "label": "1. Marking  ·  one 0-5 score per criterion"},

    {"a": 0, "b": 1, "kind": "call", "label": "1. Open the allocated form"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. GET /api/evaluations/{id}"},
    {"a": 2, "b": 3, "kind": "call",
     "label": "3. Load the form with its rubric and scores"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "4. SELECT evaluation_scores + weights"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "5. 200 OK: one 0-5 select per criterion"},
    {"a": 1, "b": 0, "kind": "return", "label": "6. The form is shown"},

    {"kind": "section", "label": "2. Saving  ·  the item score becomes the rubric's own weight"},

    {"a": 0, "b": 1, "kind": "call", "label": "7. Choose scores and a comment"},
    {"a": 1, "b": 2, "kind": "call", "label": "8. PUT /api/evaluations/{id}/marks"},
    {"a": 2, "b": 3, "kind": "call", "label": "9. saveMarks(evaluation, marks)"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "10. Gate: the form is editable and\nthis form's window is open"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "11. UPDATE the weighted mark\n+ the item score as answered"},
    {"a": 2, "b": 1, "kind": "return", "label": "12. 200 OK: marks saved"},

    {"kind": "section", "label": "3. Submitting  ·  the form locks and the mark is published in one step"},

    {"a": 0, "b": 1, "kind": "call", "label": "13. Submit"},
    {"a": 1, "b": 2, "kind": "call", "label": "14. POST /api/evaluations/{id}/submit"},
    {"a": 2, "b": 3, "kind": "call", "label": "15. submit(evaluation)"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "16. Gate: every criterion marked, the\nmilestones approved, the window open"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "17. Lock the form, recompute the final\nmark, publish it to the student"},
    {"a": 3, "b": 4, "kind": "call", "label": "18. Notify the coordinator"},
    {"a": 2, "b": 1, "kind": "return", "label": "19. 200 OK: marks locked"},
    {"a": 1, "b": 0, "kind": "return",
     "label": "20. The student sees the mark"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
