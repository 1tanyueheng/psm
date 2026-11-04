"""Validate the COMBINED figure's arrows against the live foreign keys.

`verify_arrows_are_fks.py` reads `make_class_diagram.RELATIONS` — the original,
pre-redirect list. The combined figure re-points four arrows to
`SupervisorProfile`, so that checker would be validating a file nobody renders.

This checks the file that is actually written. The rule it enforces is the one
that makes the redirection legitimate: an arrow may end on `SupervisorProfile`
only if the foreign key behind it **cannot** hold a non-supervisor. Concretely,
the column must be one of the role columns whose value is always a supervisor's,
and the link must still be to `users` in the schema — the diagram is saying "this
is a supervisor", which is true, not "this foreign key points at
supervisor_profiles", which would be false.

Reports every arrow that leaves the diagram's own rules.
"""
import io
import re
import subprocess
import sys

sys.path.insert(0, ".workbuddy-ai")

import make_class_diagram as gen  # noqa: E402
import make_class_diagram_combined as combined  # noqa: E402

PATH = "thesis/diagrams/class_diagram.txt"

# Columns that always hold a supervisor's user id. Reviewed by hand against the
# schema on 2026-11-04; this is the justification for each redirected arrow.
SUPERVISOR_COLUMNS = {
    "evaluations": ["assessor_id"],
    "examiner_assignments": ["examiner_id"],
    "examiner_pairs": ["examiner_1_id", "examiner_2_id"],
    "sections": ["lecturer_id"],
}

# Arrows that were ALREADY on SupervisorProfile before the redirect, because the
# foreign key is to `supervisor_profiles` directly rather than to `users`. These
# are not being validated here — they were validated by
# `verify_arrows_are_fks.py` against the original relation list.
ALREADY_ON_PROFILE = {
    "signs",        # supervisor_agreements.supervisor_profile_id
    "supervises",   # supervision_assignments.supervisor_profile_id
    "files for",    # lampiran_b_forms.supervisor_profile_id
}

# Columns whose value may be any staff member or an admin — these must not be
# drawn on a profile class. `assigned_by` and `created_by` are here for that
# reason; they keep their arrow to `User`.
ACTOR_ONLY = {"created_by", "approved_by", "decided_by", "locked_by", "opened_by",
              "closed_by", "rejected_by", "reviewed_by", "assigned_by"}


def live_fks():
    subprocess.run(["docker", "cp", ".workbuddy-ai/dump_foreign_keys.php",
                    "psm-app:/var/www/html/_fk.php"], check=True, capture_output=True)
    try:
        out = subprocess.run(
            ["docker", "exec", "psm-app", "php", "artisan", "tinker", "_fk.php"],
            check=True, capture_output=True, text=True,
        ).stdout
    finally:
        subprocess.run(["docker", "exec", "psm-app", "rm", "-f",
                        "/var/www/html/_fk.php"], capture_output=True)

    keys = set()
    for line in out.splitlines():
        p = line.strip().split("|")
        if len(p) == 3:
            keys.add((p[0], p[1], p[2]))
    return keys


ARROW_RE = re.compile(
    r"^\s*(\w+)\s*(?:\"([^\"]*)\"\s*)?(-->|\*--|o--)(?:\s*\"([^\"]*)\")?\s*(\w+)\s*:\s*(.+)$",
    re.M,
)
INHERIT_RE = re.compile(r"^\s*(\w+)\s*<\|--\s*(\w+)\s*:\s*(.+)$", re.M)


def main():
    text = io.open(PATH, encoding="utf-8").read()
    body = text.split("classDiagram", 1)[1]

    declared = set(re.findall(r"^\s*class\s+(\w+)\{", body, re.M))
    fks = live_fks()

    table_of = {a: t for a, t, _uc, _g in gen.CLASSES}

    problems = []
    supervisor_arrows = []

    for line in body.splitlines():
        s = line.strip()
        if not s or s.startswith("%%"):
            continue

        m = ARROW_RE.match(s)
        if not m:
            continue

        left, _lc, _arrow, _rc, right, label = m.groups()

        for end in (left, right):
            if end not in declared:
                problems.append(f"arrow names {end}, which the figure does not "
                                f"declare: {s}")

        if left == "SupervisorProfile" or right == "SupervisorProfile":
            # Arrows that were already on the profile before the redirect are
            # backed by a foreign key to `supervisor_profiles` itself and are
            # covered by the original checker — not re-judged here.
            if label in ALREADY_ON_PROFILE:
                continue

            other = right if left == "SupervisorProfile" else left
            table = table_of.get(other)

            columns = SUPERVISOR_COLUMNS.get(table, [])

            if columns and any((table, c, "users") in fks for c in columns):
                supervisor_arrows.append((label, other, ".".join(columns)))
            else:
                problems.append(
                    f"arrow on SupervisorProfile is not backed by a supervisor-only "
                    f"foreign key: {left} -> {right} ({label})")

    # The actor columns must every one still land on User. Each is checked as a
    # *column*, not as "the table has some arrow to User" — `examiner_pairs` and
    # `examiner_assignments` have one arrow each, redirected to the profile, and
    # their `created_by` / `assigned_by` still need their own arrow to `User`.
    for table, column, parent in sorted(fks):
        if parent != "users" or column not in ACTOR_ONLY:
            continue
        if table not in table_of.values():
            continue

        alias = next(a for a, t in table_of.items() if t == table)

        # `User "0..1" --> "0..*" <Alias> : ...` with the column named in the
        # label, or a label ending in `*` meaning "several actor columns".
        pattern = re.compile(
            rf'User\s+"[^"]*"\s*-->\s*"[^"]*"\s+{alias}\b\s*:\s*(.+)$', re.M)
        labels = [m.group(1) for m in pattern.finditer(body)]

        named = any(column.replace("_", " ") in lab or lab.rstrip().endswith("*")
                    for lab in labels)

        if not named:
            problems.append(f"{table}.{column} is an actor column with no arrow "
                            f"to {alias}")

    print(f"classes declared : {len(declared)}")
    print(f"arrows to User   : {len(re.findall(chr(94) + r'\s*User\s+"', body, re.M))}")
    print(f"arrows on SupervisorProfile : {len(supervisor_arrows)}")
    for label, target, column in supervisor_arrows:
        print(f"   {target:22} <- {column:28} ({label})")

    print()
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  -", p)
        return 1

    print("OK - every SupervisorProfile arrow is backed by a supervisor-only "
          "foreign key, and every actor column still lands on User")
    return 0


if __name__ == "__main__":
    sys.exit(main())
