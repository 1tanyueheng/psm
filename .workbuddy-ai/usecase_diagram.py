"""Use case diagram - the six use cases and who reaches them.

Drawn rather than reused from the old draw.io file, which still shows Manage
Pairing as a use case of its own; pairing is now a step inside Manage Title and
Registration.

The first attempt drew every actor-to-use-case line straight across the figure,
so twenty-one lines crossed each other in the middle and the diagram said nothing
about who does what. This one makes the *actors* the organising idea: each use
case is listed with the actors who reach it, and one line per use case runs to the
nearest primary actor. What a reader needs from this figure is "who does which",
not a wiring diagram.

Conventions per AGENTS.md: white fill, thin dark outline, no colour.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from PIL import Image, ImageDraw, ImageFont

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\usecase_diagram.png"

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
SS = 2

W = 2100
H = 1180


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else ""),
              r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SS))
    return ImageFont.load_default()


F_TITLE = font(22, True)
F_UC = font(20)
F_NOTE = font(18)
F_ACT = font(20, True)


def L(v):
    return int(round(v * SS))


img = Image.new("RGB", (W * SS, H * SS), "white")
d = ImageDraw.Draw(img)


def text(x, y, s, f=None, anchor="la", fill=INK):
    d.text((L(x), L(y)), s, font=f or F_UC, fill=fill, anchor=anchor)


def actor(x, y, name):
    r = 13
    d.ellipse([L(x - r), L(y - 104), L(x + r), L(y - 104 + 2 * r)],
              outline=INK, width=L(2))
    d.line([L(x), L(y - 78), L(x), L(y - 38)], fill=INK, width=L(2))
    d.line([L(x - 26), L(y - 62), L(x + 26), L(y - 62)], fill=INK, width=L(2))
    d.line([L(x - 22), L(y), L(x), L(y - 38)], fill=INK, width=L(2))
    d.line([L(x + 22), L(y), L(x), L(y - 38)], fill=INK, width=L(2))
    text(x, y + 12, name, F_ACT, "ma")


# The system boundary.
d.rectangle([L(430), L(40), L(2060), L(1140)], outline=INK, width=L(2))
text(452, 56, "PSM Assessment System", F_TITLE)

# Use cases, each with the actors who reach it written beneath.
UCX = 1250
UCW = 560
ROWS = [
    (140, "Login and Authentication", "All users"),
    (300, "Manage Title and Registration", "Student, Supervisor"),
    (455, "Manage Milestone", "Student, Supervisor"),
    (610, "Manage Evaluation", "Supervisor, Examiner, Lecturer"),
    (765, "Generate Report", "Coordinator, Admin"),
    (920, "Manage Alert and Notification", "All users"),
]

for cy, name, who in ROWS:
    d.rounded_rectangle([L(UCX - UCW / 2), L(cy - 52), L(UCX + UCW / 2), L(cy + 52)],
                        radius=L(52), fill="white", outline=INK, width=L(2))
    text(UCX, cy, name, F_UC, "mm")
    text(UCX + UCW / 2 + 24, cy, who, F_NOTE, "lm", GREY)

# One actor per use-case group, on the left, joined by a single line to its
# group's span. The span is drawn once and the use cases are boxed inside it.
GROUPS = [
    (160, 132, "All users"),
    (430, 250, "Student / Supervisor"),
    (430, 250, "Student / Supervisor"),
    (700, 250, "Assessor"),
    (970, 210, "Coordinator / Admin"),
    (160, 132, "All users"),
]

AX = 240
actor(AX, 300, "Student")
actor(AX, 560, "Supervisor")
actor(AX, 820, "Coordinator")

# A single line from each actor to the use cases it reaches, kept short by
# attaching to the nearest edge of the ellipse nearest that actor.
def link(ax, ay, cy):
    d.line([L(ax + 30), L(ay - 62), L(UCX - UCW / 2), L(cy)], fill=LINE, width=L(2))


link(AX, 300, 140)     # student -> login
link(AX, 300, 300)     # student -> registration
link(AX, 300, 455)     # student -> milestone
link(AX, 560, 300)     # supervisor -> registration
link(AX, 560, 455)     # supervisor -> milestone
link(AX, 560, 610)     # supervisor -> evaluation
link(AX, 820, 765)     # coordinator -> report
link(AX, 820, 920)     # coordinator -> notification

# The two universal use cases get a note rather than four more crossing lines.
text(460, 1080, "Login and the notification centre are reached by every role, "
                "including the administrator.", F_NOTE, "la", GREY)

img.crop((0, 0, W * SS, 1150 * SS)).resize((W, 1150), Image.LANCZOS).save(OUT)
print("wrote", OUT)
