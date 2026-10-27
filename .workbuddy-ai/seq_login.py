"""Sequence diagram - Login and Authentication.

Five messages and a decision, which is all this use case has to say:

    the credentials go in
    they are checked
    a session comes back, or a refusal does

The refusals are one message, not four. Table 4.1 names them individually; the
diagram would only be repeating the table.

No section bands - the messages carry their own order.

Grounded in `AuthController::login`, `User` (locked_until, status) and the
Sanctum token guard.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_login.png"

XS = [180, 620, 1140, 1660]
HEADS = [
    "User\n(student, staff,\nadmin)",
    "Login UI\n(React SPA)",
    "AuthController\n(Laravel API)",
    "User model\n+ database",
]

STEPS = [
    {"a": 0, "b": 1, "kind": "call", "label": "1. Enter email and password"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. POST /api/auth/login"},
    {"a": 2, "b": 3, "kind": "call", "label": "3. Find the user and verify the password"},
    {"a": 3, "b": 2, "kind": "return", "label": "4. user and password hash"},
    {"a": 2, "b": 2, "kind": "self",
     "label": "5. Check: the account exists, is not locked,\n"
              "the password matches, and the account is active"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "6. 422 refused: unknown account, wrong password,\nlocked, or inactive"},
    {"a": 1, "b": 0, "kind": "return", "label": "7. Show the error and stay on the form"},

    {"a": 2, "b": 3, "kind": "call", "label": "8. Create the Sanctum token"},
    {"a": 3, "b": 2, "kind": "return", "label": "9. token"},
    {"a": 2, "b": 1, "kind": "return", "label": "10. 200 OK: token, user, home route"},
    {"a": 1, "b": 0, "kind": "return", "label": "11. Redirect to the role dashboard"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
