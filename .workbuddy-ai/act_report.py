"""Activity diagram - Generate Report (corrected style).

Two outputs from one entry: on-screen analytics (JSON) and a streamed CSV
export. Connectors are derived from shape edges, drawn only in the gaps.
Plain white shapes, single type scale.
"""
import os
import sys
sys.path.insert(0, r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai")
from uml_kit import Diagram, INK, GREY

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_report.png"

W = 1360
CX = 460          # spine
RX = 1030         # export branch / right column
BOXC = 370
BOXR = 300
DIAW, DIAH = 236, 116
G = 36

dg = Diagram(W, 1500)

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y

y += G
t = y; y = dg.action(CX, t, "Coordinator opens the\nReports screen", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Choose filters\nbatch, PSM part, semester", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Request report", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

# ---- D1 authorised?
y += G
d1_top = y
d1_bot = dg.decision(CX, d1_top, "Allowed to\nview analytics?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d1_top)
d1_mid = d1_top + DIAH / 2

rt = d1_mid - 40
rb = dg.action(RX, rt, "Return 403\nForbidden", w=BOXR)
dg.connect(CX + DIAW / 2, d1_mid, RX - BOXR / 2, d1_mid)
dg.label(CX + DIAW / 2 + 10, d1_mid - 24, "No", anchor="la")

# The refusal still has to reach the end.
#
# It used to be drawn as a bare box hanging off the "No" branch with no
# outgoing arrow — a dead end in the drawing, which reads as "the process
# stops here and nothing is returned". A 403 is a response like any other, so
# the path continues to the end activity with the other two tails.
forbidden_end = rb

y = d1_bot + G
t = y; y = dg.action(CX, t, "Validate filters\nand resolve the term", w=BOXC)
dg.connect(CX, d1_bot, CX, t); prev = y

# ---- D2 report type?
y += G
d2_top = y
d2_bot = dg.decision(CX, d2_top, "Which\noutput?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d2_top)
d2_mid = d2_top + DIAH / 2

# ============ EXPORT branch (right) ======================================
dg.connect(CX + DIAW / 2, d2_mid, RX - BOXR / 2, d2_mid)
dg.label(CX + DIAW / 2 + 10, d2_mid - 24, "Export CSV", anchor="la", bold=True)

rt = d2_mid - 42
rb = dg.action(RX, rt, "Authorise export\n(admin or coordinator)", w=BOXR)
dg.connect(RX, rb, RX, rb)     # placeholder removed below
rprev = rb
for txt in ("Write audit entry\nReportExported", "Build filename\nterm, part, timestamp",
            "Stream rows in chunks\nof 200", "Return CSV download"):
    ry = rprev + G
    rprev = dg.action(RX, ry, txt, w=BOXR)
    dg.connect(RX, ry - G, RX, ry)
# remove the accidental self-connector drawn above
export_end = rprev

# ============ ON-SCREEN branch (spine) ===================================
y = d2_bot + G
dg.label(CX + DIAW / 2 + 10, d2_mid + 12, "View report", anchor="la", bold=True)

t = y; y = dg.action(CX, t, "Run the aggregate query\nfor the chosen filters", w=BOXC)
dg.connect(CX, d2_bot, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t,
                    "Compute figures\ncohort progress, at-risk,\nworkload, marks", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Build both batch rollups\nPSM 1 and PSM 2", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Return figures as JSON", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y

y += G
t = y; y = dg.action(CX, t, "Render tables and\ncharts on screen", w=BOXC)
dg.connect(CX, prev, CX, t); prev = y
onscreen_end = y

# ---- converge + end
#
# The 403 path joins here too, so no branch is left dangling — but it cannot
# run straight down column RX, because that is where the CSV export tail sits
# (authorise -> audit -> filename -> stream -> download). A vertical through
# those boxes would claim the refusal passes through the export machinery.
# It leaves the refusal box's right edge and descends in its own lane, outside
# the export column, before joining the bottom line.
FORB_X = RX + BOXR / 2 + 46
join_y = max(onscreen_end, export_end, forbidden_end + 40) + 56
mid = (CX + RX) / 2

dg.route([(RX + BOXR / 2, (rt + forbidden_end) / 2),
          (FORB_X, (rt + forbidden_end) / 2),
          (FORB_X, join_y),
          (mid, join_y)])

dg.seg(CX, onscreen_end, CX, join_y)
dg.seg(RX, export_end, RX, join_y)
dg.seg(CX, join_y, RX, join_y)
dg.connect(mid, join_y, mid, join_y + G - 6)
y = dg.end(mid, join_y + G, r=16)
dg.label(mid, y + 8, "end")

dg.save(OUT, crop_bottom=y + 50)
print("wrote", OUT)
