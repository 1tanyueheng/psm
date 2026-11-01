"""The final three Chapter 3 sentences over the 15-word limit."""
import io

P = ".workbuddy-ai/build_ch3.py"
t = io.open(P, encoding="utf-8").read()

REPL = [
    # 3.2 body — the faculty reports with two citations
    (
        '"The workflow around them exists only in the coordinator\'s practice. The "\n'
        '       "faculty\'s own reports describe the difficulty this causes "\n'
        '       "(Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022). Working assumptions "\n'
        '       "are never written down. ',
        '"The workflow around them exists only in the coordinator\'s practice. The "\n'
        '       "faculty\'s own reports describe the difficulty this causes. Two "\n'
        '       "studies cover it (Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022). "\n'
        '       "Working assumptions are never written down. ',
    ),
    # 3.2.3 — web approach with a long author pair
    (
        '"need rebuilding. The web based approach is also the one comparable "\n'
        '        "faculty systems adopted (Arumugam & Abd Ishak, 2025). The CIO Wiki "\n'
        '        "gives the same reason (CIO Wiki, 2023). ',
        '"need rebuilding. The web based approach is also the one comparable "\n'
        '        "faculty systems adopted. Arumugam and Abd Ishak are the example "\n'
        '        "(Arumugam & Abd Ishak, 2025). The CIO Wiki gives the same reason "\n'
        '        "(CIO Wiki, 2023). ',
    ),
    # 3.2.4 — the rejected-rule sentence with a two-author citation
    (
        '"has to apply it can still reject it. Chong and Wahid report this "\n'
        '        "(Chong Er Juan & Wahid, 2021).",',
        '"has to apply it can still reject it. Chong and Wahid report this "\n'
        '        "outcome (Chong Er Juan & Wahid, 2021).",',
    ),
]

missed = 0
for old, new in REPL:
    if old in t:
        t = t.replace(old, new, 1)
    else:
        missed += 1
        print("NOT FOUND:", old[:75].replace("\n", " "))

io.open(P, "w", encoding="utf-8").write(t)
print(f"applied {len(REPL) - missed} of {len(REPL)}")
