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
from actkit import Diagram

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
    return dg.action(RX, mid - 38, text, w=w)


prev = step(prev, "Student fills in Lampiran A\nand submits it")

# The three registration refusals share one column, so they need 150px of air
# between them; at G the first two boxes overlapped and read as one.
_, d1_bot, d1 = decide(prev, "May the\nstudent\nregister?", h=150)
refuse(d1, "Refuse 422, naming the cause:\nno open term, a project already\nexists, or an agreement in flight")

d2_top, d2_bot, d2 = decide(d1_bot + 116, "Titles\nusable?")
refuse(d2, "Refuse 422: too short,\ntoo few words, or a placeholder")

prev = step(d2_bot, "Agreement created\nstatus = pending_supervisor")

prev = step(prev, "Supervisor picks one of the\nproposed titles and acknowledges")

d3_top, d3_bot, d3 = decide(prev, "Is it one of\nthe three?", w=DIAW + 40)
refuse(d3, "Refuse 422: Part C is a choice\nbetween the candidates")

prev = step(d3_bot, "Supervision created\nagreed title recorded")

prev = step(prev, "Student files Lampiran B\nand the supervisor signs")

_, d4_bot, d4 = decide(prev, "Signed?", w=DIAW - 70, h=124)

# No -> the form goes back and the student re-files it.
RB_X = CX + BOXC / 2 + 60 + 190
rb = dg.action(RB_X, d4 - 38, "Returned to the student\nwith a reason", w=380)
dg.connect(CX + (DIAW - 70) / 2, d4, RB_X - 190, d4)
dg.label(CX + (DIAW - 70) / 2 + 12, d4 - 26, "No", anchor="la", bold=True)
back_x = RB_X + 190 + 48
dg.route([(RB_X, rb), (RB_X, rb + 44), (back_x, rb + 44),
          (back_x, d3_bot + 124), (CX + BOXC / 2, d3_bot + 124)])
dg.label(back_x + 12, rb + 54, "re-files", anchor="la")
dg.label(CX - (DIAW - 70) / 2 - 12, d4 + 10, "Yes", anchor="ra", bold=True)

prev = step(d4_bot, "Proposal milestone opened")

join_y = prev + G + 30
dg.connect(CX, prev, CX, join_y - 10)
y = dg.end(CX, join_y, r=18)
dg.label(CX, y + 10, "end")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
