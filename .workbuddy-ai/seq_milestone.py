"""Sequence diagram - Manage Milestone.

No section bands: the messages carry their own order. Fourteen of them, and the
figure is wide enough at 16.4 cm that the 19 px labels read as 11 pt on the page.

    the reviewer opens the submitted work
    the reviewer decides: approve or send back
    approval opens the next milestone

Grounded in `MilestoneController::approve` / `requestRevision` / `endorse`,
`MilestoneService::transitionTo` / `approve` / `requestRevision` /
`activateNext` and `MilestoneStatus`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_milestone.png"

XS = [150, 460, 780, 1100, 1440, 1780]
HEADS = [
    "Supervisor /\nCoordinator",
    "Milestone UI\n(React SPA)",
    "Milestone\nController",
    "Milestone\nModel + DB",
    "Milestone\nService",
    "Notification",
]

STEPS = [
    {"a": 0, "b": 1, "kind": "call", "label": "1. Open the milestone"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. GET /api/milestones/{id}"},
    {"a": 2, "b": 3, "kind": "call", "label": "3. Read the milestone and its files"},
    {"a": 3, "b": 2, "kind": "return", "label": "4. milestone + submissions"},
    {"a": 2, "b": 1, "kind": "return", "label": "5. The work is shown"},

    {"a": 0, "b": 1, "kind": "call", "label": "6. Approve, or request a revision"},
    {"a": 1, "b": 2, "kind": "call",
     "label": "7. POST .../approve   or   POST .../request-revision"},
    {"a": 2, "b": 4, "kind": "call", "label": "8. approve() / requestRevision()"},
    {"a": 4, "b": 4, "kind": "self",
     "label": "9. Check: the actor may review it,\nand the status allows the move"},
    {"a": 4, "b": 3, "kind": "call",
     "label": "10. Save the status, the reviewer\nand the comment"},

    {"a": 4, "b": 4, "kind": "self",
     "label": "11. The proposal is the panel's; everything\nelse opens the next milestone"},
    {"a": 4, "b": 5, "kind": "call", "label": "12. Notify the student"},
    {"a": 2, "b": 1, "kind": "return", "label": "13. 200 OK: status and timestamp"},
    {"a": 1, "b": 0, "kind": "return", "label": "14. The next milestone is open"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
