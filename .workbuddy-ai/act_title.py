"""Activity diagram - Manage Title and Registration.

Two people, one record: the student files Lampiran A and names a supervisor, the
supervisor acknowledges it and fixes which of the proposed titles will be
registered, and Lampiran B records the title once the student files it.

What the figure must say, because each is a rule rather than a detail:

  * **The agreed title is chosen, not typed.** Part C offers the titles the
    student proposed; anything else is refused and the student files a new
    Lampiran A.
  * **Acknowledgement is where the pairing is registered.** It is not a second
    approval step - it is the moment the supervision exists.
  * **Signing Lampiran B opens the proposal.** Nothing reaches a panel for a
    title no supervisor has agreed to supervise.
  * **Withdrawal is a student's act and is never a deletion.** It is refused
    once a project exists, and it retires the pairing so the supervisor's slot
    comes back.

Two independent halves, so there are two columns and one end node: the filing
route (Filing -> Acknowledgement -> Lampiran B) runs down the right, and the
withdrawal route is its own branch out of the Lampiran B step.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import sys
sys.path.insert(0, r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai")
from uml_kit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_title.png"

CX = 800          # spine
WD_X = 300        # withdrawal column, left of the spine
WX = 1700         # refusal column, right of the spine
W = 2200
BOXC = 440        # spine box width
BOXL = 400        # side column box width
DIAW, DIAH = 300, 128
G = 40

dg = Diagram(W, 3000)

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y


def step(prev, text, w=BOXC, cx=CX, gap=G):
    """Draw a box below `prev` and connect the two. Returns the box's bottom."""
    top = prev + gap
    bottom = dg.action(cx, top, text, w=w)
    dg.connect(cx, prev, cx, top)
    return bottom


def decide(prev, text, w=DIAW):
    """Draw a diamond below `prev`. Returns (top, bottom, mid)."""
    top = prev + G
    bottom = dg.decision(CX, top, text, w=w, h=DIAH)
    dg.connect(CX, prev, CX, top)
    return top, bottom, top + DIAH / 2


def refuse(mid_y, text, label="No", w=BOXL):
    """A refusal box in the right-hand column, level with `mid_y`."""
    left = WX - w / 2
    dg.connect(CX + DIAW / 2, mid_y, left, mid_y)
    dg.label(CX + DIAW / 2 + 10, mid_y - 24, label, anchor="la", bold=True)
    return dg.action(WX, mid_y - 34, text, w=w)


# ---- 1. the student files Lampiran A --------------------------------------
prev = step(prev, "Student opens Lampiran A\nsession is a dropdown of the terms")

# ---- guard: may this student register at all? -----------------------------
d1_top, d1_bot, d1_mid = decide(prev, "May they\nregister?")
refuse(d1_mid, "Refuse 422, naming the cause:\nno term is open · a project already\nexists · an agreement is in flight")

prev = step(d1_bot, "Student enters particulars\nand proposes up to three titles")

d2_top, d2_bot, d2_mid = decide(prev, "Titles are\nusable?")
refuse(d2_mid, "Refuse 422: IsTitle\nunder 10 characters, fewer\nthan 3 words, or a placeholder")

prev = step(d2_bot, "Student names a supervisor\nand submits")

# ---- guard: the programme rule --------------------------------------------
d3_top, d3_bot, d3_mid = decide(prev, "Supervisor serves\nthe student's\nprogramme?", w=DIAW + 40)
refuse(d3_mid, "Refuse 422: the supervisor does\nnot serve this programme\n(an unrecorded programme passes)")

prev = step(d3_bot, "Agreement created\nstatus = pending_supervisor")

# ---- 2. the supervisor acknowledges ---------------------------------------
prev = step(prev + 26, "Supervisor opens the\nincoming Lampiran A")

d4_top, d4_bot, d4_mid = decide(prev, "Agreed title is one\nthe student\nproposed?", w=DIAW + 40)
refuse(d4_mid, "Refuse 422: Part C is a choice\nbetween the candidates — a different\ntitle needs a new Lampiran A")

prev = step(d4_bot, "assignSupervisor()\nsupervision now exists")

prev = step(prev, "status = approved\nagreed_title recorded")

# ---- 3. Lampiran B: the title is filed and signed --------------------------
prev = step(prev + 26, "Student fills Lampiran B\nproject type, field of study,\nrequirements, origin")

prev = step(prev, "status = submitted\nawaiting the supervisor")

d5_top, d5_bot, d5_mid = decide(prev, "Supervisor\nsigns?", w=DIAW - 40)

# ---- Yes -> the proposal opens -------------------------------------------
prev = step(d5_bot, "Open the proposal milestone\nonly now is a panel asked")
dg.label(CX - (DIAW - 40) / 2 - 12, d5_mid + 8, "Yes", anchor="ra", bold=True)

prev = step(prev, "Student uploads the proposal\n(supervisor endorses, panel decides)")

# ---- No -> back to the student -------------------------------------------
RB_W = BOXL
RB_X = CX + BOXC / 2 + 60 + RB_W / 2
rb = dg.action(RB_X, d5_mid - 34,
               "Send back to the student\nstatus = draft, reason required", w=RB_W)
dg.connect(CX + (DIAW - 40) / 2, d5_mid, RB_X - RB_W / 2, d5_mid)
dg.label(CX + (DIAW - 40) / 2 + 8, d5_mid - 24, "No", anchor="la", bold=True)

# The reason is kept, so the student can act on it; the form stays theirs.
back_x = RB_X + RB_W / 2 + 44
dg.route([(RB_X, rb), (RB_X, rb + 34), (back_x, rb + 34),
          (back_x, d5_top - 190), (CX + BOXC / 2, d5_top - 190)])
dg.label(back_x + 10, rb + 44, "student revises and\nre-files the form", anchor="la")

# ---- 4. withdrawal, from the filed-but-not-yet-project state --------------
#  Out of the Lampiran B box: while the form is filed and no project exists.
#  Its own column on the LEFT, so it never touches the send-back loop that
#  runs down the right, and it stays inside the canvas.
WD_X = CX - BOXC / 2 - 130 - BOXL / 2
wd_mid = (d4_bot + d5_top) / 2 + 60
wd = dg.action(WD_X, wd_mid - 34, "Student withdraws the filing\n(own act, never a deletion)", w=BOXL)
dg.connect(CX - BOXC / 2, wd_mid, WD_X + BOXL / 2, wd_mid)
dg.label(CX - BOXC / 2 - 10, wd_mid - 24, "Withdraw", anchor="ra", bold=True)

wd2 = dg.action(WD_X, wd + 46, "Refuse 422 once a Lampiran B\nhas registered a project", w=BOXL)
dg.connect(WD_X, wd, WD_X, wd + 46)

wd3 = dg.action(WD_X, wd2 + 46, "status = cancelled\nretire the pairing, free the slot", w=BOXL)
dg.connect(WD_X, wd2, WD_X, wd2 + 46)

# ---- converge -------------------------------------------------------------
join_y = max(prev, wd3) + 90
dg.seg(WD_X, wd3, WD_X, join_y)
dg.seg(CX, prev, CX, join_y)
dg.seg(WD_X, join_y, CX, join_y)
mid = (CX + WD_X) / 2
dg.connect(mid, join_y, mid, join_y + G - 8)
y = dg.end(mid, join_y + G, r=16)
dg.label(mid, y + 8, "end")

dg.save(OUT, crop_bottom=y + 60)
print("wrote", OUT)
