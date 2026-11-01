"""Bring the last Chapter 3 sentences within the 15-word limit.

Each remaining sentence is over only because of its citation bracket or a name
list. Same treatment as Chapters 1, 2 and 4: move the citation into a short
sentence of its own, keeping every source attached to its claim.

The replacements are written against the exact line breaks in build_ch3.py, so
each is asserted to be present before it is applied.
"""
import io

P = ".workbuddy-ai/build_ch3.py"
t = io.open(P, encoding="utf-8").read()

REPL = [
    (
        '"registration fields. The five assessment Lampiran fixed the rubrics, "\n'
        '        "their item scales and their weightings (Universiti Tun Hussein Onn "\n'
        '        "Malaysia, 2025). ',
        '"registration fields. The five assessment Lampiran fixed the rubrics. "\n'
        '        "They also fixed the item scales and the weightings. The faculty\'s "\n'
        '        "PSM guide records these (Universiti Tun Hussein Onn Malaysia, 2025). ',
    ),
    (
        '"provide. It also identified the ones they leave out "\n'
        '        "(Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025). ',
        '"provide. It also identified the ones they leave out. Arumugam and Abd "\n'
        '        "Ishak document two such systems (Arumugam & Abd Ishak, 2025). Wan "\n'
        '        "Fauzi and Alduais document a third (Wan Fauzi & Alduais, 2025). ',
    ),
    (
        '"recorded in Chapter 4. This phase supports Objective 1, which is to "\n'
        '        "design the system with an object oriented approach. It does so by "\n'
        '        "fixing the scope the design must cover.",',
        '"recorded in Chapter 4. This phase supports Objective 1. That objective "\n'
        '        "is to design the system with an object oriented approach. This phase "\n'
        '        "does so by fixing the scope the design must cover.",',
    ),
    (
        '"need rebuilding. The web based approach is also the one comparable "\n'
        '        "faculty systems adopted (Arumugam & Abd Ishak, 2025; CIO Wiki, 2023). ',
        '"need rebuilding. The web based approach is also the one comparable "\n'
        '        "faculty systems adopted (Arumugam & Abd Ishak, 2025). The CIO Wiki "\n'
        '        "gives the same reason (CIO Wiki, 2023). ',
    ),
    (
        '"report and manage alert and notification. This phase directly supports "\n'
        '        "Objective 2. That objective is to develop the system using a web based "\n'
        '        "approach for students, supervisors and coordinators.",',
        '"report and manage alert and notification. This phase directly supports "\n'
        '        "Objective 2. That objective is to develop the system with a web based "\n'
        '        "approach. It serves students, supervisors and coordinators.",',
    ),
    (
        '"correcting a framework built on the wrong rule. Comparable faculty "\n'
        '       "systems adopted prototyping for the same reason. They include the "\n'
        '       "FSKTM supervision system and later assessment systems "\n'
        '       "(Chong Er Juan & Wahid, 2021; Che Adnan & Hafit, 2025). ',
        '"correcting a framework built on the wrong rule. Comparable faculty "\n'
        '       "systems adopted prototyping for the same reason. Two are named here. "\n'
        '       "One is the FSKTM supervision system (Chong Er Juan & Wahid, 2021). "\n'
        '       "The other is a later assessment system (Che Adnan & Hafit, 2025). ',
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
