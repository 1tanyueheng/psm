"""Find the largest figure width that still leaves room for its caption.

Word and LibreOffice put a picture and its caption on one page only if
`picture height + caption height + a line of slack` fits in the usable page
height. The visible paper in a render is the drawn rectangle the PDF carries, not
the full page box, so this reads the paper size out of a PDF rather than
assuming it.

Usage:
  python fit_figure_width.py paper <pdf>          # report paper box in cm
  python fit_figure_width.py width <png> <paper_w_cm> <paper_h_cm>
"""
import sys
from PIL import Image

TOP_CM = 0.6      # caption: 12pt TNR at 1.5 spacing, plus its spacing
SLACK_CM = 0.8


def cm(px, dpi=96.0):
    return px / dpi * 2.54


def figure_width(png, paper_w, paper_h):
    im = Image.open(png)
    w, h = im.size
    aspect = h / w
    # Leave a 2 cm gutter on the wide axis so the frame is not flush to the edge.
    room_w = paper_w - 2.0
    room_h = paper_h - TOP_CM - SLACK_CM
    by_h = room_h / aspect
    print(f"{png}: {w}x{h} aspect={aspect:.3f} "
          f"room {room_w:.2f} x {room_h:.2f} cm -> {min(room_w, by_h):.2f} cm")
    return min(room_w, by_h)


if __name__ == "__main__":
    if sys.argv[1] == "paper":
        import re
        data = open(sys.argv[2], "rb").read()
        boxes = set(re.findall(rb"/MediaBox\s*\[\s*0 0 ([\d.]+) ([\d.]+)\s*\]", data))
        for bw, bh in boxes:
            print(f"paper {float(bw)/72*2.54:.2f} x {float(bh)/72*2.54:.2f} cm")
    else:
        figure_width(sys.argv[2], float(sys.argv[3]), float(sys.argv[4]))
