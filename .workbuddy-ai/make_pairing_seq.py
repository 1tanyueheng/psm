"""Sequence + Activity diagrams for "Manage Pairing" (supervisor <-> student).

Grounded in AssignmentService::assignSupervisor():
  1 role is primary          4 supervisor has room IN THAT PART
  2 PSM part is known        5 student has spare supervisor slots
  3 supervisor accepting     6 this pairing not already active for that part
then: DB transaction -> create -> audit -> warnIfPartNowFull -> notify student
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
os.makedirs(OUTDIR, exist_ok=True)
SCALE = 2

NAVY = "#1F3864"
BLUE = "#2F5597"
FILL = "#EDF2FA"
YEL_F = "#FFF4E5"
YEL_L = "#BF8F00"
RED_F = "#FDECEA"
RED_L = "#C00000"
GREY = "#595959"
GRN = "#548235"


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibrib.ttf" if bold else r"C:\Windows\Fonts\calibri.ttf",
              r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(c):
            return ImageFont.truetype(c, size * SCALE)
    return ImageFont.load_default()


# =====================================================================
# SEQUENCE DIAGRAM
# =====================================================================
def sequence():
    xs = [120, 310, 560, 800, 1010, 1200]
    W = 1360
    top = 30
    head_h = 132

    steps = [
        # (from, to, label, kind)  kind: call|return|self
        (0, 1, "1. Select student and\nsupervisor", "call"),
        (1, 2, "2. POST /api/assignments/supervisions\n{student_profile_id,\nsupervisor_profile_id, psm_part}", "call"),
        (2, 2, "3. Authorise create\n(coordinator / admin only)", "self"),
        (2, 2, "4. Validate input\n(ids exist, part in\nPSM1|PSM2|BOTH)", "self"),
        (2, 3, "5. Resolve default PSM part\n+ semester check", "call"),
        (3, 2, "6. psm_part, semester", "return"),
        (2, 4, "7. assignSupervisor()", "call"),
        # six ordered guards
        (4, 4, "8. Guards 1-3: role is primary;\nPSM part recognised;\nsupervisor accepting", "self"),
        (4, 4, "9. Guard 4: programme refusal?\n(student's programme not\nsupervisor's)", "self"),
        (4, 3, "10. Per-part capacity query\n(PSM1 / PSM2 / BOTH)", "call"),
        (3, 4, "11. used vs cap for this term", "return"),
        (4, 4, "12. Guard 5: student has spare\nsupervisor slots", "self"),
        (4, 3, "13. Existing pairing lookup", "call"),
        (3, 4, "14. active row | none", "return"),
        (4, 4, "15. Guard 6: pairing not already active", "self"),
        # write path
        (4, 4, "16. Open DB transaction", "self"),
        (4, 3, "17. INSERT supervision_assignment", "call"),
        (3, 4, "18. assignment", "return"),
        (4, 5, "19. Log SupervisorAssigned", "call"),
        (4, 4, "20. Warn if part now full", "self"),
        (4, 5, "21. Notify student\n(SupervisorAssigned)", "call"),
        (4, 2, "22. assignment", "return"),
        (2, 1, "23. 201 Created\n{student, supervisor, psm_part}", "return"),
        (1, 0, "24. Show pairing in list\nand capacity bar", "call"),
    ]

    y = top + head_h + 46
    layout = []
    for (a, b, label, kind) in steps:
        n = label.count("\n") + 1
        h = max(60, 18 * n + 30) if kind == "self" else max(50, 18 * (n + 1) + 18)
        layout.append((a, b, label, kind, y, h))
        y += h + 20
    bottom = y + 24
    H = bottom + 110

    img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
    d = ImageDraw.Draw(img)

    def L(v):
        return int(round(v * SCALE))

    heads = [("Coordinator\n/ Admin", 0), ("Pairing UI\n(React SPA)", 1),
             ("Assignment\nController", 2), ("Student / Supervisor\nModel + DB", 3),
             ("Assignment\nService", 4), ("Audit +\nNotification", 5)]

    fh = font(13, True)
    fm = font(13)
    fs = font(12)

    for i, (name, _) in enumerate(heads):
        cx = xs[i]
        lines = name.split("\n")
        wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
        bw = wmax + 28
        bh = 21 * len(lines) + 20
        x0, y0 = cx - bw / 2, top + 8
        d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)], radius=L(8),
                            fill=FILL, outline=BLUE, width=L(2))
        ty = y0 + 9
        for ln in lines:
            d.text((L(cx), L(ty)), ln, font=fh, fill=NAVY, anchor="ma")
            ty += 21
        d.line([L(cx), L(y0 + bh), L(cx), L(bottom)], fill="#9DB4D6", width=L(2))

    for (a, b, label, kind, yy, hh) in layout:
        cxa, cxb = xs[a], xs[b]
        cy = yy + hh / 2
        lines = label.split("\n")

        if kind == "self":
            xr = cxa + 232
            block_h = len(lines) * 18
            half = (block_h + 2 * 9) / 2 + 5
            yt, yb = cy - half, cy + half
            d.line([L(cxa), L(yt), L(xr), L(yt)], fill=BLUE, width=L(2))
            d.line([L(xr), L(yt), L(xr), L(yb)], fill=BLUE, width=L(2))
            d.line([L(xr), L(yb), L(cxa), L(yb)], fill=BLUE, width=L(2))
            d.polygon([(L(cxa), L(yb)), (L(cxa + 14), L(yb - 6)),
                       (L(cxa + 14), L(yb + 6))], fill=BLUE)
            ty = cy - block_h / 2 + 1
            for ln in lines:
                d.text((L(cxa + 34), L(ty)), ln, font=fs, fill=NAVY, anchor="la")
                ty += 18
            continue

        if kind == "return":
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill="#808080", width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)), (L(cxb - 13 * dirn), L(cy - 6)),
                       (L(cxb - 13 * dirn), L(cy + 6))], fill="white",
                      outline="#808080", width=L(2))
            ty = cy - 12 - (len(lines) - 1) * 18 - 13
            mid = (cxa + cxb) / 2
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * 18)), ln, font=fs, fill=GREY, anchor="ma")
            continue

        d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=BLUE, width=L(2))
        dirn = 1 if cxb > cxa else -1
        d.polygon([(L(cxb), L(cy)), (L(cxb - 15 * dirn), L(cy - 6)),
                   (L(cxb - 15 * dirn), L(cy + 6))], fill=BLUE)
        ty = cy - 13 - (len(lines) - 1) * 18 - 13
        mid = (cxa + cxb) / 2
        for j, ln in enumerate(lines):
            d.text((L(mid), L(ty + j * 18)), ln, font=fm, fill=NAVY, anchor="ma")

    # alt-guard failure annotation
    note = ("Guards 1-6 throw InvalidArgumentException -> 422\n"
            "with the specific reason (not a generic error)")
    d.rounded_rectangle([L(20), L(bottom - 46), L(20 + 430), L(bottom - 8)],
                        radius=L(6), fill=RED_F, outline=RED_L, width=L(1.5))
    d.multiline_text((L(34), L(bottom - 40)), note, font=fs, fill=RED_L, spacing=L(3))

    out = os.path.join(OUTDIR, "sequence_pairing.png")
    img.resize((W, H), Image.LANCZOS).save(out)
    return out


if __name__ == "__main__":
    print("wrote", sequence())
