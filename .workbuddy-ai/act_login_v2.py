"""Activity diagram - Login and Authentication (corrected).

Nodes are laid out in sequence; connectors are derived from the recorded
top/bottom of each node so an arrow can only ever run from one shape edge to
the next shape edge. No fills, no coloured borders.
"""
import os
from uml_kit import Diagram, INK, GREY

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_login.png"

W, CX, EXC = 1200, 400, 790
BOXW, EXCW = 330, 258
DIAW, DIAH = 216, 110
G = 36

dg = Diagram(W, 1700)

# ------------------------------------------------------------------ layout
nodes = []          # (kind, top, bottom)
y = 30

# start
s_top = y + 6
y = dg.start(CX, s_top, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
nodes.append(("start", s_top, y))

y += G
t = y; y = dg.action(CX, t, "Open Login page", w=BOXW); nodes.append(("act", t, y))
login_page = (t, y)

y += G
t = y; y = dg.action(CX, t, "Enter email and password", w=BOXW); nodes.append(("act", t, y))
enter = (t, y)

y += G
t = y; y = dg.action(CX, t, "Click Login", w=BOXW); nodes.append(("act", t, y))

y += G
t = y; y = dg.action(CX, t, "Validate input\nrequired, email format, lowercase", w=BOXW)
nodes.append(("act", t, y))

# ---- D1 Valid?
y += G
d1_top = y
d1_bot = dg.decision(CX, d1_top, "Valid?", w=DIAW, h=DIAH)
nodes.append(("dec", d1_top, d1_bot))
d1 = (d1_top, d1_bot)

y = d1_bot + G
t = y; y = dg.action(CX, t, "Find user by email", w=BOXW); nodes.append(("act", t, y))

y += G
d2_top = y
d2_bot = dg.decision(CX, d2_top, "User found?", w=DIAW, h=DIAH)
nodes.append(("dec", d2_top, d2_bot))

y = d2_bot + G
d3_top = y
d3_bot = dg.decision(CX, d3_top, "Account\nlocked?", w=DIAW, h=DIAH)
nodes.append(("dec", d3_top, d3_bot))

y = d3_bot + G
d4_top = y
d4_bot = dg.decision(CX, d4_top, "Password\ncorrect?", w=DIAW, h=DIAH)
nodes.append(("dec", d4_top, d4_bot))

y = d4_bot + G
d5_top = y
d5_bot = dg.decision(CX, d5_top, "Account\nactive?", w=DIAW, h=DIAH)
nodes.append(("dec", d5_top, d5_bot))

y = d5_bot + G
t = y; y = dg.action(CX, t, "Create Sanctum token\nreset attempt counter", w=BOXW)
nodes.append(("act", t, y))

y += G
t = y; y = dg.action(CX, t, "Log login success\nactor and IP", w=BOXW); nodes.append(("act", t, y))

y += G
t = y; y = dg.action(CX, t, "Return 200 OK\ntoken, user, home_route", w=BOXW)
nodes.append(("act", t, y))

y += G
t = y; y = dg.action(CX, t, "Redirect to role dashboard", w=BOXW); nodes.append(("act", t, y))

y += G
e_top = y
y = dg.end(CX, e_top, r=16)
nodes.append(("end", e_top, y))
dg.label(CX, y + 8, "end")

# ------------------------------------------------------------------ spine arrows
for i in range(len(nodes) - 1):
    b = nodes[i][2]
    nxt = nodes[i + 1][1]
    dg.connect(CX, b, CX, nxt)

# ------------------------------------------------------------------ rejects
def reject(dec_top, dec_bot, text, side="right", label="No"):
    mid = dec_top + (dec_bot - dec_top) / 2
    lines = text.count("\n") + 1
    h = lines * 21 + 20
    top = mid - h / 2
    bot = dg.action(EXC, top, text, w=EXCW)
    dg.connect(CX + DIAW / 2, mid, EXC - EXCW / 2, mid)
    dg.label(CX + DIAW / 2 + 12, mid - 22, label, anchor="la")
    return top, bot


r1 = reject(d1[0], d1[1], "Show input\nerror message")
r2 = reject(d2_top, d2_bot, "Log failed attempt\nReturn 422\nauthentication failed")
r3 = reject(d3_top, d3_bot, "Return 422\naccount temporarily locked", label="Yes")
r4 = reject(d4_top, d4_bot, "Increment failed attempts\nLog failure, return 422\n5 tries = lock 15 min, doubling")
r5 = reject(d5_top, d5_bot, "Return 422\nsuspended / inactive / unverified")

# ------------------------------------------------------------------ failure return
# Every reject path returns the user to the login form. The channel runs down
# the right of the reject boxes, then up the FAR right of the canvas (clear of
# the start node) and left into the TOP edge of "Enter email and password".
CHAN = EXC + EXCW / 2 + 34              # vertical channel beside the reject boxes
FAR = W - 46                            # outer vertical channel
LAND = enter[0]                         # y of the "Enter email" top edge

for (top, bot) in (r1, r2, r3, r4, r5):
    mid = (top + bot) / 2
    dg.seg(EXC + EXCW / 2, mid, CHAN, mid)

top_mid = (r1[0] + r1[1]) / 2
bot_mid = (r5[0] + r5[1]) / 2
dg.seg(CHAN, top_mid, CHAN, bot_mid)
dg.seg(CHAN, top_mid, FAR, top_mid)

# up the outside, then left into the entry step's top edge
dg.seg(FAR, top_mid, FAR, LAND - 26)
dg.seg(FAR, LAND - 26, CX, LAND - 26)
dg.connect(CX, LAND - 26, CX, LAND)

dg.label(FAR - 12, (top_mid + LAND) / 2,
         "every rejection returns\nthe user to the login form", anchor="ra")

dg.save(OUT, crop_bottom=y + 46)
print("wrote", OUT)
