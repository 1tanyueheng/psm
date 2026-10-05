import re, zlib, json

path = r"C:\Users\yuehe\Downloads\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.pdf"
data = open(path, "rb").read()
m = re.search(rb"5 0 obj(.*?)endobj", data, re.S)
body = m.group(1)
sm = re.search(rb"stream\r?\n", body)
content = zlib.decompress(body[sm.end():body.rfind(b"endstream")]).decode("latin-1")
out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\colors.txt", "w", encoding="utf-8")

tok_re = re.compile(r"""
    (?P<num>[-+]?\d*\.?\d+)
  | (?P<str>\((?:\\.|[^()\\])*\))
  | (?P<arr>\[)
  | (?P<arrend>\])
  | (?P<name>/[^\s/\[\]()<>]+)
  | (?P<op>[A-Za-z'"*]+)
""", re.X)

def unescape(s):
    s = s[1:-1]; o=[]; i=0
    while i < len(s):
        c=s[i]
        if c=="\\" and i+1<len(s):
            n=s[i+1]
            mp={"n":"\n","r":"\r","t":"\t","b":"\b","f":"\f","(":"(",")":")","\\":"\\"}
            if n in mp: o.append(mp[n]); i+=2; continue
            if n.isdigit():
                j=i+1; od=""
                while j<len(s) and len(od)<3 and s[j].isdigit(): od+=s[j]; j+=1
                o.append(chr(int(od,8)&0xFF)); i=j; continue
            o.append(n); i+=2; continue
        o.append(c); i+=1
    return "".join(o)

toks=[]
for mm in tok_re.finditer(content):
    k=mm.lastgroup; v=mm.group()
    if k=="num": toks.append(("num",float(v)))
    elif k=="str": toks.append(("str",unescape(v)))
    elif k=="arr": toks.append(("arr_open",None))
    elif k=="arrend": toks.append(("arr_close",None))
    elif k=="name": toks.append(("name",v))
    else: toks.append(("op",v))

color=(0.0,0.0,0.0)
stack=[]; in_arr=0; cur=None
runs=[]
for kind,val in toks:
    if kind=="arr_open": in_arr+=1; cur=[]; continue
    if kind=="arr_close":
        in_arr-=1; stack.append(cur); cur=None; continue
    if kind in ("num","str","name"):
        if in_arr: cur.append(val)
        else: stack.append(val)
        continue
    if val=="rg": color=tuple(stack[-3:])
    elif val=="g":
        g=stack[-1]; color=(g,g,g)
    elif val=="Tj":
        s=stack[-1]
        if isinstance(s,str) and s.strip(): runs.append((color,s))
    elif val=="TJ":
        arr=stack[-1]
        if isinstance(arr,list):
            parts=[]
            for el in arr:
                if isinstance(el,str): parts.append(el)
                elif isinstance(el,float) and el<-100: parts.append(" ")
            t="".join(parts)
            if t.strip(): runs.append((color,t))
    stack=[]

print("=== COLOURED / NON-BLACK RUNS ===", file=out)
for col,t in runs:
    if col!=(0.0,0.0,0.0):
        print(f"{col} |{t.rstrip()}|", file=out)
print("\n=== total runs:", len(runs), file=out)

# extract image 14 (JPEG)
m14 = re.search(rb"14 0 obj(.*?)endobj", data, re.S)
b14 = m14.group(1)
im = re.search(rb"stream\r?\n", b14)
istart = im.end()
iend = b14.rfind(b"endstream")
jpeg = b14[istart:iend]
if jpeg.endswith(b"\r\n"): jpeg = jpeg[:-2]
elif jpeg.endswith(b"\n"): jpeg = jpeg[:-1]
open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\logo.jpg","wb").write(jpeg)
print("\nJPEG bytes:", len(jpeg), "starts:", jpeg[:4], "ends:", jpeg[-2:], file=out)
out.close()
