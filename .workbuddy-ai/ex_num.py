import zipfile, re
z = zipfile.ZipFile(r"C:\Users\yuehe\Downloads\Chapter2.docx")
num = z.read("word/numbering.xml").decode("utf-8","ignore")
for m in re.finditer(r'<w:num w:numId="(\d+)"[^>]*>\s*<w:abstractNumId w:val="(\d+)"/>', num):
    print("numId", m.group(1), "-> abstract", m.group(2))
print()
m = re.search(r'<w:abstractNum w:abstractNumId="(\d+)".*?</w:abstractNum>', num, re.S)
# find the abstract used by numId 2
a = re.search(r'<w:num w:numId="2"[^>]*>\s*<w:abstractNumId w:val="(\d+)"/>', num)
aid = a.group(1)
mm = re.search(r'<w:abstractNum w:abstractNumId="%s".*?</w:abstractNum>' % aid, num, re.S)
s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',mm.group(0))
for lm in re.finditer(r'<w:lvl w:ilvl="(\d)".*?</w:lvl>', s, re.S):
    if int(lm.group(1)) <= 2:
        print("--- ilvl", lm.group(1), "---")
        print(lm.group(0)[:600]); print()
