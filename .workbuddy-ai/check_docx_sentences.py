"""Check sentence length and citation counts in a built .docx.

The script-level counter reads the source strings; this reads what Word will
show, so a difference between the two is visible.

Usage:  python check_docx_sentences.py <docx> [--limit 15]
"""
import re
import sys

from docx import Document

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

ABBREV = r"(?:e\.g|i\.e|etc|vs|al|Dr|Prof|Mr|Mrs|Ms|No|Fig|Tab)"


def sentences_of(text: str) -> list[str]:
    spans = []

    def shield(m):
        spans.append(m.group(0))
        return f"\x00{len(spans) - 1}\x00"

    text = re.sub(r"\([^()]*\)", shield, text)
    text = re.sub(rf"\b{ABBREV}\.", lambda m: m.group(0).replace(".", "\x01"), text)
    parts = re.split(r"(?<=[.!?])\s+", text.strip())
    out = []
    for p in parts:
        p = p.replace("\x01", ".")
        p = re.sub(r"\x00(\d+)\x00", lambda m: spans[int(m.group(1))], p)
        if p.strip():
            out.append(p.strip())
    return out


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    limit = 15
    for a in sys.argv[1:]:
        if a.startswith("--limit="):
            limit = int(a.split("=", 1)[1])

    doc = Document(args[0])
    section = "(front)"
    per_section = {}
    over = 0
    total = 0
    longest = ("", 0)

    for p in doc.paragraphs:
        text = p.text.strip()
        if not text:
            continue
        style = p.style.name if p.style is not None else ""
        if style.startswith("Heading"):
            section = text.split("\n")[0][:40]
            continue
        if section == "References":
            continue
        per_section.setdefault(section, {"paras": 0, "sentences": 0, "cites": 0})
        if style in ("Normal", "Paragraph First and Last"):
            sents = sentences_of(text)
            if sents:
                per_section[section]["paras"] += 1
            per_section[section]["cites"] += len(
                re.findall(r"\([^()]*(?:19|20)\d\d[^()]*\)", text)
            )
            for s in sents:
                total += 1
                per_section[section]["sentences"] += 1
                n = len(s.split())
                if n > longest[1]:
                    longest = (s, n)
                if n > limit:
                    over += 1
                    print(f"[{section}] {n}w  {s}")

    print("\n--- per section ---")
    for name, d in per_section.items():
        print(f"  {name:<22} paragraphs={d['paras']:<3} sentences={d['sentences']:<4} citation brackets={d['cites']}")

    print(f"\n{longest[1]} words is the longest sentence: {longest[0][:90]}")
    print(f"{total} sentences, {over} over {limit} words")
    return 1 if over else 0


if __name__ == "__main__":
    raise SystemExit(main())
