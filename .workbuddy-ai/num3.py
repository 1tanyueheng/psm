import zipfile, re
src = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
z = zipfile.ZipFile(src)
num = z.read("word/numbering.xml").decode("utf-8","ignore")
m = re.search(r'<w:abstractNum w:abstractNumId="22".*?</w:abstractNum>', num, re.S)
s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',m.group(0))
# print only lvl 0..2
for lm in re.finditer(r'<w:lvl w:ilvl="(\d)".*?</w:lvl>', s, re.S):
    if int(lm.group(1)) <= 3:
        print(f"--- ilvl {lm.group(1)} ---")
        print(lm.group(0))
        print()
