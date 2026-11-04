"""Write `thesis/diagrams/class_diagram.drawio` — a real draw.io file, not Mermaid.

Two ways to get this diagram into draw.io, and they fail differently:

  * **`.txt`, pasted into Arrange > Insert > Mermaid.** Keeps the source
    editable, but draw.io's Mermaid converter is what decides whether the lines
    appear — which is why the crow's-foot notation (`||--o{`) produced 36 boxes
    and no connectors: it is not Mermaid's class grammar.
  * **`.drawio`, opened as a file.** Native `mxCell` shapes and `mxCell` edges.
    The connectors are already there because this file *is* the connectors; no
    converter is involved.

This script writes the second, from the same declarations the Mermaid version
uses (`make_class_diagram.RELATIONS`), so the two cannot disagree.

Layout: classes are placed in the use-case group they belong to, on a grid per
group, and every edge is a straight orthogonal connector between two shape
centres. That is deliberately dumb — draw.io's own *Edit > Arrange > Layout* does
a better job than any hand-rolled packing, and the point of this file is that the
arrows exist and are attached to both ends, not that the packing is beautiful.

Every edge carries `source` and `target` pointing at real cell ids. An edge with
`source`/`target` set is anchored to those shapes; one without is a free line
that looks correct and moves the moment anything does — the exact fault the user
described as "no line connected between the class".
"""
import io
import os
import xml.etree.ElementTree as ET

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
OUT = os.path.join(BASE, "thesis", "diagrams", "class_diagram.drawio")

import make_class_diagram as gen  # noqa: E402

CELL_W = 230
CELL_H = 40          # title + one row; the real height is computed from members
COL_GAP = 70
ROW_GAP = 60
GROUP_COLS = 5

# UML arrow decorations, as draw.io edge styles.
EDGE_STYLE = {
    "assoc":   "endArrow=open;endFill=0;html=1;rounded=0;",
    "comp":    "startArrow=diamondThin;startFill=1;endArrow=none;html=1;rounded=0;",
    "agg":     "startArrow=diamondThin;startFill=0;endArrow=none;html=1;rounded=0;",
    "dependency": "endArrow=open;endFill=0;dashed=1;html=1;rounded=0;",
    "inherit": "endArrow=block;endFill=0;html=1;rounded=0;",
}

# draw.io escapes its labels as HTML.
ESC = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;"}

def esc(text):
    out = str(text)
    for a, b in ESC.items():
        out = out.replace(a, b)
    return out


