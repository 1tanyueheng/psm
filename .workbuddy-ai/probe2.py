import re, zipfile
src = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
z = zipfile.ZipFile(src)
doc = z.read("word/document.xml").decode("utf-8","ignore")
# strip namespaces for readability
for i, m in enumerate(re.finditer(r"<w:sectPr.*?</w:sectPr>", doc, re.S)):
    s = m.group(0)
    s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"', "", s)
    print(f"--- sectPr {i} --- (pos {m.start()})")
    print(s)
    print()
print("=== footer1 stripped ===")
f = z.read("word/footer1.xml").decode("utf-8","ignore")
f = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"', "", f)
print(f)
