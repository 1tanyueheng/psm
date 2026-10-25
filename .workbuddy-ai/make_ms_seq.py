"""Sequence diagram for "Manage Milestone" - the review/decision flow.

Grounded in MilestoneController::approve/requestRevision + MilestoneService
::transitionTo/approve/requestRevision/activateNext and MilestoneStatus.

Two outcomes are shown from the same entry point: Approve (which also opens
the next milestone in the chain) and Request Revision (which reopens this one).
"""
import os
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
os.makedirs(OUTDIR, exist_ok=True)
SCALE = 2

NAVY = "#1F3864"
BLUE = "#2F5597"
FILL = "#EDF2FA"
GRN = "#548235"
RED_F = "#FDECEA"
RED_L = "#C00000"
GREY = "#595959"


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibrib.ttf" if bold else r"C:\Windows\Fonts\calibri.ttf",
              r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(c):
            return ImageFont.truetype(c, size * SCALE)
    return ImageFont.load_default()


def sequence():
    xs = [110, 300, 545, 790, 1015, 1215]
    W = 1380
    top = 30
    head_h = 130

    steps = [
        (0, 1, "1. Open milestone\n(sees submitted work)", "call"),
        (1, 2, "2. POST /api/milestones/{id}/approve\n{comment}", "call"),
        (2, 2, "3. Authorise review\n(supervisor / coordinator /\nadmin)", "self"),
        (2, 2, "4. Status progressed?\n(submitted / reviewed /\napproved)", "self"),
        (2, 3, "5. approve() -> guard:\nnot the proposal milestone", "call"),
        (3, 2, "6. ok", "return"),
        (2, 4, "7. transitionTo(Approved)", "call"),
        (4, 4, "8. Legal transition?\n(submitted|reviewed|conditional\n-> approved)", "self"),
        (4, 4, "9. Open DB transaction", "self"),
        (4, 3, "10. UPDATE status + approved_at\n+ reviewed_by + comment", "call"),
        (3, 4, "11. updated", "return"),
        # record event + audit
        (4, 4, "12. Record timeline event", "self"),
        (4, 4, "13. Audit: status change\n(before / after)", "self"),
        # chain activation
        (4, 3, "14. activateNext(project, sequence)", "call"),
        (3, 4, "15. next milestone | none", "return"),
        (4, 4, "16. Next exists?\n(pending / overdue / rejected)", "self"),
        (4, 3, "17. UPDATE next: status=open\n+ opens_at, repair due_at", "call"),
        (3, 4, "18. opened", "return"),
        (4, 4, "19. No next + none outstanding?\n-> project completed", "self"),
        (4, 2, "20. milestone (Approved)", "return"),
        (2, 5, "21. Notify students\n(MilestoneApproved)", "call"),
        (2, 1, "22. 200 OK\n{status, approved_at}", "return"),
        (1, 0, "23. Badge shows Approved;\nnext milestone now open", "call"),
    ]

    y = top + head_h + 46
    layout = []
    for (a, b, label, kind) in steps:
        n = label.count("\n") + 1
        h = max(58, 18 * n + 28) if kind == "self" else max(50, 18 * (n + 1) + 16)
        layout.append((a, b, label, kind, y, h))
        y += h + 19
    bottom = y + 22
    H = bottom + 210

    img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
    d = ImageDraw.Draw(img)

    def L(v):
        return int(round(v * SCALE))

    heads = ["Supervisor /\nCoordinator", "Milestone UI\n(React SPA)",
             "Milestone\nController", "Milestone\nModel + DB",
             "Milestone\nService", "Notification"]

    fh = font(13, True)
    fm = font(13)
    fs = font(12)

    for i, name in enumerate(heads):
        cx = xs[i]
        lines = name.split("\n")
        wmax = max(d.textbbox((0, 0), ln, font=fh)[2] for ln in lines)
        bw = wmax + 26
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
            xr = cxa + 225
            block_h = len(lines) * 18
            half = (block_h + 16) / 2 + 4
            yt, yb = cy - half, cy + half
            d.line([L(cxa), L(yt), L(xr), L(yt)], fill=BLUE, width=L(2))
            d.line([L(xr), L(yt), L(xr), L(yb)], fill=BLUE, width=L(2))
            d.line([L(xr), L(yb), L(cxa), L(yb)], fill=BLUE, width=L(2))
            d.polygon([(L(cxa), L(yb)), (L(cxa + 13), L(yb - 6)),
                       (L(cxa + 13), L(yb + 6))], fill=BLUE)
            ty = cy - block_h / 2 + 1
            for ln in lines:
                d.text((L(cxa + 32), L(ty)), ln, font=fs, fill=NAVY, anchor="la")
                ty += 18
            continue

        if kind == "return":
            d.line([L(cxa), L(cy), L(cxb), L(cy)], fill="#808080", width=L(2))
            dirn = 1 if cxb > cxa else -1
            d.polygon([(L(cxb), L(cy)), (L(cxb - 12 * dirn), L(cy - 6)),
                       (L(cxb - 12 * dirn), L(cy + 6))], fill="white",
                      outline="#808080", width=L(2))
            ty = cy - 11 - (len(lines) - 1) * 18 - 12
            mid = (cxa + cxb) / 2
            for j, ln in enumerate(lines):
                d.text((L(mid), L(ty + j * 18)), ln, font=fs, fill=GREY, anchor="ma")
            continue

        d.line([L(cxa), L(cy), L(cxb), L(cy)], fill=BLUE, width=L(2))
        dirn = 1 if cxb > cxa else -1
        d.polygon([(L(cxb), L(cy)), (L(cxb - 14 * dirn), L(cy - 6)),
                   (L(cxb - 14 * dirn), L(cy + 6))], fill=BLUE)
        ty = cy - 12 - (len(lines) - 1) * 18 - 12
        mid = (cxa + cxb) / 2
        for j, ln in enumerate(lines):
            d.text((L(mid), L(ty + j * 18)), ln, font=fm, fill=NAVY, anchor="ma")

    # notes
    nb = bottom + 18
    d.rounded_rectangle([L(16), L(nb), L(520), L(nb + 34)], radius=L(6),
                        fill=RED_F, outline=RED_L, width=L(1.5))
    d.multiline_text((L(30), L(nb + 6)),
                     "Proposal milestone is refused here (422):\nits verdict is the panel's.",
                     font=fs, fill=RED_L, spacing=L(3))
    d.rounded_rectangle([L(16), L(nb + 44), L(560), L(nb + 84)], radius=L(6),
                        fill="#EAF1DF", outline=GRN, width=L(1.5))
    d.multiline_text((L(30), L(nb + 50)),
                     "Request Revision uses the same transitionTo() but targets\n"
                     "Rejected: revision_count++, notifies the student and\n"
                     "reopens this milestone (it does NOT open the next one).",
                     font=fs, fill=GRN, spacing=L(3))

    out = os.path.join(OUTDIR, "sequence_milestone.png")
    img.resize((W, H), Image.LANCZOS).save(out)
    return out


if __name__ == "__main__":
    print("wrote", sequence())
