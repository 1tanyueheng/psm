import zipfile, re
src = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx"
z = zipfile.ZipFile(src)
doc = z.read("word/document.xml").decode("utf-8","ignore")
m = re.search(r'<w:p [^>]*>(?:(?!</w:p>).)*?INTRODUCTION(?:(?!</w:p>).)*?</w:p>', doc, re.S)
if m:
    s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"','',m.group(0))
    print(s[:2500])
