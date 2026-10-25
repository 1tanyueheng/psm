from docx import Document
from docx.oxml.ns import qn
import re
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx")
st = d.styles["Heading 2"]
pPr = st.element.find(qn("w:pPr"))
numPr = pPr.find(qn("w:numPr"))
print("Heading2 numPr:", re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',numPr.xml) if numPr is not None else None)
st1 = d.styles["Heading 1"]
pPr1 = st1.element.find(qn("w:pPr"))
numPr1 = pPr1.find(qn("w:numPr"))
print("Heading1 numPr:", re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',numPr1.xml) if numPr1 is not None else None)

num = d.part.numbering_part.element
for n in num.findall(qn("w:num")):
    nid = n.get(qn("w:numId"))
    a = n.find(qn("w:abstractNumId")).get(qn("w:val"))
    if nid in ("1","2","3"):
        print("numId", nid, "-> abstract", a, "| overrides:", [o.xml[:120] for o in n.findall(qn("w:lvlOverride"))])
