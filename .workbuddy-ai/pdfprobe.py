import re, zlib, sys, collections

path = r"C:\Users\yuehe\Downloads\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.pdf"
data = open(path, "rb").read()
print("size", len(data))
print("header", data[:20])

markers = [b"/AcroForm", b"/XFA", b"/Widget", b"/ObjStm", b"/XRefStm", b"/Font",
           b"/Type0", b"/TrueType", b"/Image", b"/Encrypt", b"/FlateDecode",
           b"/Subtype/Type1", b"/Subtype/Type0", b"/ToUnicode", b"/Annots"]
for m in markers:
    print(m.decode(), data.count(m))

# list objects
objs = re.findall(rb"(\d+)\s+(\d+)\s+obj(.*?)endobj", data, re.S)
print("num obj", len(objs))
for num, gen, body in objs:
    head = body[:220].replace(b"\n", b" ").replace(b"\r", b" ")
    print("---", num.decode(), gen.decode(), len(body), head[:220])
