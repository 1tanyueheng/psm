"""Generate a high-fidelity PNG for a UML sequence diagram and an activity
diagram, then place them in a DOCX.  PIL is used so the result is deterministic
and needs no network or external renderer.
"""
import os, math
from PIL import Image, ImageDraw, ImageFont

OUTDIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams"
os.makedirs(OUTDIR, exist_ok=True)

SCALE = 2  # supersampling for crisp text


def font(size, bold=False):
    cands = [
        r"C:\Windows\Fonts\calibrib.ttf" if bold else r"C:\Windows\Fonts\calibri.ttf",
        r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf",
        r"C:\Windows\Fonts\segoeuib.ttf" if bold else r"C:\Windows\Fonts\segoeui.ttf",
    ]
    for c in cands:
        if os.path.exists(c):
            return ImageFont.truetype(c, size * SCALE)
    return ImageFont.load_default()


def tw(draw, text, f):
    b = draw.textbbox((0, 0), text, font=f)
    return b[2] - b[0], b[3] - b[1]


# =====================================================================
# SEQUENCE DIAGRAM
# =====================================================================
def sequence_diagram():
    actors = [
        ("Student / Admin /\nSupervisor /\nLecturer / Panel /\nCoordinator", 150),
        ("Login UI\n(React SPA)", 380),
        ("AuthController\n(Laravel API)", 640),
        ("User Model\n+ Database", 900),
        ("AuditLogger", 1120),
    ]
    boxes = []
    x_cursor = 40
    for name, _ in actors:
        boxes.append(None)

    # column x centres
    xs = [150, 400, 690, 960, 1210]
    width = 1380
    top = 40
    head_h = 130
    lane_w = 190

    # messages: (from_idx, to_idx, label, kind)
    # kind: call / return / self
    steps = [
        (0, 1, "1. Enter email & password", "call"),
        (1, 2, "2. POST /api/auth/login\n{email, password}", "call"),
        (2, 2, "3. Validate input\n(required, email format,\nlowercase + trim)", "self"),
        (2, 3, "4. Find user by email", "call"),
        (3, 2, "5. user | null", "return"),
        (2, 2, "6. User found?", "self"),
        (2, 4, "7. Log LoginFailed\n(unknown account)", "call"),
        (2, 1, "8. 422 authentication failed", "return"),
        (2, 2, "9. Account locked?\n(locked_until > now)", "self"),
        (2, 1, "10. 422 account temporarily locked", "return"),
        (2, 3, "11. Verify password hash", "call"),
        (3, 2, "12. match | mismatch", "return"),
        (2, 2, "13. Failed attempt count++\n(5 attempts -> lock 15 min,\ndoubling each block)", "self"),
        (2, 4, "14. Log LoginFailed\n(payload on wrong password)", "call"),
        (2, 1, "15. 422 authentication failed", "return"),
        (2, 2, "16. Account active?\n(status = active)", "self"),
        (2, 1, "17. 422 suspended / inactive /\nunverified", "return"),
        (2, 3, "18. Create Sanctum token\n(+ markLoggedIn)", "call"),
        (3, 2, "19. plainTextToken", "return"),
        (2, 4, "20. Log Login (success,\nactor, IP)", "call"),
        (2, 1, "21. 200 OK\n{token, user, home_route}", "return"),
        (1, 0, "22. Redirect to role\ndashboard", "call"),
    ]

    # compute vertical layout
    y = top + head_h + 50
    layout = []
    for (a, b, label, kind) in steps:
        nlines = label.count("\n") + 1
        if kind == "self":
            # the self-call label needs room inside the loop band
            h = max(78, 20 * nlines + 52)
        else:
            h = max(58, 20 * (nlines + 1) + 26)
        layout.append((a, b, label, kind, y, h))
        y += h + 30
    bottom = y + 30
    total_h = bottom + 130

    img = Image.new("RGB", (width * SCALE, total_h * SCALE), "white")
    d = ImageDraw.Draw(img)
    f_head = font(15, True)
    f_msg = font(14)
    f_self = font(13)
    f_lbl = font(13, True)

    def L(v):
        return int(v * SCALE)

    # ---- headers
    for i, (name, _) in enumerate(actors):
        cx = xs[i]
        lines = name.split("\n")
        fh = font(14, True)
        wmax = max(tw(d, ln, fh)[0] for ln in lines)
        bw = wmax + 35
        bh = 24 * len(lines) + 22
        x0 = cx - bw / 2
        y0 = top + 10
        d.rounded_rectangle([L(x0), L(y0), L(x0 + bw), L(y0 + bh)],
                            radius=L(8), fill="#EDF2FA", outline="#2F5597", width=L(2))
        ty = y0 + 10
        for ln in lines:
            d.text((L(cx), L(ty)), ln, font=fh, fill="#1F3864", anchor="ma")
            ty += 24
        # lifeline
        d.line([L(cx), L(y0 + bh), L(cx), L(bottom)], fill="#9DB4D6", width=L(2))

    # ---- messages
    for (a, b, label, kind, yy, hh) in layout:
        cx_a, cx_b = xs[a], xs[b]
        cy = yy + hh / 2

        if kind == "return":
            d.line([L(cx_a), L(cy), L(cx_b), L(cy)], fill="#7F7F7F", width=L(2))
            dirn = 1 if cx_b > cx_a else -1
            d.polygon([(L(cx_b), L(cy)), (L(cx_b - 14 * dirn), L(cy - 7)),
                       (L(cx_b - 14 * dirn), L(cy + 7))], fill="white",
                      outline="#7F7F7F", width=L(2))
            lines = label.split("\n")
            ty = cy - 12 - (len(lines) - 1) * 19 - 14
            midx = (cx_a + cx_b) / 2
            for j, ln in enumerate(lines):
                d.text((L(midx), L(ty + j * 19)), ln, font=f_self,
                       fill="#595959", anchor="ma")
            continue

        if cx_a == cx_b:
            # self call: bracket to the right of the lifeline, label inside it
            x_r = cx_a + 250
            lines = label.split("\n")
            block_h = len(lines) * 19
            pad = 10
            half = (block_h + 2 * pad) / 2 + 6
            y_top = cy - half
            y_bot = cy + half
            d.line([L(cx_a), L(y_top), L(x_r), L(y_top)], fill="#2F5597", width=L(2))
            d.line([L(x_r), L(y_top), L(x_r), L(y_bot)], fill="#2F5597", width=L(2))
            d.line([L(x_r), L(y_bot), L(cx_a), L(y_bot)], fill="#2F5597", width=L(2))
            d.polygon([(L(cx_a), L(y_bot)), (L(cx_a + 15), L(y_bot - 7)),
                       (L(cx_a + 15), L(y_bot + 7))], fill="#2F5597")
            ty = cy - block_h / 2 + 1
            tx = cx_a + 36
            for ln in lines:
                d.text((L(tx), L(ty)), ln, font=f_self, fill="#1F3864", anchor="la")
                ty += 19
            continue

        # solid forward call
        d.line([L(cx_a), L(cy), L(cx_b), L(cy)], fill="#2F5597", width=L(2))
        dirn = 1 if cx_b > cx_a else -1
        d.polygon([(L(cx_b), L(cy)), (L(cx_b - 16 * dirn), L(cy - 7)),
                   (L(cx_b - 16 * dirn), L(cy + 7))], fill="#2F5597")
        lines = label.split("\n")
        midx = (cx_a + cx_b) / 2
        ty = cy - 14 - (len(lines) - 1) * 19 - 14
        for j, ln in enumerate(lines):
            d.text((L(midx), L(ty + j * 19)), ln, font=f_msg,
                   fill="#1F3864", anchor="ma")

    out = os.path.join(OUTDIR, "sequence_login.png")
    img = img.resize((width, total_h), Image.LANCZOS)
    img.save(out)
    return out


if __name__ == "__main__":
    p = sequence_diagram()
    print("wrote", p)
