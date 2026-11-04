"""Fix the MySQL claims in docs/SETUP.md.

`SETUP.md` still told a new developer to install MySQL 8, expose port 3306 and
set `DB_CONNECTION=mysql`. Followed literally that produces a setup that cannot
talk to the real database, which is PostgreSQL on Supabase.

Only the statements that are *wrong* are changed. Lines that describe the
project's history — "it was written for MySQL", "the MySQL container" in a
troubleshooting entry — are left alone, because they are true about the past.
"""
import io

PATH = "docs/SETUP.md"
text = io.open(PATH, encoding="utf-8").read()

REPLACEMENTS = [
    # The service table
    (
        "| MySQL | `localhost:3306` | Credentials in `docker-compose.yml` |",
        "| PostgreSQL (dev) | `localhost:5432` | The local `psm-db` container. "
        "Production is Supabase — see `docs/DEPLOYMENT.md` |",
    ),
    # The prerequisites line
    (
        "Requires PHP 8.2+, Composer 2, MySQL 8, and Node 20+.",
        "Requires PHP 8.2+, Composer 2, **PostgreSQL 14+** (or a Supabase "
        "connection string) and Node 20+.",
    ),
    # The env example, native setup
    (
        "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=psm_system\n"
        "DB_USERNAME=root\nDB_PASSWORD=",
        "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=psm_system\n"
        "DB_USERNAME=postgres\nDB_PASSWORD=postgres",
    ),
    # The managed-database advice
    (
        "1. Create a **PostgreSQL or MySQL** managed database, or use Render's MySQL.",
        "1. Create a **PostgreSQL** managed database — Supabase is what this "
        "project uses. (The code ran on MySQL early on; it has not since the "
        "schema moved.)",
    ),
]

count = 0
for old, new in REPLACEMENTS:
    if old in text:
        text = text.replace(old, new)
        count += 1
        print(f"replaced: {old.splitlines()[0][:70]}")
    else:
        print(f"NOT FOUND: {old.splitlines()[0][:70]}")

# The second DB_CONNECTION block, further down.
text = text.replace("DB_CONNECTION=mysql", "DB_CONNECTION=pgsql")

io.open(PATH, "w", encoding="utf-8", newline="\n").write(text)
print(f"\n{count} block(s) replaced; remaining 'mysql' mentions:")
for i, line in enumerate(text.splitlines(), 1):
    if "mysql" in line.lower():
        print(f"  {i}: {line.strip()[:90]}")
