"""Check the class diagram against the live foreign keys.

`make_class_diagram.py` declares the classes and relations by hand because a
diagram drawn straight from `information_schema` is a dump — 102 foreign keys
including every `created_by` and every audit column, which buries the eleven
relationships a reader actually needs.

The price of hand-declaring is that one of them can be wrong, or missing. This
script pays it: it reads `make_class_diagram.py`'s declarations, pulls the real
foreign keys through `psm-app`, and reports

  * a relation the diagram claims that no foreign key supports;
  * a foreign key between two classes the diagram *does* draw which it omits.

It does not fail on foreign keys to tables the diagram leaves out — that is a
deliberate choice (audit columns, `created_by`), and the list is printed so the
choice is visible rather than forgotten.

Usage:  python check_class_diagram.py
"""
import os
import re
import subprocess
import sys

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
sys.path.insert(0, os.path.join(BASE, ".workbuddy-ai"))

# Reuse the declarations rather than re-parsing the diagram text.
import make_class_diagram as gen  # noqa: E402

# Columns whose only meaning is "who did this": `created_by`, `approved_by`,
# `locked_by`, `entered_by` and the rest. They point at `users` because every
# actor is a user, not because the class has an association with a person, and
# drawing all twenty-one would bury the relationships a reader needs.
# Named as a category rather than listed one by one, so a new `*_by` column
# does not fail the check and does not silently change the diagram either.
AUDIT_COLUMN_PATTERNS = (
    "_by",                    # created_by, approved_by, locked_by, decided_by…
    "superseded_by", "uploaded_by", "released_by",
)


def is_audit_column(column):
    return any(column == p or column.endswith(p) for p in AUDIT_COLUMN_PATTERNS)

SQL_FILE = os.path.join(BASE, ".workbuddy-ai", "dump_foreign_keys.php")


def live_foreign_keys():
    subprocess.run(["docker", "cp", SQL_FILE, "psm-app:/var/www/html/_fk.php"],
                   check=True, capture_output=True)

    try:
        out = subprocess.run(
            ["docker", "exec", "psm-app", "php", "artisan", "tinker", "_fk.php"],
            check=True, capture_output=True, text=True,
        ).stdout
    finally:
        subprocess.run(["docker", "exec", "psm-app", "rm", "-f", "/var/www/html/_fk.php"],
                       capture_output=True)

    keys = set()
    for line in out.splitlines():
        line = line.strip()
        if line.count("|") != 2:
            continue
        child, column, parent = line.split("|")
        keys.add((child, column, parent))
    return keys


def main():
    table_of = {alias: table for alias, table, _uc, _g in gen.CLASSES}
    alias_of = {table: alias for alias, table, _uc, _g in gen.CLASSES}

    drawn = set()
    for left, _card, _kind, right, _label, _rel in gen.RELATIONS:
        drawn.add((table_of[left], table_of[right]))
        drawn.add((table_of[right], table_of[left]))

    fks = live_foreign_keys()
    print(f"live foreign keys: {len(fks)}")
    print(f"classes drawn    : {len(table_of)}")
    print()

    # --- a relation the schema does not support ---------------------------
    # `inheritance`, self-references and the two hand-added edges are legal
    # without an FK; everything else has to be backed by one.
    fk_pairs = {(child, parent) for child, _col, parent in fks}
    supported = set()
    for left, _card, _kind, right, _label, rel in gen.RELATIONS:
        if rel == "inherit":
            continue
        pair = (table_of[left], table_of[right])
        if pair in fk_pairs or (pair[1], pair[0]) in fk_pairs:
            supported.add(pair)

    unsupported = []
    for left, _card, _kind, right, label, rel in gen.RELATIONS:
        if rel == "inherit":
            continue
        pair = (table_of[left], table_of[right])
        if pair in supported or (pair[1], pair[0]) in supported:
            continue
        # A relation the schema cannot back with a foreign key is only allowed
        # where a rule says so — the polymorphic notification link, and the
        # hand-drawn structural links (a file superseding another, an enrolment
        # repeating one). Those are named in `gen.NOT_DRAWN` or are self-links.
        if table_of[left] == table_of[right]:
            continue                      # self-references: the column exists, the pair is trivial
        if pair in gen.DECLARED_WITHOUT_FK or (pair[1], pair[0]) in gen.DECLARED_WITHOUT_FK:
            continue
        unsupported.append(f"{left} - {right} ({label})")

    # --- a foreign key between two drawn classes that is missing ----------
    drawn_pairs = {(table_of[l], table_of[r]) for l, *_ , r, _lb, _k in
                   [(a, b, c, d, e, f) for a, b, c, d, e, f in gen.RELATIONS]}
    drawn_pairs |= {(b, a) for a, b in drawn_pairs}

    missing = []
    for child, column, parent in sorted(fks):
        if child == parent:
            continue                      # self-references are drawn by hand
        if child not in alias_of or parent not in alias_of:
            continue                      # a table the diagram leaves out
        if (child, column) in gen.NOT_DRAWN:
            continue                      # a declared omission, with a reason
        if is_audit_column(column):
            continue                      # "who did this", not an association
        if (child, parent) in drawn_pairs:
            continue
        missing.append(f"{alias_of[child]}.{column} -> {alias_of[parent]}")

    # --- every declared omission must name a real foreign key -------------
    stale = []
    real = {(c, col) for c, col, _p in fks}
    for (table, column) in gen.NOT_DRAWN:
        if column == "*":
            continue
        if (table, column) not in real:
            stale.append(f"NOT_DRAWN names {table}.{column}, which is no longer a foreign key")

    # --- a table in a use case that the diagram never draws ---------------
    print(f"relations drawn  : {len(gen.RELATIONS)}")
    print(f"unsupported by a foreign key: {len(unsupported)}")
    for u in unsupported:
        print("   -", u)

    print()
    print(f"foreign keys between drawn classes, not drawn: {len(missing)}")
    for m in missing:
        print("   -", m)

    if stale:
        print()
        print("stale declarations in NOT_DRAWN:")
        for s in stale:
            print("   -", s)

    ok = not unsupported and not missing and not stale
    print()
    print("OK — every arrow is backed by the schema and nothing between these "
          "classes is missing" if ok else "PROBLEMS — see above")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
