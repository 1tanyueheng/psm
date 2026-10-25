from docx import Document
p = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
out = open(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai\ch2_full.txt", "w", encoding="utf-8")
d = Document(p)
for i, para in enumerate(d.paragraphs):
    print(f"########## P{i:03d} style={para.style.name}", file=out)
    for j, r in enumerate(para.runs):
        print(f"   run{j}: b={r.bold} i={r.italic} size={r.font.size} name={r.font.name} :: {r.text!r}", file=out)
    print(file=out)

print("\n\n===== STYLES IN USE =====", file=out)
for s in d.styles:
    try:
        if s.type is not None and s.name:
            print(f"  {s.type} :: {s.name}", file=out)
    except Exception:
        pass

print("\n\n===== SECTIONS =====", file=out)
for i, sec in enumerate(d.sections):
    print(f"  section {i}: w={sec.page_width} h={sec.page_height} ml={sec.left_margin} mr={sec.right_margin} "
          f"mt={sec.top_margin} mb={sec.bottom_margin} start={sec.start_type}", file=out)
out.close()
print("ok")
