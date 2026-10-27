"""Activity diagram - Manage Milestone.

Corrected a second time. What was wrong in the previous version:

  * **The proposal guard sat inside the Approve path.** It was drawn as a box
    saying "Guard: proposal milestone is refused here" *after* the decision to
    approve, which says the opposite of the rule: it implies the proposal can be
    approved by a reviewer and merely gets refused downstream. In the code
    `MilestoneService::assertReviewable()` refuses the generic review actions on
    `proposal` outright - its verdict is the seated panel's and belongs to
    `ProposalReviewService` - so the check happens *before* the reviewer decides
    anything. A guard is a test, not a consequence.

  * **The "Refuse 422" box for an unacceptable submission had no outgoing
    arrow.** A decision with an escape path that leads nowhere is a dead end in
    the drawing and implies the process stops; in reality the student retries.

  * The re-upload loop returned from the *middle* of the revision branch
    (a hardcoded `revision_end - 21`) rather than from the end of it.

What the diagram now says:
  1. the chain is generated and the first milestone opened;
  2. the student uploads; an unacceptable upload is refused and returns to the
     upload step;
  3. an acceptable one is `submitted`;
  4. a **guard** asks whether a supervisor may review this milestone at all -
     no for `proposal`, which branches right into the panel's own decision and
     then rejoins the common tail;
  5. otherwise the reviewer approves or requests revision, and both paths
     converge on the end.
"""
import os
from uml_kit import Diagram, INK, GREY

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_milestone.png"

W = 2000
CX = 450          # spine
RX = 1010         # guard refusal -> panel column
APX = 1390        # approve column (right of the panel column, never over it)
BOXC = 360        # spine box width
BOXR = 320        # panel column box width
APPW = 300        # approve column box width
DIAW, DIAH = 240, 116
G = 38

dg = Diagram(W, 3600)

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

