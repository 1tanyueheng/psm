"""Shared drawing helpers for the UML activity diagrams.

Design rules enforced here (this is where the previous bugs came from):
  * every connector is drawn only in the GAP between two shapes — never
    through a box, and its arrowhead lands exactly on the target's edge;
  * no fills and no coloured borders: plain white shapes with a thin dark
    outline, so the labels carry the meaning instead of the colour;
  * one consistent type scale, large enough to read at the width the figure
    is placed in the document.
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont

# ------------------------------------------------------------------ palette
INK = "#1A1A1A"        # outlines and text
LINE = "#333333"       # connectors
PALE = "#FFFFFF"
GREY = "#6B6B6B"

# ------------------------------------------------------------------ type
BASE = 17              # points at 1x; the image is rendered SS times larger
SS = 2                 # supersampling


def font(size, bold=False):
    for path in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
                 r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else ""),
                 r"C:\Windows\Fonts\segoeui%s.ttf" % ("b" if bold else "")):
        if os.path.exists(path):
            return ImageFont.truetype(path, int(size * SS))
    return ImageFont.load_default()


F_BOX = font(BASE - 1)
F_BOX_B = font(BASE - 1, True)
F_EDGE = font(BASE - 3)
F_EDGE_B = font(BASE - 3, True)
F_NOTE = font(BASE - 4)


class Diagram:
    def __init__(self, width, min_height=200):
        self.W = width
        self.H = min_height
        self.img = Image.new("RGB", (width * SS, min_height * SS), "white")
        self.d = ImageDraw.Draw(self.img)

    # -------------------------------------------------- primitives
    def L(self, v):
        return int(round(v * SS))

    def seg(self, x1, y1, x2, y2, width=2, colour=LINE, dash=False):
        if not dash:
            self.d.line([self.L(x1), self.L(y1), self.L(x2), self.L(y2)],
                        fill=colour, width=self.L(width))
            return
        dx, dy = x2 - x1, y2 - y1
        dist = math.hypot(dx, dy)
        if dist == 0:
            return
        step, n = 10, max(1, int(dist // 10))
        for i in range(n):
            if i % 2:
                continue
            a, b = i / n, min(1.0, (i + 1) / n)
            self.d.line([self.L(x1 + dx * a), self.L(y1 + dy * a),
                         self.L(x1 + dx * b), self.L(y1 + dy * b)],
                        fill=colour, width=self.L(width))

    def arrowhead(self, x1, y1, x2, y2, colour=LINE, size=12, open_=False):
        ang = math.atan2(y2 - y1, x2 - x1)
        p1 = (x2 - size * math.cos(ang - 0.38), y2 - size * math.sin(ang - 0.38))
        p2 = (x2 - size * math.cos(ang + 0.38), y2 - size * math.sin(ang + 0.38))
        pts = [(self.L(x2), self.L(y2)), (self.L(p1[0]), self.L(p1[1])),
               (self.L(p2[0]), self.L(p2[1]))]
        if open_:
            self.d.polygon(pts, fill="white", outline=colour, width=self.L(2))
        else:
            self.d.polygon(pts, fill=colour)

    # -------------------------------------------------- connectors
    def connect(self, x1, y1, x2, y2, head=True, colour=LINE, dash=False,
                open_head=False):
        """Straight connector; caller is responsible for end points sitting on
        the edges of the two shapes."""
        self.seg(x1, y1, x2, y2, colour=colour, dash=dash)
        if head:
            self.arrowhead(x1, y1, x2, y2, colour=colour, open_=open_head)

    def route(self, points, head=True, colour=LINE, dash=False, open_head=False):
        """Orthogonal connector through `points`; head at the last point."""
        for i in range(len(points) - 1):
            a, b = points[i], points[i + 1]
            self.seg(a[0], a[1], b[0], b[1], colour=colour, dash=dash)
        if head:
            a, b = points[-2], points[-1]
            self.arrowhead(a[0], a[1], b[0], b[1], colour=colour, open_=open_head)

    # -------------------------------------------------- shapes
    def action(self, cx, top, text, w=300, min_h=None):
        lines = text.split("\n")
        lh = 21
        h = len(lines) * lh + 20
        if min_h:
            h = max(h, min_h)
        self.d.rounded_rectangle(
            [self.L(cx - w / 2), self.L(top), self.L(cx + w / 2), self.L(top + h)],
            radius=self.L(9), fill=PALE, outline=INK, width=self.L(2))
        ty = top + (h - len(lines) * lh) / 2 + 2
        for ln in lines:
            self.d.text((self.L(cx), self.L(ty)), ln, font=F_BOX,
                        fill=INK, anchor="ma")
            ty += lh
        return top + h

    def decision(self, cx, top, text, w=210, h=108):
        self.d.polygon([(self.L(cx), self.L(top)), (self.L(cx + w / 2), self.L(top + h / 2)),
                        (self.L(cx), self.L(top + h)), (self.L(cx - w / 2), self.L(top + h / 2))],
                       fill=PALE, outline=INK, width=self.L(2))
        lines = text.split("\n")
        ty = top + h / 2 - (len(lines) - 1) * 10.5 - 9
        for ln in lines:
            self.d.text((self.L(cx), self.L(ty)), ln, font=F_BOX, fill=INK, anchor="ma")
            ty += 21
        return top + h

    def start(self, cx, top, r=14):
        self.d.ellipse([self.L(cx - r), self.L(top), self.L(cx + r), self.L(top + 2 * r)],
                       fill=INK)
        return top + 2 * r

    def end(self, cx, top, r=16):
        self.d.ellipse([self.L(cx - r), self.L(top), self.L(cx + r), self.L(top + 2 * r)],
                       fill="white", outline=INK, width=self.L(2))
        self.d.ellipse([self.L(cx - r + 5), self.L(top + 5),
                        self.L(cx + r - 5), self.L(top + 2 * r - 5)], fill=INK)
        return top + 2 * r

    def label(self, x, y, text, anchor="ma", colour=GREY, bold=False, mono=False):
        f = F_EDGE_B if bold else F_EDGE
        self.d.multiline_text((self.L(x), self.L(y)), text, font=f, fill=colour,
                              anchor=anchor, align="center", spacing=self.L(3))

    def note(self, x, y, text, w=None):
        """Borderless note: no box, no fill — just text."""
        self.d.multiline_text((self.L(x), self.L(y)), text, font=F_NOTE, fill=GREY,
                              spacing=self.L(4))

    # -------------------------------------------------- export
    def save(self, path, crop_bottom=None):
        h = int(crop_bottom) if crop_bottom else self.H
        h = min(h, self.img.height // SS)
        out = self.img.crop((0, 0, self.W * SS, h * SS)).resize((self.W, h), Image.LANCZOS)
        out.save(path)
        return path, out.size
