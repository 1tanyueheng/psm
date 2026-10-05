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
    s = s[1:-1]
    out = []
    i = 0
    while i < len(s):
        c = s[i]
        if c == "\\" and i + 1 < len(s):
            n = s[i+1]
            mp = {"n":"\n","r":"\r","t":"\t","b":"\b","f":"\f","(":"(",")":")","\\":"\\"}
            if n in mp:
                out.append(mp[n]); i += 2; continue
            if n.isdigit():
                j = i+1; od = ""
                while j < len(s) and len(od) < 3 and s[j].isdigit():
                    od += s[j]; j += 1
                out.append(chr(int(od, 8) & 0xFF)); i = j; continue
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

tm = [1,0,0,1,0,0]; tlm = [1,0,0,1,0,0]
font = None; size = 0; leading = 0
runs = []

def mul(a,b):
    return [a[0]*b[0]+a[1]*b[2], a[0]*b[1]+a[1]*b[3],
            a[2]*b[0]+a[3]*b[2], a[2]*b[1]+a[3]*b[3],
            a[4]*b[0]+a[5]*b[2]+b[4], a[4]*b[1]+a[5]*b[3]+b[5]]

stack = []
in_arr = 0
cur_arr = None

for kind, val in tokens:
    if kind == "arr_open":
        in_arr += 1; cur_arr = []
        continue
    if kind == "arr_close":
        in_arr -= 1
        stack.append(cur_arr); cur_arr = None
        continue
    if kind in ("num","str","name"):
        if in_arr:
            cur_arr.append(val)
        else:
            stack.append(val)
        continue
    op = val
    if op == "BT":
        tm = [1,0,0,1,0,0]; tlm = list(tm)
    elif op == "Tf":
        size = stack[-1]; font = stack[-2]
    elif op == "Td":
        ty, tx = stack[-1], stack[-2]
        tlm = mul([1,0,0,1,tx,ty], tlm); tm = list(tlm)
    elif op == "TD":
        ty, tx = stack[-1], stack[-2]
        leading = -ty
        tlm = mul([1,0,0,1,tx,ty], tlm); tm = list(tlm)
    elif op == "Tm":
        tlm = list(stack[-6:]); tm = list(tlm)
    elif op == "T*":
        tlm = mul([1,0,0,1,0,-leading], tlm); tm = list(tlm)
    elif op == "TL":
        leading = stack[-1]
    elif op in ("Tj","'",'"'):
        if op in ("'", '"'):
            tlm = mul([1,0,0,1,0,-leading], tlm); tm = list(tlm)
        s = stack[-1]
        if isinstance(s, str):
            runs.append({"x": tm[4], "y": tm[5], "font": font, "size": size, "text": s})
    elif op == "TJ":
        arr = stack[-1]
        if isinstance(arr, list):
            parts = []
            for el in arr:
                if isinstance(el, str): parts.append(el)
                elif isinstance(el, float) and el < -100: parts.append(" ")
            txt = "".join(parts)
            if txt.strip():
                runs.append({"x": tm[4], "y": tm[5], "font": font, "size": size, "text": txt})
    stack = []

print("RUNS", len(runs), file=sys.stderr)

lines = {}
for r in runs:
    lines.setdefault(round(r["y"]), []).append(r)

out = []
for y in sorted(lines, reverse=True):
    rs = sorted(lines[y], key=lambda r: r["x"])
    merged = []
    for r in rs:
        est_w = len(r["text"]) * r["size"] * 0.5
        if merged and r["x"] - merged[-1]["end"] < 3.0:
            merged[-1]["text"] += r["text"]
            merged[-1]["end"] = r["x"] + est_w
        else:
            merged.append({"x": r["x"], "text": r["text"], "font": r["font"],
                           "size": r["size"], "end": r["x"] + est_w})
    out.append((y, merged))

with open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\layout.txt", "w", encoding="utf-8") as f:
    for y, merged in out:
        f.write(f"y={y}\n")
        for mg in merged:
            f.write(f"   x={mg['x']:8.2f} {mg['font']} {mg['size']} |{mg['text'].rstrip()}|\n")
print("WROTE layout.txt", file=sys.stderr)
