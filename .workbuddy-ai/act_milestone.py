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
from actkit import Diagram

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
dg.connect(CX + (DIAW + 40) / 2, d2, RB_X - 200, d2)
dg.label(CX + (DIAW + 40) / 2 + 12, d2 - 28, "send back", anchor="la", bold=True)
back_x = RB_X + 200 + 50
dg.route([(RB_X, rb), (RB_X, rb + 46), (back_x, rb + 46),
          (back_x, upload_top + 30), (CX + BOXC / 2, upload_top + 30)])
dg.label(back_x + 12, rb + 56, "re-upload", anchor="la")

prev = step(d2_bot, "Milestone approved")
dg.label(CX - (DIAW + 40) / 2 - 12, d2 + 12, "approve", anchor="ra", bold=True)

prev = step(prev, "Next milestone opened;\nif there is none, the part is complete")

y = prev + G + 30
dg.connect(CX, prev, CX, y + 10)
y = dg.end(CX, y, r=18)
dg.label(CX, y + 10, "end")

dg.note(40, 210, "The proposal is the exception: its verdict is the\n"
                 "panel's, and approving it never opens the chain.")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
