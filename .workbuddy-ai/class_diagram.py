"""Class diagram - the PSM Assessment System domain.

Hand-placed. Two earlier attempts were routed automatically and both failed the
same way: with every connector taking the midpoint of the gap it was given,
eleven relations ran through each other, labels landed on boxes, and one arrow
went diagonally across the whole figure.

Here each relation is given an explicit corridor and, where two relations share a
target edge, its own entry point on that edge.

    left    the people          User, StudentProfile, SupervisorProfile, StudentEnrolment
    centre  the project         Project, Milestone, LampiranBForm, SupervisionAssignment
    right   the marking chain   Evaluation, EvaluationScore, FinalGrade, AuditLog

Twelve classes. Attributes are the columns a reader needs in order to see the
relationships; operations are the rules that live on the class.

Conventions per AGENTS.md: white fill, thin dark outline, no colour, arrows on
box edges and never through one.
"""
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from PIL import Image, ImageDraw, ImageFont

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\class_diagram.png"

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
SS = 2

LH = 22
PAD = 15
TITLE_H = 46

W = 2700
TOP = 150
BOT = 1640

C1, C2, C3 = 430, 1320, 2230
BAND_L_OUT = 150       # outside the left column
COR_L = 800            # between column 1 and 2
COR_M = 1000           # second corridor between 1 and 2
COR_R = 1770           # between column 2 and 3


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else ""),
              r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SS))
    return ImageFont.load_default()


F_TITLE = font(21, True)
F_BODY = font(18)
F_NOTE = font(17)

img = Image.new("RGB", (W * SS, (BOT + 40) * SS), "white")
draw = ImageDraw.Draw(img)


def L(v):
    return int(round(v * SS))


class Box:
    def __init__(self, cx, top, name, attrs, ops, w=430):
        self.cx, self.w, self.top = cx, w, top
        self.name, self.attrs, self.ops = name, attrs, ops
        self.h = TITLE_H + PAD + LH * len(attrs) + PAD + LH * len(ops) + PAD

    l = property(lambda s: s.cx - s.w / 2)
    r = property(lambda s: s.cx + s.w / 2)
    b = property(lambda s: s.top + s.h)
    m = property(lambda s: (s.top + s.b) / 2)

    def at(self, side, y):
        """A point on a vertical edge at absolute y."""
        return (self.l, y) if side == "l" else (self.r, y)

    def draw(self):
        draw.rounded_rectangle([L(self.l), L(self.top), L(self.r), L(self.b)],
                               radius=L(7), fill="white", outline=INK, width=L(2))
        draw.text((L(self.cx), L(self.top + (TITLE_H - LH) / 2 + 3)), self.name,
                  font=F_TITLE, fill=INK, anchor="ma")
        y = self.top + TITLE_H
        draw.line([L(self.l), L(y), L(self.r), L(y)], fill=INK, width=L(2))
        y += PAD
        for a in self.attrs:
            draw.text((L(self.l + 16), L(y)), a, font=F_BODY, fill=INK, anchor="la")
            y += LH
        y += PAD - 4
        draw.line([L(self.l), L(y), L(self.r), L(y)], fill=INK, width=L(2))
        y += PAD - 6
        for o in self.ops:
            draw.text((L(self.l + 16), L(y)), o, font=F_BODY, fill=INK, anchor="la")
            y += LH


def arrow(a, b, kind="open"):
    ang = math.atan2(b[1] - a[1], b[0] - a[0])
    s = 17
    p1 = (b[0] - s * math.cos(ang - 0.36), b[1] - s * math.sin(ang - 0.36))
    p2 = (b[0] - s * math.cos(ang + 0.36), b[1] - s * math.sin(ang + 0.36))
    flat = [L(b[0]), L(b[1]), L(p1[0]), L(p1[1]), L(p2[0]), L(p2[1])]
    if kind == "open":
        draw.polygon(flat, fill="white", outline=LINE)
    elif kind == "closed":
        draw.polygon(flat, fill=LINE)


