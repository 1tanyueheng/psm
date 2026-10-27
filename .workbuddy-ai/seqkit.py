"""Shared drawing helpers for the sequence diagrams.

Every sequence figure in Chapter 4 goes through this module, so one style change
reaches all six and a new figure cannot reintroduce a fault the others have been
cured of.

Rules encoded here, each of them a fault that actually appeared on a page:

  * **A label sits ABOVE its arrow with a real gap.** The label's last line and
    the arrow are `LEAD` apart and `LEAD > LH`; when it was not, the last line of
    every two-line label was struck through by its own connector.
  * **A row reserves the label, the lead and the tail.** `row_h()` is the only
    place a row's height is computed, so two messages cannot overlap.
  * **Lifelines are DOTTED.** A solid line reads as a property of the object;
    UML draws the lifeline dashed for a reason and the supervisor's example does
    the same.
  * **16 px base type at 1x**, roughly 1.5x the first draft, because these
    figures are placed at 16-17 cm and anything smaller prints below 8 pt.
"""
import os
from PIL import Image, ImageDraw, ImageFont

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
DIVIDER = "#8C8C8C"
LIFELINE = "#8C8C8C"

SCALE = 2

LH = 22        # label line height
LEAD = 34      # from the label's last line to the arrow (must exceed LH)
TAIL = 26      # from the arrow to the top of the next row
BAND = 46      # a section band: rule, title and air
HEAD = 126     # lifeline boxes


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


def row_h(label, kind):
    """Height a single message row needs."""
    n = label.count("\n") + 1
    if kind == "self":
        return max(52, LH * n + 28)
    return LH * n + LEAD + TAIL


def dotted_v(d, x, y1, y2, colour=LIFELINE, width=2, dash=9, gap=7):
    """A vertical dashed rule - the lifeline."""
    y = y1
    while y < y2:
        d.line([(x, y), (x, min(y + dash, y2))], fill=colour, width=width)
        y += dash + gap


class Sequence:
    def __init__(self, xs, heads, width=None):
        self.xs = xs
        self.heads = heads
        self.W = width or (xs[-1] + xs[1] // 2)

    def layout(self, steps):
        """Assign every row a y and a height. Returns (rows, total height)."""
        y, rows = 0, []
        for step in steps:
            kind = step["kind"]
            if kind == "section":
                rows.append({**step, "y": y, "h": 0})
                y += BAND
                continue
            h = row_h(step["label"], kind)
            rows.append({**step, "y": y, "h": h})
            y += h
        return rows, y

    def draw(self, steps, out):
        rows, content = self.layout(steps)
        top = 26
        height = top + HEAD + 34 + content + 58

        img = Image.new("RGB", (self.W * SCALE, height * SCALE), "white")
        d = ImageDraw.Draw(img)

        def L(v):
            return int(round(v * SCALE))

        fh = font(17, True)
        fmsg = font(17)
        fsmall = font(16)
        fsec = font(17, True)

        base = top + HEAD + 34
        bottom = base + content + 16

        for i, name in enumerate(self.heads):
            cx = self.xs[i]
            lines = name.split("\n")
            wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
            bw, bh = wmax + 34, 24 * len(lines) + 26
            x0, y0 = cx - bw / 2, top + 8
            d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)],
                                radius=L(10), fill="white", outline=INK, width=L(2))
            ty = y0 + 13
            for ln in lines:
                d.text((L(cx), L(ty)), ln, font=fh, fill=INK, anchor="ma")
                ty += 24
            dotted_v(d, L(cx), L(y0 + bh), L(bottom), width=L(2))

        for row in rows:
            kind = row["kind"]
            if kind == "section":
                ybar = base + row["y"] + 24
                d.line([L(18), L(ybar), L(self.W - 18), L(ybar)], fill=DIVIDER, width=L(1))
                d.text((L(26), L(ybar + 9)), row["label"], font=fsec, fill=INK)
                continue

            cxa, cxb = self.xs[row["a"]], self.xs[row["b"]]
            cy = base + row["y"] + row["h"] - TAIL
            lines = row["label"].split("\n")

            if kind == "self":
                gap = (self.xs[row["a"] + 1] - cxa if row["a"] + 1 < len(self.xs) else 320) - 60
                xr = cxa + gap
                block = LH * len(lines)
                half = (block + 16) / 2 + 4
                yt, yb = cy - half, cy + half
                d.line([L(cxa), L(yt), L(xr), L(yt)], fill=LINE, width=L(2))
                d.line([L(xr), L(yt), L(xr), L(yb)], fill=LINE, width=L(2))
                d.line([L(xr), L(yb), L(cxa), L(yb)], fill=LINE, width=L(2))
                d.polygon([(L(cxa), L(yb)), (L(cxa + 13), L(yb - 6)),
                           (L(cxa + 13), L(yb + 6))], fill=LINE)
                ty = cy - block / 2 + 1
                for ln in lines:
                    d.text((L(cxa + 18), L(ty)), ln, font=fsmall, fill=INK, anchor="la")
                    ty += LH
                continue

            returning = kind == "return"
            colour = GREY if returning else LINE
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=colour, width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)),
                       (L(cxb - 16 * dirn), L(cy - 6)),
                       (L(cxb - 16 * dirn), L(cy + 6))],
                      fill="white" if returning else LINE,
                      outline=colour, width=L(2))
            ty = cy - LEAD - (len(lines) - 1) * LH
            mid = (cxa + cxb) / 2
            f = fsmall if returning else fmsg
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * LH)), ln, font=f, fill=colour, anchor="ma")

        img.resize((self.W, height), Image.LANCZOS).save(out)
        return out, (self.W, height), round(height / self.W, 3)
