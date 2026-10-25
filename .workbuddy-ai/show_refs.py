from docx import Document
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - PSM Assessment System.docx")
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if t.startswith(("i.","ii.","iii.","iv.")) or t == "References":
        print(f"[{i}] {t}")
