"""Activity diagram - Manage Pairing (corrected).

Structure: one spine. After the action choice the Assign path descends the
spine through 7 guards; the Remove path is a short right-hand branch. Both
converge on a single end node. Connectors are derived from recorded node
edges so arrows always sit between shapes. No fills, no coloured borders.
"""
import os
from uml_kit import Diagram, INK, GREY

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_pairing.png"

W = 1340
CX = 400        # spine
EXC = 760       # reject column
RM = 1120       # remove branch column
BOXW = 340
EXCW = 250
RMW = 250
DIAW, DIAH = 216, 110
G = 34

dg = Diagram(W, 2200)
rejects = []

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y

y += G
t = y; y = dg.action(CX, t, "Coordinator opens\nPairing screen", w=BOXW)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Select a student", w=BOXW)
dg.connect(CX, prev, CX, t); prev = y

# ---- action choice
y += G
ch_top = y
ch_bot = dg.decision(CX, ch_top, "Which action?", w=DIAW, h=DIAH)
ch_mid = ch_top + DIAH / 2
dg.connect(CX, prev, CX, ch_top)

# ================= REMOVE branch (right) ==================================
seg_x = ch_mid
rm_top = ch_mid - 40
rm_bot = dg.action(RM, rm_top, "Remove pairing\nselect existing", w=RMW)
dg.connect(CX + DIAW / 2, ch_mid, RM - RMW / 2, ch_mid)
dg.label(CX + DIAW / 2 + 10, ch_mid - 24, "Remove", anchor="la", bold=True)

rm_prev = rm_bot
for txt in ("Confirm removal\nreason optional",
            "Deactivate assignment\nis_active = false",
            "Log SupervisorRemoved\nbefore / after kept",
            "Notify student\nsupervision changed",
            "Return 200 OK"):
    rm_y = rm_prev + 44
    rm_prev = dg.action(RM, rm_y, txt, w=RMW)
    dg.connect(RM, rm_y - 44, RM, rm_y)

# ================= ASSIGN branch (spine down) =============================
y = ch_bot + G
dg.label(CX + DIAW / 2 + 10, ch_mid + 30, "Assign", anchor="la", bold=True)

t = y; y = dg.action(CX, t, "Choose supervisor from pool\nand PSM part", w=BOXW)
dg.connect(CX, ch_bot, CX, t)
prev = y

GUARDS = [
    ("Role is\nprimary?", "Reject: no\nco-supervisor role"),
    ("PSM part\nvalid?", "Reject: part not\nrecognised"),
    ("Supervisor\naccepting?", "Reject: not accepting\nnew students"),
    ("Programme\nmatches?", "Reject: programme\nnot served"),
    ("Room in\npart?", "Reject: part capacity\nfull (PSM1/PSM2/BOTH)"),
    ("Student has\nspare slots?", "Reject: maximum\nsupervisors reached"),
    ("Pairing not\nactive yet?", "Reject: already\nassigned for part"),
]

for q, fail in GUARDS:
    y += G
    d_top = y
    d_bot = dg.decision(CX, d_top, q, w=DIAW, h=DIAH)
    dg.connect(CX, prev, CX, d_top)
    mid = d_top + DIAH / 2

    lines = fail.count("\n") + 1
    h = lines * 21 + 20
    rt = mid - h / 2
    rb = dg.action(EXC, rt, fail, w=EXCW)
    dg.connect(CX + DIAW / 2, mid, EXC - EXCW / 2, mid)
    dg.label(CX + DIAW / 2 + 10, mid - 22, "No", anchor="la")
    rejects.append((rt, rb))
    prev = d_bot
    y = d_bot


for txt in ("Open transaction and\ninsert assignment",
            "Log SupervisorAssigned",
            "Warn supervisor if part now full",
            "Notify student\nSupervisorAssigned",
            "Return 201 Created"):
    y += G
    t = y; y = dg.action(CX, t, txt, w=BOXW)
    dg.connect(CX, prev, CX, t)
    prev = y

assign_end = prev

# ================= converge + end =========================================
join_y = max(assign_end, rm_prev) + 56
dg.seg(CX, assign_end, CX, join_y)
dg.seg(RM, rm_prev, RM, join_y)
dg.seg(CX, join_y, RM, join_y)
mid_x = (CX + RM) / 2
dg.connect(mid_x, join_y, mid_x, join_y + G - 6)
end_top = join_y + G
y = dg.end(mid_x, end_top, r=16)
dg.label(mid_x, y + 8, "end")

# ================= reject return channel ==================================
# All guards refuse the request and nothing is written, so they terminate in
# one common note rather than looping back into the flow.
CHAN = EXC + EXCW / 2 + 30
r_top = (rejects[0][0] + rejects[0][1]) / 2
r_bot = (rejects[-1][0] + rejects[-1][1]) / 2
for (a, b) in rejects:
    m = (a + b) / 2
    dg.seg(EXC + EXCW / 2, m, CHAN, m)
dg.seg(CHAN, r_top, CHAN, r_bot)
dg.label(CHAN + 12, (r_top + r_bot) / 2 - 20,
         "each guard refuses the request\nwith 422 and the specific\nreason; no pairing is created",
         anchor="la")

dg.save(OUT, crop_bottom=y + 50)
print("wrote", OUT)
