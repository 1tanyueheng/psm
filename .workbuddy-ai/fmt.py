from docx import Document
from docx.shared import Pt
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx")
out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\ch1_fmt.txt","w",encoding="utf-8")
for name in ["Normal","Heading 1","Heading 2","Heading 3","Paragraph First and Last","Caption","List Paragraph"]:
    try:
        s = d.styles[name]
        f = s.font
        pf = s.paragraph_format
        print(f"STYLE {name}: font={f.name} size={f.size.pt if f.size else None} bold={f.bold} "
              f"| align={pf.alignment} first_line={pf.first_line_indent} left={pf.left_indent} "
              f"space_before={pf.space_before} space_after={pf.space_after} line={pf.line_spacing}", file=out)
    except Exception as e:
        print("STYLE", name, "ERR", e, file=out)
print(file=out)
print("=== first 12 paragraphs with run detail ===", file=out)
for i, p in enumerate(d.paragraphs[:14]):
    print(f"[{i}] style={p.style.name} align={p.paragraph_format.alignment} "
          f"first={p.paragraph_format.first_line_indent} line={p.paragraph_format.line_spacing} :: {p.text[:80]!r}", file=out)
    for r in p.runs[:3]:
        print(f"      run: {r.text[:40]!r} font={r.font.name} size={r.font.size} bold={r.bold}", file=out)
print(file=out)
print("=== table style ===", file=out)
for t in d.tables:
    print("table style:", t.style.name if t.style else None, "rows", len(t.rows), file=out)
    c = t.rows[0].cells[0]
    print("  hdr font:", c.paragraphs[0].runs[0].font.size if c.paragraphs[0].runs else None,
          "bold:", c.paragraphs[0].runs[0].bold if c.paragraphs[0].runs else None, file=out)
out.close()
print("ok")
