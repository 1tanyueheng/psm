"""Activity diagram - Manage Alert and Notification.

Two independent flows, shown side by side on one canvas:

  LEFT  (system-driven, scheduled): the deadline reminder command runs for each
        wave (7/3/1 days), finds milestones due, filters recipients, and sends
        through the dispatcher with de-duplication via the reminder ledger.

  RIGHT (user-driven): the notification centre — list, mark read, preferences.

Connectors run only in the gaps between shapes. Plain white shapes, one scale.
"""
import os
import sys
sys.path.insert(0, r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai")
from uml_kit import Diagram, INK, GREY, F_BOX_B

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_notification.png"

W = 1760
LX = 420          # left (scheduler) column
RX = 1360         # right (user) column
BOXL = 340
BOXR = 340
DIAW, DIAH = 250, 120
G = 34

dg = Diagram(W, 1700)

# ============================================================ LEFT: scheduler
y = 30
y = dg.start(LX, y + 8, r=14)
dg.label(LX - 26, y - 4, "start", anchor="ra")
prev = y

y += G
t = y; y = dg.action(LX, t, "Scheduled command runs\npsm:send-deadline-reminders", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y

y += G
t = y; y = dg.action(LX, t, "For each reminder wave\n7, 3 and 1 days before", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y

y += G
t = y; y = dg.action(LX, t, "Find milestones due\nin that many days", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y

# D1 any due?
y += G
d1_top = y
d1_bot = dg.decision(LX, d1_top, "Any milestone\ndue?", w=DIAW, h=DIAH)
dg.connect(LX, prev, LX, d1_top)
d1_mid = d1_top + DIAH / 2
# no -> next wave (loop back to the wave action)
wave_bot = None
dg.label(LX + DIAW / 2 + 10, d1_mid - 22, "No", anchor="la")

y = d1_bot + G
t = y; y = dg.action(LX, t, "Collect students\non the project", w=BOXL)
dg.connect(LX, d1_bot, LX, t); prev = y

# D2 digest mode?
y += G
d2_top = y
d2_bot = dg.decision(LX, d2_top, "Digest mode?", w=DIAW, h=DIAH)
dg.connect(LX, prev, LX, d2_top)
d2_mid = d2_top + DIAH / 2

y = d2_bot + G
t = y; y = dg.action(LX, t, "Keep only digest-only\nusers (if digest)", w=BOXL)
dg.connect(LX, d2_bot, LX, t); prev = y

y += G
t = y; y = dg.action(LX, t, "Build payload\ntitle, body, link, urgent", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y

# D3 already sent?
y += G
d3_top = y
d3_bot = dg.decision(LX, d3_top, "Already sent\nthis wave?", w=DIAW, h=DIAH)
dg.connect(LX, prev, LX, d3_top)
d3_mid = d3_top + DIAH / 2
dg.label(LX + DIAW / 2 + 10, d3_mid - 22, "Yes", anchor="la")

y = d3_bot + G
t = y; y = dg.action(LX, t, "Check user active and\nnot opted out", w=BOXL)
dg.connect(LX, d3_bot, LX, t); prev = y

# D4 allowed?
y += G
d4_top = y
d4_bot = dg.decision(LX, d4_top, "Should\nreceive?", w=DIAW, h=DIAH)
dg.connect(LX, prev, LX, d4_top)
d4_mid = d4_top + DIAH / 2
dg.label(LX + DIAW / 2 + 10, d4_mid - 22, "No", anchor="la")
# No -> skip this recipient and rejoin after the send/ledger pair
SKIP = LX + BOXL / 2 + 150
dg.seg(LX + DIAW / 2, d4_mid, SKIP, d4_mid)

y = d4_bot + G
t = y; y = dg.action(LX, t, "Send mail + in-app\nthrough dispatcher", w=BOXL)
dg.connect(LX, d4_bot, LX, t); prev = y

y += G
t = y; y = dg.action(LX, t, "Record in reminder ledger\nde-duplicates retries", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y

y += G
t = y; y = dg.action(LX, t, "Next wave or finish", w=BOXL)
dg.connect(LX, prev, LX, t); prev = y
left_end = y

y += G
end_l = y
y = dg.end(LX, end_l, r=16)
dg.connect(LX, prev, LX, end_l)
dg.label(LX, y + 8, "end")

# ============================================================ RIGHT: centre
ry = 30
ry = dg.start(RX, ry + 8, r=14)
dg.label(RX - 26, ry - 4, "start", anchor="ra")
rprev = ry

ry += G
t = ry; ry = dg.action(RX, t, "User opens the\nnotification centre", w=BOXR)
dg.connect(RX, rprev, RX, t); rprev = ry

ry += G
t = ry; ry = dg.action(RX, t, "System returns unread count\nand latest five", w=BOXR)
dg.connect(RX, rprev, RX, t); rprev = ry

# D5 what action?
ry += G
d5_top = ry
d5_bot = dg.decision(RX, d5_top, "User\naction?", w=DIAW, h=DIAH)
dg.connect(RX, rprev, RX, d5_top)
d5_mid = d5_top + DIAH / 2

# The action menu has two alternatives. Shown as two stacked branches:
#   branch A (down the spine)  = read / dismiss
#   branch B (to the left)     = preferences
LB = RX - BOXR / 2 - 210
ry = d5_bot + G
t = ry; ry = dg.action(RX, t, "Open notification, mark\nread, or dismiss", w=BOXR)
dg.connect(RX, d5_bot, RX, t); rprev = ry
dg.label(RX + DIAW / 2 + 10, d5_mid - 22, "Read / dismiss", anchor="la", bold=True)

ry += G
t = ry; ry = dg.action(RX, t, "Update read_at\nrefresh unread badge", w=BOXR)
dg.connect(RX, rprev, RX, t); rprev = ry
read_end = ry

# branch B: preferences, hung off the SAME decision, out to the left and down
dg.connect(RX - DIAW / 2, d5_mid, LB + 125, d5_mid)
dg.label(RX - DIAW / 2 - 12, d5_mid - 24, "Preferences", anchor="ra", bold=True)

pb = dg.action(LB, d5_mid - 42, "Change preferences", w=250)
for txt in ("Toggle a type on or off\nurgent types are ignored", "Save preferences"):
    py = pb + G
    pb = dg.action(LB, py, txt, w=250)
    dg.connect(LB, py - G, LB, py)

# converge both branches on one end node under the centre column
conv = max(read_end, pb) + G
dg.seg(RX, read_end, RX, conv)
dg.seg(LB, pb, LB, conv)
dg.seg(LB, conv, RX, conv)
mid_r = RX
dg.connect(mid_r, conv, mid_r, conv + G - 6)
end_r = conv + G
ry = dg.end(mid_r, end_r, r=16)
dg.label(mid_r, ry + 8, "end")
# ============================================================ column titles
dg.d.text((dg.L(LX), dg.L(14)), "Scheduled reminders", font=F_BOX_B, fill=INK, anchor="ma")
dg.d.text((dg.L(RX), dg.L(14)), "Notification centre", font=F_BOX_B, fill=INK, anchor="ma")
dg.seg(LX - BOXL / 2, 30, LX + BOXL / 2, 30, colour="#CCCCCC", width=1)
dg.seg(RX - BOXR / 2, 30, RX + BOXR / 2, 30, colour="#CCCCCC", width=1)

# ============================================================ "no" returns
# D1 No -> finish this wave (down the left outside the column)
far = LX - BOXL / 2 - 40
dg.seg(LX - DIAW / 2, d1_mid, far, d1_mid)
dg.seg(far, d1_mid, far, left_end - 20)
dg.connect(far, left_end - 20, LX - BOXL / 2, left_end - 20)
# "should receive = No" skips the send and rejoins the same box from the right
dg.seg(SKIP, d4_mid, SKIP, left_end - 20)
dg.connect(SKIP, left_end - 20, LX + BOXL / 2, left_end - 20)
dg.label(far - 10, (d1_mid + left_end) / 2, "nothing due:\nskip to next wave",
         anchor="ra")

# D3 Yes (already sent) -> skip this recipient, join after send
dg.seg(LX + DIAW / 2, d3_mid, LX + BOXL / 2 + 36, d3_mid)
dg.seg(LX + BOXL / 2 + 36, d3_mid, LX + BOXL / 2 + 36, left_end - 20)
dg.connect(LX + BOXL / 2 + 36, left_end - 20, LX + BOXL / 2, left_end - 20)
dg.label(LX + BOXL / 2 + 46, (d3_mid + left_end) / 2, "already sent:\nskip", anchor="la")

maxb = max(left_end, end_r)
# --- crop the two columns into separate, legible figures ---------------
full_w = dg.W
full_h = maxb + 96
dg.save(OUT, crop_bottom=full_h)

from PIL import Image as _I
src = _I.open(OUT)
sx = src.width / full_w            # saved image is downscaled from SS

def crop(px0, px1):
    box = (int(px0 * sx), 0, int(px1 * sx), src.height)
    return src.crop(box)

# left figure: everything from the canvas edge to just before the centre column
left_edge  = 0
left_right = (SKIP + 90) / 1.0
l_img = crop(left_edge, left_right)
import PIL.ImageChops as _IC2
bb = _IC2.difference(l_img, _I.new("RGB", l_img.size, "white")).getbbox()
if bb:
    l_img = l_img.crop((0, 0, l_img.width, min(l_img.height, bb[3] + 40)))
l_img.save(OUT.replace("activity_notification.png", "activity_notification_reminders.png"))

# right figure: from just left of the preferences branch to the canvas edge
right_left = LB - 250 / 2 - 40
r_img = crop(right_left, full_w)

# trim the empty space below the end node of the right column
import PIL.ImageChops as _IC
bg = _I.new("RGB", r_img.size, "white")
bbox = _IC.difference(r_img, bg).getbbox()
if bbox:
    r_img = r_img.crop((0, 0, r_img.width, min(r_img.height, bbox[3] + 40)))
r_img.save(OUT.replace("activity_notification.png", "activity_notification_centre.png"))
print("split:", l_img.size, r_img.size)