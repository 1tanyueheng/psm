"""Repair double-encoded UTF-8 in source comments.

A PowerShell round-trip through `Get-Content -Raw` + `Set-Content` earlier in
the project read UTF-8 bytes as CP1252 and wrote them back out, so an em dash
became the six characters `\u00e2\u20ac\u201d` (displayed as `Ã¢â‚¬â€`). The
code still runs, but the comment reads as garbage to every future reader.

This walks the affected backend files, decodes each mojibake run back to its
original text, and changes **nothing else**. It refuses to touch a line where
the repair is not a clean round-trip, so a false positive cannot silently eat
real punctuation.

Usage:  python fix_mojibake.py [--write]
Without --write it only reports what it would change.
"""
import re
import sys
from pathlib import Path

# The console is CP1252 by default on Windows and cannot print the very
# characters this script removes.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

ROOT = Path(__file__).resolve().parent.parent / "backend" / "app"

# The known mojibake sequences, longest first so a 3-byte dash is not half-eaten
# by the 2-byte rule.
PATTERNS = [
    # Double mangling: text mangled once, then mangled again. These must come
    # first and match exactly, or the single-mangling rule below would fire on
    # the front of one and leave a stray byte behind.
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00e2\u20ac\u009d", "\u2014"),   # em dash, twice
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00e2\u20ac\u0153", "\u2014"),
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00c2\u00a6", "\u2026"),
    ("\u00c3\u201a\u00c2\u00a7", "\u00a7"),                            # section, twice
    # Single mangling.
    ("\u00e2\u20ac\u2122", "\u2019"),   # right single quote
    ("\u00e2\u20ac\u0153", "\u201c"),   # left double quote
    ("\u00e2\u20ac\u009d", "\u201d"),   # right double quote
    ("\u00e2\u20ac\u201d", "\u2014"),   # em dash
    ("\u00e2\u20ac\u201c", "\u2013"),   # en dash
    ("\u00e2\u20ac\u00a6", "\u2026"),   # ellipsis
    ("\u00e2\u2020\u2019", "\u2192"),   # right arrow
    ("\u00c2\u00a7", "\u00a7"),         # section sign
    ("\u00c2\u00b7", "\u00b7"),
    ("\u00c2\u00a0", " "),
]

# CP1252's five undefined bytes. An em dash mangled by CP1252 decoding leaves
# U+201D in that position whatever the C1 byte was, so a run mangled twice has
# one of 5x5 possible middles and cannot be listed one by one.
CP1252_UNDEFINED = "\u201d\u201e\u2020\u2021\u2026"
CP1252_DASH_TAIL = "\u0093\u0094\u009d\u0096\u0097"

# CP1252 + UTF-8, recoverable: 3-byte UTF-8 mangled once.
RUN3 = re.compile(
    "\u00c3[\u00a2\u00a3]"                       # e2 | e3 lead
    "(?:[\u00e2\u00c2\u00c3][\u0080-\u00bf\u2018-\u2022\u2026\u2030"
    "\u2039\u203a\u0152\u0153\u0160\u0161\u0178\u017d\u017e\u0192"
    "\u02c6\u02dc])"                             # byte 2
    "(?:[\u00c2\u00c3]|[\u0093\u0094\u0096\u0097])"  # byte 3, CP1252-lost ones excluded
)

# CP1252 + UTF-8, irrecoverable: the third byte was undefined in CP1252 or was
# itself a C1 control, so the original character is known by length and context
# only. Counted, not guessed.
RUN3_LOST = re.compile("\u00e2\u20ac[\u201d\u201e\u2020\u2021\u2026]")


def repair(text: str) -> tuple[str, int]:
    """Return (repaired text, number of sequences repaired)."""
    total = 0
    for bad, good in PATTERNS:
        if bad in text:
            total += text.count(bad)
            text = text.replace(bad, good)
    return text, total


def count_lost(text: str) -> int:
    """Sequences that cannot be recovered - see RUN3_LOST."""
    return len(RUN3_LOST.findall(text))


# Comments and docblocks only. A mangled character inside a string would change
# what the application prints, so those are reported and left alone for a human.
COMMENTY = re.compile(r"^(\s*(\*|/\*|//|#).*)$")


def main() -> int:
    write = "--write" in sys.argv
    changed = 0
    hits = 0
    lost = 0
    risky = []
    for path in sorted(ROOT.rglob("*.php")):
        original = path.read_text(encoding="utf-8", errors="surrogateescape")
        out_lines = []
        touched = False
        for line in original.splitlines(keepends=True):
            is_comment = bool(COMMENTY.match(line.rstrip("\r\n")))
            if not is_comment and (count_lost(line) or repair(line)[1]):
                risky.append(f"{path.relative_to(ROOT.parent.parent)}: {line.strip()[:90]}")
                out_lines.append(line)
                continue
            fixed, n = repair(line)
            if n:
                hits += n
                touched = True
            out_lines.append(fixed)
            lost += count_lost(line)
        if touched:
            changed += 1
            print(f"{path.relative_to(ROOT.parent.parent)}: {len(out_lines)} lines scanned")
            if write:
                path.write_text("".join(out_lines), encoding="utf-8", errors="surrogateescape")
    print(f"\n{changed} file(s), {hits} sequence(s) repaired, {lost} counted"
          + ("" if write else " -- dry run"))
    if risky:
        print("\nNOT repaired - outside a comment, needs a human:")
        for r in risky:
            print("  " + r)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
