"""Sequence diagram - Manage Title and Registration.

Drawn at the level the reader needs: who asks whom for what, in order. A
request and its reply are one exchange (the arrow returns on the same row when
the reply is the answer to the request), controller-to-service delegation is
kept because it says where the rule lives, and each stage's guard is one
labelled message rather than a self-call per check.

Fifteen messages. Everything that is not a step of the *flow* is elsewhere:
the individual guards are listed in Table 4.2, and the branches are shapes in
Figure 4.4.

Grounded in `RegistrationService::submitAgreement` / `acknowledgeBySupervisor`
/ `withdrawAgreement` and `LampiranBController::file` / `sign`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_title.png"

XS = [150, 500, 860, 1220, 1600, 1970]
HEADS = [
    "Student /\nSupervisor",
    "Registration UI\n(React SPA)",
    "Registration\nController",
    "Registration\nService",
    "Agreement +\nSupervision",
    "Lampiran B +\nMilestone",
]

STEPS = [
    {"kind": "section", "label": "1. Filing Lampiran A"},

    {"a": 0, "b": 1, "kind": "call", "label": "1. Fill in Lampiran A and submit"},
    {"a": 1, "b": 2, "kind": "call",
     "label": "2. POST /api/registrations/agreements"},
    {"a": 2, "b": 3, "kind": "call", "label": "3. submitAgreement(...)"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "4. Validate: open term, no project yet,\ntitle usable, supervisor serves\nthe programme"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "5. Save the agreement\nstatus = pending_supervisor"},
    {"a": 2, "b": 1, "kind": "return", "label": "6. 201 Created"},

    {"kind": "section", "label": "2. Supervisor acknowledgement  ·  this is where the pairing is created"},

    {"a": 0, "b": 1, "kind": "call",
     "label": "7. Choose one of the proposed titles\nand acknowledge"},
    {"a": 1, "b": 2, "kind": "call",
     "label": "8. POST .../{agreement}/acknowledge"},
    {"a": 2, "b": 3, "kind": "call", "label": "9. acknowledgeBySupervisor(...)"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "10. Create the supervision and\nsave the agreed title"},
    {"a": 2, "b": 1, "kind": "return", "label": "11. Lampiran B may be filed"},

    {"kind": "section", "label": "3. Filing and signing Lampiran B  ·  the signature opens the proposal"},

    {"a": 0, "b": 1, "kind": "call", "label": "12. File Lampiran B, then sign it"},
    {"a": 1, "b": 3, "kind": "call",
     "label": "13. PUT .../{form}/file\nPOST .../{form}/sign"},
    {"a": 3, "b": 5, "kind": "call",
     "label": "14. Endorse the form and\nopen the proposal milestone"},
    {"a": 1, "b": 0, "kind": "return", "label": "15. The proposal is open"},

    {"kind": "section", "label": "4. Withdrawal  ·  only while no project exists yet"},

    {"a": 0, "b": 1, "kind": "call", "label": "16. Withdraw the filing"},
    {"a": 1, "b": 2, "kind": "call", "label": "17. POST .../{agreement}/withdraw"},
    {"a": 2, "b": 3, "kind": "call", "label": "18. withdrawAgreement()"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "19. 200 OK: cancelled, pairing retired"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
