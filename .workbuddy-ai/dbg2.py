from docx import Document
from docx.oxml.ns import qn
import re
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - Literature Review (v3).docx")
num = d.part.numbering_part.element
for n in num.findall(qn("w:num")):
    if n.get(qn("w:numId")) == "3":
        for o in n.findall(qn("w:lvlOverride")):
            print("override:", re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',o.xml))
# what does abstract 22 ilvl1 say
ab = None
for a in num.findall(qn("w:abstractNum")):
    if a.get(qn("w:abstractNumId")) == "22":
        ab = a
for lm in ab.findall(qn("w:lvl")):
    if lm.get(qn("w:ilvl")) in ("0","1"):
        t = lm.find(qn("w:lvlText"))
        print("ilvl", lm.get(qn("w:ilvl")), "lvlText=", t.get(qn("w:val")) if t is not None else None,
              "start=", lm.find(qn("w:start")).get(qn("w:val")) if lm.find(qn("w:start")) is not None else None)
