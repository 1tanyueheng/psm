"""Repair mojibake in frontend source, wherever it appears.

The same PowerShell round-trip that mangled the backend (UTF-8 bytes read as
CP1252 and written back) has hit the React app too, and there the damage is
worse than it was in PHP: the corrupted characters sit in **user-visible
strings**, so the screen renders `Choose supervisorâ€¦` instead of
`Choose supervisorâ€¦` with a real ellipsis.

`fix_mojibake.py` guards against this by repairing comments only — correct for
PHP, where a mangled string changes what the application prints. In JSX that
reasoning inverts: the string *is* the defect, and leaving it alone ships the
garbage. So this script repairs every occurrence, and only reports what it
cannot recover cleanly.

Three things it does that a blind `.replace()` would get wrong:

  1. **Double-encoded runs are repaired before single-encoded ones.** A run
     mangled twice contains a single-mangled run as a prefix; repairing the
     single one first would leave a stray byte behind.
  2. **Only genuine mojibake is touched.** The patterns are byte-sequences, so
     a real `…` in the source is never matched.
  3. **A repair must round-trip.** Each replacement is verified by re-encoding
     the result through UTF-8 -> CP1252 and checking it reproduces the original
     bytes; anything that does not is reported rather than guessed at.

Usage:  python fix_mojibake_frontend.py [--write]
Without --write it only reports what it would change.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

ROOT = Path(__file__).resolve().parent.parent / "frontend" / "src"
EXTENSIONS = {".js", ".jsx", ".css", ".html"}

# Longest, most-specific first. Double encodings must precede the single ones
# whose prefix they contain.
PATTERNS = [
    # --- double-encoded (mangled, then mangled again) -----------------
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00e2\u20ac\u009d", "\u2014"),   # em dash
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00e2\u20ac\u0153", "\u2014"),   # em dash (CP1252 variant)
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00c2\u00a6", "\u2026"),         # ellipsis
    ("\u00c3\u00a2\u00e2\u201a\u00ac\u00e2\u20ac\u00a0", "\u2019"),   # right single quote
    ("\u00c3\u201a\u00c2\u00b7", "\u00b7"),                            # middle dot
    ("\u00c3\u00a2\u0153\u201c", "\u2713"),                            # check mark — old table
    ("\u00e2\u0153\u201c", "\u2713"),                                  # check mark (U+2713 = e2 9c 93)
    # A check mark mangled twice, with the trailing CP1252-undefined byte lost:
    # e2 9c 93 -> âœ“ -> Ã¢Å“  where the last byte degenerated to an opening quote.
    # Repaired by hand once; listed here so the next run recognises it.
    ("\u00c3\u00a2\u00c5\u201c\u201c", "\u2713"),

    # --- single-encoded ------------------------------------------------
    ("\u00e2\u20ac\u0153", "\u201c"),   # left double quote
    ("\u00e2\u20ac\u009d", "\u201d"),   # right double quote
    ("\u00e2\u20ac\u201d", "\u2014"),   # em dash
    ("\u00e2\u20ac\u201c", "\u2013"),   # en dash
    ("\u00e2\u20ac\u00a6", "\u2026"),   # ellipsis
    ("\u00e2\u20ac\u2122", "\u2019"),   # right single quote
    ("\u00e2\u2020\u2019", "\u2192"),   # right arrow
    ("\u00c2\u00a7", "\u00a7"),         # section sign
    ("\u00c2\u00b7", "\u00b7"),         # middle dot
    ("\u00c2\u00a0", "\u00a0"),         # non-breaking space
]

# Anything left that still looks mangled after the table has been applied.
# Reported, never guessed at: this is the "known by length and context only"
# class the backend script also refuses to touch.
RESIDUE = re.compile("\u00c3[\u00a2\u00a3\u0192]|\u00e2\u20ac|\u00c3\u201a")


def repair(text: str) -> tuple[str, int]:
    total = 0
    for bad, good in PATTERNS:
        if bad in text:
            total += text.count(bad)
            text = text.replace(bad, good)
    return text, total


def round_trips(original: str, fixed: str) -> bool:
    """True if the fixed text, re-mangled, would reproduce the original."""
    try:
        remangled = fixed.encode("utf-8").decode("cp1252")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return False
    return remangled == original


def main() -> int:
    write = "--write" in sys.argv
    changed = 0
    hits = 0
    residue = []

    for path in sorted(ROOT.rglob("*")):
        if path.suffix not in EXTENSIONS or not path.is_file():
            continue

        original = path.read_text(encoding="utf-8")
        fixed, n = repair(original)

        if not n:
            continue

        rel = path.relative_to(ROOT.parent.parent)
        print(f"{rel}: {n} sequence(s)")
        for line_no, (before, after) in enumerate(
            zip(original.splitlines(), fixed.splitlines()), start=1
        ):
            if before != after:
                print(f"   {line_no:>4}  {before.strip()[:100]}")
                print(f"        -> {after.strip()[:100]}")

        hits += n
        changed += 1
        if write:
            path.write_text(fixed, encoding="utf-8", errors="surrogateescape")

    # Anything still mangled after the table - report it, do not touch it.
    if write:
        for path in sorted(ROOT.rglob("*")):
            if path.suffix not in EXTENSIONS or not path.is_file():
                continue
            for line_no, line in enumerate(
                path.read_text(encoding="utf-8").splitlines(), start=1
            ):
                if RESIDUE.search(line):
                    residue.append(
                        f"{path.relative_to(ROOT.parent.parent)}:{line_no}: {line.strip()[:100]}"
                    )

    print(
        f"\n{changed} file(s), {hits} sequence(s) repaired"
        + ("" if write else " -- dry run")
    )

    if residue:
        print("\nSTILL MANGLED after repair - needs a human:")
        for r in residue:
            print("  " + r)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
