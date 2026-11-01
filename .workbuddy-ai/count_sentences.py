"""Measure sentence length in the prose of build_ch1_cited.py.

Reads the string literals passed to body(doc, ...) straight out of the script,
splits them into sentences the way a reader would, and reports every sentence
longer than the limit. Citation brackets are protected from the splitter, since
"(Universiti Tun Hussein Onn Malaysia, 2025; Tam & Abdullah, 2025)" contains no
sentence break even though it looks like it might.

Usage:  python count_sentences.py [--limit 15] [path]
"""
import re
import sys

ABBREV = r"(?:e\.g|i\.e|etc|vs|al|Dr|Prof|Mr|Mrs|Ms|No|Fig|Tab)"


def sentences_of(text: str) -> list[str]:
    # Shield the contents of parentheses so a "2025; Foo" inside a citation is
    # not mistaken for a sentence boundary.
    spans = []

    def shield(m):
        spans.append(m.group(0))
        return f"\x00{len(spans) - 1}\x00"

    text = re.sub(r"\([^()]*\)", shield, text)

    # Protect common abbreviations and initials from a false stop.
    text = re.sub(rf"\b{ABBREV}\.", lambda m: m.group(0).replace(".", "\x01"), text)

    parts = re.split(r"(?<=[.!?])\s+", text.strip())

    out = []
    for p in parts:
        p = p.replace("\x01", ".")
        p = re.sub(r"\x00(\d+)\x00", lambda m: spans[int(m.group(1))], p)
        out.append(p.strip())
    return [p for p in out if p]


def main() -> int:
    limit = 15
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    for a in sys.argv[1:]:
        if a.startswith("--limit="):
            limit = int(a.split("=", 1)[1])

    path = args[0] if args else ".workbuddy-ai/build_ch1_cited.py"
    src = open(path, encoding="utf-8").read()

    # The prose is every implicit string concatenation passed into body(doc, ...).
    blocks = re.findall(r'body\(\s*doc,\s*("(?:[^"\\]|\\.)*"(?:\s*"(?:[^"\\]|\\.)*")*)\s*\)', src, re.S)

    over = 0
    total = 0
    for i, block in enumerate(blocks, 1):
        text = "".join(re.findall(r'"((?:[^"\\]|\\.)*)"', block))
        text = text.replace("\\'", "'")
        sents = sentences_of(text)
        for s in sents:
            total += 1
            n = len(s.split())
            if n > limit:
                over += 1
                print(f"[para {i}] {n:3}w  {s}")

    print(f"\n{total} sentences, {over} over {limit} words")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
