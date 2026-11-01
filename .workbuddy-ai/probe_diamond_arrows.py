"""Measure where the "No" arrow starts relative to its diamond.

Reads the rendered PNG and reports, on the row of each horizontal edge, every
run of dark pixels. The diamond's right vertex and the arrow's start should be
one continuous run; a gap between them is the fault the user reported.

Usage:  python probe_diamond_arrows.py <png> <y_row> ...
"""
import sys

from PIL import Image

path = sys.argv[1]
rows = [int(v) for v in sys.argv[2:]]

img = Image.open(path).convert("L")
w, h = img.size
px = img.load()


def runs_on(y):
    runs, start, prev = [], None, None
    for x in range(w):
        if px[x, y] < 110:
            if start is None:
                start = x
            prev = x
        elif start is not None:
            runs.append((start, prev))
            start = None
    if start is not None:
        runs.append((start, prev))
    return runs


for y in rows:
    runs = runs_on(y)
    print(f"y={y}: {len(runs)} run(s)")
    for a, b in runs:
        print(f"    x {a:5} .. {b:5}   width {b - a:4}")
