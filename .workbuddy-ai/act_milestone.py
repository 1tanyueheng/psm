"""Activity diagram - Manage Milestone.

Eleven shapes. The old version carried one diamond per guard plus three
exception boxes off to the side; a reader only needs the decision the *supervisor*
makes and what the chain does afterwards.

    start -> the chain is generated -> first milestone opened
          -> student uploads -> accepted? --no--> refuse, try again
          -> supervisor reviews -> approve or revise
          -> approve: the next milestone opens
          -> revise:  this one reopens
          -> end

The proposal's different verdict is one note on the review step, not a second
spine: the panel decides the title, and that is a fact about one milestone, not
a second flow.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import LINE_H, PAD, Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_milestone.png"

CX = 620
RX = 1340
W = 1800
BOXC = 510
BOXR = 430
DIAW, DIAH = 330, 144
G = 48

dg = Diagram(W, 2400)

y = 30
y = dg.start(CX, y + 8)
dg.label(CX - 34, y - 6, "start", anchor="ra")
prev = y


def step(prev, text, w=BOXC):
    top = prev + G
    bottom = dg.action(CX, top, text, w=w)
    dg.connect(CX, prev, CX, top, into=6)
    return bottom


def decide(prev, text, w=DIAW, h=DIAH):
    top = prev + G
    bottom = dg.decision(CX, top, text, w=w, h=h)
    dg.connect(CX, prev, CX, top, into=6)
    return top, bottom, top + h / 2


def refuse(mid, text, label="No", w=BOXR):
    dg.connect(CX + DIAW / 2, mid, RX - w / 2, mid, into=6)
    dg.label(CX + DIAW / 2 + 12, mid - 26, label, anchor="la", bold=True)
    return dg.action(RX, mid - 40, text, w=w)


prev = step(prev, "Coordinator registers the project;\nthe milestone chain is generated")

prev = step(prev, "First milestone opened")

upload_top = prev
prev = step(upload_top, "Student uploads the file(s)")

_, d1_bot, d1 = decide(prev, "Accepted?", w=DIAW - 40, h=132)
refuse(d1, "Refuse 422: the chain has\nnot opened it yet")

prev = step(d1_bot, "Supervisor opens the\nsubmitted work")

_, d2_bot, d2 = decide(prev, "Approve, or\nsend back?", w=DIAW + 40, h=150)

# Send back -> the milestone reopens and the student re-uploads.
RB_X = CX + BOXC / 2 + 60 + 210
rb = dg.action(RB_X, d2 - 40, "status = rejected\nrevision counter + 1", w=400)
dg.connect(CX + (DIAW + 40) / 2, d2, RB_X - 200, d2, into=6)
dg.label(CX + (DIAW + 40) / 2 + 12, d2 - 28, "send back", anchor="la", bold=True)

# The re-upload path.
#
# Two faults were here, both found by looking at the render rather than the code:
#
#   1. It came back in at `upload_top + 30`, which is *inside* the action box, so
#      the arrowhead was swallowed by the box's outline and the line ended in the
#      middle of the shape. It now lands on the box's RIGHT edge at its centre.
#
#   2. The vertical run sat at `back_x`, which is 1395 — straight through the
#      "Refuse 422" box (x 1432..1992 is clear of it, but 1395 is not: the box
#      starts at RX - w/2 = 1340 - 280 = 1060 and ends at 1620). The run now
#      goes down the right-hand margin at `MARGIN_X`, past every shape.
UPLOAD_H = 1 * LINE_H + PAD          # one line of text, so the box is this tall
right_edge = CX + BOXC / 2
mid_up = upload_top + UPLOAD_H / 2

# Right of the widest shape on the right-hand column (the Refuse box ends at
# 1620), so the return line never crosses a box.
MARGIN_X = W - 30

dg.route([(RB_X, rb), (RB_X, rb + 46), (MARGIN_X, rb + 46),
          (MARGIN_X, mid_up), (right_edge, mid_up)])
dg.label(MARGIN_X - 12, rb + 56, "re-upload", anchor="ra")

prev = step(d2_bot, "Milestone approved")
dg.label(CX - (DIAW + 40) / 2 - 12, d2 + 12, "approve", anchor="ra", bold=True)

prev = step(prev, "Next milestone opened;\nif there is none, the part is complete")

y = prev + G + 30
dg.connect(CX, prev, CX, y + 10)
y = dg.end(CX, y, r=18)
dg.label(CX, y + 10, "end")

# The note about the proposal has been removed (2026-11-01).
#
# It read "The proposal is the exception: its verdict is the panel's, and
# approving it never opens the chain", and it was drawn at x=40, y=210 — which
# is on the main spine, so its second line was struck through by the arrow
# between "chain generated" and "First milestone opened". Worse, it was a claim
# the specification contradicts: the panel decision is asserted in the use case
# as an alternative flow (A.4), not left off the figure. The flow itself is
# unchanged; only the offending label is gone.

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
