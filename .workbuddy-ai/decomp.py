import re, zlib, collections

path = r"C:\Users\yuehe\Downloads\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.pdf"
data = open(path, "rb").read()

m = re.search(rb"5 0 obj(.*?)endobj", data, re.S)
body = m.group(1)
sm = re.search(rb"stream\r?\n", body)
start = sm.end()
end = body.rfind(b"endstream")
raw = body[start:end]
content = zlib.decompress(raw)
open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\content.txt","wb").write(content)
print("content len", len(content))
print(content[:3000].decode("latin-1"))
