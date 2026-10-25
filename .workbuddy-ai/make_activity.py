"""Activity diagram for "Login and Authentication" (UML activity notation).

Mirrors AuthController::login():
  validate -> find user -> locked? -> password ok? -> active? -> issue token
Each failure branch returns to the Login page.
"""
import os
import math
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
os.makedirs(OUTDIR, exist_ok=True)
SCALE = 2

NAVY = "#1F3864"
BLUE = "#2F5597"
FILL = "#EDF2FA"
RED_F = "#FDECEA"
RED_L = "#C00000"
GREY = "#595959"

W, H = 1330, 1860
CX = 400
EXC_X = 780
BUS_X = 1090


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


def action(cx, top, text, w=300, fill=FILL, outline=BLUE, fs=f_s, lh=20, pad=11):
    lines = text.split("\n")
    h = len(lines) * lh + pad * 2
    d.rounded_rectangle([L(cx - w / 2), L(top), L(cx + w / 2), L(top + h)],
                        radius=L(10), fill=fill, outline=outline, width=L(2))
    ty = top + pad
    for ln in lines:
        d.text((L(cx), L(ty)), ln, font=fs, fill=NAVY, anchor="ma")
        ty += lh
    return top + h


def decision(cx, top, text, w=214, h=100):
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


def arrow(x1, y1, x2, y2, colour=BLUE, width=2):
    seg(x1, y1, x2, y2, colour, width)
    head(x1, y1, x2, y2, colour)


def link(y_from, y_to, cx=CX):
    """Vertical connector between two shapes, drawn only in the gap."""
    seg(cx, y_from, cx, y_to, BLUE, 2)
    head(cx, y_from, cx, y_to, BLUE)


def label(x, y, text, colour=GREY, anchor="ma", f=None):
    d.text((L(x), L(y)), text, font=f or f_xs, fill=colour, anchor=anchor)


# ---------------------------------------------------------------- main spine
y = 26
y = start_node(CX, y + 16)
label(CX, y + 6, "start", GREY)

GAP = 34
collected = []          # (right_edge_x, y_centre) of each error box

y += GAP
y = action(CX, y, "Open Login page")
link(y - GAP, y)

y += GAP
entered_top = y
y = action(CX, y, "Enter email and password")
link(y - GAP, y)

y += GAP
y = action(CX, y, "Click Login")
link(y - GAP, y)

y += GAP
y = action(CX, y, "Validate input\nrequired, email format,\nlowercase and trim", w=320)
link(y - GAP, y)

# ---- D1 valid?
y += GAP
d1_top = y
d1_bot = decision(CX, d1_top, "Valid?")

link(d1_top - GAP, d1_top)
head(CX, d1_top - GAP, CX, d1_top)

y_no1c = d1_top + 50
seg(CX + 107, y_no1c, EXC_X - 110, y_no1c)
head(CX + 107, y_no1c, EXC_X - 110, y_no1c)
nb1_top = action(EXC_X, y_no1c - 21, "Show input\nerror message", w=210,
                 fill=RED_F, outline=RED_L, fs=f_xs)
label(CX + 116, y_no1c - 18, "No", RED_L, anchor="la")
collected.append((EXC_X + 105, y_no1c))

y = d1_bot
y += GAP
y = action(CX, y, "Find user by email")
link(d1_bot, y)

# ---- D2 user found?
y += GAP
d2_top = y
d2_bot = decision(CX, d2_top, "User found?")
link(d2_top - GAP, d2_top)

y_no2c = d2_top + 50
label(CX + 116, y_no2c - 18, "No", RED_L, anchor="la")
seg(CX + 107, y_no2c, EXC_X - 120, y_no2c)
head(CX + 107, y_no2c, EXC_X - 120, y_no2c)
action(EXC_X, y_no2c - 31, "Log LoginFailed\nReturn 422\n\"authentication failed\"",
       w=236, fill=RED_F, outline=RED_L, fs=f_xs)
collected.append((EXC_X + 118, y_no2c))

y = d2_bot + GAP

# ---- D3 locked?
d3_top = y
d3_bot = decision(CX, d3_top, "Account\nlocked?")
link(d3_top - GAP, d3_top)

