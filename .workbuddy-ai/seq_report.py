"""Sequence diagram - Generate Report.

Grounded in ReportController (dashboard / cohortProgress / atRisk /
supervisorWorkload / markDistribution / milestoneBreakdown / exportMarks /
exportProjects) and ReportingService (dashboardSummary -> cohortProgress,
atRiskStudents, markDistribution, categoryBreakdown, dashboardByPart).

Shows the on-screen report path, then the CSV export path from the same
screen. Uses the shared plain-white style of the other sequences.
"""
import os
import sys
sys.path.insert(0, r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai")
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
SCALE = 2

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


def sequence():
    xs = [110, 300, 545, 790, 1020, 1235]
    W = 1400
    top = 30
    head_h = 132

    steps = [
        (0, 1, "1. Open Reports screen\nchoose batch, part, term", "call"),
        (1, 2, "2. GET /api/reports/dashboard\n?batch&psm_part&semester_id", "call"),
        (2, 2, "3. Authorise viewAnalytics\n(coordinator / admin)", "self"),
        (2, 2, "4. Validate filters\nresolve semester id", "self"),
        (2, 4, "5. dashboardSummary()", "call"),
        (4, 4, "6. cohortProgress()\ncount projects by status", "self"),
        (4, 3, "7. Aggregate milestones\nby status", "call"),
        (3, 4, "8. counts", "return"),
        (4, 4, "9. awaitReview + atRisk\n(top 5 students)", "self"),
        (4, 3, "10. markDistribution()\nreleased grades only", "call"),
        (3, 4, "11. bands + mean/median", "return"),
        (4, 4, "12. categoryBreakdown()", "self"),
        (4, 4, "13. dashboardByPart()\nfor PSM1 and PSM2", "self"),
        (4, 2, "14. summary object", "return"),
        (2, 1, "15. 200 OK\n{cohort, marks, at_risk,\nby_part}", "return"),
        (1, 0, "16. Render tables\nand charts", "call"),
        # ---- export path
        (0, 1, "17. Click Export CSV", "call"),
        (1, 2, "18. GET /api/reports/export/\ngrades.csv", "call"),
        (2, 2, "19. Authorise export\n(admin / coordinator)", "self"),
        (2, 4, "20. Build filename", "call"),
        (4, 2, "21. filename", "return"),
        (2, 5, "22. Log ReportExported\n(term, batch, part)", "call"),
        (2, 3, "23. Chunked read of\ngrades (200 per page)", "call"),
        (3, 2, "24. rows", "return"),
        (2, 2, "25. Stream CSV rows\n(final mark >= pass mark\n= Pass)", "self"),
        (2, 1, "26. CSV download", "return"),
        (1, 0, "27. File saved", "call"),
    ]

    y = top + head_h + 46
    layout = []
    for (a, b, label, kind) in steps:
        n = label.count("\n") + 1
        h = max(62, 20 * n + 30) if kind == "self" else max(52, 20 * (n + 1) + 16)
        layout.append((a, b, label, kind, y, h))
        y += h + 20
    bottom = y + 22
    H = bottom + 150

    img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
    d = ImageDraw.Draw(img)

    def L(v):
        return int(round(v * SCALE))

    heads = ["Coordinator", "Reports UI\n(React SPA)", "Report\nController",
             "Reporting\nService", "Grades +\nMilestones", "Audit Log"]

    fh = font(14, True)
    fm = font(14)
    fs = font(13)

    for i, name in enumerate(heads):
        cx = xs[i]
        lines = name.split("\n")
        wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
        bw = wmax + 28
        bh = 22 * len(lines) + 20
        x0, y0 = cx - bw / 2, top + 8
        d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)], radius=L(9),
                            fill="white", outline=INK, width=L(2))
        ty = y0 + 9
        for ln in lines:
            d.text((L(cx), L(ty)), ln, font=fh, fill=INK, anchor="ma")
            ty += 22
        d.line([L(cx), L(y0 + bh), L(cx), L(bottom)], fill="#BBBBBB", width=L(2))

    for (a, b, label, kind, yy, hh) in layout:
        cxa, cxb = xs[a], xs[b]
        cy = yy + hh / 2
        lines = label.split("\n")

        if kind == "self":
            xr = cxa + 250
            block_h = len(lines) * 19
            half = (block_h + 16) / 2 + 4
            yt, yb = cy - half, cy + half
            d.line([L(cxa), L(yt), L(xr), L(yt)], fill=LINE, width=L(2))
            d.line([L(xr), L(yt), L(xr), L(yb)], fill=LINE, width=L(2))
            d.line([L(xr), L(yb), L(cxa), L(yb)], fill=LINE, width=L(2))
            d.polygon([(L(cxa), L(yb)), (L(cxa + 13), L(yb - 6)),
                       (L(cxa + 13), L(yb + 6))], fill=LINE)
            ty = cy - block_h / 2 + 1
            for ln in lines:
                d.text((L(cxa + 34), L(ty)), ln, font=fs, fill=INK, anchor="la")
                ty += 19
            continue

        if kind == "return":
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=GREY, width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)), (L(cxb - 12 * dirn), L(cy - 6)),
                       (L(cxb - 12 * dirn), L(cy + 6))], fill="white",
                      outline=GREY, width=L(2))
            ty = cy - 11 - (len(lines) - 1) * 19 - 13
            mid = (cxa + cxb) / 2
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * 19)), ln, font=fs, fill=GREY, anchor="ma")
            continue

        d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=LINE, width=L(2))
        dirn = 1 if cxb > cxa else -1
        d.polygon([(L(cxb), L(cy)), (L(cxb - 14 * dirn), L(cy - 6)),
                   (L(cxb - 14 * dirn), L(cy + 6))], fill=LINE)
        ty = cy - 12 - (len(lines) - 1) * 19 - 13
        mid = (cxa + cxb) / 2
        for j, ln in enumerate(lines):
            d.text((L(mid), L(ty + j * 19)), ln, font=fm, fill=INK, anchor="ma")

    # section markers
    first_export = next(i for i, s in enumerate(layout) if s[2].startswith("17."))
    ybar = layout[first_export][4] - 34
    d.line([L(30), L(ybar), L(W - 30), L(ybar)], fill="#999999", width=L(1))
    d.text((L(36), L(ybar + 4)), "on-screen report  /  CSV export", font=fs, fill=GREY)

    out = os.path.join(OUTDIR, "sequence_report.png")
    img.resize((W, H), Image.LANCZOS).save(out)
    return out


if __name__ == "__main__":
    print("wrote", sequence())
