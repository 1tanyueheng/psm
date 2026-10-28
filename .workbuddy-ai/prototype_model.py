"""Prototype model figure for Chapter 3.

Six stages in the order the method runs, with the feedback loop drawn as the
thing that separates prototyping from waterfall: the client reviews a working
prototype, it is refined, and the loop returns to requirement gathering until the
client is satisfied.

    Requirement Gathering & Analysis -> Quick Design -> Build a Prototype
        -> Client Evaluation -> Refining the Prototype -> (loop back)
        -> Implementation and Maintenance -> end

Routing rules learned the hard way on this one: the loop, the end connector and
the forward path each get their own x, and no label is allowed closer to the
left edge than its own width. The first two renders had a clipped label, a loop
that crossed the end arrow, and a merge that read as a junction with no origin.

Conventions per AGENTS.md: white fill, thin dark outline, no fills, arrows land
on box edges and never cross one.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\prototype_model.png"

W = 1900
BOXW, BOXH = 340, 130
GX = 110
GY = 150

LEFT = 560
CX = [LEFT, LEFT + BOXW + GX, LEFT + 2 * (BOXW + GX)]
ROW1 = 200
ROW2 = ROW1 + BOXH + GY

dg = Diagram(W, ROW2 + BOXH + 260)

dg.action(CX[0], ROW1, "Requirement\nGathering & Analysis", w=BOXW, min_h=BOXH)
dg.action(CX[1], ROW1, "Quick Design", w=BOXW, min_h=BOXH)
dg.action(CX[2], ROW1, "Build a Prototype", w=BOXW, min_h=BOXH)
dg.action(CX[2], ROW2, "Client Evaluation", w=BOXW, min_h=BOXH)
dg.action(CX[1], ROW2, "Refining the Prototype", w=BOXW, min_h=BOXH)
dg.action(CX[0], ROW2, "Implementation\nand Maintenance", w=BOXW, min_h=BOXH)

mid1 = ROW1 + BOXH / 2
mid2 = ROW2 + BOXH / 2

# ---- forward flow along the top row, down, and back along the bottom row ----
dg.connect(CX[0] + BOXW / 2, mid1, CX[1] - BOXW / 2, mid1)
dg.connect(CX[1] + BOXW / 2, mid1, CX[2] - BOXW / 2, mid1)
dg.connect(CX[2], ROW1 + BOXH, CX[2], ROW2)
dg.connect(CX[2] - BOXW / 2, mid2, CX[1] + BOXW / 2, mid2)
dg.connect(CX[1] - BOXW / 2, mid2, CX[0] + BOXW / 2, mid2)

# ---- start ----
dg.start(CX[0], ROW1 - 100, r=13)
dg.connect(CX[0], ROW1 - 74, CX[0], ROW1)
dg.label(CX[0] + 26, ROW1 - 96, "start", anchor="la")

# ---- end: below Implementation, on the same x as the forward path ----
end_top = ROW2 + BOXH + 150
dg.connect(CX[0], ROW2 + BOXH, CX[0], end_top)
dg.end(CX[0], end_top, r=17)
dg.label(CX[0], end_top + 42, "end")

# ---- the feedback loop ----
#  It leaves Refining's bottom edge, drops to a lane BELOW both rows, runs left
#  outside the column and climbs back into Requirement Gathering. The lane is
#  below the Implementation box and below the end connector's turn, so the two
#  never touch.
lane_y = ROW2 + BOXH + 72
loop_x = CX[0] - BOXW / 2 - 120
refine_x = CX[1] - BOXW / 2 - 55
dg.route([(refine_x, ROW2 + BOXH), (refine_x, lane_y),
          (loop_x, lane_y), (loop_x, mid1),
          (CX[0] - BOXW / 2, mid1)])
dg.label(loop_x - 16, lane_y + 10,
         "loop until the client\nis satisfied", anchor="ra")

dg.save(OUT, crop_bottom=end_top + 76)
print("wrote", OUT)
