"""Sequence diagram - Manage Evaluation.

One assessor marks one form, and the number that results is published the moment
they submit it. There is no second signature: moderation, the Release button and
the coordinator's verdict were all removed deliberately, and this figure has to
show the mark travelling from the assessor's input to the student's screen with
nobody else in between.

Grounded in `EvaluationController::saveMarks` / `submit`,
`EvaluationService::saveMarks` / `submit` / `computeFinalGrade`, and
`MarkVisibilityService::syncProject`.

Style follows `seq_title.py`: white lifelines, thin outline, labels above their
arrow, dashed returns. No fills.
"""
import os
from PIL import Image, ImageDraw, ImageFont

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_evaluation.png"

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
DIVIDER = "#999999"
SCALE = 2

W = 2000
LH = 17        # label line height
LEAD = 26      # label's last line to the arrow (must exceed LH)
TAIL = 22      # arrow to the next row
BAND = 40      # section band height
XS = [170, 500, 830, 1150, 1500, 1840]
HEADS = [
    "Assessor\n(supervisor,\nexaminer, lecturer)",
    "Evaluation UI\n(React SPA)",
    "Evaluation\nController",
    "Evaluation\nService",
    "Rubric +\nScores",
    "Mark Visibility +\nAudit + Notification",
]


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


STEPS = [
    (None, None, "1. Marking  ·  the assessor works through the rubric, one 0-5 score per criterion", "section"),

    (0, 1, "1. Open a form the\ncoordinator allocated", "call"),
    (1, 2, "2. GET /api/evaluations/{id}", "call"),
    (2, 2, "3. EvaluationPolicy::view\nassessor, supervisor,\ncoordinator or admin", "self"),
    (2, 3, "4. Load the rubric snapshot\nand the student's scores", "call"),
    (3, 4, "5. SELECT evaluation_scores\n+ criterion weights", "call"),
    (4, 3, "6. criteria + weights", "return"),
    (3, 2, "7. form", "return"),
    (2, 1, "8. 200 OK\n{criteria, max_marks}", "return"),
    (1, 0, "9. Render one 0-5 select\nper criterion", "call"),

    (None, None, "2. Saving  ·  the item score is converted to the rubric's own weight here", "section"),

    (0, 1, "10. Choose a score, add\na comment", "call"),
    (1, 2, "11. PUT /api/evaluations/{id}/marks\n[{criterion_code, marks}]", "call"),
    (2, 3, "12. saveMarks(evaluation, marks)", "call"),
    (3, 3, "13. Status is editable?", "self"),
    (3, 3, "14. assertMarkingOpen()\nthis form's window, not another's", "self"),
    (3, 3, "15. Convert 0-5 to the\ncriterion's weight", "self"),
    (3, 4, "16. UPDATE evaluation_scores\nmarks + item_score", "call"),
    (3, 2, "17. evaluation", "return"),
    (2, 1, "18. 200 OK\nMarks saved", "return"),

    (None, None, "3. Submitting  ·  the form locks and the mark becomes visible in the same step", "section"),

    (0, 1, "19. Click Submit", "call"),
    (1, 2, "20. POST /api/evaluations/{id}/submit", "call"),
    (2, 3, "21. submit(evaluation, actor)", "call"),
    (3, 3, "22. Status still draft?", "self"),
    (3, 3, "23. assertMarkingOpen()\n+ milestones complete", "self"),
    (3, 3, "24. validationErrors():\nevery criterion marked?", "self"),
    (3, 4, "25. recalculateTotals()\nscore_percent", "call"),
    (3, 4, "26. UPDATE status = submitted\nis_late_assessment", "call"),
    (3, 5, "27. Log EvaluationSubmitted", "call"),
    (3, 5, "28. syncProject()\ncomputeFinalGrade + reconcile", "call"),
    (5, 3, "29. final_mark + release flag", "return"),
    (3, 5, "30. Notify coordinator:\nthe batch moved", "call"),
    (3, 2, "31. evaluation", "return"),
    (2, 1, "32. 200 OK\nMarks submitted and locked", "return"),
    (1, 0, "33. Student sees the mark;\nno release step exists", "call"),
]


