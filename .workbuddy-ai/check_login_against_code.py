"""Does Figure 4.2 still describe `AuthController::login()`?

The user's instruction was "show the actual flow, whats already deleted dont show
it". A sequence diagram drifts silently: the controller changes and the picture
keeps the old guard order, and nothing fails.

This compares the diagram's claims against the method's source:

  * every branch label in the `alt` must name a guard that exists in the code;
  * every call the diagram draws (a method or facade) must appear in the source;
  * anything the code has that the diagram does not mention is listed, so the
    omission is a decision rather than an oversight.

Read-only. Prints a table; exits non-zero only on a claim the code contradicts.
"""
import io
import re
import sys

DIAGRAM = r"thesis/diagrams/login.txt"
SOURCE = r"backend/app/Http/Controllers/Api/AuthController.php"

# What the code offers, and the diagram's words for it.
GUARDS = {
    "the account does not exist": ["User::where", "$user === null"],
    "the account is locked": ["isLocked()"],
    "the password is wrong": ["Hash::check", "registerFailedLogin"],
    "the account is suspended or inactive": ["isActive()"],
}

# Behaviours the code has that the diagram intentionally collapses into one
# branch, with the reason. Listed so "not shown" is on the record.
MERGED = {
    "suspended / inactive / unverified": "all three are A.3; the message differs, "
                                         "the flow does not",
}


def main():
    diagram = io.open(DIAGRAM, encoding="utf-8").read().lower()
    source = io.open(SOURCE, encoding="utf-8").read()

    # Only the login() method matters.
    start = source.index("public function login(")
    end = source.index("public function logout(")
    login = source[start:end]

    print(f"source   : {SOURCE} login()  ({login.count(chr(10))} lines)")
    print(f"diagram  : {DIAGRAM}")
    print()

    missing_in_code = []

    print("guards the diagram draws, checked against the code:")
    for label, needles in GUARDS.items():
        in_diagram = label in diagram
        in_code = [n for n in needles if n in login]
        ok = in_diagram and len(in_code) == len(needles)
        print(f"  {'OK ' if ok else 'BAD'}  {label}")
        if in_diagram and len(in_code) != len(needles):
            missing_in_code.append(f"diagram draws {label!r} but the code has "
                                   f"only {in_code} of {needles}")

    # Tokens every real login path must have, per the source.
    print()
    print("behaviours the code performs, and whether the diagram shows them:")
    behaviours = [
        ("validate the request", "LoginRequest", "checks the request"),
        ("look the account up", "User::where('email'", "queries the account by email"),
        ("hash comparison", "Hash::check", "compares the password"),
        ("failed-attempt counter", "registerFailedLogin", "counts the failed attempt"),
        ("lock check", "isLocked()", "is locked"),
        ("active check", "isActive()", "checks the account status"),
        ("revoke existing tokens", "tokens()->delete()", "removes every previous login token"),
        ("create the token", "createToken", "stores a new login token"),
        ("mark logged in", "markLoggedIn", "updates the sign in time"),
        ("audit the sign-in", "AuditAction::Login,", "records the successful sign in"),
        # The diagram says "returns the user"; `home_route` is what the SPA
        # redirects with, and the message names the outcome rather than the key.
        ("return home_route", "home_route", "returns the user"),
    ]

    for label, needle, diagram_needle in behaviours:
        in_code = needle in login
        in_diagram = diagram_needle in diagram
        mark = "OK " if (in_code and in_diagram) else ("MISS" if in_code else "n/a ")
        print(f"  {mark} {label:26} code={in_code!s:5} diagram={in_diagram}")
        if in_code and not in_diagram:
            missing_in_code.append(f"the code does {label!r} and the diagram omits it")

    # Anything the code no longer has should not be in the diagram.
    print()
    print("gone from the code, and still in the diagram?")
    for label, needle in MERGED.items():
        print(f"  {label:34} {needle}")

    deleted_markers = ["record a verdict", "verdict board", "change title",
                       "home_route was", "quiz"]
    stale = [d for d in deleted_markers if d in diagram]
    print(f"  stale terms found in the diagram: {stale or 'none'}")

    print()
    if missing_in_code or stale:
        print("PROBLEMS:")
        for p in missing_in_code:
            print("  -", p)
        for s in stale:
            print("  - stale:", s)
        return 1

    print("OK - the diagram's guards exist in the code, every behaviour the code "
          "performs is drawn, and nothing deleted is claimed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
