"""Activity diagram - Manage Milestone (corrected).

Fixes vs previous version:
  * every connector runs from one shape edge to the next; no line passes
    through a box and no arrow starts from empty space
  * the review branch is a clean left/right split that converges on one end
  * the re-upload loop returns into the UPLOAD step from its left edge
  * no fills, no coloured borders, one type scale
"""
import os
from uml_kit import Diagram, INK, GREY

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_milestone.png"

W = 1300
CX = 470          # spine
RX = 990          # approve column
BOXC = 360        # spine box width
BOXR = 300        # right box width
DIAW, DIAH = 230, 116
G = 36

dg = Diagram(W, 1900)

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y

y += G
t = y; y = dg.action(CX, t, "Coordinator registers project\nmilestone chain generated", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "First milestone opened\nstatus = open", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
upload_top = y
t = y; y = dg.action(CX, t, "Student uploads file(s)", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

# ---- D1 accepts submission?
y += G
d1_top = y
d1_bot = dg.decision(CX, d1_top, "Accepts\nsubmission?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d1_top)
d1_mid = d1_top + DIAH / 2

# reject (right)
rt = d1_mid - 44
rb = dg.action(RX, rt, "Refuse 422: deadline\npassed, or chain has\nnot opened it", w=BOXR)
dg.connect(CX + DIAW / 2, d1_mid, RX - BOXR / 2, d1_mid)
dg.label(CX + DIAW / 2 + 10, d1_mid - 24, "No", anchor="la")

y = d1_bot + G
t = y; y = dg.action(CX, t, "Status = submitted", w=BOXC)
dg.connect(CX, d1_bot, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Supervisor opens\nsubmission", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

# ---- D_guard: is this milestone reviewable by a supervisor? -----------------
#
# The guard used to be drawn *inside* the Approve path, which was wrong twice
# over: a guard is a check made before the decision, not a consequence of it,
# and as drawn it implied the proposal could be approved here and merely
# "refused" afterwards. MilestoneService::assertReviewable() refuses the generic
# review actions outright for `proposal` — its verdict is the seated panel's and
# belongs to ProposalReviewService — so the branch has to be taken *before* the
# reviewer reaches Approve/Request revision at all.
y += G
dg_top = y
dg_bot = dg.decision(CX, dg_top, "Supervisor may\nreview it?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, dg_top)
dg_mid = dg_top + DIAH / 2

# proposal (right): refused, and it re-enters the flow at the panel, not at end
gt = dg_mid - 40
gbox = dg.action(RX, gt, "Refuse 422: proposal\nis the panel's to decide", w=BOXR)
dg.connect(CX + DIAW / 2, dg_mid, RX - BOXR / 2, dg_mid)
dg.label(CX + DIAW / 2 + 10, dg_mid - 24, "No", anchor="la")

# panel decision, then the same tail an approval has
gprev = gbox
for txt in ("ProposalReviewService\npanel records its verdict", "Open transaction\nwrite status + timestamp",
            "Record timeline event\nand audit entry", "activateNext()\nopen next milestone",
            "Notify students"):
    gy = gprev + G
    gprev = dg.action(RX, gy, txt, w=BOXR)
    dg.connect(RX, gy - G, RX, gy)
panel_end = gprev

# ---- D2 reviewer decision ---------------------------------------------------
#
# Fed from the guard's Yes side, so the spine below is the ordinary milestone
# path: everything that is not the proposal.
y = dg_bot + G
d2_top = y
d2_bot = dg.decision(CX, d2_top, "Reviewer\ndecision?", w=DIAW, h=DIAH)
dg.connect(CX, dg_bot, CX, d2_top)
d2_mid = d2_top + DIAH / 2

# ---- APPROVE branch (right) ----
d2_ht = d2_mid - 40
d2_hb = d2_mid + 40
dv = dg.action(CX, d2_ht, "transitionTo(Approved)\ncheck legal transition", w=BOXC)
dg.connect(CX + DIAW / 2, d2_mid - 30, CX + DIAW / 2, d2_mid - 30)
dg.connect(CX, d2_hb, CX, d2_ht + 52)
dg.label(CX + DIAW / 2 + 10, d2_mid - 44, "Approve / Request revision -> see the branch below",
         anchor="la", bold=True)
approve_end = dv

# ---- REQUEST REVISION branch (down the spine) ----
y = d2_bot + G
dg.label(CX + DIAW / 2 + 10, d2_mid + 12, "Request\nrevision", anchor="la", bold=True)

t = y; y = dg.action(CX, t, "transitionTo(Rejected)\ncomment required", w=BOXC)
dg.connect(CX, d2_bot, CX, t); prev = y

for txt in ("Revision counter + 1", "Record timeline event\nand audit entry", "Notify students"):
    y += G
    t = y; y = dg.action(CX, t, txt, w=BOXC)
    dg.connect(CX, prev, CX, t); prev = y
revision_end = prev

# ---- converge + end ----
join_y = max(approve_end, revision_end) + 60
dg.seg(RX, approve_end, RX, join_y)
dg.seg(CX, revision_end, CX, join_y)
dg.seg(CX, join_y, RX, join_y)
mid = (CX + RX) / 2
dg.connect(mid, join_y, mid, join_y + G - 6)
y = dg.end(mid, join_y + G, r=16)
dg.label(mid, y + 8, "end")

# ---- re-upload loop: revision END back to the UPLOAD action's left edge ----
LOOPX = CX - BOXC / 2 - 44
land = upload_top + 22
dg.seg(CX - BOXC / 2, (revision_end - 21), LOOPX, revision_end - 21)
dg.seg(LOOPX, revision_end - 21, LOOPX, land)
dg.connect(LOOPX, land, CX - BOXC / 2, land)
dg.label(LOOPX - 10, (land + revision_end) / 2 - 18,
         "reopened\nfor re-upload", anchor="ra")

dg.save(OUT, crop_bottom=y + 48)
print("wrote", OUT)