# ---- D1: does the upload get accepted? -------------------------------------
y += G
d1_top = y
d1_bot = dg.decision(CX, d1_top, "Accepts\nsubmission?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d1_top)
d1_mid = d1_top + DIAH / 2

# Refuse 422 (right) - and it goes BACK to the upload step, not nowhere.
rt = d1_mid - 44
dg.connect(CX + DIAW / 2, d1_mid, RX - BOXR / 2, d1_mid)
dg.label(CX + DIAW / 2 + 10, d1_mid - 24, "No", anchor="la")
refuse_bot = dg.action(RX, rt, "Refuse 422: the chain has\nnot opened it yet\n(late work is accepted)", w=BOXR)

RETRY_X = RX + BOXR / 2 + 44
dg.route([(RETRY_X, d1_mid),  # from the refusal box's right edge
          (RETRY_X, upload_top + 22),
          (CX + BOXC / 2, upload_top + 22)])
dg.label(RETRY_X + 8, (d1_mid + upload_top) / 2 - 26, "student retries\nwithin the deadline", anchor="la")

# ---- accepted ---------------------------------------------------------------
y = d1_bot + G
t = y; y = dg.action(CX, t, "Status = submitted", w=BOXC)
dg.connect(CX, d1_bot, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Supervisor opens\nsubmission", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

# ---- GUARD: is this the proposal, or an ordinary milestone? -----------------
#
# The earlier version of this figure had it backwards. It asked "may a
# supervisor review it?" and sent the proposal off to a box reading "Refuse 422",
# because the panel owned the proposal. That is no longer the rule: the faculty's
# sequence is that the **supervisor reads the proposal first** and only then does
# the panel sit for the title defence.
#
# So the branch is not a refusal, it is a *different route*. An ordinary
# milestone takes the spine: approve or request revision, and approval unlocks
# the next milestone. The proposal takes the right-hand column, where the
# supervisor endorses and — separately, later, and by other people — the panel
# decides the title.
y += G
g_top = y
g_bot = dg.decision(CX, g_top, "Is this the\nproposal?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, g_top)
g_mid = g_top + DIAH / 2

# Yes (proposal) -> supervisor endorses, then the panel decides the title.
gt = g_mid - 44
dg.connect(CX + DIAW / 2, g_mid, RX - BOXR / 2, g_mid)
dg.label(CX + DIAW / 2 + 10, g_mid - 24, "Yes — proposal", anchor="la", bold=True)
gprev = dg.action(RX, gt, "Supervisor reviews the\nuploaded proposal", w=BOXR)

# Endorse or send back: the two things a supervisor may do here.
gy = gprev + G
g_dec_top = gy
g_dec_bot = dg.decision(RX, g_dec_top, "Endorse it?", w=DIAW - 30, h=DIAH)
dg.connect(RX, gprev, RX, g_dec_top)

# No -> back to the student for a re-upload.
REJ_X = RX + BOXR / 2 + 170
REJ_W = BOXR - 20
rb_top = g_dec_top + DIAH / 2 - 40
rb = dg.action(REJ_X, rb_top, "transitionTo(Rejected)\nrevision_count + 1", w=REJ_W)
dg.connect(RX + (DIAW - 30) / 2, g_dec_top + DIAH / 2, REJ_X - REJ_W / 2, g_dec_top + DIAH / 2)
dg.label(RX + (DIAW - 30) / 2 + 8, g_dec_top + DIAH / 2 - 22, "No", anchor="la")

# Endorsed -> the panel's gate.
gy = g_dec_bot + G
end_box = dg.action(RX, gy, "status = reviewed\n(chain NOT opened)", w=BOXR)
dg.connect(RX, g_dec_bot, RX, gy)

gy = end_box + G
panel_dec_top = gy
panel_dec_bot = dg.decision(RX, gy, "Panel's verdict?", w=DIAW - 30, h=DIAH)
dg.connect(RX, end_box, RX, panel_dec_top)

# ---- Accepted: the title becomes the project's title ----------------------
gy = panel_dec_bot + G
accepted = dg.action(RX, gy, "status = approved\nprojects.title = this title", w=BOXR)
dg.connect(RX, panel_dec_bot, RX, gy)
dg.label(RX + (DIAW - 30) / 2 + 8, panel_dec_top + DIAH / 2 - 24, "accept", anchor="la", bold=True)

for txt in ("Open transaction\nwrite status + timestamp",
            "Record timeline event\nand audit entry",
            "activateNext()\nopen next milestone",
            "Notify students"):
    gy = accepted + G
    accepted = dg.action(RX, gy, txt, w=BOXR)
    dg.connect(RX, gy - G, RX, gy)
panel_end = accepted

# ---- Refused: the title is re-presented (spec 3.2, R4) ---------------------
#
# This is the loop the whole design turns on. A refused title is not a failure:
# the panel's reason is recorded against the form that earned it, a *new*
# Lampiran B opens as a draft pre-filled from it, and a *new* proposal milestone
# is inserted after the refused one. Nothing is overwritten, so the second
# defence can show what changed. There is no cap — the count is visible instead.
REF_X = RX + BOXR / 2 + 190
REF_W = BOXR - 10
ref_top = panel_dec_top + DIAH / 2 - 44
dg.connect(RX + (DIAW - 30) / 2, panel_dec_top + DIAH / 2, REF_X - REF_W / 2, panel_dec_top + DIAH / 2)
dg.label(RX + (DIAW - 30) / 2 + 8, panel_dec_top + DIAH / 2 - 24, "refuse", anchor="la", bold=True)

ref = dg.action(REF_X, ref_top, "Mark the form rejected\nrecord the panel's reason", w=REF_W)
LOOPX2 = REF_X + REF_W / 2 + 44

for txt in ("Open Lampiran B #n+1\nas a draft, pre-filled",
            "Insert a new proposal\nafter the refused one",
            "notify: revise the title,\nsign, re-present"):
    ry = ref + G
    ref = dg.action(REF_X, ry, txt, w=REF_W)
    dg.connect(REF_X, ry - G, REF_X, ry)

# Back to the top: the student revises and the cycle repeats.
dg.route([(REF_X, ref), (REF_X, ref + 30), (LOOPX2, ref + 30), (LOOPX2, g_top - 26),
          (RX + BOXR / 2, g_top - 26), (RX + BOXR / 2, g_top)])
dg.label(LOOPX2 + 10, g_top + 40, "re-presented\nuntil accepted\n(no cap)", anchor="la")

# ---- D2: reviewer decision (only reached on the No side of the guard) ------
y = g_bot + G
d2_top = y
d2_bot = dg.decision(CX, d2_top, "Reviewer\ndecision?", w=DIAW, h=DIAH)
dg.connect(CX, g_bot, CX, d2_top)
dg.label(CX - DIAW / 2 - 10, g_bot + 8, "No — other", anchor="ra", bold=True)
d2_mid = d2_top + DIAH / 2

# ---- Request revision (down the spine) -------------------------------------
#
# Drawn BEFORE the approve column so the approve route can be placed below every
# branch. Ordering matters here only because `a_top` depends on how deep the
# other two columns ran.
y = d2_bot + G
dg.label(CX + DIAW / 2 + 10, d2_mid + 10, "Request revision", anchor="la", bold=True)

t = y; y = dg.action(CX, t, "transitionTo(Rejected)\ncomment required", w=BOXC)
dg.connect(CX, d2_bot, CX, t); prev = y

for txt in ("Revision counter + 1",
            "Record timeline event\nand audit entry",
            "Notify students"):
    y += G
    t = y; y = dg.action(CX, t, txt, w=BOXC)
    dg.connect(CX, prev, CX, t); prev = y
revision_end = prev

# ---- Approve (its own column, to the right of the panel column) ------------
#
# ROUTED UNDER EVERYTHING, not across it.
#
# The reviewer decision sits level with the panel column, and the revision
# branch runs down the spine to the same depth — so any horizontal run from the
# decision to APX crosses one of them. Two attempts to thread it between the
# columns put it straight through a box, which is the fault this pass exists to
# remove: a line through a shape says "this box is on that path".
#
# So the approve path leaves the decision's bottom, drops below both columns,
# runs right along the bottom, and only then climbs into its own column. The
# three tails (approve, panel, revision) are then visually separate all the way
# to the join.
a_top = max(panel_end, revision_end) + G + 40
underx = APX - APPW / 2 - 60

# The vertical drop leaves the decision's bottom and travels *left of the spine*
# before descending, so it never passes through the revision column below the
# decision — which is where "transitionTo(Rejected)" and its tail sit.
DROPGAP = BOXC / 2 + 40

dg.route([(CX - DROPGAP, d2_mid),             # from the decision's left vertex
          (CX - DROPGAP, a_top - 24),         # down, clear of the spine
          (underx, a_top - 24),               # right, below both columns
          (underx, a_top),
          (APX, a_top)])
dg.label(CX + DIAW / 2 + 10, d2_mid - 26, "Approve", anchor="la", bold=True)

aprev = dg.action(APX, a_top, "transitionTo(Approved)\ncheck legal transition", w=APPW)

for txt in ("Open transaction\nwrite status + timestamp",
            "Record timeline event\nand audit entry",
            "activateNext()\nopen next milestone",
            "Notify students"):
    ay = aprev + G
    aprev = dg.action(APX, ay, txt, w=APPW)
    dg.connect(APX, ay - G, APX, ay)
approve_end = aprev

# ---- converge + end --------------------------------------------------------
join_y = max(approve_end, panel_end, revision_end) + 64
dg.seg(APX, approve_end, APX, join_y)
dg.seg(RX, panel_end, RX, join_y)
dg.seg(CX, revision_end, CX, join_y)
dg.seg(CX, join_y, APX, join_y)
mid = (CX + APX) / 2
dg.connect(mid, join_y, mid, join_y + G - 8)
y = dg.end(mid, join_y + G, r=16)
dg.label(mid, y + 8, "end")

# ---- re-upload loop: from the END of the revision branch back to UPLOAD -----
LOOPX = CX - BOXC / 2 - 46
land = upload_top + 22
dg.route([(CX - BOXC / 2, revision_end - 22),
          (LOOPX, revision_end - 22),
          (LOOPX, land),
          (CX - BOXC / 2, land)])
dg.label(LOOPX - 10, (land + revision_end) / 2 - 20, "reopened\nfor re-upload", anchor="ra")

dg.save(OUT, crop_bottom=y + 50)
print("wrote", OUT)
