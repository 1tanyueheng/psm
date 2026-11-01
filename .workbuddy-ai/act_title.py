"""Activity diagram - Manage Title and Registration.

The flow a reader has to be able to follow:

    start -> fill in Lampiran A -> registerable? --no--> refuse
          -> titles usable?     --no--> refuse
          -> agreement pending  -> supervisor acknowledges
          -> title is a proposed one? --no--> refuse
          -> supervision created, agreed title recorded
          -> Lampiran B filed and signed? --no--> returned, re-filed
          -> proposal opened    -> end

Withdrawal is a separate, small figure: it is an independent student action that
is available at any point before a project exists, and threading it into the
spine is what made the previous version unreadable.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import LINE_H, PAD, Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_title.png"

CX = 640
RX = 1360          # refusal column
W = 1800
BOXC = 500
BOXR = 420
DIAW, DIAH = 320, 136
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
    # The right-hand vertex is at CX + w/2 for the width THIS diamond was given.
    # `refuse()` used the module default DIAW and so started every "No" arrow
    # from the wrong x — some inside the diamond, some floating in the gap
    # between the diamond and the refusal box.
    return top, bottom, top + h / 2, w


def refuse(mid, text, label="No", w=BOXR, dia_w=DIAW):
    dg.connect(CX + dia_w / 2, mid, RX - w / 2, mid, into=6)
    dg.label(CX + dia_w / 2 + 12, mid - 26, label, anchor="la", bold=True)
    return dg.action(RX, mid - 38, text, w=w)


prev = step(prev, "Student fills in Lampiran A\nand submits it")

# The three registration refusals share one column, so they need 150px of air
# between them; at G the first two boxes overlapped and read as one.
_, d1_bot, d1, d1_w = decide(prev, "May the\nstudent\nregister?", h=150)
refuse(d1, "Refuse 422, naming the cause:\nno open term, a project already\nexists, or an agreement in flight",
       dia_w=d1_w)

d2_top, d2_bot, d2, d2_w = decide(d1_bot + 116, "Titles\nusable?")
refuse(d2, "Refuse 422: too short,\ntoo few words, or a placeholder", dia_w=d2_w)

prev = step(d2_bot, "Agreement created\nstatus = pending_supervisor")

prev = step(prev, "Supervisor picks one of the\nproposed titles and acknowledges")

d3_top, d3_bot, d3, d3_w = decide(prev, "Is it one of\nthe three?", w=DIAW + 40)
refuse(d3, "Refuse 422: Part C is a choice\nbetween the candidates", dia_w=d3_w)

prev = step(d3_bot, "Supervision created\nagreed title recorded")

prev = step(prev, "Student files Lampiran B\nand the supervisor signs")

_, d4_bot, d4, d4_w = decide(prev, "Signed?", w=DIAW - 70, h=124)

# No -> the form goes back and the student re-files it.
#
# The return used to land at `d3_bot + 124`, which is *inside* the
# "Student files Lampiran B" box: the arrowhead was drawn over the box's own
# outline and the line appeared to stop in mid-air. It now lands on that box's
# RIGHT edge, at its vertical centre.
RB_X = CX + BOXC / 2 + 60 + 190
rb = dg.action(RB_X, d4 - 38, "Returned to the student\nwith a reason", w=380)
dg.connect(CX + d4_w / 2, d4, RB_X - 190, d4, into=6)
dg.label(CX + d4_w / 2 + 12, d4 - 26, "No", anchor="la", bold=True)

FILE_H = 2 * LINE_H + PAD        # "Student files Lampiran B / and the supervisor signs"
file_top = prev - (2 * LINE_H + PAD)     # prev is the box's BOTTOM
mid_file = file_top + FILE_H / 2
right_edge = CX + BOXC / 2
# Run down the right margin, clear of the "Returned" box and the refusal column.
MARGIN_X = W - 30

dg.route([(RB_X, rb), (RB_X, rb + 44), (MARGIN_X, rb + 44),
          (MARGIN_X, mid_file), (right_edge, mid_file)])
dg.label(MARGIN_X - 12, rb + 54, "re-files", anchor="ra")
dg.label(CX - d4_w / 2 - 12, d4 + 10, "Yes", anchor="ra", bold=True)

prev = step(d4_bot, "Proposal milestone opened")

join_y = prev + G + 30
dg.connect(CX, prev, CX, join_y - 10)
y = dg.end(CX, join_y, r=18)
dg.label(CX, y + 10, "end")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
