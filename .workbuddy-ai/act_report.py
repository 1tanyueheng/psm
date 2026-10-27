"""Activity diagram - Generate Report.

Nine shapes. The use case is one question with two answers at the end: show the
figures, or write them to a file. The old figure ran a separate six-step
aggregate down the spine; that work is one action here, because the steps are a
detail of *how* the figures are computed, not of the flow.

    start -> open the report screen -> choose the filters
          -> may view analytics? --no--> 403
          -> compute the figures -> view or export?
                                     view   -> render the tables and charts
                                     export -> write the CSV in pages, logged
          -> both join -> end

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_report.png"

CX = 640
RX = 1400
W = 1880
BOXC = 540
BOXR = 420
DIAW, DIAH = 340, 146
G = 48

dg = Diagram(W, 1400)

y = 30
y = dg.start(CX, y + 8)
dg.label(CX - 34, y - 6, "start", anchor="ra")
prev = y


def step(prev, text, w=BOXC):
    top = prev + G
    bottom = dg.action(CX, top, text, w=w)
    dg.connect(CX, prev, CX, top)
    return bottom


def decide(prev, text, w=DIAW, h=DIAH):
    top = prev + G
    bottom = dg.decision(CX, top, text, w=w, h=h)
    dg.connect(CX, prev, CX, top)
    return top, bottom, top + h / 2


def refuse(mid, text, label="No", w=BOXR):
    dg.connect(CX + DIAW / 2, mid, RX - w / 2, mid)
    dg.label(CX + DIAW / 2 + 12, mid - 26, label, anchor="la", bold=True)
    return dg.action(RX, mid - 40, text, w=w)


prev = step(prev, "Coordinator opens the report screen\nand chooses the batch, part and term")

_, d1_bot, d1 = decide(prev, "May view\nanalytics?", h=150)
refuse(d1, "403 Forbidden", label="No")

prev = step(d1_bot, "Computes the figures: progress,\nat-risk, workload and mark bands")

_, d2_bot, d2 = decide(prev, "View or\nexport?", w=DIAW + 40, h=150)

# Export -> its own column; both branches meet below.
EX_X = CX + BOXC / 2 + 60 + 220
exp = dg.action(EX_X, d2 - 40, "Writes the CSV in pages\nand logs the export", w=430)
dg.connect(CX + (DIAW + 40) / 2, d2, EX_X - 215, d2)
dg.label(CX + (DIAW + 40) / 2 + 12, d2 - 28, "export", anchor="la", bold=True)
dg.label(CX - (DIAW + 40) / 2 - 12, d2 + 12, "view", anchor="ra", bold=True)

prev = step(d2_bot, "Renders the tables and charts")

join_y = max(prev, exp) + 64
dg.seg(CX, prev, CX, join_y)
dg.seg(EX_X, exp, EX_X, join_y)
dg.seg(EX_X, join_y, CX, join_y)
dg.connect(CX, join_y, CX, join_y + 14)
y = dg.end(CX, join_y + 18, r=18)
dg.label(CX, y + 10, "end")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
