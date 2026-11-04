"""Check that a sequence diagram's messages read as English, one verb each.

The reader of this thesis is a panel member, not a developer. Two rules, both the
user's:

  1. **Every message starts with a verb.** "Queries the account by email", not
     "the account by email" and not "Email".
  2. **No machine syntax.** No endpoint paths (`POST /api/auth/login`), no SQL
     (`SELECT`, `INSERT`, `UPDATE`, `DELETE`), no method names with brackets
     (`Hash::check()`), no snake_case column names.

This is a lint, not a parser: it reads message text after the `:` and tests the
first word against a verb allow-list, and the whole message against patterns
that mean "this was written for a compiler".

Usage:  python check_message_wording.py [files...]
"""
import re
import sys

# Verbs a message may open with. Deliberately a list rather than a grammar: an
# unfamiliar verb should be a prompt to look, not something a heuristic waves
# through, and adding one here is one line.
VERBS = {
    "accepts", "accept", "adds", "add", "applies", "asks", "assigns",
    "calculates", "cancels", "carries", "changes", "checks", "clears", "closes",
    "collects", "compares", "completes", "confirms", "counts", "creates",
    "decides", "declares", "deletes", "delivers", "denies", "displays",
    "enrols", "enters", "evaluates", "files", "finds", "generates", "gets",
    "grants", "holds", "keeps", "loads", "locks", "logs", "looks", "marks",
    "moves", "notifies", "opens", "picks", "places", "prepares", "prevents",
    "provides", "queries", "reads", "records", "redirects", "refuses",
    "rejects", "releases", "reloads", "removes", "reports", "requests",
    "resends", "resets", "returns", "reveals", "saves", "schedules", "sends",
    "sets", "shows", "signs", "starts", "stores", "submits", "takes", "tidies",
    "triggers", "updates", "validates", "verifies", "withdraws", "writes",
}

# Things that mean the message was written for a machine.
BAD_PATTERNS = [
    (r"\b(GET|POST|PUT|PATCH|DELETE)\s+/", "an HTTP endpoint"),
    (r"\b/api/", "an API path"),
    (r"\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|JOIN)\b", "SQL"),
    (r"\w+::\w+", "a class-qualified method"),
    (r"\w+\(\)", "a method call with brackets"),
    (r"\b[a-z]+_[a-z_]+\b", "a snake_case column or field"),
    (r"\b[A-Za-z]+Resource\b", "a resource class"),
    (r"\b\d{3}\b", "an HTTP status code"),
    (r"\{[^}]*\}", "a JSON body"),
]

MSG = re.compile(r"^\s*(\w+)\s*(-->>|->>|-x|--x|-\)|--\))\s*(\w+)\s*:\s*(.+)$")


def check(path):
    text = open(path, encoding="utf-8").read()

    if "sequenceDiagram" not in text:
        return [f"{path}: not a sequence diagram"]

    body = text.split("sequenceDiagram", 1)[1]
    problems = []
    messages = 0

    for no, line in enumerate(body.splitlines(), 1):
        s = line.strip()

        if not s or s.startswith("%%"):
            continue

        m = MSG.match(s)
        if not m:
            continue

        messages += 1
        text_part = m.group(4).strip()

        first = text_part.split()[0].strip(",.:").lower()

        if first not in VERBS:
            problems.append(f"line {no}: does not open with a verb -> {text_part!r}")

        for pattern, what in BAD_PATTERNS:
            if re.search(pattern, text_part):
                problems.append(f"line {no}: contains {what} -> {text_part!r}")

    print(f"{path}: {messages} message(s)")

    return [f"{path}: {p}" for p in problems]


def main(paths):
    problems = []

    for path in paths:
        problems.extend(check(path))

    print()
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  -", p)
        return 1

    print("OK - every message opens with a verb and carries no machine syntax")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:] or ["thesis/diagrams/login.txt"]))
