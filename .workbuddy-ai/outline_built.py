"""Outline a built .docx: headings with tab replaced, plus table shapes."""
import sys

import docx

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="backslashreplace")

path = sys.argv[1]
d = docx.Document(path)

print("paragraphs", len(d.paragraphs), "tables", len(d.tables))
for p in d.paragraphs:
    t = (p.text or "").strip()
    st = p.style.name if p.style else ""
    if t and st.startswith("Heading"):
        print(f"  [{st}] {t.replace(chr(9), '  ')[:85]}")

print("\n--- tables ---")
for i, tb in enumerate(d.tables):
    print(f"  table {i}: {len(tb.rows)} rows x {len(tb.columns)} cols")

pics = 0
for rel in d.part.rels.values():
    if "image" in rel.reltype:
        pics += 1
print("\nembedded images:", pics)
