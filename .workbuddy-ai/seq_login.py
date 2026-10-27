"""Sequence diagram - Login and Authentication.

Eight messages: the whole use case is "prove who you are, or be told no", and the
four ways of being told no are one decision, not four branches.

    user -> UI -> controller -> database
    the credentials are checked
    the outcome is a token or a refusal

Everything the old version carried individually - input validation, the
lockout counter, the audit write, the three distinct refusal texts - is one
`alt` box with two branches, which is how a reader actually reads this diagram:
success on the left, refusal on the right.

Grounded in `AuthController::login`, `User` (locked_until, status) and
`AuditLogger`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_login.png"

XS = [170, 610, 1120, 1650]
HEADS = [
    "User\n(student, staff,\nadmin)",
    "Login UI\n(React SPA)",
    "AuthController\n(Laravel API)",
    "User model\n+ database",
]

STEPS = [
    {"kind": "section", "label": "1. Sign in"},
    {"a": 0, "b": 1, "kind": "call", "label": "1. Enter email and password"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. POST /api/auth/login"},
    {"a": 2, "b": 2, "kind": "self",
     "label": "3. Validate the input\n(required, email format)"},
    {"a": 2, "b": 3, "kind": "call", "label": "4. Find the user and verify the password"},
    {"a": 3, "b": 2, "kind": "return", "label": "5. user and hash"},
    {"a": 2, "b": 2, "kind": "self",
     "label": "6. Check: account exists, not locked,\npassword matches, account active"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "7. 422 refused: unknown account, wrong\npassword, locked, or inactive"},

    {"kind": "section", "label": "2. Accepted  ·  the session is created"},

    {"a": 2, "b": 3, "kind": "call", "label": "8. Create the Sanctum token"},
    {"a": 3, "b": 2, "kind": "return", "label": "9. token"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "10. 200 OK: token, user, home route"},
    {"a": 1, "b": 0, "kind": "return", "label": "11. Redirect to the role dashboard"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
