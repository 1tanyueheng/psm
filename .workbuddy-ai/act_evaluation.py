"""Activity diagram - Manage Evaluation.

One assessor, one form, one number. The figure has to make three things plain,
because each replaced something that used to exist and no longer does:

  * **The window gates the form, not the milestone.** A form opens when the
    coordinator opens *its* assessment window; opening Lampiran E does not open
    Lampiran I.
  * **The mark publishes itself.** Submitting locks the form and reconciles
    visibility in the same call - there is no Release button, and none should be
    added back.
  * **No zero is ever assumed.** An assessor who has not marked every criterion
    is blocked from submitting; an assessor marking a student who did not attend
    types 0.

Grounded in `EvaluationController::saveMarks` / `submit` / `declareConflict` and
`EvaluationService` / `MarkVisibilityService`.

Layout: the spine is the marking route. Every refusal hangs off it into one
right-hand column, and the column runs down to the shared end node, so each
refusal visibly ends the attempt rather than dangling. A recusal is the one
branch that leaves from the spine itself and must NOT join the refusal column -
it was drawn level with the "every criterion marked" refusal in the first draft
and the two lines met at a T, which reads as one path.

Conventions per AGENTS.md: white shapes, thin dark outline, no fills, arrows on
edges, nothing crossing a box.
"""
import sys
sys.path.insert(0, r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai")
from uml_kit import Diagram

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\activity_evaluation.png"

CX = 560
RX = 1480         # the single refusal column
W = 1960
BOXC = 430
BOXR = 400
DIAW, DIAH = 300, 128
G = 40

dg = Diagram(W, 2600)

y = 30
y = dg.start(CX, y + 8, r=14)
dg.label(CX - 26, y - 4, "start", anchor="ra")
prev = y


def step(prev, text, gap=G, w=BOXC):
    top = prev + gap
    bottom = dg.action(CX, top, text, w=w)
    dg.connect(CX, prev, CX, top)
    return bottom


def decide(prev, text, w=DIAW, h=DIAH):
    top = prev + G
    bottom = dg.decision(CX, top, text, w=w, h=h)
    dg.connect(CX, prev, CX, top)
    return top, bottom, top + h / 2


def refuse(mid_y, text, label="No", w=BOXR):
    """A refusal box in the right column, level with the diamond's mid-line.

    The connector leaves the diamond's RIGHT VERTEX, so it cannot pass through
    the "Yes" label that sits below that vertex on the spine.
    """
    left = RX - w / 2
    dg.connect(CX + DIAW / 2, mid_y, left, mid_y)
    dg.label(CX + DIAW / 2 + 10, mid_y - 24, label, anchor="la", bold=True)
    return dg.action(RX, mid_y - 34, text, w=w)


# ---- 1. the assessor opens a form -----------------------------------------
prev = step(prev, "Assessor opens the form the\ncoordinator allocated")

# ---- guard: is this form's own window open? -------------------------------
d1_top, d1_bot, d1_mid = decide(prev, "This form's\nwindow open?")
r1 = refuse(d1_mid, "Refuse 422: the assessment window\nfor this form has not opened\n(opening E does not open I)")

# ---- guard: a recusal is final --------------------------------------------
d2_top, d2_bot, d2_mid = decide(d1_bot, "Status not\nrecused?")
r2 = refuse(d2_mid, "Refuse 422: a recusal is final —\nthe coordinator allocates\na replacement")

prev = step(d2_bot, "Mark each criterion\non the 0-5 scale")

# ---- 2. a score is saved --------------------------------------------------
d3_top, d3_bot, d3_mid = decide(prev, "Every score\nin range?")
r3 = refuse(d3_mid, "Refuse 422: 0 to 5 only")

# No -> correct the score and mark again.
back_x = RX + BOXR / 2 + 44
dg.route([(RX, r3), (RX, r3 + 34), (back_x, r3 + 34),
          (back_x, d2_bot + 66), (CX + BOXC / 2, d2_bot + 66)])
dg.label(back_x + 10, r3 + 44, "correct the score", anchor="la")

prev = step(d3_bot, "Convert each score to the\ncriterion's weight\n(item_score kept beside it)")

prev = step(prev, "Marks saved\nprogress total recomputed")

# ---- 3. submit ------------------------------------------------------------
d4_top, d4_bot, d4_mid = decide(prev, "Every criterion\nmarked?")
r4 = refuse(d4_mid, "Refuse 422 naming each\nunmarked criterion — an\nunmarked form is not a zero")

prev = step(d4_bot, "recalculateTotals()\nscore_percent")

prev = step(prev, "status = submitted\nsubmitted_at, is_late_assessment")

prev = step(prev, "syncProject():\ncomputeFinalGrade() and\nreconcile the release flag")

prev = step(prev, "final_mark visible to the student\nno Release step exists")

prev = step(prev, "Notify the coordinator\nand the student")

# ---- the recusal branch ---------------------------------------------------
#  Leaves the spine on its own x, well clear of the refusal column, and runs
#  down the far right to the end. Voluntary, and it ends this assessor's
#  involvement - it is not a refusal of the form.
RC_X = RX + BOXR / 2 + 150
rc_mid = d4_top - 10
rc = dg.action(RC_X, rc_mid - 34, "Assessor declares a conflict\nreason required, status = recused", w=BOXR)
dg.route([(CX + BOXC / 2, rc_mid), (RC_X - BOXR / 2 - 40, rc_mid),
          (RC_X - BOXR / 2 - 40, rc_mid - 8), (RC_X - BOXR / 2, rc_mid - 8)])
dg.label(CX + BOXC / 2 + 10, rc_mid - 30, "conflict of interest", anchor="la")

# ---- converge -------------------------------------------------------------
join_y = max(prev, r1, r2, r3, r4, rc) + 84
dg.seg(CX, prev, CX, join_y)
dg.seg(RX, r4, RX, join_y)
dg.seg(RC_X, rc, RC_X, join_y)
dg.seg(CX, join_y, RC_X, join_y)
mid = (CX + RX) / 2
dg.connect(mid, join_y + 4, mid, join_y + G - 8)
y = dg.end(mid, join_y + G, r=16)
dg.label(mid, y + 8, "end")

dg.save(OUT, crop_bottom=y + 60)
print("wrote", OUT)
