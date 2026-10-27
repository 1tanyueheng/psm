"""Activity diagram - Change title at the PSM 2 boundary.

The faculty permits a student who passed PSM 1 to choose a different title for
PSM 2. The cost is theirs: the PSM 1 documentation was written for the old title,
so the proposal and Chapters 1-4 are done again before Chapters 5-7 can be
assessed against the new one.

What this figure has to make clear, because each is a rule rather than a detail:

  * the change is **optional** and offered **once**, at the start of the chain;
  * it is available only **before Chapter 5 opens**;
  * it is decided by a **full defence**, not by a supervisor alone;
  * the refiled Chapters 1-4 carry **no weight** — the PSM 1 mark was awarded on
    the old title and cannot be re-awarded.

Layout is a straight spine with one branch column. Earlier attempts used two
nested columns and boxes collided; a spine plus one side branch is both easier to
read and easier to keep correct.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, connectors
only in the gap between two shapes.
"""
from uml_kit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_psm2_title_change.png"

W = 1560
CX = 400
RX = 1120
BOXC = 400
BOXR = 330
DIAW, DIAH = 260, 124
G = 40

dg = Diagram(W, 2400)

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y


def step(y, text, w=BOXC, cx=CX):
    y += G
    top = y
    bottom = dg.action(cx, top, text, w=w)
    return top, bottom


# ---- 1. the starting position ---------------------------------------------
y += G
t = y; y = dg.action(CX, t, "Student passed PSM 1\nPSM 2 project created, title inherited", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Student starts the PSM 2 chain", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

# ---- 2. does the student want a different title? ---------------------------
y += G
d1_top = y
d1_bot = dg.decision(CX, d1_top, "Change the\ntitle?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d1_top)
d1_mid = d1_top + DIAH / 2

# No -> straight on, the inherited title.
dg.connect(CX, d1_bot, CX, d1_bot + G)
dg.label(CX - DIAW / 2 - 10, d1_bot + 10, "No", anchor="ra", bold=True)
keep_top = d1_bot + G
keep = dg.action(CX, keep_top, "Keep the inherited title", w=BOXC)

# ---- 3. Yes -> the eligibility guard, in the right column ------------------
d1_yes_y = d1_mid
dq = RX - BOXR / 2
dg.connect(CX + DIAW / 2, d1_yes_y, dq, d1_yes_y)
dg.label(CX + DIAW / 2 + 10, d1_yes_y - 24, "Yes", anchor="la", bold=True)

# The guard box sits ABOVE the decision, not on it.
#
# `action()` returns the box's bottom edge, so `guard` is a y coordinate rather
# than a shape. The box is five lines tall (21px a line plus padding), which is
# taller than half the diamond — starting it only 70px above the mid-line left it
# overlapping the diamond's top vertex.
guard_bot = dg.action(RX, d1_mid - 108, "All four must hold:\n"
                     "· it is a PSM 2 project\n"
                     "· the title has not changed before\n"
                     "· Chapter 5 has not opened\n"
                     "· no work submitted yet", w=BOXR)

d2_top = guard_bot + G
d2_bot = dg.decision(RX, d2_top, "Eligible?", w=DIAW - 40, h=DIAH)
dg.connect(RX, guard_bot, RX, d2_top)
d2_mid = d2_top + DIAH / 2

# No -> refused with the reason, and it rejoins the spine at "keep".
ref_x = RX + BOXR / 2 + 160
ref_w = 250
ref_top = d2_mid - 46
dg.connect(RX + (DIAW - 40) / 2, d2_mid, ref_x - ref_w / 2, d2_mid)
dg.label(RX + (DIAW - 40) / 2 + 8, d2_mid - 22, "No", anchor="la", bold=True)
refused = dg.action(ref_x, ref_top, "Refuse 422\nthe failed guard is named", w=ref_w)

# Yes -> the new form and the signature.
#
# Each box starts where the previous one ENDED, not at a fixed increment: a box
# is 2-4 lines tall (21px a line plus padding), so stepping by G alone put every
# multi-line box on top of the one below it. That was the bug in the first two
# renders of this figure.
dg.label(RX - (DIAW - 40) / 2 - 10, d2_mid + 10, "Yes", anchor="ra", bold=True)

top = d2_bot + G
form_bot = dg.action(RX, top, "New Lampiran B, pre-filled\nwith the new title", w=BOXR)
dg.connect(RX, d2_bot, RX, top)

top = form_bot + G
signed_bot = dg.action(RX, top, "Supervisor signs the new title\n(a signature covers one title)", w=BOXR)
dg.connect(RX, form_bot, RX, top)

# ---- 4. the install -------------------------------------------------------
top = signed_bot + G
install_bot = dg.action(
    RX, top,
    "install():\n"
    "· projects.title = the new title\n"
    "· proposal and Chapters 1-4 prepended\n"
    "· those chapters weigh 0",
    w=BOXR,
)
dg.connect(RX, signed_bot, RX, top)

top = install_bot + G
redo_bot = dg.action(RX, top, "Student redoes the PSM 1 documents\nfor the new title", w=BOXR)
dg.connect(RX, install_bot, RX, top)

top = redo_bot + G
gy = dg.action(RX, top, "Panel defends the new title", w=BOXR)
dg.connect(RX, redo_bot, RX, top)

gy += G
d3_top = gy
d3_bot = dg.decision(RX, d3_top, "Accepted?", w=DIAW - 40, h=DIAH)
dg.connect(RX, gy, RX, d3_top)
d3_mid = d3_top + DIAH / 2

# No -> the re-file loop, which the proposal figure draws in full.
dg.connect(RX + (DIAW - 40) / 2, d3_mid, ref_x - ref_w / 2, d3_mid)
dg.label(RX + (DIAW - 40) / 2 + 8, d3_mid - 22, "No", anchor="la", bold=True)
nrej = dg.action(ref_x, d3_mid - 46, "Re-present the title\n(proposal figure)", w=ref_w)

# Yes -> the PSM 2 chapters.
gy = d3_bot + G
rest = dg.action(RX, gy, "Chapters 5-7, report, Form K\n(chain still totals 100)", w=BOXR)
dg.connect(RX, d3_bot, RX, gy)
dg.label(RX - (DIAW - 40) / 2 - 10, d3_mid + 10, "Yes", anchor="ra", bold=True)

# ---- 5. converge ----------------------------------------------------------
# Both columns must reach the end, or a reader takes the untaken branch for a
# dead end.
JOIN_Y = max(keep, rest, refused, nrej) + 70

dg.seg(CX, keep, CX, JOIN_Y)
dg.seg(RX, rest, RX, JOIN_Y)
dg.seg(CX, JOIN_Y, RX, JOIN_Y)
mid = (CX + RX) / 2
dg.connect(mid, JOIN_Y, mid, JOIN_Y + G - 8)
y = dg.end(mid, JOIN_Y + G, r=16)
dg.label(mid, y + 8, "end")

dg.save(OUT, crop_bottom=y + 50)
print("wrote", OUT)
