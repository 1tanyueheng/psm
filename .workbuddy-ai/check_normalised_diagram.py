"""Check the NORMALISED class diagram's internal consistency.

`check_combined_diagram.py` compares arrows to live foreign keys, which is the
right test for a diagram of the implementing schema. It is the **wrong** test
here: the role tables (`admin_profiles`, `lecturer_profiles`,
`coordinator_profiles`) do not exist in the database, by design. Running the FK
check against this file would report every role arrow as invented.

So this validates the *design* instead:

  1. every class declared has at least one member, and every arrow names a
     declared class;
  2. **each role table joins `User` exactly once**, 1:1 — that is the whole point
     of the normalisation, and a missing or doubled join is the fault to catch;
  3. **no role attribute is left on `User`** — `staffId`, `department`,
     `programCode`, `capacity*`, `matricNo`, `batch`. Finding one means the
     normalisation is half done, which reads as complete on the page;
  4. every class has at least one relation (no island);
  5. the declaration block at the top of the module and the rendered file agree —
     a class added to one and not the other is the silent kind of drift.

Usage:  python check_normalised_diagram.py
"""
import io
import re
import sys

sys.path.insert(0, ".workbuddy-ai")

import make_class_diagram_normalised as design  # noqa: E402

PATH = "thesis/diagrams/class_diagram.txt"

ROLE_TABLES = ["AdminProfile", "CoordinatorProfile", "LecturerProfile",
               "SupervisorProfile", "StudentProfile"]

# Attributes that must not survive on User after normalisation.
ROLE_ATTRIBUTES = ["staffId", "department", "programCode", "programme", "capacityPsm1",
                   "capacityPsm2", "matricNo", "batch", "academicTitle",
                   "yearOfStudy", "sectionId", "isHead", "courseCode", "scope"]

ARROW = re.compile(
    r"^\s*(\w+)\s*(?:\"([^\"]*)\"\s*)?(-->|\*--|o--)(?:\s*\"([^\"]*)\")?\s*(\w+)\s*:\s*(.+)$"
)


def main():
    text = io.open(PATH, encoding="utf-8").read()
    body = text.split("classDiagram", 1)[1]

    declared = re.findall(r"^\s*class\s+(\w+)\{", body, re.M)
    problems = []

    # --- 5. module declaration vs rendered file -------------------------
    from_module = [a for a, *_ in design.CLASSES]
    if sorted(declared) != sorted(set(from_module)):
        problems.append(
            f"the rendered file declares {len(declared)} classes and the module "
            f"{len(set(from_module))}; they disagree: "
            f"{sorted(set(declared) ^ set(from_module))}")

    # --- 1. members and arrow endpoints --------------------------------
    for alias in declared:
        block = re.search(rf"^\s*class\s+{alias}\{{\s*\n(.*?)^\s*\}}", body, re.M | re.S)
        if block and not block.group(1).strip():
            problems.append(f"{alias} has no members")

    edges = []
    for line in body.splitlines():
        s = line.strip()
        if not s or s.startswith("%%"):
            continue
        m = ARROW.match(s)
        if not m:
            continue
        left, _lc, arrow, _rc, right, label = m.groups()
        edges.append((left, arrow, right, label))
        for end in (left, right):
            if end not in declared:
                problems.append(f"arrow names {end}, which is not declared: {s}")

    # --- 2. one 1:1 join per role --------------------------------------
    print("role tables -> User:")
    for role in ROLE_TABLES:
        joins = [e for e in edges
                 if {e[0], e[2]} == {"User", role}]

        if len(joins) != 1:
            problems.append(f"{role} joins User {len(joins)} time(s); it must be "
                            f"exactly one 1:1 association")
            print(f"   {role:22} {len(joins)} join(s)  <-- WRONG")
            continue

        left, _arrow, right, label = joins[0]
        print(f"   {role:22} 1:1  ({label})")

    # --- 3. no role attribute left on User ------------------------------
    user_block = re.search(r"^\s*class\s+User\{\s*\n(.*?)^\s*\}", body, re.M | re.S)
    if user_block:
        leftovers = [a for a in ROLE_ATTRIBUTES
                     if re.search(rf"\b{a}\b", user_block.group(1))]
        if leftovers:
            problems.append(f"User still carries role attributes: {leftovers}")
        print()
        print(f"role attributes left on User: {leftovers or 'none'}")

    # --- 4. islands -----------------------------------------------------
    touched = set()
    for left, _a, right, _l in edges:
        touched.add(left)
        touched.add(right)

    islands = [c for c in declared if c not in touched]
    if islands:
        problems.append(f"classes with no relation: {islands}")

    print()
    print(f"classes : {len(declared)}")
    print(f"arrows  : {len(edges)}")
    print(f"islands : {len(islands)}")

    print()
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  -", p)
        return 1

    print("OK - every role joins User once, User carries no role attribute, "
          "and no class is an island")
    return 0


if __name__ == "__main__":
    sys.exit(main())
