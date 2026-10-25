from docx import Document
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx")
out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\ch1_intro.txt","w",encoding="utf-8")
for i, p in enumerate(d.paragraphs[:14]):
    print(f"[{i}] style={p.style.name} :: {p.text}", file=out)
    print(file=out)
out.close()
print("ok")
