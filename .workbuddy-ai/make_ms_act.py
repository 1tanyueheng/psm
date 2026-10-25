"""Activity diagram for "Manage Milestone".

Covers the full milestone lifecycle from MilestoneStatus:
  pending -> open -> submitted -> (review) -> approved / rejected / conditional
with the chain rule: approving one milestone opens the next.
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
SCALE = 2

NAVY = "#1F3864"
BLUE = "#2F5597"
FILL = "#EDF2FA"
GRN_F = "#EAF1DF"
GRN_L = "#548235"
RED_F = "#FDECEA"
RED_L = "#C00000"
AMB_F = "#FFF4E5"
AMB_L = "#BF8F00"
GREY = "#595959"

W, H = 1560, 2050
CX = 480
RX = 1120


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibrib.ttf" if bold else r"C:\Windows\Fonts\calibri.ttf",
              r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(c):
            return ImageFont.truetype(c, size * SCALE)
    return ImageFont.load_default()


f_s = font(14)
f_xs = font(13)

img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
d = ImageDraw.Draw(img)


def L(v):
    return int(round(v * SCALE))


def start_node(cx, cy, r=15):
    d.ellipse([L(cx - r), L(cy - r), L(cx + r), L(cy + r)], fill=NAVY)
    return cy + r


def end_node(cx, cy, r=17):
    d.ellipse([L(cx - r), L(cy - r), L(cx + r), L(cy + r)], fill="white",
              outline=NAVY, width=L(2.5))
    d.ellipse([L(cx - r + 5), L(cy - r + 5), L(cx + r - 5), L(cy + r - 5)], fill=NAVY)
    return cy + r


def action(cx, top, text, w=360, fill=FILL, outline=BLUE, fs=f_s, lh=20, pad=11):
    lines = text.split("\n")
    h = len(lines) * lh + pad * 2
    d.rounded_rectangle([L(cx - w / 2), L(top), L(cx + w / 2), L(top + h)],
                        radius=L(10), fill=fill, outline=outline, width=L(2))
    ty = top + pad
    for ln in lines:
        d.text((L(cx), L(ty)), ln, font=fs, fill=NAVY, anchor="ma")
        ty += lh
    return top + h


def decision(cx, top, text, w=250, h=112):
    d.polygon([(L(cx), L(top)), (L(cx + w / 2), L(top + h / 2)),
               (L(cx), L(top + h)), (L(cx - w / 2), L(top + h / 2))],
              fill="white", outline=BLUE, width=L(2))
    lines = text.split("\n")
    ty = top + h / 2 - (len(lines) - 1) * 10 - 7
    for ln in lines:
        d.text((L(cx), L(ty)), ln, font=f_s, fill=NAVY, anchor="ma")
        ty += 20
    return top + h


def seg(x1, y1, x2, y2, colour=BLUE, width=2):
    d.line([L(x1), L(y1), L(x2), L(y2)], fill=colour, width=L(width))


def head(x1, y1, x2, y2, colour=BLUE, s=11):
    ang = math.atan2(y2 - y1, x2 - x1)
    p1 = (x2 - s * math.cos(ang - 0.42), y2 - s * math.sin(ang - 0.42))
    p2 = (x2 - s * math.cos(ang + 0.42), y2 - s * math.sin(ang + 0.42))
    d.polygon([(L(x2), L(y2)), (L(p1[0]), L(p1[1])), (L(p2[0]), L(p2[1]))], fill=colour)


def link(y_from, y_to, cx=CX, colour=BLUE):
    seg(cx, y_from, cx, y_to, colour, 2)
    head(cx, y_from, cx, y_to, colour)


def elbow(x1, y1, x2, y2, colour=BLUE, my=None):
    myy = my if my is not None else (y1 + y2) / 2
    seg(x1, y1, x1, myy, colour, 2)
    seg(x1, myy, x2, myy, colour, 2)
    seg(x2, myy, x2, y2, colour, 2)
    head(x2, myy, x2, y2, colour)


def label(x, y, text, colour=GREY, anchor="ma", f=None):
    d.text((L(x), L(y)), text, font=f or f_xs, fill=colour, anchor=anchor)


GAP = 30

# ---------------------------------------------------------------- start
y = 24
y = start_node(CX, y + 16)
label(CX, y + 6, "start", GREY)
prev = y

y += GAP
y = action(CX, y, "Coordinator registers project\n-> milestone chain generated\n(sequence 1..n, status = pending)")
link(prev, y)
prev = y

y += GAP
y = action(CX, y, "First milestone opened\n(status = open, opens_at stamped)")
link(prev, y)
prev = y

# ---------------------------------------------------------------- student submits
y += GAP
y_upload = y
y = action(CX, y, "Student opens milestone\nand uploads file(s)")
link(prev, y)
prev = y

y += GAP
d1_top = y
d1_bot = decision(CX, d1_top, "Accepts\nsubmission?")
link(prev, d1_top)
cy1 = d1_top + 56
seg(CX + 125, cy1, RX - 130, cy1, RED_L, 2)
head(CX + 125, cy1, RX - 130, cy1, RED_L)
label(CX + 134, cy1 - 20, "No", RED_L, anchor="la")
e_no_submit = action(RX, cy1 - 32,
                     "Refuse (422): deadline passed\nwithout late window, or chain\nhas not opened it yet",
                     w=290, fill=RED_F, outline=RED_L, fs=f_xs)
prev = d1_bot

# ---------------------------------------------------------------- review decision
y = d1_bot + GAP
y = action(CX, y, "Status = submitted\n(student notified)")
link(prev, y)
prev = y

y += GAP
y = action(CX, y, "Supervisor opens the submission")
link(prev, y)
prev = y

y += GAP
d2_top = y
d2_bot = decision(CX, d2_top, "Reviewer\ndecision?")
link(prev, d2_top)

# ---- APPROVE branch (right, green)
cy2 = d2_top + 56
seg(CX + 125, cy2, RX - 145, cy2, GRN_L, 2)
head(CX + 125, cy2, RX - 145, cy2, GRN_L)
label(CX + 134, cy2 - 20, "Approve", GRN_L, anchor="la")

ry = cy2 - 32
ry = action(RX, ry, "transitionTo(Approved)\n(legal-transition check)",
            w=290, fill=GRN_F, outline=GRN_L)
for txt in ("Guard: not the proposal\nmilestone (panel decides)",
            "Open transaction;\nstatus + approved_at",
            "Record timeline event\n+ audit log"):
    ry += GAP
    ry = action(RX, ry, txt, w=290, fill=GRN_F, outline=GRN_L, fs=f_xs)
    link(ry - GAP, ry, cx=RX, colour=GRN_L)
ry += GAP
ry = action(RX, ry, "activateNext():\nopen next milestone\n(status = open)",
            w=290, fill=GRN_F, outline=GRN_L)
link(ry - GAP, ry, cx=RX, colour=GRN_L)
ry += GAP
ry = action(RX, ry, "Notify students\n(MilestoneApproved)",
            w=290, fill=GRN_F, outline=GRN_L)
link(ry - GAP, ry, cx=RX, colour=GRN_L)
approve_bottom = ry

# ---- REQUEST REVISION branch (down)
prev = d2_bot
y = d2_bot + GAP
label(CX + 132, d2_bot - 40, "Request\nrevision", AMB_L, anchor="la")

y = action(CX, y, "transitionTo(Rejected)\n(comment required, min 10 chars)",
           fill=AMB_F, outline=AMB_L)
link(prev, y)
prev = y

for txt in ("Revision counter +1;\nstatus = rejected",
            "Record timeline event\n+ audit log",
            "Notify students\n(RevisionRequested)"):
    y += GAP
    y = action(CX, y, txt, fill=AMB_F, outline=AMB_L)
    link(prev, y)
    prev = y

# loop back to the student upload step (left-hand return channel)
upload_mid = y_upload + 42
seg(CX, prev, CX - 200, prev, AMB_L, 2)
seg(CX - 200, prev, CX - 200, upload_mid, AMB_L, 2)
seg(CX - 200, upload_mid, CX - 180, upload_mid, AMB_L, 2)
head(CX - 200, upload_mid, CX - 180, upload_mid, AMB_L)
label(CX - 208, (upload_mid + prev) / 2, "reopened\nfor re-upload", AMB_L, anchor="ra", f=f_xs)
y = prev

# ---------------------------------------------------------------- join + end
join_y = max(approve_bottom, y) + 70
seg(RX, approve_bottom, RX, join_y, GRN_L, 2)
seg(CX, y, CX, join_y, NAVY, 2)
seg(CX, join_y, RX, join_y, NAVY, 4)
mid = (CX + RX) / 2
link(join_y, join_y + GAP, cx=mid)
endy = end_node(mid, join_y + GAP)
label(mid, endy + 8, "end", GREY)

crop_h = int(min(H, endy + 70))
img2 = img.crop((0, 0, W * SCALE, crop_h * SCALE)).resize((W, crop_h), Image.LANCZOS)
out = os.path.join(OUTDIR, "activity_milestone.png")
img2.save(out)
print("wrote", out, img2.size)
