"""Sequence diagram - Manage Title and Registration, laid out to fit a page.

Why this is a second script rather than a tweak to `seq_title.py`: the first
version was 38 messages tall, and at a width of 25.2 cm on a landscape page its
*native* height was 67 cm. Splitting it in two did not help - each half was
still taller than the page, so the caption fell to a second page and the
document grew a blank page per figure.

The fix is at the source. The long labels wrap onto more lines here, which makes
the figure wider relative to its height; the section bands and the arrow gaps
are tighter; and the whole thing is one figure again, because one figure that
fits beats two that do not.

Everything below is grounded in `RegistrationService::submitAgreement`,
`acknowledgeBySupervisor` and `withdrawAgreement`, and
`LampiranBController::file` / `sign` / `openProposalFor`.
"""
import os
from PIL import Image, ImageDraw, ImageFont

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_title.png"

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"
DIVIDER = "#999999"
SCALE = 2

W = 2000
XS = [150, 425, 730, 1075, 1420, 1770]
HEADS = [
    "Student /\nSupervisor",
    "Registration UI\n(React SPA)",
    "Registration\nController",
    "Registration\nService",
    "Agreement +\nSupervision",
    "Lampiran B +\nMilestone",
]

LH = 17        # label line height
LEAD = 26      # from the label's last line to the arrow (must exceed LH)
TAIL = 22      # from the arrow to the next row
BAND = 40      # section band height


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


# (from, to, label, kind); a section row has kind "section" and its label is the band.
STEPS = [
    (None, None, "1. Filing Lampiran A", "section"),
    (0, 1, "1. Complete Lampiran A: session (a dropdown\nof the terms), particulars, titles 1-3", "call"),
    (1, 2, "2. POST /api/registrations/agreements\n{titles, supervisor_profile_id}", "call"),
    (2, 2, "3. Authorise create (student only)", "self"),
    (2, 3, "4. submitAgreement(student, data)", "call"),
    (3, 3, "5. assertRegistrationOpen()", "self"),
    (3, 3, "6. assertStudentMayRegister()", "self"),
    (3, 4, "7. INSERT supervisor_agreement\nstatus = pending_supervisor", "call"),
    (3, 2, "8. agreement", "return"),
    (2, 1, "9. 201 Created", "return"),
    (1, 0, "10. Awaiting the supervisor", "call"),

    (None, None, "2. Supervisor acknowledgement  ·  the title and the pairing are fixed here", "section"),
    (0, 1, "11. Open the incoming Lampiran A", "call"),
    (1, 2, "12. POST /api/registrations/agreements/\n{agreement}/acknowledge {agreed_title}", "call"),
    (2, 3, "13. acknowledgeBySupervisor(", "call"),
    (3, 3, "14. status still pending_supervisor?", "self"),
    (3, 3, "15. agreed title is a candidate?", "self"),
    (3, 4, "16. assignSupervisor() if no pairing", "call"),
    (4, 3, "17. supervision_assignment_id", "return"),
    (3, 4, "18. UPDATE agreed_title + status", "call"),
    (3, 2, "19. agreement", "return"),
    (2, 1, "20. 200 OK: Lampiran B may be filed", "return"),
    (1, 0, "21. Notify the student", "call"),

    (None, None, "3. Filing Lampiran B  ·  the supervisor's signature opens the proposal", "section"),
    (0, 1, "22. Open the Lampiran B form", "call"),
    (1, 2, "23. PUT /api/lampiran-b/{form}/file\n{title, project_type, requirements}", "call"),
    (2, 2, "24. LampiranBFormPolicy::file", "self"),
    (2, 2, "25. IsTitle, and a re-file must differ", "self"),
    (3, 5, "26. status = submitted, signature cleared", "call"),
    (3, 2, "27. form", "return"),
    (2, 1, "28. 200 OK: awaiting signature", "return"),
    (1, 0, "29. Supervisor signs or sends back", "call"),

    (None, None, "4. Withdrawal  ·  before a project exists", "section"),
    (0, 1, "30. Choose Withdraw, give a reason", "call"),
    (1, 2, "31. POST .../{agreement}/withdraw", "call"),
    (2, 3, "32. withdrawAgreement()", "call"),
    (3, 3, "33. canBeWithdrawn(): no project yet?", "self"),
    (3, 4, "34. Retire the supervision", "call"),
    (3, 5, "35. Cancel the pending Lampiran B", "call"),
    (3, 2, "36. agreement (cancelled)", "return"),
    (2, 1, "37. 200 OK", "return"),
    (1, 0, "38. Filing kept as a record", "call"),
]


def measure(label):
    return label.count("\n") + 1


def layout():
    y = 0
    rows = []
    for a, b, label, kind in STEPS:
        if kind == "section":
            # The band starts with 18px of air above it; the line sits 14px in.
            rows.append((None, None, label, "section", y, 0))
            y += BAND
            continue
        n = measure(label)
        if kind == "self":
            h = max(40, LH * n + 22)
        else:
            # LEAD must exceed one line height, or the last line of the label
            # lands on the arrow. It did: every two-line label had its second
            # line struck through. LEAD is now the line height plus air.
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
            gap = (XS[a + 1] - XS[a] if a + 1 < len(XS) else 280) - 50
            xr = cxa + gap
            block_h = len(lines) * LH
            half = (block_h + 12) / 2 + 3
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
