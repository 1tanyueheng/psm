"""Sequence diagram - Manage Alert and Notification (reminder dispatch).

Grounded in SendDeadlineRemindersCommand -> NotificationDispatcher::sendReminder
-> notify -> shouldReceive/channelsFor -> PsmNotification (mail + database)
-> ReminderDispatch ledger.

Uses the shared plain-white sequence style.
"""
import os

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
SCALE = 2

INK = "#1A1A1A"
LINE = "#333333"
GREY = "#595959"

from PIL import Image, ImageDraw, ImageFont


def font(size, bold=False):
    for c in (r"C:\Windows\Fonts\calibri%s.ttf" % ("b" if bold else ""),
              r"C:\Windows\Fonts\arial%s.ttf" % ("bd" if bold else "")):
        if os.path.exists(c):
            return ImageFont.truetype(c, int(size * SCALE))
    return ImageFont.load_default()


def sequence():
    xs = [110, 300, 545, 790, 1015, 1240]
    W = 1400
    top = 30
    head_h = 132

    steps = [
        (0, 1, "1. Scheduler fires the\nreminder command", "call"),
        (1, 1, "2. Read waves\n7, 3, 1 days", "self"),
        (1, 2, "3. Find milestones\ndue in N days", "call"),
        (2, 3, "4. Query milestones\nnot yet settled", "call"),
        (3, 2, "5. milestones", "return"),
        (2, 1, "6. milestones + students\n+ supervisors", "return"),
        (1, 1, "7. Any due?", "self"),
        (1, 1, "8. Build payload\ntitle, body, link, urgent", "self"),
        (1, 2, "9. sendReminder(user,\nmilestone, N, type)", "call"),
        (2, 5, "10. alreadySent()?\nledger lookup", "call"),
        (5, 2, "11. true | false", "return"),
        (2, 2, "12. Already sent?\n-> skip (dedupe)", "self"),
        (2, 4, "13. shouldReceive()\nactive and not opted out", "call"),
        (4, 2, "14. yes | no", "return"),
        (2, 4, "15. channelsFor(type)\nmail, database", "call"),
        (4, 2, "16. channels", "return"),
        (2, 3, "17. notifyNow\nPsmNotification", "call"),
        (3, 3, "18. Store in-app row\n+ send mail", "self"),
        (3, 2, "19. sent", "return"),
        (2, 5, "20. Create ReminderDispatch\n(subject, user, days, type)", "call"),
        (5, 2, "21. recorded", "return"),
        (2, 1, "22. true | false", "return"),
        (1, 1, "23. Final wave only:\nsame for supervisors", "self"),
        (1, 0, "24. Summary printed\n(count per wave)", "call"),
    ]

    y = top + head_h + 46
    layout = []
    for (a, b, label, kind) in steps:
        n = label.count("\n") + 1
        h = max(62, 20 * n + 30) if kind == "self" else max(52, 20 * (n + 1) + 16)
        layout.append((a, b, label, kind, y, h))
        y += h + 20
    bottom = y + 22
    H = bottom + 120

    img = Image.new("RGB", (W * SCALE, H * SCALE), "white")
    d = ImageDraw.Draw(img)

    def L(v):
        return int(round(v * SCALE))

    heads = ["Scheduler", "SendDeadline\nReminders", "Notification\nDispatcher",
             "Notification\n+ Mail", "User model", "Reminder\nLedger"]

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

    out = os.path.join(OUTDIR, "sequence_notification.png")
    img.resize((W, H), Image.LANCZOS).save(out)
    return out


if __name__ == "__main__":
    print("wrote", sequence())
