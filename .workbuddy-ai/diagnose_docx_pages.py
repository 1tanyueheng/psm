"""Diagnose why a figure paragraph is pushed onto a page of its own.

Reads the generated .docx, lists every body paragraph in order with its page
size (from the sectPr that closes its section), its paragraph format and whether
it holds a drawing. Prints the XML of the figure paragraph itself so the
suspect attributes can be seen rather than guessed at.

Usage:  python diagnose_docx_pages.py "<path to .docx>"
"""
import sys
import zipfile
from lxml import etree

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS = {"w": W}

path = sys.argv[1]
with zipfile.ZipFile(path) as z:
    xml = z.read("word/document.xml")
root = etree.fromstring(xml)
body = root.find("w:body", NS)

print("body children:", len(body))
for i, el in enumerate(body):
    tag = etree.QName(el).localname
    if tag != "p":
        print(f"{i:3d} {tag}")
        continue

    pPr = el.find("w:pPr", NS)
    sect = pPr.find("w:sectPr", NS) if pPr is not None else None
    pg = None
    if sect is not None:
        sz = sect.find("w:pgSz", NS)
        pg = sz.get(f"{{{W}}}orient", "portrait") if sz is not None else "?"

    has_drawing = el.find(".//w:drawing", NS) is not None
    text = "".join(el.itertext()).strip()[:52]
    spacing = None
    if pPr is not None and pPr.find("w:spacing", NS) is not None:
        sp = pPr.find("w:spacing", NS)
        spacing = {etree.QName(k).localname: v for k, v in sp.attrib.items()}

    flags = []
    if has_drawing:
        flags.append("IMG")
    if sect is not None:
        flags.append(f"SECT->{pg}")
    print(f"{i:3d} {' '.join(flags):22s} spacing={spacing} {text!r}")

    if has_drawing:
        print("      XML:", etree.tostring(el, pretty_print=False).decode()[:900])
