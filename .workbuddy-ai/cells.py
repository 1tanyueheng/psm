import re, zlib, json, sys

path = r"C:\Users\yuehe\Downloads\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.pdf"
data = open(path, "rb").read()
m = re.search(rb"5 0 obj(.*?)endobj", data, re.S)
body = m.group(1)
sm = re.search(rb"stream\r?\n", body)
content = zlib.decompress(body[sm.end():body.rfind(b"endstream")]).decode("latin-1")

tok_re = re.compile(r"""
    (?P<num>[-+]?\d*\.?\d+)
  | (?P<str>\((?:\\.|[^()\\])*\))
  | (?P<arr>\[)
  | (?P<arrend>\])
  | (?P<name>/[^\s/\[\]()<>]+)
  | (?P<op>[A-Za-z'"*]+)
""", re.X)

def unescape(s):
    s = s[1:-1]; out = []; i = 0
    while i < len(s):
        c = s[i]
        if c == "\\" and i + 1 < len(s):
            n = s[i+1]
            mp = {"n":"\n","r":"\r","t":"\t","b":"\b","f":"\f","(":"(",")":")","\\":"\\"}
            if n in mp: out.append(mp[n]); i += 2; continue
            if n.isdigit():
                j = i+1; od = ""
                while j < len(s) and len(od) < 3 and s[j].isdigit(): od += s[j]; j += 1
                out.append(chr(int(od,8) & 0xFF)); i = j; continue
            out.append(n); i += 2; continue
        out.append(c); i += 1
    return "".join(out)

tokens = []
for mm in tok_re.finditer(content):
    k = mm.lastgroup; v = mm.group()
    if k == "num": tokens.append(("num", float(v)))
    elif k == "str": tokens.append(("str", unescape(v)))
    elif k == "arr": tokens.append(("arr_open", None))
    elif k == "arrend": tokens.append(("arr_close", None))
    elif k == "name": tokens.append(("name", v))
    else: tokens.append(("op", v))

def mul(a,b):
    return [a[0]*b[0]+a[1]*b[2], a[0]*b[1]+a[1]*b[3],
            a[2]*b[0]+a[3]*b[2], a[2]*b[1]+a[3]*b[3],
            a[4]*b[0]+a[5]*b[2]+b[4], a[4]*b[1]+a[5]*b[3]+b[5]]

tm=[1,0,0,1,0,0]; tlm=list(tm); font=None; size=0; leading=0
stack=[]; in_arr=0; cur_arr=None
clip = None          # current clip rect from content
gs_stack = []        # graphics state stack for clip
runs = []
fills = []           # filled rects (colors + rect)

i = 0
n = len(tokens)
pending_rect = None
while i < n:
    kind, val = tokens[i]
    if kind == "arr_open":
        in_arr += 1; cur_arr = []; i += 1; continue
    if kind == "arr_close":
        in_arr -= 1; stack.append(cur_arr); cur_arr = None; i += 1; continue
    if kind in ("num","str","name"):
        if in_arr: cur_arr.append(val)
        else: stack.append(val)
        i += 1; continue
    op = val
    if op == "q":
        gs_stack.append(clip)
    elif op == "Q":
        if gs_stack: clip = gs_stack.pop()
    elif op == "re":
        y, x, h, w = stack[-4], stack[-3], stack[-2], stack[-1]
        pending_rect = (x, y, w, h)
    elif op == "W*" or op == "W":
        if pending_rect: clip = pending_rect; pending_rect = None
    elif op == "n":
        pending_rect = None
    elif op == "f*" or op == "f" or op == "F":
        if pending_rect:
            fills.append((pending_rect, tuple(stack[:3]) if len(stack) >= 3 else None))
            pending_rect = None
    elif op == "rg":
        pass
    elif op == "BT":
        tm=[1,0,0,1,0,0]; tlm=list(tm)
    elif op == "Tf":
        size = stack[-1]; font = stack[-2]
    elif op == "Td":
        ty, tx = stack[-1], stack[-2]
        tlm = mul([1,0,0,1,tx,ty], tlm); tm=list(tlm)
    elif op == "TD":
        ty, tx = stack[-1], stack[-2]; leading = -ty
        tlm = mul([1,0,0,1,tx,ty], tlm); tm=list(tlm)
    elif op == "Tm":
        tlm = list(stack[-6:]); tm=list(tlm)
    elif op == "T*":
        tlm = mul([1,0,0,1,0,-leading], tlm); tm=list(tlm)
    elif op == "TL":
        leading = stack[-1]
    elif op in ("Tj","'",'"'):
        if op in ("'", '"'):
            tlm = mul([1,0,0,1,0,-leading], tlm); tm=list(tlm)
        s = stack[-1]
        if isinstance(s,str):
            runs.append({"clip":clip,"x":round(tm[4],2),"y":round(tm[5],2),
                         "font":font,"size":size,"text":s})
    elif op == "TJ":
        arr = stack[-1]
        if isinstance(arr,list):
            parts=[]
            for el in arr:
                if isinstance(el,str): parts.append(el)
                elif isinstance(el,float) and el < -100: parts.append(" ")
            txt="".join(parts)
            if txt.strip():
                runs.append({"clip":clip,"x":round(tm[4],2),"y":round(tm[5],2),
                             "font":font,"size":size,"text":txt})
    stack = []
    i += 1

out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\cells.txt", "w", encoding="utf-8")
def P(*a):
    print(*a, file=out)

# group runs by clip rect -> cell
cells = {}
for r in runs:
    key = r["clip"]
    cells.setdefault(str(key), {"clip":key,"runs":[]})["runs"].append(r)

print("=== CELLS (by clip rect) ===", file=out)
order = sorted(cells.values(), key=lambda c: (-(c["clip"][1] if c["clip"] else 0), (c["clip"][0] if c["clip"] else 0)))
for c in order:
    cl = c["clip"]
    rs = sorted(c["runs"], key=lambda r: -r["y"])
    txt = " / ".join(r["text"].rstrip() for r in rs)
    P(f"clip x={cl[0]:7.2f} y={cl[1]:7.2f} w={cl[2]:7.2f} h={cl[3]:6.2f} :: {txt}")

P()
P("=== FILLED RECTS (non-white) ===")
for rect, col in fills:
    P(f"x={rect[0]:8.2f} y={rect[1]:8.2f} w={rect[2]:8.2f} h={rect[3]:8.2f} color={col}")
out.close()
