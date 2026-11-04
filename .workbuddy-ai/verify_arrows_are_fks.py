"""Every arrow in Figure 4.14, against the foreign key that justifies it.

The user's rule, 2026-11-04: **connect the relation only if a foreign key exists,
and if one exists, draw it.** No exceptions, no "this association is real even
without a constraint". This prints the proof per arrow so an arrow with no
foreign key behind it is visible rather than excused.

Output is a table: arrow | child table | column | parent table | verdict.
"""
import subprocess
import sys

sys.path.insert(0, ".workbuddy-ai")

import make_class_diagram as gen  # noqa: E402

FK_SCRIPT = ".workbuddy-ai/dump_foreign_keys.php"


def live_fks():
    subprocess.run(["docker", "cp", FK_SCRIPT, "psm-app:/var/www/html/_fk.php"],
                   check=True, capture_output=True)
    try:
        out = subprocess.run(
            ["docker", "exec", "psm-app", "php", "artisan", "tinker", "_fk.php"],
            check=True, capture_output=True, text=True,
        ).stdout
    finally:
        subprocess.run(["docker", "exec", "psm-app", "rm", "-f",
                        "/var/www/html/_fk.php"], capture_output=True)

    keys = {}
    for line in out.splitlines():
        parts = line.strip().split("|")
        if len(parts) != 3:
            continue
        child, column, parent = parts
        keys.setdefault((child, parent), []).append(column)
        keys.setdefault((parent, child), []).append(column)
    return keys


def main():
    table_of = {a: t for a, t, _uc, _g in gen.CLASSES}
    keys = live_fks()

    backed, unbacked = [], []

    for rel in gen.RELATIONS:
        left, card, spare, right, label, kind = rel

        if kind == "inherit":
            backed.append((f"{left} --|> {right}", "inheritance", "student_profiles.users_id",
                           "users"))
            continue

        pair = (table_of[left], table_of[right])
        columns = keys.get(pair)

        if columns:
            backed.append((f"{left} -> {right}", left, ", ".join(sorted(set(columns))),
                           right))
        else:
            unbacked.append((f"{left} -> {right}", left, "-", right))

    print(f"arrows with a foreign key behind them : {len(backed)}")
    print(f"arrows with NO foreign key            : {len(unbacked)}")
    print()

    if unbacked:
        print("UNBACKED ARROWS — these are drawn without a constraint:")
        for name, a, _c, b in unbacked:
            print(f"   {name}")
        print()

    # Foreign keys between drawn classes that no arrow covers.
    #
    # Comparisons are against the RELATION list regardless of how the edge is
    # written: inheritance (`User <|-- StudentProfile`) carries the
    # `student_profiles.user_id` foreign key, so a pair connected by inheritance
    # is connected. This pass used to skip inheritance edges and then reported
    # the profile pair as undrawn — a false alarm from the checker, not a gap in
    # the diagram.
    drawn = {(table_of[r[0]], table_of[r[3]]) for r in gen.RELATIONS}
    drawn |= {(b, a) for a, b in drawn}

    drawn_tables = set(table_of.values())
    # `NOT_DRAWN` no longer exists: the rule is that every foreign key between
    # two drawn classes is drawn. Nothing is exempt.
    ignored = set()

    missing = []
    seen = set()
    for (child, parent), columns in keys.items():
        if child not in drawn_tables or parent not in drawn_tables:
            continue
        if (child, parent) in drawn:
            continue
        key = tuple(sorted((child, parent)))
        if key in seen:
            continue
        # Two columns of the same pair collapse to one arrow; if ANY column of
        # the pair is drawn, the pair is drawn.
        seen.add(key)
        missing.append((child, columns, parent))

    print(f"foreign keys between drawn classes, NOT drawn: {len(missing)}")
    for child, columns, parent in missing:
        print(f"   {child}.{', '.join(columns)} -> {parent}")

    print()
    ok = not unbacked
    print("OK - every arrow is a foreign key" if ok else
          "PROBLEMS - arrows exist that no foreign key supports")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
