"""Replace diagram images in a .docx by the caption that follows them.

Matching by content hash failed because the user rebuilt the chapter with their
own Mermaid renders, so the embedded copies of the activity diagrams are stale —
no byte-identical file exists on disk to match against. Position is what
actually identifies an image here: every figure is a drawing paragraph followed
by its caption.

This walks `word/document.xml`, pairs each `a:blip r:embed` with the next
non-empty text run (the caption), and rewrites the bytes of the media part that
relationship points at. Nothing else in the package is touched: no paragraph, no
table, no section, no style.

Usage:  python replace_docx_images_by_caption.py <docx> "Figure 4.5"=<png> ...
        python replace_docx_images_by_caption.py <docx> --list
"""
import re
import shutil
import sys
import zipfile
from xml.etree import ElementTree as ET

NS = {
    "w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main",
    "a": "http://schemas.openxmlformats.org/drawingml/2006/main",
    "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "rel": "http://schemas.openxmlformats.org/package/2006/relationships",
}


def paragraph_text(p) -> str:
    return "".join(t.text or "" for t in p.iter(f"{{{NS['w']}}}t")).strip()


def map_images(docx_path: str):
    """Return [(caption, relationship id, media part name)] in document order."""
    with zipfile.ZipFile(docx_path) as z:
        document = ET.fromstring(z.read("word/document.xml"))
        rels = ET.fromstring(z.read("word/_rels/document.xml.rels"))

    media = {
        r.get("Id"): r.get("Target")
        for r in rels.findall("rel:Relationship", NS)
        if (r.get("Target") or "").startswith("media/")
    }

    body = document.find("w:body", NS)
    paras = body.findall(".//w:p", NS)

    out = []
    for i, p in enumerate(paras):
        embed = p.find(".//a:blip", NS)
        if embed is None:
            continue
        rid = embed.get(f"{{{NS['r']}}}embed")
        caption = ""
        for q in paras[i + 1 : i + 4]:
            text = paragraph_text(q)
            if text:
                caption = text
                break
        out.append((caption, rid, media.get(rid)))
    return out


def main() -> int:
    docx_path = sys.argv[1]
    args = sys.argv[2:]

    if args and args[0] == "--list":
        for caption, rid, part in map_images(docx_path):
            print(f"  {rid:8} {str(part):20} {caption[:70]}")
        return 0

    wanted = {}
    for a in args:
        caption, _, png = a.partition("=")
        wanted[caption.strip()] = png.strip()

    pairs = map_images(docx_path)
    plan = []
    for caption, rid, part in pairs:
        for key, png in wanted.items():
            if caption.startswith(key):
                plan.append((caption, part, png))
                break

    missing = [k for k in wanted if not any(c.startswith(k) for c, _, _ in plan)]
    if missing:
        print("caption(s) not found:", missing)
        return 1

    by_part = {part: png for _, part, png in plan}

    backup = docx_path + ".bak"
    shutil.copy2(docx_path, backup)
    tmp = docx_path + ".tmp"

    with zipfile.ZipFile(docx_path) as src, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as dst:
        for item in src.infolist():
            data = src.read(item.filename)
            name = item.filename
            if name.startswith("word/media/"):
                short = name[len("word/media/"):]
                for part, png in by_part.items():
                    if part and part.endswith(short):
                        data = open(png, "rb").read()
                        print(f"  {part:20} <- {png}")
                        break
            dst.writestr(item, data)

    shutil.move(tmp, docx_path)
    print(f"backup kept at {backup}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
