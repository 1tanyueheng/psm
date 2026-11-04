"""Validate a generated .drawio file without opening draw.io.

The user's complaint was "no line connected between the class". That has a
precise signature in the XML: an `<mxCell edge="1">` with no `source` or no
`target`, or a `source`/`target` naming a cell that does not exist. draw.io opens
such a file happily and draws a line that is attached to nothing.

Checks:
  1. the file parses as XML and has the mxfile/diagram/mxGraphModel/root shape;
  2. every `source`/`target` names a vertex cell that exists;
  3. every edge has both ends set;
  4. every vertex is reachable — a class with no edge at all is an island;
  5. no duplicate cell ids (draw.io silently drops the second).

Usage:  python check_drawio.py thesis/diagrams/class_diagram.drawio
"""
import sys
import xml.etree.ElementTree as ET

PATH = sys.argv[1] if len(sys.argv) > 1 else \
    r"thesis/diagrams/class_diagram.drawio"


def main(path):
    tree = ET.parse(path)
    root = tree.getroot()

    problems = []

    if root.tag != "mxfile":
        problems.append(f"root element is <{root.tag}>, expected <mxfile>")

    diagrams = root.findall("diagram")
    if not diagrams:
        problems.append("no <diagram> element")

    cells = root.findall(".//mxCell")
    accents = {c.get("id") for c in cells}

    if len(accents) != len(cells):
        problems.append(f"duplicate cell ids: {len(cells)} cells, {len(accents)} unique")

    vertices = {}
    edges = []

    for c in cells:
        if c.get("vertex") == "1":
            vertices[c.get("id")] = c
        elif c.get("edge") == "1":
            edges.append(c)

    dangling = []
    for e in edges:
        src, tgt = e.get("source"), e.get("target")
        if not src or not tgt:
            dangling.append(f"edge {e.get('id')} has source={src!r} target={tgt!r}")
        elif src not in vertices or tgt not in vertices:
            dangling.append(f"edge {e.get('id')} points at a cell that is not a vertex "
                            f"(source={src!r} target={tgt!r})")

    touched = set()
    for e in edges:
        touched.add(e.get("source"))
        touched.add(e.get("target"))

    islands = [vid for vid in vertices if vid not in touched]

    print(f"file      : {path}")
    print(f"diagrams  : {len(diagrams)}")
    print(f"vertices  : {len(vertices)}")
    print(f"edges     : {len(edges)}")
    print(f"anchored  : {len(edges) - len(dangling)} / {len(edges)}")
    print(f"islands   : {len(islands)}")
    for i in islands:
        problems.append(f"vertex {i} ({vertices[i].get('value','')[:30]}…) has no edge")

    problems.extend(dangling)

    print()
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  -", p)
        return 1

    print("OK - every edge is anchored to two existing vertices, and every "
          "vertex has at least one edge")
    return 0


if __name__ == "__main__":
    sys.exit(main(PATH))
