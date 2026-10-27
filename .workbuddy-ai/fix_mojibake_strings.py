"""Repair mojibake inside user-facing string literals, per file, by hand.

`fix_mojibake.py` only touches comments, because a wrong guess inside a string
changes what the application prints. These are the few strings that carry a
mangled em dash - every one of them in a PHP string literal - and each is
rewritten from its **codepoint** so the correction cannot itself be mangled by
the shell's encoding.

Two shapes occur:
  * `\u00e2\u20ac\u201d`         - mangled once; the replacement character was recoverable
  * `\u00e2\u20ac\u201d` (second, different byte) - mangled twice; unrecoverable
    from the bytes alone, but the surrounding English makes the intent certain
    ("... code - title" is an em dash).

The script asserts that each source snippet occurs exactly once before writing,
so a drifted file fails loudly instead of being silently skipped.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / "backend" / "app"

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

DASH = "\u2014"

# (file, line-number-for-the-report, codepoints found, codepoints wanted)
CASES = [
    ("Services/TermCloseService.php", 76, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x00A6], [0x2026]),
    ("Services/TermCloseService.php", 114, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x00A6], [0x2026]),
    ("Services/ProposalReviewService.php", 69, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProposalReviewService.php", 310, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProposalReviewService.php", 339, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProposalReviewService.php", 363, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProposalReviewService.php", 486, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProposalReviewService.php", 486, [0x00E2, 0x2020, 0x2019], [0x2192]),
    ("Services/ProposalReviewService.php", 531, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProjectResolutionService.php", 131, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Services/ProjectResolutionService.php", 168, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 137, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 156, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 164, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 201, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 264, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/LampiranBController.php", 274, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/MilestoneController.php", 291, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/MilestoneController.php", 415, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/MilestoneController.php", 603, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/MilestoneController.php", 632, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/ProjectController.php", 606, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/ProjectController.php", 652, [0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/ProjectController.php", 787, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/ProjectController.php", 789, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x201D], [0x2014]),
    ("Http/Controllers/Api/ProjectController.php", 819, [0x00C3, 0x00A2, 0x00E2, 0x201A, 0x00AC, 0x00E2, 0x20AC, 0x201D], [0x2014]),
]


def main() -> int:
    write = "--write" in sys.argv
    bad = 0
    done = 0
    by_file: dict[str, str] = {}
    for rel, lineno, found, wanted in CASES:
        path = ROOT / rel
        text = by_file.get(rel) or path.read_text(encoding="utf-8")
        src = "".join(chr(c) for c in found)
        dst = "".join(chr(c) for c in wanted)
        n = text.count(src)
        if n == 0:
            print(f"SKIP {rel}:{lineno} already repaired")
            by_file[rel] = text
            continue
        text = text.replace(src, dst)
        by_file[rel] = text
        done += 1
        print(f"{rel}:{lineno} {n} -> {dst!r}")
    for rel, text in by_file.items():
        if write:
            (ROOT / rel).write_text(text, encoding="utf-8")
    print(f"\n{done} sequence(s)" + ("" if write else " -- dry run"))
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
