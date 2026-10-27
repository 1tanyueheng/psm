"""Shared drawing helpers for the sequence diagrams - large-type edition.

Two things changed after the reference chapter was supplied: the section bands
are gone (a horizontal rule with a caption between blocks was more furniture than
the reader needed), and the type is larger again.

The consequence is arithmetic, not taste. A portrait A4 page with Word's margins
gives 16.4 x 21.3 cm of paper for a figure plus its caption. A sequence diagram
fits when `height / width <= 21.3 / WIDTH_CM`. Fewer messages per figure is what
buys the size: at 11-18 rows and 4-6 lifelines the aspect lands between 0.55 and
0.95, and the figures are 6-15 cm tall at full width.

Rules encoded here, each of them a fault that actually appeared on a page:

  * **A label sits ABOVE its arrow with a real gap.** The label's last line and
    the arrow are `LEAD` apart and `LEAD > LH`; when it was not, the last line of
    every two-line label was struck through by its own connector.
  * **A row reserves its label, the lead and the tail**, in `row_h()` and
    nowhere else, so two messages cannot overlap.
  * **Lifelines are DOTTED**, as in the reference drawing.
  * **No section bands.** Consecutive messages carry the flow; a rule across the
    figure is one more line to read and says nothing a reader wants.
"""
import os
from PIL import Image, ImageDraw, ImageFont

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
LIFELINE = "#8C8C8C"

SCALE = 2

LH = 24        # label line height
LEAD = 36      # from the label's last line to the arrow (must exceed LH)
TAIL = 24      # from the arrow to the top of the next row
HEAD = 134     # lifeline boxes


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


def row_h(label, kind):
    n = label.count("\n") + 1
    if kind == "self":
        return max(56, LH * n + 30)
    return LH * n + LEAD + TAIL


def dotted_v(d, x, y1, y2, colour=LIFELINE, width=2, dash=9, gap=7):
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
        y, rows = 0, []
        for step in steps:
            h = row_h(step["label"], step["kind"])
            rows.append({**step, "y": y, "h": h})
            y += h
        return rows, y

    def draw(self, steps, out):
        rows, content = self.layout(steps)
        top = 26
        height = top + HEAD + 30 + content + 56

        img = Image.new("RGB", (self.W * SCALE, height * SCALE), "white")
        d = ImageDraw.Draw(img)

        def L(v):
            return int(round(v * SCALE))

        fh = font(19, True)
        fmsg = font(19)
        fsmall = font(18)

        base = top + HEAD + 30
        bottom = base + content + 14

        for i, name in enumerate(self.heads):
            cx = self.xs[i]
            lines = name.split("\n")
            wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
            bw, bh = wmax + 36, 26 * len(lines) + 28
            x0, y0 = cx - bw / 2, top + 8
            d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)],
                                radius=L(11), fill="white", outline=INK, width=L(2))
            ty = y0 + 14
            for ln in lines:
                d.text((L(cx), L(ty)), ln, font=fh, fill=INK, anchor="ma")
                ty += 26
            dotted_v(d, L(cx), L(y0 + bh), L(bottom), width=L(2))

        for row in rows:
            kind = row["kind"]
            cxa, cxb = self.xs[row["a"]], self.xs[row["b"]]
            cy = base + row["y"] + row["h"] - TAIL
            lines = row["label"].split("\n")

            if kind == "self":
                gap = (self.xs[row["a"] + 1] - cxa if row["a"] + 1 < len(self.xs) else 320) - 60
                xr = cxa + gap
                block = LH * len(lines)
                half = (block + 18) / 2 + 5
                yt, yb = cy - half, cy + half
                d.line([L(cxa), L(yt), L(xr), L(yt)], fill=LINE, width=L(2))
                d.line([L(xr), L(yt), L(xr), L(yb)], fill=LINE, width=L(2))
                d.line([L(xr), L(yb), L(cxa), L(yb)], fill=LINE, width=L(2))
                d.polygon([(L(cxa), L(yb)), (L(cxa + 14), L(yb - 7)),
                           (L(cxa + 14), L(yb + 7))], fill=LINE)
                ty = cy - block / 2 + 1
                for ln in lines:
                    d.text((L(cxa + 20), L(ty)), ln, font=fsmall, fill=INK, anchor="la")
                    ty += LH
                continue

            returning = kind == "return"
            colour = GREY if returning else LINE
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=colour, width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)),
                       (L(cxb - 17 * dirn), L(cy - 7)),
                       (L(cxb - 17 * dirn), L(cy + 7))],
                      fill="white" if returning else LINE,
                      outline=colour, width=L(2))
            ty = cy - LEAD - (len(lines) - 1) * LH
            mid = (cxa + cxb) / 2
            f = fsmall if returning else fmsg
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * LH)), ln, font=f, fill=colour, anchor="ma")

        img.resize((self.W, height), Image.LANCZOS).save(out)
        return out, (self.W, height), round(height / self.W, 3)
