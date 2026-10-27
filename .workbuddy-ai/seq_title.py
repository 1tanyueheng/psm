"""Sequence diagram - Manage Title and Registration.

Simplified for the page. The rule applied: **one message per thing that
changes hands**, not one per method call. A request and its reply are one row;
a controller delegating to a service and the service answering are one row.

So the four stages of the use case are four request/response exchanges:

    1  the student files Lampiran A            -> the agreement waits
    2  the supervisor acknowledges it           -> the supervisor and the title are fixed
    3  the student files Lampiran B, signs it   -> the proposal opens
    4  the student withdraws                    -> the filing is cancelled

Ten numbered messages, six lifelines, four bands. The guard on each stage is
written on the message that enforces it rather than drawn as a separate call.

Grounded in `RegistrationService::submitAgreement` / `acknowledgeBySupervisor`
/ `withdrawAgreement` and `LampiranBController::file` / `sign`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_title.png"

XS = [150, 500, 860, 1220, 1600, 1960]
HEADS = [
    "Student /\nSupervisor",
    "Registration UI\n(React SPA)",
    "Registration\nController",
    "Registration\nService",
    "Agreement +\nSupervision",
    "Lampiran B +\nMilestone",
]

STEPS = [
    {"kind": "section", "label": "1. Filing Lampiran A  ·  the student proposes up to three titles"},

    {"a": 0, "b": 1, "kind": "call",
     "label": "1. Complete Lampiran A and submit it"},
    {"a": 1, "b": 3, "kind": "call",
     "label": "2. POST /api/registrations/agreements\nsubmitAgreement()"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "3. Gate: a term is open, no project or\nagreement in flight, the title is usable,\nthe supervisor serves the programme"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "4. Create the agreement\nstatus = pending_supervisor"},
    {"a": 1, "b": 0, "kind": "return",
     "label": "5. 201 Created: the supervisor is notified"},

    {"kind": "section", "label": "2. Supervisor acknowledgement  ·  the title and the pairing are fixed here"},

    {"a": 0, "b": 1, "kind": "call",
     "label": "6. Choose one proposed title and acknowledge"},
    {"a": 1, "b": 3, "kind": "call",
     "label": "7. POST .../{agreement}/acknowledge\nacknowledgeBySupervisor()"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "8. Gate: still pending, and the title is\none the student proposed"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "9. Assign the supervisor, record the\nagreed title, status = approved"},
    {"a": 1, "b": 0, "kind": "return", "label": "10. Lampiran B may be filed"},

    {"kind": "section", "label": "3. Filing and signing Lampiran B  ·  the signature opens the proposal"},

    {"a": 0, "b": 1, "kind": "call", "label": "11. File Lampiran B, then sign it"},
    {"a": 1, "b": 3, "kind": "call",
     "label": "12. PUT .../{form}/file\nPOST .../{form}/sign"},
    {"a": 3, "b": 3, "kind": "self",
     "label": "13. Gate: the form is the caller's, and the\ntitle differs from the one the panel refused"},
    {"a": 3, "b": 5, "kind": "call",
     "label": "14. status = endorsed\nopen the proposal milestone"},
    {"a": 1, "b": 0, "kind": "return", "label": "15. The proposal is open"},

    {"kind": "section", "label": "4. Withdrawal  ·  only while no project has been registered from the filing"},

    {"a": 0, "b": 1, "kind": "call", "label": "16. Withdraw the filing, with a reason"},
    {"a": 1, "b": 3, "kind": "call", "label": "17. POST .../{agreement}/withdraw"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "18. Cancel the agreement, retire the supervision"},
    {"a": 1, "b": 0, "kind": "return", "label": "19. 200 OK: the filing is kept"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
