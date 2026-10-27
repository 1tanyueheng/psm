"""Activity diagram - Login and Authentication.

Nine shapes. The old version had a six-diamond ladder, one per way a login can
fail; a reader does not need to see each refusal, only that there is one decision
and what happens on either side of it.

    start -> open the form -> enter credentials -> submit
          -> credentials valid? --no--> show the error -> back to the form
                                --yes-> create the session
          -> redirect to the role dashboard -> end

The specific refusals - unknown account, wrong password, locked account, inactive
account - stay written in one sentence on the No edge; Table 4.1 lists them
individually.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_login.png"

CX = 640
W = 2000
BOXC = 520
DIAW, DIAH = 340, 150
G = 50

dg = Diagram(W, 1400)

y = 30
y = dg.start(CX, y + 8)
dg.label(CX - 34, y - 6, "start", anchor="ra")
prev = y

prev = (lambda t: (dg.connect(CX, prev, CX, t), dg.action(CX, t, "Open the login page", w=BOXC))[1])(prev + G)
prev = (lambda t: (dg.connect(CX, prev, CX, t), dg.action(CX, t, "Enter email and password", w=BOXC))[1])(prev + G)
prev = (lambda t: (dg.connect(CX, prev, CX, t), dg.action(CX, t, "Submit the form", w=BOXC))[1])(prev + G)

d_top = prev + G
d_bot = dg.decision(CX, d_top, "Credentials\nvalid?", w=DIAW, h=DIAH)
dg.connect(CX, prev, CX, d_top)
d_mid = d_top + DIAH / 2

# No -> the error, then back to the form.
RX = CX + BOXC / 2 + 60 + 230
err = dg.action(RX, d_mid - 44, "Show the error:\nunknown account, wrong\npassword, locked, or inactive", w=460)
dg.connect(CX + DIAW / 2, d_mid, RX - 230, d_mid)
dg.label(CX + DIAW / 2 + 12, d_mid - 28, "No", anchor="la", bold=True)

back_x = RX + 230 + 50
dg.route([(RX, err), (RX, err + 44), (back_x, err + 44),
          (back_x, d_top - 150), (CX + BOXC / 2, d_top - 150)])
dg.label(back_x + 12, err + 54, "correct the input", anchor="la")
dg.label(CX - DIAW / 2 - 12, d_mid + 12, "Yes", anchor="ra", bold=True)

y = d_bot + G
y = dg.action(CX, y, "Create the session token", w=BOXC)
dg.connect(CX, d_bot, CX, y - G)

y2 = y + G
y2 = dg.action(CX, y2, "Redirect to the role dashboard", w=BOXC)
dg.connect(CX, y, CX, y2 - G)

end_top = y2 + G
dg.connect(CX, y2, CX, end_top)
y = dg.end(CX, end_top, r=18)
dg.label(CX, y + 10, "end")

dg.save(OUT, crop_bottom=y + 64)
print("wrote", OUT)
