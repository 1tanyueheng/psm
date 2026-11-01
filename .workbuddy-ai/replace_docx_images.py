"""Replace diagram images inside an existing .docx, leaving everything else alone.

The user edited Chapter 4 by hand after the last build — the Database Design and
Interface Design sections were removed — so rebuilding from the script would
destroy that work. This swaps only the image parts whose bytes match a known
file on disk, and rewrites nothing else: no paragraph, no table, no section.

A .docx is a zip. Each image lives at word/media/imageN.png, and the drawing in
document.xml points at it by relationship id. Replacing the bytes of an existing
part keeps every reference intact.

Usage:  python replace_docx_images.py <docx> <png> [<png> ...]
"""
import hashlib
import shutil
import sys
import zipfile
from pathlib import Path


def sha(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()


def main() -> int:
    docx_path = Path(sys.argv[1])
    sources = [Path(p) for p in sys.argv[2:]]

    wanted = {sha(p.read_bytes()): p for p in sources}

    backup = docx_path.with_suffix(docx_path.suffix + ".bak")
    shutil.copy2(docx_path, backup)
    print(f"backup: {backup.name}")

    tmp = docx_path.with_suffix(".tmp.docx")

    replaced = []
    with zipfile.ZipFile(docx_path) as src, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as dst:
        for item in src.infolist():
            data = src.read(item.filename)
            if item.filename.startswith("word/media/"):
                h = sha(data)
                if h in wanted:
                    new = wanted[h].read_bytes()
                    dst.writestr(item, new)
                    replaced.append((item.filename, wanted[h].name))
                    continue
            dst.writestr(item, data)

    tmp.replace(docx_path)

    if not replaced:
        print("no image matched — nothing changed")
        return 1

    for part, name in replaced:
        print(f"  {part}  <-  {name}")

    # Verify: reopen and confirm the new bytes are embedded.
    import docx as _docx

    d = _docx.Document(str(docx_path))
    embedded = {sha(r.target_part.blob) for r in d.part.rels.values() if "image" in r.reltype}
    missing = [p.name for p in sources if sha(p.read_bytes()) not in embedded]
    print(f"paragraphs={len(d.paragraphs)} tables={len(d.tables)}")
    print("still missing from the document:", missing or "none")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
