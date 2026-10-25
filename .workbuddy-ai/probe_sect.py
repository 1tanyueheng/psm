from docx import Document
from docx.oxml.ns import qn
import zipfile, re

# Check page numbering / footer content in original parts
src = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
z = zipfile.ZipFile(src)
print("=== PARTS ===")
for n in z.namelist():
    print("  ", n, z.getinfo(n).file_size)

d = Document(src)
sectPrs = d.element.body.findall('.//' + qn('w:sectPr'))
print("\n=== sectPr elements found:", len(sectPrs))
for i, sp in enumerate(sectPrs):
    print(f"\n--- sectPr {i} ---")
    print(sp.xml[:1500])

print("\n=== footer parts xml ===")
for n in z.namelist():
    if 'footer' in n or 'header' in n:
        data = z.read(n).decode('utf-8', 'ignore')
        print(f"\n### {n} (len {len(data)})")
        print(data[:1200])
