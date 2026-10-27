"""Activity diagram - Manage Evaluation.

The flow a reader has to be able to follow:

    start -> open the allocated form -> this form's window open? --no--> refuse
          -> mark each criterion on the 0-5 scale
          -> every score in range?  --no--> refuse and correct
          -> save the marks            (a save can happen many times)
          -> the assessor submits
          -> every criterion marked? --no--> refuse
          -> the form locks, the final mark is recomputed and published -> end

One path, one refusal column, no branch for "not yet": saving is a step, not an
alternative, so it sits on the spine and the submit decision is the one that
ends the attempt. The conflict-of-interest recusal is a real rule, but it is a
single alternative flow (A6 in Table 4.4) and drawing it here doubled the figure
for an edge case.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_evaluation.png"

CX = 620
RX = 1350
W = 1800
BOXC = 510
BOXR = 420
DIAW, DIAH = 320, 140
G = 48

dg = Diagram(W, 1200)

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


prev = step(prev, "Assessor opens the\nallocated form")

_, d1_bot, d1 = decide(prev, "Window\nopen?", h=132)
refuse(d1, "Refuse 422: this form's\nassessment window is not open")

prev = step(d1_bot, "Marks each criterion\non the 0-5 scale")

_, d2_bot, d2 = decide(prev, "Every score\nin range?", h=136)
refuse(d2, "Refuse 422\n0 to 5 only")

prev = step(d2_bot, "Saves the marks\n(0-5 becomes the weight)")

_, d3_bot, d3 = decide(prev, "Submits the\nform?", w=DIAW - 40, h=132)
refuse(d3, "Refuse 422 naming each\ncriterion still unmarked", label="No")

prev = step(d3_bot, "Form locked; final mark\nrecomputed and published")

y = prev + G + 30
dg.connect(CX, prev, CX, y + 10)
y = dg.end(CX, y, r=18)
dg.label(CX, y + 10, "end")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