def main(classes=None, relations=None, out=None, title=None):
    """Write a .drawio for a subset of the model.

    Called with no arguments it writes the whole 4.14. `split_class_drawio.py`
    passes one figure's classes and relations so the two halves can each be a
    native draw.io file, laid out separately.
    """
    classes = gen.CLASSES if classes is None else classes
    relations = gen.RELATIONS if relations is None else relations
    out = out or OUT

    cell_specs = []

    # --- lay the classes out, one block per use-case group -------------------
    groups = {}
    for alias, _table, uc, group in classes:
        groups.setdefault((uc, group), []).append(alias)

    ids = {}
    y_cursor = 40
    next_id = 10

    for (uc, group), aliases in groups.items():
        # A cheap shelf pack: five across, then down. draw.io can re-flow it.
        for index, alias in enumerate(aliases):
            col, row = index % GROUP_COLS, index // GROUP_COLS
            x = 40 + col * (CELL_W + COL_GAP)
            y = y_cursor + row * (CELL_H + 90 + ROW_GAP)

            cell_id = f"cls{next_id}"
            next_id += 1
            ids[alias] = cell_id

            members = gen.ATTRIBUTES[alias]
            height = 26 + 16 * max(1, len(members))

            # draw.io treats `value` as HTML (html=1). So the label carries real
            # `<div>`/`<br/>` tags and real `<<PK>>` stereotypes, and ElementTree
            # escapes it exactly once on the way out. Escaping it here as well
            # produced `&amp;lt;&amp;lt;PK&amp;gt;&amp;gt;`, which draw.io
            # faithfully renders as the literal text `&lt;&lt;PK&gt;&gt;`.
            label = (
                f'<div style="font-weight:bold;border-bottom:1px solid #1a1a1a;'
                f'padding-bottom:2px;margin-bottom:3px">{alias}</div>'
                + "<br/>".join(members)
            )

            cell_specs.append({
                "id": cell_id,
                "value": label,
                "style": ("swimlane;fontStyle=1;align=left;verticalAlign=top;spacingLeft=6;"
                          "spacingTop=2;html=1;whiteSpace=nowrap;startSize=22;"
                          "fillColor=none;strokeColor=#1A1A1A;"
                          "fontFamily=Times New Roman;fontSize=11;"),
                "vertex": "1",
                "parent": "1",
                "geometry": {"x": str(x), "y": str(y),
                             "width": str(CELL_W), "height": str(height),
                             "as": "geometry"},
            })

        rows_used = (len(aliases) + GROUP_COLS - 1) // GROUP_COLS
        y_cursor += rows_used * (CELL_H + 90 + ROW_GAP) + 80

    # --- one edge per relation, anchored at both ends -----------------------
    missing = []
    for rel in relations:
        left, card, _spare, right, label, kind = rel

        source_alias = right if kind == "inherit" else left
        target_alias = left if kind == "inherit" else right

        if source_alias not in ids or target_alias not in ids:
            missing.append(f"{left} -> {right}")
            continue

        edge_id = f"edge{next_id}"
        next_id += 1

        style = EDGE_STYLE.get(kind, EDGE_STYLE["assoc"])
        cell_specs.append({
            "id": edge_id,
            "value": label,
            "style": (style + "edgeStyle=orthogonalEdgeStyle;endSize=6;"
                      "fontFamily=Times New Roman;fontSize=10;"
                      "labelBackgroundColor=#FFFFFF;"),
            "edge": "1",
            "parent": "1",
            "source": ids[source_alias],
            "target": ids[target_alias],
            "geometry": {"relative": "1", "as": "geometry"},
        })

    extents_y = y_cursor + 200

    # draw.io treats `value` as HTML (html=1), and the XML writer escapes it once
    # on the way out. So the label is written with real `<div>` and `<br/>` tags
    # and real `<<PK>>` stereotypes, and `escape_attr` below escapes the quotes
    # and ampersands exactly once.
    tree = ET.Element("mxfile", host="app.diagrams.net", type="device")
    diagram = ET.SubElement(tree, "diagram",
                            name=title or "Class diagram - PSM Assessment System",
                            id="psm-class-diagram")
    model = ET.SubElement(
        diagram, "mxGraphModel",
        dx="1600", dy="1400", grid="1", gridSize="10", guides="1", tooltips="1",
        connect="1", arrows="1", fold="1", page="1", pageScale="1",
        pageWidth="1654", pageHeight=str(max(1169, extents_y)), math="0", shadow="0",
    )
    root = ET.SubElement(model, "root")
    ET.SubElement(root, "mxCell", id="0")
    ET.SubElement(root, "mxCell", id="1", parent="0")

    for cell in cell_specs:
        attrs = {k: v for k, v in cell.items() if k != "geometry"}
        el = ET.SubElement(root, "mxCell", **attrs)
        geo = ET.SubElement(el, "mxGeometry", **cell["geometry"])

    ET.indent(tree, space="  ")
    io.open(out, "w", encoding="utf-8", newline="\n").write(
        ET.tostring(tree, encoding="unicode")
    )
    io.open(out, "a", encoding="utf-8").write("\n")

    print(f"classes : {len(ids)}")
    print(f"edges   : {len(relations) - len(missing)}")
    print(f"missing : {missing or 'none'}")
    print(f"wrote {out}")
    return 0 if not missing else 1


if __name__ == "__main__":
    raise SystemExit(main())
