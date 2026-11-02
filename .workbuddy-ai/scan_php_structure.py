"""Structural scan for the PHP files this session changed.

`php -l` cannot run: Docker is not up and there is no PHP binary on the host.
This is not a substitute for a lint — it only proves that comments, strings and
braces are balanced and that a class body closes where it should. It catches the
one class of damage an edit tool actually causes (a truncated block), not a
syntax error in the code itself.

Written to a file rather than `python -c` because a one-liner with quotes and
backslashes is itself mangled on the way through PowerShell — the same fault
recorded in PROGRESS.md §5.
"""
import io
import sys


def scan(path):
    text = io.open(path, encoding="utf-8").read()
    depth = 0
    i, n, line = 0, len(text), 1
    in_line = in_block = False
    quote = None
    problems = []

    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ""

        if c == "\n":
            line += 1
            in_line = False
            i += 1
            continue
        if in_line:
            i += 1
            continue
        if in_block:
            if c == "*" and nxt == "/":
                in_block = False
                i += 2
                continue
            i += 1
            continue
        if quote is not None:
            if c == "\\":
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c == "/" and nxt == "/":
            in_line = True
            i += 2
            continue
        if c == "#":
            in_line = True
            i += 1
            continue
        if c == "/" and nxt == "*":
            in_block = True
            i += 2
            continue
        if c in ("'", '"'):
            quote = c
            i += 1
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth < 0:
                problems.append(f"depth negative at line {line}")
                break
        i += 1

    if depth != 0:
        problems.append(f"unbalanced braces: depth {depth}")
    if in_block:
        problems.append("a /* comment is never closed")
    if quote is not None:
        problems.append(f"an open {quote} string is never closed")

    return problems


FILES = [
    "backend/app/Models/LecturerMark.php",
    "backend/app/Models/Section.php",
    "backend/app/Models/User.php",
    "backend/app/Http/Controllers/Api/LecturerMarkController.php",
    "backend/config/psm.php",
    "backend/database/seeders/LecturerMarkSeeder.php",
    "backend/database/migrations/2026_11_02_000001_move_coursework_to_three_criteria.php",
    "backend/database/migrations/2026_11_02_000002_give_each_section_a_course.php",
]

bad = 0
for path in FILES:
    problems = scan(path)
    name = path.split("/")[-1]
    if problems:
        bad += 1
        print(f"FAIL {name}")
        for p in problems:
            print(f"     {p}")
    else:
        print(f"ok   {name}")

print()
print(f"{len(FILES) - bad}/{len(FILES)} structurally clean")
sys.exit(1 if bad else 0)