def layout():
    """(a, b, label, kind, y, h) rows.

    LEAD must exceed the label's own line height, or the last line of every
    multi-line label lands on the arrow beneath it — which is exactly what the
    first layout did.
    """
    y = 0
    rows = []
    for a, b, label, kind in STEPS:
        if kind == "section":
            rows.append((None, None, label, "section", y, 0))
            y += BAND
            continue
        n = label.count("\n") + 1
        if kind == "self":
            h = max(40, LH * n + 22)
        else:
            h = LH * n + LEAD + TAIL
        rows.append((a, b, label, kind, y, h))
        y += h
    return rows, y


def draw():
    rows, content_h = layout()
    top = 26
    head_h = 116
    H = top + head_h + 40 + content_h + 60

    img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
    d = ImageDraw.Draw(img)

    def L(v):
        return int(round(v * SCALE))

    fh = font(15, True)
    fm = font(16)
    fs = font(15)
    fsec = font(15, True)

    base = top + head_h + 40
    bottom = base + content_h + 20
    for i, name in enumerate(HEADS):
        cx = XS[i]
        lines = name.split("\n")
        wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
        bw = wmax + 30
        bh = 21 * len(lines) + 22
        x0, y0 = cx - bw / 2, top + 8
        d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)], radius=L(9),
                            fill="white", outline=INK, width=L(2))
        ty = y0 + 11
        for ln in lines:
            d.text((L(cx), L(ty)), ln, font=fh, fill=INK, anchor="ma")
            ty += 21
        d.line([L(cx), L(y0 + bh), L(cx), L(bottom)], fill="#BBBBBB", width=L(2))

    for (a, b, label, kind, yy, hh) in rows:
        if kind == "section":
            ybar = base + yy + 20
            d.line([L(20), L(ybar), L(W - 20), L(ybar)], fill=DIVIDER, width=L(1))
            d.text((L(28), L(ybar + 7)), label, font=fsec, fill=INK)
            continue

        cxa, cxb = XS[a], XS[b]
        cy = base + yy + hh - TAIL
        lines = label.split("\n")

        if kind == "self":
            gap = (XS[a + 1] - XS[a] if a + 1 < len(XS) else 300) - 60
            xr = cxa + gap
            block_h = len(lines) * LH
            half = (block_h + 14) / 2 + 3
            yt, yb = cy - half, cy + half
            d.line([L(cxa), L(yt), L(xr), L(yt)], fill=LINE, width=L(2))
            d.line([L(xr), L(yt), L(xr), L(yb)], fill=LINE, width=L(2))
            d.line([L(xr), L(yb), L(cxa), L(yb)], fill=LINE, width=L(2))
            d.polygon([(L(cxa), L(yb)), (L(cxa + 12), L(yb - 5)),
                       (L(cxa + 12), L(yb + 5))], fill=LINE)
            ty = cy - block_h / 2 + 1
            for ln in lines:
                d.text((L(cxa + 16), L(ty)), ln, font=fs, fill=INK, anchor="la")
                ty += LH
            continue

        if kind == "return":
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=GREY, width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)), (L(cxb - 13 * dirn), L(cy - 5)),
                       (L(cxb - 13 * dirn), L(cy + 5))], fill="white",
                      outline=GREY, width=L(2))
            ty = cy - LEAD - (len(lines) - 1) * LH
            mid = (cxa + cxb) / 2
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * LH)), ln, font=fs, fill=GREY, anchor="ma")
            continue

        d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=LINE, width=L(2))
        dirn = 1 if cxb > cxa else -1
        d.polygon([(L(cxb), L(cy)), (L(cxb - 15 * dirn), L(cy - 5)),
                   (L(cxb - 15 * dirn), L(cy + 5))], fill=LINE)
        ty = cy - LEAD - (len(lines) - 1) * LH
        mid = (cxa + cxb) / 2
        for j, ln in enumerate(lines):
            d.text((L(mid), L(ty + j * LH)), ln, font=fm, fill=INK, anchor="ma")

    img.resize((W, H), Image.LANCZOS).save(OUT)
    return OUT, (W, H), round(H / W, 3)


if __name__ == "__main__":
    print("wrote", draw())
