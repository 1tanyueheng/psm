import zipfile, re
src = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
z = zipfile.ZipFile(src)
num = z.read("word/numbering.xml").decode("utf-8","ignore")
# find num elements mapping numId -> abstractNumId
for m in re.finditer(r'<w:num w:numId="(\d+)"[^>]*>\s*<w:abstractNumId w:val="(\d+)"/>', num):
    print("numId", m.group(1), "-> abstract", m.group(2))
print()
# abstractNum 0 and the one used by numId 1
for aid in ["0","1"]:
    m = re.search(r'<w:abstractNum w:abstractNumId="%s".*?</w:abstractNum>' % aid, num, re.S)
    if m:
        s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',m.group(0))
        print(f"=== abstractNum {aid} ===")
        print(s[:1400])
        print()
