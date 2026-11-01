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

# `ldecide` returns the diamond's geometry but does not reassign `ly`, so this
# step has to take `l1_bot`. Passing `ly` drew the box on top of the diamond.
ly = lstep(l1_bot, "Checks each user: active,\nnot already sent")

_, l2_bot, l2 = ldecide(ly, "Should they\nreceive it?")
dg.label(LX + DIAW / 2 + 12, l2 - 26, "No", anchor="la", bold=True)

ly = lstep(l2_bot, "Sends the in-app record\nand the mail")
ly = lstep(ly, "Records the dispatch in the ledger\n(so a retry cannot repeat it)")

# Both No branches and the send path arrive here: move to the next wave.
next_top = ly + G
lnext_bot = dg.action(LX, next_top, "Next wave, or finish", w=BOXL)
dg.connect(LX, ly, LX, next_top)

# 'No' on either decision skips to "Next wave".
#
# Both routes leave from the diamond's right vertex and travel down a channel
# clear of every shape. The earlier numbers (BOXL/2 + 46 and + 80) were computed
# when a taller right-hand column pushed the frame wider; with that column gone
# the two runs cut through "Checks each user" and the second diamond, which the
# render showed plainly. They now sit outside the widest box on the spine.
SIDE_A = LX + BOXL / 2 + 40
SIDE_B = LX + BOXL / 2 + 74

dg.route([(LX + DIAW / 2, l1), (SIDE_A, l1),
          (SIDE_A, next_top + 40), (LX + BOXL / 2, next_top + 40)])
dg.route([(LX + DIAW / 2, l2), (SIDE_B, l2),
          (SIDE_B, next_top + 40), (LX + BOXL / 2, next_top + 40)])

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

# One thing the centre can do: read or dismiss a notification.
#
# A second branch here used to model switching a notification type on or off,
# and "Preferences saved". That flow was removed from the system on 2026-11-01
# — nothing in the catalogue can be switched off, because every notification
# reports a decision about the student's own project. The figure showed a
# capability the system no longer has, and the sequence diagram beside it had
# already lost the same branch, so the two disagreed.
read_end = rstep(r1_bot, "Opens, marks read\nor dismisses it")
read_end = rstep(read_end, "Unread badge refreshed")
dg.label(RX - DIAW / 2 - 12, r1 + 12, "read / dismiss", anchor="ra")

y_end = read_end + G + 14
dg.connect(RX, read_end, RX, y_end)
ry = dg.end(RX, y_end, r=18)
dg.label(RX, ry + 10, "end")

bottom = max(ly, ry) + 64

# Frame each half, so nobody reads the two columns as one flow.
dg.frame(60, 40, LX + BOXL / 2 + 130, bottom - 30, "Scheduled reminder")
dg.frame(LX + BOXL / 2 + 200, 40, W - 50, bottom - 30, "Notification centre")

dg.save(OUT, crop_bottom=bottom)
print("wrote", OUT)
