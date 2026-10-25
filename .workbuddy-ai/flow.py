from docx import Document
from docx.oxml.ns import qn
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - Literature Review.docx")
body = d.element.body
print("=== body children in order ===")
for i, ch in enumerate(body.iterchildren()):
    tag = ch.tag.split('}')[-1]
    if tag == 'p':
        txt = "".join(n.text or "" for n in ch.iter(qn('w:t')))
        sty = ch.find(qn('w:pPr'))
        sname = None
        if sty is not None:
            ps = sty.find(qn('w:pStyle'))
            sname = ps.get(qn('w:val')) if ps is not None else None
        has_sect = ch.find('.//'+qn('w:sectPr')) is not None
        print(f"{i:03d} p  style={sname} sect={has_sect} :: {txt[:70]!r}")
    elif tag == 'tbl':
        rows = ch.findall(qn('w:tr'))
        print(f"{i:03d} TABLE rows={len(rows)}")
    else:
        print(f"{i:03d} {tag}")