def route(points, head="open", dashed=False):
    for i in range(len(points) - 1):
        a, b = points[i], points[i + 1]
        if dashed and (a[1] == b[1] or a[0] == b[0]):
            n = max(1, int(max(abs(b[0] - a[0]), abs(b[1] - a[1])) // 16))
            for k in range(n):
                if k % 2:
                    continue
                t0, t1 = k / n, (k + 1) / n
                draw.line([L(a[0] + (b[0] - a[0]) * t0), L(a[1] + (b[1] - a[1]) * t0),
                           L(a[0] + (b[0] - a[0]) * t1), L(a[1] + (b[1] - a[1]) * t1)],
                          fill=LINE, width=L(2))
        else:
            draw.line([L(a[0]), L(a[1]), L(b[0]), L(b[1])], fill=LINE, width=L(2))
    if head:
        arrow(points[-2], points[-1], kind=head)


def label(x, y, text, anchor="ma"):
    draw.text((L(x), L(y)), text, font=F_NOTE, fill=GREY, anchor=anchor)


# ------------------------------------------------------------------ the boxes
USER = Box(C1, 240, "User",
           ["- id : bigint <<PK>>", "- name : varchar(255)", "- email : varchar(255)",
            "- password : varchar(255)", "- role : varchar(255)", "- status : varchar(255)",
            "- failedLoginAttempts : smallint"],
           ["+ hasRole(role) : bool", "+ canAssess() : bool"])

STUDENT = Box(C1, 690, "StudentProfile",
              ["- id : bigint <<PK>>", "- userId : bigint <<FK>>",
               "- matricNo : varchar(32)", "- programme : varchar(8)",
               "- yearOfStudy : smallint"],
              ["+ label() : string"])

SUPERVISOR = Box(C1, 960, "SupervisorProfile",
                 ["- id : bigint <<PK>>", "- userId : bigint <<FK>>",
                  "- programme : varchar(8)", "- capacityPsm1 : smallint",
                  "- capacityPsm2 : smallint"],
                 ["+ canSuperviseProgramme(p) : bool"])

ENROL = Box(C1, 1270, "StudentEnrolment",
            ["- id : bigint <<PK>>", "- studentProfileId : bigint <<FK>>",
             "- psmPart : varchar(8)", "- attemptNo : smallint",
             "- status : varchar(32)"],
            ["+ place(semester) : void", "+ settle(passed) : void"])

PROJECT = Box(C2, 240, "Project",
              ["- id : bigint <<PK>>", "- code : varchar(32)", "- title : varchar(255)",
               "- psmPart : varchar(8)", "- status : varchar(255)",
               "- agreementId : bigint <<FK>>"],
              ["+ isActive() : bool", "+ isPassed() : bool"])

MILESTONE = Box(C2, 580, "Milestone",
                ["- id : bigint <<PK>>", "- projectId : bigint <<FK>>",
                 "- code : varchar(32)", "- attempt : smallint",
                 "- status : varchar(32)", "- dueAt : timestamp"],
                ["+ acceptsSubmission() : bool", "+ lateness() : array"])

LAMPIRAN = Box(C2, 880, "LampiranBForm",
               ["- id : bigint <<PK>>", "- projectId : bigint <<FK>>",
                "- revisionNo : smallint", "- title : varchar(255)",
                "- status : varchar(32)", "- rejectedReason : text"],
               ["+ scopeCurrent() : Builder"])

ASSIGN = Box(C2, 1200, "SupervisionAssignment",
             ["- id : bigint <<PK>>", "- studentProfileId : bigint <<FK>>",
              "- supervisorProfileId : bigint <<FK>>", "- psmPart : varchar(8)",
              "- isActive : bool"],
             ["+ retire() : void"])

EVAL = Box(C3, 240, "Evaluation",
           ["- id : bigint <<PK>>", "- projectId : bigint <<FK>>",
            "- assessorId : bigint <<FK>>", "- status : varchar(32)",
            "- scorePercent : numeric"],
           ["+ recalculateTotals() : void"])

SCORE = Box(C3, 540, "EvaluationScore",
            ["- id : bigint <<PK>>", "- evaluationId : bigint <<FK>>",
             "- criterionCode : varchar(32)", "- marks : numeric"],
            ["+ weight() : numeric"])

GRADE = Box(C3, 820, "FinalGrade",
            ["- id : bigint <<PK>>", "- projectId : bigint <<FK>>",
             "- finalMark : numeric", "- releasedAt : timestamp"],
            ["+ isPassed() : bool"])

AUDIT = Box(C3, 1110, "AuditLog",
            ["- id : bigint <<PK>>", "- actorId : bigint <<FK>>",
             "- action : varchar(64)", "- subjectType : varchar(64)"],
            ["+ log(action, subject) : void"])

BOXES = [USER, STUDENT, SUPERVISOR, ENROL, PROJECT, MILESTONE, LAMPIRAN,
         ASSIGN, EVAL, SCORE, GRADE, AUDIT]
for b in BOXES:
    b.draw()

# ------------------------------------------------------------------ relations
# 1. User is specialised. The trunk runs OUTSIDE the left column so it cannot
#    cross StudentProfile on its way down to SupervisorProfile.
trunk_y = USER.top - 80
draw.line([L(USER.l + 90), L(USER.top), L(USER.l + 90), L(trunk_y)],
          fill=LINE, width=L(2))
draw.line([L(USER.l + 90), L(trunk_y), L(BAND_L_OUT), L(trunk_y)],
          fill=LINE, width=L(2))
draw.line([L(BAND_L_OUT), L(trunk_y), L(BAND_L_OUT), L(SUPERVISOR.m)],
          fill=LINE, width=L(2))
arrow((BAND_L_OUT, trunk_y), (USER.l + 90, trunk_y), kind="open")
route([(BAND_L_OUT, STUDENT.m), (STUDENT.l, STUDENT.m)], head=None)
# The branch to SupervisorProfile has to leave the trunk ABOVE it and come down
# onto its top edge: a horizontal run at its mid-height entered the box from the
# right and crossed StudentProfile's edge on the way.
route([(BAND_L_OUT, STUDENT.b + 40), (SUPERVISOR.l - 70, STUDENT.b + 40),
       (SUPERVISOR.l - 70, SUPERVISOR.top), (SUPERVISOR.l - 70, SUPERVISOR.top)],
      head=None)
draw.line([L(SUPERVISOR.l - 70), L(STUDENT.b + 40),
           L(BAND_L_OUT), L(STUDENT.b + 40)], fill=LINE, width=L(2))
label(BAND_L_OUT + 16, trunk_y - 26, "is a")

# 2. StudentProfile -> StudentEnrolment: down the column, in the corridor
#    outside the boxes, and past SupervisorProfile rather than through it.
col_x = STUDENT.l - 100
route([(STUDENT.l, STUDENT.top + STUDENT.h * 0.75), (col_x, STUDENT.top + STUDENT.h * 0.75)],
      head=None)
route([(col_x, STUDENT.top + STUDENT.h * 0.75), (col_x, ENROL.top + 60),
       (ENROL.l, ENROL.top + 60)])
label(col_x - 14, (SUPERVISOR.b + ENROL.top) / 2, "1  ·  enrolled by", "ra")

# 3. StudentProfile -> Project: corridor COR_L, entering Project on the left.
route([(STUDENT.r, STUDENT.top + 60), (COR_L, STUDENT.top + 60),
       (COR_L, PROJECT.b + 150), (PROJECT.l, PROJECT.b + 150)])
label(COR_L + 12, STUDENT.top + 34, "1  ·  registers", "la")

# 4. StudentEnrolment -> Project: corridor COR_M, low entry on Project. The
#    label sits just outside the assignment box rather than across its edge.
route([(ENROL.r, ENROL.top + 60), (COR_M, ENROL.top + 60),
       (COR_M, PROJECT.b + 240), (PROJECT.l, PROJECT.b + 240)])
label(COR_M - 14, ENROL.top + 34, "0..1  ·  becomes", "ra")

# 5. SupervisionAssignment sits between the supervisor and the project.
route([(SUPERVISOR.r, SUPERVISOR.top + 60), (COR_L, SUPERVISOR.top + 60),
       (COR_L, ASSIGN.top + 60), (ASSIGN.l, ASSIGN.top + 60)], head=None)
label(COR_L + 12, SUPERVISOR.top + 34, "1  ·  takes", "la")
route([(ASSIGN.r, ASSIGN.top + 60), (COR_R - 120, ASSIGN.top + 60),
       (COR_R - 120, PROJECT.b + 330), (PROJECT.l, PROJECT.b + 330)])
label(ASSIGN.r + 16, ASSIGN.top + 34, "0..*  ·  on", "la")

# 6. Project -> Milestone and -> LampiranBForm: one corridor, two entries.
route([(PROJECT.r, PROJECT.top + 90), (COR_R, PROJECT.top + 90),
       (COR_R, MILESTONE.top + 60), (MILESTONE.r, MILESTONE.top + 60)])
label(COR_R - 12, PROJECT.top + 64, "1..*  ·  carries", "ra")
route([(PROJECT.r, PROJECT.top + 150), (COR_R + 70, PROJECT.top + 150),
       (COR_R + 70, LAMPIRAN.top + 60), (LAMPIRAN.r, LAMPIRAN.top + 60)])
label(COR_R + 82, PROJECT.top + 124, "1..*  ·  filed as", "la")

# 7. Project -> Evaluation and -> FinalGrade, same corridor, lower entries.
route([(PROJECT.r, PROJECT.top + 300), (COR_R - 40, PROJECT.top + 300),
       (COR_R - 40, EVAL.b - 40), (EVAL.l, EVAL.b - 40)])
label(COR_R - 52, PROJECT.top + 274, "1..*  ·  assessed by", "ra")
route([(PROJECT.r, PROJECT.top + 360), (COR_R + 40, PROJECT.top + 360),
       (COR_R + 40, GRADE.b - 40), (GRADE.l, GRADE.b - 40)])
label(COR_R + 52, PROJECT.top + 334, "1  ·  results in", "la")

# 8. Evaluation -> EvaluationScore.
route([(EVAL.cx, EVAL.b), (SCORE.cx, SCORE.top)], head="open")
label(EVAL.cx + 14, (EVAL.b + SCORE.top) / 2, "1..*  ·  scores", "la")

# 9. EvaluationScore -> FinalGrade, dashed: the marks add up to the grade.
route([(SCORE.r, SCORE.top + 60), (SCORE.r + 60, SCORE.top + 60),
       (SCORE.r + 60, GRADE.top + 60), (GRADE.r, GRADE.top + 60)], dashed=True)
label(SCORE.r + 72, SCORE.top + 34, "sums into", "la")

img.crop((0, 0, W * SS, BOT * SS)).resize((W, BOT), Image.LANCZOS).save(OUT)
print("wrote", OUT)
