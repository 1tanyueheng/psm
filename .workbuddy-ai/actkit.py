"""Shared drawing helpers for the activity diagrams.

Replaces `uml_kit.py`, which drew at a type scale that printed around 8 pt once
the figure was placed at 14 cm. Base type is now 20 px at 1x, which reaches
about 11 pt on the page at 16 cm.

Design rules carried over from `uml_kit` (these are the faults the user once
rejected):

  * **No fills and no coloured borders.** White shapes, thin dark outline, so the
    labels carry the meaning instead of the colour.
  * **Every connector is drawn only in the gap between two shapes** - never
    through a box - and its arrowhead lands exactly on the target's edge.
  * **`action()` returns the BOTTOM**, not the top. Passing that straight into
    the next `action()` as `top` stacks boxes on each other; that mistake cost
    three renders.
  * **A label needs `LEAD` air above its arrow and `LEAD > line height`**, or the
    last line of a two-line edge label is struck through by its own line.
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont

INK = "#1A1A1A"
LINE = "#333333"
PALE = "#FFFFFF"
GREY = "#5E5E5E"

BASE = 20          # points at 1x
SS = 2             # supersampling


def font(size, bold=False):
    for path in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
                 r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else ""),
                 r"C:\Windows\Fonts\segoeui%s.ttf" % ("b" if bold else "")):
        if os.path.exists(path):
            return ImageFont.truetype(path, int(size * SS))
    return ImageFont.load_default()


# The same three scales as the sequence figures, so the two diagram types in one
# document do not disagree about how big a word is. Sized in "points at 1x":
# these figures are placed at 16 cm and a 20 px char lands at about 11 pt on the
# page, which is what makes them readable in print.
F_BOX = font(22)        # wording inside an action box
F_DIA = font(21)        # wording inside a decision diamond
F_EDGE = font(20)       # Yes / No on an edge
F_EDGE_B = font(20, True)
F_NOTE = font(18)

LINE_H = 24        # one line of box text
PAD = 22           # vertical padding inside a box, both edges


class Diagram:
    def __init__(self, width, min_height=200):
        self.W = width
        self.H = min_height
        self.img = Image.new("RGB", (width * SS, min_height * SS), "white")
        self.d = ImageDraw.Draw(self.img)

    def L(self, v):
        return int(round(v * SS))

    # ---------------------------------------------------------------- lines
    def seg(self, x1, y1, x2, y2, width=2, colour=LINE, dash=False):
        if not dash:
            self.d.line([self.L(x1), self.L(y1), self.L(x2), self.L(y2)],
                        fill=colour, width=self.L(width))
            return
        dx, dy = x2 - x1, y2 - y1
        dist = math.hypot(dx, dy)
        if dist == 0:
            return
        step, n = 12, max(1, int(dist // 12))
        for i in range(n):
            if i % 2:
                continue
            a, b = i / n, min(1.0, (i + 1) / n)
            self.d.line([self.L(x1 + dx * a), self.L(y1 + dy * a),
                         self.L(x1 + dx * b), self.L(y1 + dy * b)],
                        fill=colour, width=self.L(width))

    def arrowhead(self, x1, y1, x2, y2, colour=LINE, size=14, open_=False):
        ang = math.atan2(y2 - y1, x2 - x1)
        p1 = (x2 - size * math.cos(ang - 0.38), y2 - size * math.sin(ang - 0.38))
        p2 = (x2 - size * math.cos(ang + 0.38), y2 - size * math.sin(ang + 0.38))
        pts = [(self.L(x2), self.L(y2)), (self.L(p1[0]), self.L(p1[1])),
               (self.L(p2[0]), self.L(p2[1]))]
        if open_:
            self.d.polygon(pts, fill="white", outline=colour, width=self.L(2))
        else:
            self.d.polygon(pts, fill=colour)

    def connect(self, x1, y1, x2, y2, head=True, colour=LINE, dash=False,
                open_head=False):
        self.seg(x1, y1, x2, y2, colour=colour, dash=dash)
        if head:
            self.arrowhead(x1, y1, x2, y2, colour=colour, open_=open_head)

    def route(self, points, head=True, colour=LINE, dash=False, open_head=False):
        for i in range(len(points) - 1):
            a, b = points[i], points[i + 1]
            self.seg(a[0], a[1], b[0], b[1], colour=colour, dash=dash)
        if head:
            a, b = points[-2], points[-1]
            self.arrowhead(a[0], a[1], b[0], b[1], colour=colour, open_=open_head)

    # ---------------------------------------------------------------- shapes
    def action(self, cx, top, text, w=300, min_h=None):
        """A rounded action box. Returns its BOTTOM edge."""
        lines = text.split("\n")
        h = len(lines) * LINE_H + PAD
        if min_h:
            h = max(h, min_h)
        self.d.rounded_rectangle(
            [self.L(cx - w / 2), self.L(top), self.L(cx + w / 2), self.L(top + h)],
            radius=self.L(10), fill=PALE, outline=INK, width=self.L(2))
        ty = top + (h - len(lines) * LINE_H) / 2 + 2
        for ln in lines:
            self.d.text((self.L(cx), self.L(ty)), ln, font=F_BOX, fill=INK,
                        anchor="ma")
            ty += LINE_H
        return top + h

    def decision(self, cx, top, text, w=240, h=130):
        self.d.polygon([(self.L(cx), self.L(top)),
                        (self.L(cx + w / 2), self.L(top + h / 2)),
                        (self.L(cx), self.L(top + h)),
                        (self.L(cx - w / 2), self.L(top + h / 2))],
                       fill=PALE, outline=INK, width=self.L(2))
        lines = text.split("\n")
        ty = top + h / 2 - (len(lines) - 1) * (LINE_H / 2) - F_DIA.size / SS * 0.62
        for ln in lines:
            self.d.text((self.L(cx), self.L(ty)), ln, font=F_DIA, fill=INK,
                        anchor="ma")
            ty += LINE_H
        return top + h

    def start(self, cx, top, r=16):
        self.d.ellipse([self.L(cx - r), self.L(top),
                        self.L(cx + r), self.L(top + 2 * r)], fill=INK)
        return top + 2 * r

    def end(self, cx, top, r=18):
        self.d.ellipse([self.L(cx - r), self.L(top),
                        self.L(cx + r), self.L(top + 2 * r)],
                       fill="white", outline=INK, width=self.L(2))
        self.d.ellipse([self.L(cx - r + 5), self.L(top + 5),
                        self.L(cx + r - 5), self.L(top + 2 * r - 5)], fill=INK)
        return top + 2 * r

    def bar(self, cx, top, w=220, h=8):
        """A merge / fork bar."""
        self.d.rectangle([self.L(cx - w / 2), self.L(top),
                          self.L(cx + w / 2), self.L(top + h)],
                         fill=INK)
        return top + h

    def label(self, x, y, text, anchor="ma", colour=GREY, bold=False):
        f = F_EDGE_B if bold else F_EDGE
        self.d.multiline_text((self.L(x), self.L(y)), text, font=f, fill=colour,
                              anchor=anchor, align="center", spacing=self.L(4))

    def note(self, x, y, text, w=None):
        self.d.multiline_text((self.L(x), self.L(y)), text, font=F_NOTE,
                              fill=GREY, spacing=self.L(5))

    def frame(self, x0, y0, x1, y1, text=None):
        """An optional boundary box with its name in the corner."""
        self.d.rectangle([self.L(x0), self.L(y0), self.L(x1), self.L(y1)],
                         outline=GREY, width=self.L(2))
        if text:
            self.d.text((self.L(x0 + 14), self.L(y0 + 10)), text, font=F_EDGE_B,
                        fill=GREY, anchor="la")

    # ---------------------------------------------------------------- export
    def save(self, path, crop_bottom=None):
        h = int(crop_bottom) if crop_bottom else self.H
        h = min(h, self.img.height // SS)
        out = self.img.crop((0, 0, self.W * SS, h * SS)).resize(
            (self.W, h), Image.LANCZOS)
        out.save(path)
        return path, out.size