y_yes3 = d3_top + 50
label(CX + 116, y_yes3 - 18, "Yes", RED_L, anchor="la")
seg(CX + 107, y_yes3, EXC_X - 120, y_yes3)
head(CX + 107, y_yes3, EXC_X - 120, y_yes3)
action(EXC_X, y_yes3 - 31, "Return 422\n\"account temporarily\nlocked\"",
       w=236, fill=RED_F, outline=RED_L, fs=f_xs)
collected.append((EXC_X + 118, y_yes3))

y = d3_bot + GAP

# ---- D4 password correct?
d4_top = y
d4_bot = decision(CX, d4_top, "Password\ncorrect?")
link(d4_top - GAP, d4_top)

y_no4 = d4_top + 50
label(CX + 116, y_no4 - 18, "No", RED_L, anchor="la")
seg(CX + 107, y_no4, EXC_X - 126, y_no4)
head(CX + 107, y_no4, EXC_X - 126, y_no4)
action(EXC_X, y_no4 - 51,
       "Increment failed attempts\nLog LoginFailed\nReturn 422\n(5 attempts = lock 15 min,\ndoubling each block)",
       w=250, fill=RED_F, outline=RED_L, fs=f_xs)
collected.append((EXC_X + 125, y_no4))

y = d4_bot + GAP

# ---- D5 account active?
d5_top = y
d5_bot = decision(CX, d5_top, "Account\nactive?")
link(d5_top - GAP, d5_top)

y_no5 = d5_top + 50
label(CX + 116, y_no5 - 18, "No", RED_L, anchor="la")
seg(CX + 107, y_no5, EXC_X - 124, y_no5)
head(CX + 107, y_no5, EXC_X - 124, y_no5)
action(EXC_X, y_no5 - 41, "Return 422\nsuspended / inactive /\nunverified",
       w=248, fill=RED_F, outline=RED_L, fs=f_xs)
collected.append((EXC_X + 124, y_no5))

y = d5_bot + GAP

# ---- success path
y = action(CX, y, "Create Sanctum token\nmarkLoggedIn, reset attempts", w=320)
link(y - GAP, y)

y += GAP
y = action(CX, y, "Log Login\nsuccess, actor, IP")
link(y - GAP, y)

y += GAP
y = action(CX, y, "Return 200 OK\n{token, user, home_route}")
link(y - GAP, y)

y += GAP
y = action(CX, y, "Redirect to role dashboard", w=320)
link(y - GAP, y)

y += GAP
y = end_node(CX, y + 17)
end_y = y
link(y - 17 - GAP, y - 17)
label(CX, y + 4, "end", GREY)

# ---------------------------------------------------------------- failure bus
# Every failure returns the user to the login form, i.e. back to the
# "Enter email and password" action. The bus runs down the right side and
# re-enters that action from above via a return channel.
bus_top = min(c[1] for c in collected) - 26
bus_bot = end_y + 10
seg(BUS_X, bus_top, BUS_X, bus_bot, "#B0B0B0", 1.6)
for (ex, sy) in collected:
    seg(ex, sy, BUS_X, sy, "#B0B0B0", 1.6)

# top of the return channel: go up the outside, then across above the spine
TOP_Y = 22
ret_y = entered_top + 13
seg(BUS_X, bus_top, BUS_X, TOP_Y, "#B0B0B0", 1.6)
seg(BUS_X, TOP_Y, CX + 178, TOP_Y, "#B0B0B0", 1.6)
seg(CX + 178, TOP_Y, CX + 178, ret_y, "#B0B0B0", 1.6)
seg(CX + 178, ret_y, CX + 160, ret_y, "#B0B0B0", 1.6)
head(CX + 178, ret_y, CX + 160, ret_y, "#B0B0B0")
label(BUS_X + 14, (bus_top + bus_bot) / 2, "all failures return\nto the Login form",
      GREY, anchor="la", f=f_xs)

crop_h = int(min(H, end_y + 60))
img2 = img.crop((0, 0, W * SCALE, crop_h * SCALE)).resize((W, crop_h), Image.LANCZOS)
out = os.path.join(OUTDIR, "activity_login.png")
img2.save(out)
print("wrote", out, img2.size)
