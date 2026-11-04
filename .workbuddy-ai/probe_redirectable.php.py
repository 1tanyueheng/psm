"""Which `User` arrows can be redirected to a profile class?

The user's instruction: combine into one diagram, and make relations attach to
the **normalised class** (StudentProfile / SupervisorProfile) rather than to
`User`, so the hub stops being the hub.

That is only honest for columns that mean "the student" or "the supervisor". A
`created_by` is not a student or a supervisor — the actor may be an admin — so
redirecting those would be a lie in a diagram that claims to be the schema.

This works out, per foreign key, whether the column names a *role-specific*
person or merely *a person*:

  * role-specific  -> redirect to the profile class in the diagram
  * a person        -> keep the arrow to User, because that is what it is

Read-only. Prints the classification so the division is auditable.
"""
import re
import subprocess
import sys

sys.path.insert(0, ".workbuddy-ai")

import make_class_diagram as gen  # noqa: E402

# Column names that mean a specific role. Anything ending in `_by` is an actor
# and stays on User.
ROLE_COLUMNS = {
    "student_profile_id": "StudentProfile",
    "supervisor_profile_id": "SupervisorProfile",
    "assessor_id": "SupervisorProfile",       # assessors are supervisors
    "examiner_id": "SupervisorProfile",       # examiners are seated from supervisors
    "examiner_1_id": "SupervisorProfile",
    "examiner_2_id": "SupervisorProfile",
    "lecturer_id": "SupervisorProfile",       # a section's teacher is staff
    "enrolled_by": None,                       # an actor: stays on User
}

ACTOR_SUFFIXES = ("_by", "reviewer_id", "actor_id", "user_id")


def live_user_fks():
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

    rows = []
    for line in out.splitlines():
        parts = line.strip().split("|")
        if len(parts) == 3 and parts[2] == "users":
            rows.append((parts[0], parts[1]))
    return rows


def main():
    redirectable, stays = [], []

    for table, column in sorted(live_user_fks()):
        if column in ROLE_COLUMNS and ROLE_COLUMNS[column]:
            redirectable.append((table, column, ROLE_COLUMNS[column]))
        else:
            stays.append((table, column))

    print(f"foreign keys to `users`: {len(redirectable) + len(stays)}")
    print()
    print(f"CANNED TO A PROFILE CLASS ({len(redirectable)}):")
    for table, column, target in redirectable:
        print(f"   {table}.{column:24} -> {target}")
    print()
    print(f"STAY ON User ({len(stays)}) - these name an actor, not a role:")
    for table, column in stays:
        print(f"   {table}.{column}")

    print()
    print("Note: a profile class is only equivalent to `users` for a column that")
    print("can ONLY hold that role. `sections.lecturer_id` points at `users` and")
    print("may be a supervisor or a lecturer; the diagram links it to")
    print("SupervisorProfile only if both are drawn, otherwise it stays on User.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
