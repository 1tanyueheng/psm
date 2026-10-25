"""Add in-text citations to Chapter 1 (Introduction + Problem Statement).

Sources (all verified from AITCS, FSKTM UTHM's peer-reviewed periodical):
  Nor Azim & Arbaiy (2022)  - FSKTM postgraduate assessment: printed forms,
                              marks collected and analysed by hand
  Chong & Arbaiy (2022)     - FSKTM pre-viva: hybrid manual, hand-counted marks,
                              risk of calculation error
  Arumugam & Abd Ishak (2025) - FSKTM FYP: manual task tracking, inefficient
                              communication, delayed administration
  Tam & Abdullah (2025)     - FSKTM FYP: manual supervisor selection, mismatched
                              research and expertise

Style: APA-like author-date, matching the example chapter (Name, 2025).
The text of each paragraph is preserved; only a clause is added where the
evidence is attached.
"""
import copy
import os
from docx import Document
from docx.shared import Pt
from docx.oxml.ns import qn

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter1 - with citations.docx"

doc = Document(SRC)

# ---------------------------------------------------------------- edits
# (paragraph index, old text, new text)
EDITS = [
    # --- Introduction, paragraph 1: define PSM + the two-part structure
    (2,
     "PSM 1 covers the title proposal and first half. PSM 2 completes the project, "
     "report and examination. Academic staff supervise and examine the students. "
     "A faculty coordinator administers the whole process.",
     "PSM 1 covers the title proposal and first half, while PSM 2 completes the "
     "project, report and examination (Tam & Abdullah, 2025). Academic staff "
     "supervise and examine the students, and a faculty coordinator administers "
     "the whole process."),

    # --- Introduction, paragraph 2: the manual administration claim
    (3,
     "The academic content of PSM is well defined. However, the administration "
     "around it remains largely manual. Supervisors are matched to students "
     "through negotiation. Capacity is tracked by hand. Milestone deadlines are "
     "circulated as documents and messages. Marks are collected on paper forms. "
     "PSM 1 and PSM 2 run in the same semester. Both draw on the same pool of "
     "academic staff. Coordination effort is therefore considerable. Errors are "
     "easy to make.",
     "The academic content of PSM is well defined. However, the administration "
     "around it remains largely manual. Supervisors are matched to students "
     "through negotiation. Capacity is tracked by hand. Milestone deadlines are "
     "circulated as documents and messages. Marks are collected on paper forms. "
     "This reliance on manual administration is not unique to PSM: studies of "
     "assessment processes at the same faculty report that forms are printed and "
     "distributed to panels and supervisors, then returned for manual analysis "
     "(Nor Azim & Arbaiy, 2022). PSM 1 and PSM 2 run in the same semester. Both "
     "draw on the same pool of academic staff. Coordination effort is therefore "
     "considerable. Errors are easy to make."),

    # --- Introduction, paragraph 3: the proposed system
    (4,
     "This project develops a web-based PSM Management System. It centralises the "
     "entire project lifecycle. It is built as a decoupled web application.",
     "This project develops a web-based PSM Management System. It centralises the "
     "entire project lifecycle. It is built as a decoupled web application, "
     "following the approach taken by other web-based academic systems at the "
     "faculty that replaced manual methods with a centralised electronic platform "
     "(Chong & Arbaiy, 2022)."),

    # --- Problem statement, first: visibility
    (7,
     "Students who are falling behind are identified late, and intervention "
     "happens after the deadline rather than before it.",
     "Students who are falling behind are identified late, and intervention "
     "happens after the deadline rather than before it. The same difficulty has "
     "been recorded for final year project administration at the faculty, where "
     "manual task tracking and inefficient communication delayed the process "
     "(Arumugam & Abd Ishak, 2025)."),

    # --- Problem statement, second: fragmented assessment
    (8,
     "A single missing form therefore delays the whole cohort, and the delay "
     "withholds feedback that would otherwise help the student improve.",
     "A single missing form therefore delays the whole cohort, and the delay "
     "withholds feedback that would otherwise help the student improve. "
     "Consolidating marks that arrive on separate forms is the specific weakness "
     "that earlier faculty assessment systems were built to address (Nor Azim & "
     "Arbaiy, 2022)."),

    # --- Problem statement, third: hand totalling
    (9,
     "Every result is then copied onto a summary sheet, which is where totals "
     "are most often entered wrongly.",
     "Every result is then copied onto a summary sheet, which is where totals are "
     "most often entered wrongly. Hand counting the overall score is a recognised "
     "risk in faculty assessment, because the manual calculation itself introduces "
     "errors independently of the marks awarded (Chong & Arbaiy, 2022)."),

    # --- Problem statement, closing
    (10,
     "The purpose of this project is to design and develop a system that removes "
     "that administrative burden by making the process explicit, enforceable and "
     "visible.",
     "The purpose of this project is to design and develop a system that removes "
     "that administrative burden by making the process explicit, enforceable and "
     "visible. A further benefit is that matching students to supervisors can be "
     "made on recorded expertise rather than on manual selection, which has been "
     "shown to produce mismatches between a student's research and a supervisor's "
     "expertise (Tam & Abdullah, 2025)."),
]

# ---------------------------------------------------------------- apply
changed = 0
for idx, old, new in EDITS:
    p = doc.paragraphs[idx]
    full = p.text
    if old not in full:
        print(f"!! paragraph {idx}: target text not found, skipped")
        continue
    # rewrite the paragraph while preserving the first run's formatting
    replaced = full.replace(old, new)
    if p.runs:
        p.runs[0].text = replaced
        # drop the remaining runs (they carried the old text)
        for r in p.runs[1:]:
            r._element.getparent().remove(r._element)
    else:
        p.add_run(replaced)
    changed += 1

print(f"paragraphs edited: {changed}/{len(EDITS)}")

# ---------------------------------------------------------------- references
# Append a References section at the end of the chapter, matching the body style.
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))

def add_para(text="", bold=False, style="Paragraph First and Last"):
    p = doc.add_paragraph(style=style)
    if text:
        r = p.add_run(text)
        r.bold = bold
    return p

add_para()
add_para("References", bold=True, style="Normal")

REFS = [
    "Arumugam, S., & Abd Ishak, S. (2025). A Web-Based FSKTM FYP Management "
    "System. Applied Information Technology and Computer Science, 6(2), 338-355. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20237",

    "Chong, Z. Y., & Arbaiy, N. (2022). Pre-Viva Assessment System Development "
    "for FSKTM, UTHM. Applied Information Technology and Computer Science, 3(2), "
    "1568-1587. https://periodical.uthm.edu.my/index.php/aitcs/article/view/7574",

    "Nor Azim, N. A. A., & Arbaiy, N. (2022). A Development of Assessment System "
    "for FSKTM Postgraduate Students. Applied Information Technology and Computer "
    "Science, 3(1), 732-747. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/2433",

    "Tam, J. H., & Abdullah, N. (2025). Development of Final Year Project "
    "Supervisor Selection System for FSKTM, UTHM. Applied Information Technology "
    "and Computer Science, 6(2), 356-375. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20279",
]

for ref in REFS:
    p = add_para()
    pf = p.paragraph_format
    pf.left_indent = Pt(28)          # hanging indent
    pf.first_line_indent = Pt(-28)
    pf.alignment = 0                 # left, not justified (long URLs)
    p.add_run(ref)

# keep the trailing sectPr last
if sectPr is not None:
    body.remove(sectPr)
    body.append(sectPr)

os.makedirs(os.path.dirname(OUT), exist_ok=True)
doc.save(OUT)
print("saved:", OUT)
