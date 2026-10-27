"""Generate demo signature images for the seeded Lampiran B forms.

These are **demo artefacts**, not evidence. Each image carries a small
"DEMO" watermark in the corner so a screenshot of a printed form can never be
mistaken for a real supervisor's sign-off. The seeder records
`signature_type = 'seeded'` alongside, so the database says the same thing.

Run: python .workbuddy-ai/make_demo_signatures.py
"""
import os
import random
from PIL import Image, ImageDraw, ImageFont

OUT = os.path.join(
    r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system",
    "backend", "storage", "app", "private", "signatures", "lampiran-b",
)

W, H = 900, 300


def font(size, bold=False):
    for path in (r"C:\Windows\Fonts\segoeuisb.ttf" if bold else r"C:\Windows\Fonts\segoeui.ttf",
                 r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def draw_signature(name: str, seed: int) -> Image.Image:
    """A plausible handwritten-looking signature, deterministic per name."""
    random.seed(seed)

    img = Image.new("RGBA", (W, H), (255, 255, 255, 0))  # transparent
    d = ImageDraw.Draw(img)
    ink = (18, 25, 60, 235)  # dark blue-black biro

    # The signatory's initials, drawn large in an italic face, is what a real
    # signature mostly is: the name's first letters, exaggerated.
    parts = [p for p in name.replace("bin", "").replace("binti", "").split() if p.isalpha()]
    initials = "".join(p[0] for p in parts[:3]).upper() or "PSM"

    f_big = font(150, bold=True)
    f_small = font(34)

    x = 40
    for i, ch in enumerate(initials):
        wobble = random.randint(-8, 8)
        d.text((x, 55 + wobble), ch, font=f_big, fill=ink)
        x += random.randint(95, 125)

        # a joining stroke, so letters read as one flowing hand
        if i < len(initials) - 1:
            d.line([(x - 40, 175), (x - 5, 140 + random.randint(-10, 10))],
                   fill=ink, width=4)

    # a sweeping tail, the thing that makes a scrawl look like a signature
    tail_y = 190
    pts = [(x - 30, tail_y)]
    for i in range(1, 9):
        pts.append((x - 30 + i * 26, tail_y + random.randint(-16, 16)))
    d.line(pts, fill=ink, width=5, joint="curve")

    # A ruled line beneath, as the form has.
    d.line([(30, 235), (W - 30, 235)], fill=(90, 90, 90, 160), width=2)

    # The printed name below the line.
    d.text((40, 248), name, font=f_small, fill=(40, 40, 40, 210))

    # The watermark. Small, out of the way, but legible on a printout.
    d.text((W - 130, 258), "DEMO", font=font(26, bold=True), fill=(200, 30, 30, 150))

    return img


SIGNATORIES = [
    ("Dr. Tan Wei Ming", "tan-wei-ming.png", 11),
    ("Dr. Muhammad Arif bin Zainal", "muhammad-arif.png", 22),
    ("Dr. Chong Mei Ling", "chong-mei-ling.png", 33),
    ("Dr. Faizal bin Ramlee", "faizal-ramlee.png", 44),
    ("Dr. Haziq bin Ramli", "haziq-ramli.png", 55),
    ("Dr. Rajesh Kumar", "rajesh-kumar.png", 66),
    ("Dr. Lim Chee Keong", "keong.png", 77),
    ("Dr. Siti Nurhaliza binti Ali", "siti-nurhaliza.png", 88),
]

os.makedirs(OUT, exist_ok=True)

for name, filename, seed in SIGNATORIES:
    img = draw_signature(name, seed)
    img.save(os.path.join(OUT, filename))
    print("wrote", filename, img.size)

print(f"\n{len(SIGNATORIES)} demo signatures in {OUT}")
