"""Activity diagram - Manage Alert and Notification.

Nine shapes in two columns, because the use case has two independent halves and
drawing them as one flow was the fault the user rejected first time round:

    LEFT  the scheduled reminder: find what is due, send what has not gone yet,
          record it, move to the next wave.
    RIGHT the reader: open the centre, read or dismiss, or change which types
          they receive.

Each half has its own start and end, in its own frame, because they are driven
by different people at different times and neither leads to the other.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows
landing on edges, nothing crossing a box.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from actkit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_notification.png"

W = 1900
LX = 520
RX = 1400
BOXL = 480
BOXR = 470
DIAW, DIAH = 320, 140
G = 46

dg = Diagram(W, 1500)


def column(cx, w):
    y = 90
    y = dg.start(cx, y)
    dg.label(cx - 34, y - 6, "start", anchor="ra")
    return y


# ---- left: the scheduled reminder -----------------------------------------
ly = column(LX, BOXL)


def lstep(prev, text):
    top = prev + G
    bottom = dg.action(LX, top, text, w=BOXL)
    dg.connect(LX, prev, LX, top)
    return bottom


def ldecide(prev, text, w=DIAW):
    top = prev + G
    bottom = dg.decision(LX, top, text, w=w, h=DIAH)
    dg.connect(LX, prev, LX, top)
    return top, bottom, top + DIAH / 2


ly = lstep(ly, "The scheduled command runs")
ly = lstep(ly, "Finds the milestones due\nin 7, 3 or 1 days")

_, l1_bot, l1 = ldecide(ly, "Anything\ndue?")
dg.label(LX + DIAW / 2 + 12, l1 - 26, "No", anchor="la", bold=True)

ly = lstep(l1_bot, "Checks each user: active,\nopted in, not already sent")

_, l2_bot, l2 = ldecide(ly, "Should they\nreceive it?")
dg.label(LX + DIAW / 2 + 12, l2 - 26, "No", anchor="la", bold=True)

ly = lstep(l2_bot, "Sends the in-app record\nand the mail")
ly = lstep(ly, "Records the dispatch in the ledger\n(so a retry cannot repeat it)")

# Both No branches and the send path arrive here: move to the next wave.
next_top = ly + G
lnext_bot = dg.action(LX, next_top, "Next wave, or finish", w=BOXL)
dg.connect(LX, ly, LX, next_top)

# 'No' on the first decision and 'No' on the second both skip to "Next wave".
dg.route([(LX + DIAW / 2, l1), (LX + BOXL / 2 + 46, l1),
          (LX + BOXL / 2 + 46, next_top + 40), (LX + BOXL / 2, next_top + 40)])
dg.route([(LX + DIAW / 2, l2), (LX + BOXL / 2 + 80, l2),
          (LX + BOXL / 2 + 80, next_top + 40), (LX + BOXL / 2, next_top + 40)])

lend = lnext_bot + G
dg.connect(LX, lnext_bot, LX, lend)
ly = dg.end(LX, lend, r=18)
dg.label(LX, ly + 10, "end")

# ---- right: the reader -----------------------------------------------------
ry = column(RX, BOXR)


def rstep(prev, text):
    top = prev + G
    bottom = dg.action(RX, top, text, w=BOXR)
    dg.connect(RX, prev, RX, top)
    return bottom


ry = rstep(ry, "User opens the\nnotification centre")

_, r1_bot, r1 = (lambda t: (t, dg.decision(RX, t, "What do they\ndo?", w=DIAW, h=DIAH), t + DIAH / 2))(ry + G)
dg.connect(RX, ry, RX, ry + G)

# Two things the centre can do, one after the other on the same column:
# read or dismiss a notification, and change which types arrive at all.
read_end = rstep(r1_bot, "Opens, marks read\nor dismisses it")
read_end = rstep(read_end, "Unread badge refreshed")
dg.label(RX - DIAW / 2 - 12, r1 + 12, "read / dismiss", anchor="ra")

pref = dg.action(RX, read_end + 70, "Turns a notification\ntype on or off", w=BOXR)
dg.connect(RX, read_end, RX, read_end + 70)
dg.label(RX + BOXR / 2 + 12, read_end + 110, "or change preferences", anchor="la")

pref_end = dg.action(RX, pref + G, "Preferences saved", w=BOXR)
dg.connect(RX, pref, RX, pref + G)

y_end = pref_end + G + 14
dg.connect(RX, pref_end, RX, y_end)
ry = dg.end(RX, y_end, r=18)
dg.label(RX, ry + 10, "end")

bottom = max(ly, ry) + 64

# Frame each half, so nobody reads the two columns as one flow.
dg.frame(60, 40, LX + BOXL / 2 + 130, bottom - 30, "Scheduled reminder")
dg.frame(LX + BOXL / 2 + 200, 40, W - 50, bottom - 30, "Notification centre")

dg.save(OUT, crop_bottom=bottom)
print("wrote", OUT)
