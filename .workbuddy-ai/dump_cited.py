import io
from docx import Document
p = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter1 - with citations.docx"
d = Document(p)
w = io.open("ch1_cited.txt","w",encoding="utf-8")
for i, para in enumerate(d.paragraphs):
    t = para.text
    if t.strip():
        print(f"[{i:03d}] {para.style.name}", file=w)
        print(f"      {t}", file=w)
        print(file=w)
w.close(); print("ok")
