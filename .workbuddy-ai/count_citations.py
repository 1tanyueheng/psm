"""Count distinct author-date citations per section of a chapter."""
import re
import sys

from docx import Document

# (Name, Year) pairs as they appear in the prose.
CITE = re.compile(r"\(([^()]*?)& ([A-Z][A-Za-z']+)[^()]*?(\d{4})\)")

for path in sys.argv[1:]:
    doc = Document(path)
    print(f"\n=== {path} ===")
    section = "(front matter)"
    per_section = {}
    all_pairs = set()
    for p in doc.paragraphs:
        text = p.text.strip()
        if not text:
            continue
        style = p.style.name if p.style is not None else ""
        if style.startswith("Heading"):
            section = text.split("\n")[0][:60]
            continue
        found = set()
        for m in CITE.finditer(text):
            first, second, year = m.group(1).split(",")[0].strip(), m.group(2), m.group(3)
            found.add(f"{first} & {second} {year}")
            all_pairs.add(f"{first} & {second} {year}")
        if found:
            per_section.setdefault(section, set()).update(found)

    for name, found in per_section.items():
        print(f"  [{name}] {len(found)}: {sorted(found)}")
    print(f"  DISTINCT CITATIONS IN PROSE: {len(all_pairs)}")
    print(f"  {sorted(all_pairs)}")
