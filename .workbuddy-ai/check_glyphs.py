from docx import Document
p = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - PSM Assessment System v2.docx"
d = Document(p)
t = d.tables[0]
ok = True
for row in t.rows:
    for c in row.cells:
        if "\u00e2" in c.text:
            ok = False
print("mojibake in table:", not ok)
print("header:", [c.text for c in t.rows[0].cells])
print("row1:", [c.text for c in t.rows[1].cells])
print("row2:", [c.text for c in t.rows[2].cells])
print("ticks:", sum(c.text.count("\u2714") for r in t.rows for c in r.cells),
      "crosses:", sum(c.text.count("\u2716") for r in t.rows for c in r.cells))
