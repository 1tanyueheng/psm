"""Activity diagram for "Manage Pairing" (supervisor <-> student).

Single-column flow with one decision at the top (Assign or Remove), so the
two behaviours never overlap. Assign passes 6 ordered guards; remove
soft-deactivates the row.
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
GREY = "#595959"

W, H = 1880, 2400
CX = 420
EXC_X = 790
BUS_X = 1560


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
collected = []


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


def action(cx, top, text, w=340, fill=FILL, outline=BLUE, fs=f_s, lh=20, pad=11):
    lines = text.split("\n")
    h = len(lines) * lh + pad * 2
    d.rounded_rectangle([L(cx - w / 2), L(top), L(cx + w / 2), L(top + h)],
                        radius=L(10), fill=fill, outline=outline, width=L(2))
    ty = top + pad
    for ln in lines:
        d.text((L(cx), L(ty)), ln, font=fs, fill=NAVY, anchor="ma")
        ty += lh
    return top + h


def decision(cx, top, text, w=240, h=110):
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


def link(y_from, y_to, cx=CX):
    seg(cx, y_from, cx, y_to, BLUE, 2)
    head(cx, y_from, cx, y_to, BLUE)


def label(x, y, text, colour=GREY, anchor="ma", f=None):
    d.text((L(x), L(y)), text, font=f or f_xs, fill=colour, anchor=anchor)


GAP = 30

# ------------------------------------------------------------------ start
y = 24
y = start_node(CX, y + 16)
label(CX, y + 6, "start", GREY)
prev = y

y += GAP
y = action(CX, y, "Coordinator opens\nPairing screen")
link(prev, y)
prev = y

y += GAP
y = action(CX, y, "Select a student")
link(prev, y)
prev = y

# ------------------------------------------------------------------ action choice
y += GAP
d0_top = y
d0_bot = decision(CX, d0_top, "Which action?")
link(prev, d0_top)
prev = d0_bot

# ---------------- REMOVE branch (right) --------------------------------
RX = 1180
rcy = d0_top + 55
seg(CX + 120, rcy, RX - 145, rcy, GRN_L, 2)
head(CX + 120, rcy, RX - 145, rcy, GRN_L)
label(CX + 130, rcy - 20, "Remove", GRN_L, anchor="la")

ry = rcy - 34
ry = action(RX, ry, "Remove pairing\n(select existing)", w=280,
            fill=GRN_F, outline=GRN_L)
ry = action(RX, ry + 30 + 62, "Confirm removal\n(reason optional)", w=280,
            fill=GRN_F, outline=GRN_L)
link(ry - 62, ry, cx=RX)
for txt in ("Deactivate assignment\n(is_active = false)",
            "Log SupervisorRemoved\n(before/after kept)",
            "Notify student\n(Supervision changed)"):
    ry += GAP
    ry = action(RX, ry, txt, w=280, fill=GRN_F, outline=GRN_L)
    link(ry - GAP, ry, cx=RX)
ry += GAP
ry = action(RX, ry, "Return 200 OK", w=280, fill=GRN_F, outline=GRN_L)
link(ry - GAP, ry, cx=RX)
remove_end = ry

# ---------------- ASSIGN branch (down) ---------------------------------
y = d0_bot + GAP
assign_bot = y
label(CX + 132, d0_bot - 40, "Assign", NAVY, anchor="la")

y = action(CX, y, "Choose supervisor from pool\n+ PSM part (PSM1/PSM2/BOTH)", w=380)
link(d0_bot, y)

GUARDS = [
    ("Role is\nprimary?", "Reject: no\nco-supervisor role"),
    ("PSM part\nvalid?", "Reject: part not\nrecognised"),
    ("Supervisor\naccepting?", "Reject: not accepting\nnew students"),
    ("Programme\nmatches?", "Reject: student's programme\nnot served"),
    ("Supervisor has\nroom in part?", "Reject: part cap full\n(PSM1 / PSM2 / BOTH)"),
    ("Student has\nspare slots?", "Reject: max supervisors\nreached"),
    ("Pairing not\nalready active?", "Reject: already assigned\nfor this part"),
]

for i, (q, fail) in enumerate(GUARDS):
    y += GAP
    top = y
    bot = decision(CX, top, q)
    link(top - GAP, top)
    cy = top + 55
    seg(CX + 120, cy, EXC_X - 118, cy, BLUE, 2)
    head(CX + 120, cy, EXC_X - 118, cy, BLUE)
    label(CX + 130, cy - 20, "No", RED_L, anchor="la")
    action(EXC_X, cy - 31, fail, w=236, fill=RED_F, outline=RED_L, fs=f_xs)
    collected.append((EXC_X + 118, cy))
    y = bot

for txt in ("Open DB transaction\nINSERT supervision_assignment",
            "Log SupervisorAssigned\n(audit trail)",
            "Warn supervisor if\npart is now full",
            "Notify student\n(SupervisorAssigned)"):
    y += GAP
    y = action(CX, y, txt, w=380)
    link(y - GAP, y)

y += GAP
y = action(CX, y, "Return 201 Created\n(pairing appears in list)", w=380)
link(y - GAP, y)
assign_end = y

# ------------------------------------------------------------------ join + end
join_y = max(assign_end, remove_end) + 64
seg(CX, assign_end, CX, join_y, NAVY, 2)
seg(RX, remove_end, RX, join_y, NAVY, 2)
seg(CX, join_y, RX, join_y, NAVY, 4)
cx_end = (CX + RX) / 2
link(join_y, join_y + GAP, cx=cx_end)
endy = end_node(cx_end, join_y + GAP)
label(cx_end, endy + 8, "end", GREY)

# ------------------------------------------------------------------ failure bus
# short bus anchored just right of the reject boxes, clear of the remove lane
bus_x = EXC_X + 150
bus_top = min(c[1] for c in collected) - 22
bus_bot = max(c[1] for c in collected) + 22
seg(bus_x, bus_top, bus_x, bus_bot, "#B0B0B0", 1.6)
for (ex, sy) in collected:
    seg(ex, sy, bus_x, sy, "#B0B0B0", 1.6)
label(bus_x + 14, (bus_top + bus_bot) / 2 - 30,
      "each failed guard is\nrefused with a 422 and the\nspecific reason; nothing\nis saved and no pairing\nis created",
      GREY, anchor="la", f=f_xs)

crop_h = int(min(H, endy + 70))
img2 = img.crop((0, 0, W * SCALE, crop_h * SCALE)).resize((W, crop_h), Image.LANCZOS)
out = os.path.join(OUTDIR, "activity_pairing.png")
img2.save(out)
print("wrote", out, img2.size)
