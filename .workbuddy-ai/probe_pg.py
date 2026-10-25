import zipfile, re
z = zipfile.ZipFile(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - Literature Review.docx")
doc = z.read("word/document.xml").decode("utf-8","ignore")
for i, m in enumerate(re.finditer(r"<w:sectPr.*?</w:sectPr>", doc, re.S)):
    s = re.sub(r'\s+xmlns:[a-z0-9]+="[^"]*"', "", m.group(0))
    has_pgnum = "<w:pgNumType" in s
    orient = "landscape" if 'w:orient="landscape"' in s else "portrait"
    print(f"sectPr {i}: {orient} pgNumType={has_pgnum}")
    if has_pgnum:
        print("   ", re.search(r"<w:pgNumType[^>]*/>", s).group(0))
