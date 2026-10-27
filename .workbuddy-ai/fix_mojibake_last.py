"""Final pass: the six sequences that are not `<lead><cp1252><utf8>` shaped.

Written as a script rather than a shell one-liner because the console cannot
carry the bytes: every replacement is built from **codepoints**, so what the
shell does to the text on the way in cannot matter.

  * `e2 1a ac c2 a6`        - an ellipsis, UTF-8 bytes CP1252-decoded one at a
                              time and re-encoded per character
  * `e2 1a ac e2 20ac 009d` - an em dash, same treatment
  * `c3 a2 e2 1a ac e2 20ac 009d` - the em dash, mangled twice

Run with --write to apply; without it, only reports.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / "backend" / "app"
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

DASH = chr(0x2014)
ELLIPSIS = chr(0x2026)

REPLACEMENTS = [
    ("".join(map(chr, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x009D])), DASH),
    ("".join(map(chr, [0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x009D])), DASH),
    ("".join(map(chr, [0x00E2, 0x201A, 0x00AC, 0x00C2, 0x00A6])), ELLIPSIS),
]

FILES = [
    "Services/TermCloseService.php",
    "Http/Controllers/Api/ProjectController.php",
]


def main() -> int:
    write = "--write" in sys.argv
    total = 0
    for rel in FILES:
        path = ROOT / rel
        text = path.read_text(encoding="utf-8")
        counts = []
        for bad, good in REPLACEMENTS:
            n = text.count(bad)
            counts.append(n)
            total += n
            text = text.replace(bad, good)
        print(f"{rel}: {counts}")
        if write:
            path.write_text(text, encoding="utf-8")
    print(f"{total} sequence(s)" + ("" if write else " -- dry run"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
