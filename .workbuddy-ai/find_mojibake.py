"""List lines that still contain mojibake, for any text file, UTF-8 safe.

Built because a `python -c` one-liner died printing a check mark to a CP1252
console, and because PROGRESS.md legitimately *quotes* mojibake examples — those
lines must be told apart from real damage.

Usage:  python find_mojibake.py <path> [<path> ...]
Exits 1 if it finds any, so it can gate a commit.
"""
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

MARKERS = ("\u00e2\u20ac", "\u00c3\u00a2", "\u00c3\u201a", "\u00c2\u00b7", "\u00c3\u00a5")


def main(paths: list[str]) -> int:
    found = 0
    for raw in paths:
        path = Path(raw)
        if path.is_dir():
            files = [p for p in path.rglob("*") if p.is_file() and p.suffix in
                     {".js", ".jsx", ".css", ".php", ".md", ".json", ".py"}]
        else:
            files = [path]

        for p in files:
            try:
                lines = p.read_text(encoding="utf-8").splitlines()
            except (OSError, UnicodeDecodeError) as e:
                print(f"{p}: unreadable ({e})")
                continue
            for i, line in enumerate(lines, 1):
                if any(m in line for m in MARKERS):
                    found += 1
                    print(f"{p}:{i}: {line.strip()[:100]}")

    if found:
        print(f"\n{found} line(s) still contain mojibake")
        return 1

    print("clean")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:] or ["."]))
