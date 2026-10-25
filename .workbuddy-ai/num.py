from docx import Document
from docx.oxml.ns import qn
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx")
out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\ch1_num.txt","w",encoding="utf-8")
# Heading 2 numbering / style xml
for name in ["Heading 1","Heading 2","Heading 3","Paragraph First and Last","Caption"]:
    s = d.styles[name]
    print("=====", name, "=====", file=out)
    print(s.element.xml[:1800], file=out)
    print(file=out)
out.close()
print("ok")
