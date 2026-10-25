"""Write CHAPTER 2 - LITERATURE REVIEW, matching the supplied example.

Style contract taken from C:\\Users\\yuehe\\Downloads\\Chapter2.docx:
  body            Normal, Times New Roman 12pt, justified, 1.5 line spacing
  section         Heading 2 (auto-numbered 2.x)
  sub-system      bold Normal paragraph + "< Name >" marker paragraph
  table caption   bold "Table 2.1 ..." above a Table Grid with grey header
  references      numbered i., ii., ... italic Roman numerals
"""
import copy
import os
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - PSM Assessment System (cited).docx"
os.makedirs(os.path.dirname(OUT), exist_ok=True)

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))

# keep the template's own section (portrait, page-number footer, margins)
_orig_sects = [copy.deepcopy(s) for s in body.iter(qn("w:sectPr"))]
FINAL_SECT = _orig_sects[-1]

# ---------------------------------------------------------------- clear body
for child in list(body):
    if child is not sectPr:
        body.remove(child)

TNR = "Times New Roman"
_sec_no = 0


def _run(p, text, bold=False, italic=False, size=12):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
    r.italic = italic
    return r


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12,
      first_indent=None, italic=False):
    """Body paragraph: TNR 12pt, 1.5 spacing, justified."""
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(0)
    p.alignment = align
    if first_indent is not None:
        pf.first_line_indent = first_indent
    if text:
        _run(p, text, bold=bold, italic=italic, size=size)
    return p


def H(text, level=2):
    """Numbered section heading.

    The template's auto numbering is %1.%2 and level 0 (chapter) is never
    incremented in a standalone chapter file, so it would print "1.1".
    The number is therefore written literally and list numbering is switched
    off for the paragraph, which renders identically in Word and LibreOffice.
    """
    global _sec_no
    _sec_no += 1
    p = doc.add_paragraph(style=f"Heading {level}")
    pPr = p._p.get_or_add_pPr()
    numPr = OxmlElement("w:numPr")
    ilvl = OxmlElement("w:ilvl")
    ilvl.set(qn("w:val"), "0")
    numId = OxmlElement("w:numId")
    numId.set(qn("w:val"), "0")          # 0 = no numbering
    numPr.append(ilvl)
    numPr.append(numId)
    pPr.insert(0, numPr)
    _run(p, f"2.{_sec_no}\t", bold=True)
    _run(p, text, bold=True)
    pf = p.paragraph_format
    pf.space_before = Pt(12)
    pf.space_after = Pt(12)
    return p


def caption(text):
    p = P(text, bold=True)
    return p


def restart_chapter_numbering(start_at=2):
    """Make Heading 2 render "2.x" in this standalone chapter file.

    The numbering is %1.%2, and level 0 (the chapter) is never incremented
    because this file has no Heading 1. A startOverride on level 0 fixes it.
    """
    numbering = doc.part.numbering_part.element
    target = None
    for num in numbering.findall(qn("w:num")):
        if num.get(qn("w:numId")) == "3":     # the numId Heading 2 points at
            target = num
            break
    if target is None:
        raise RuntimeError("numId 3 not found")
    for existing in target.findall(qn("w:lvlOverride")):
        target.remove(existing)
    lvl = OxmlElement("w:lvlOverride")
    lvl.set(qn("w:ilvl"), "0")
    start = OxmlElement("w:startOverride")
    start.set(qn("w:val"), str(start_at))
    lvl.append(start)
    target.append(lvl)


# ================================================================ title block
P("CHAPTER 2", bold=True, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
P()
P()
P("LITERATURE REVIEW", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()

# ---------------------------------------------------------------- 2.1
H("Introduction")
P("This chapter presents the literature review related to the development of the "
  "PSM Assessment System. It discusses the domain case study, the web based approach, "
  "existing related systems and the comparison between the existing systems and the "
  "proposed system to justify the need for the new system.")

# ---------------------------------------------------------------- 2.2
H("Domain Case Study: PSM Assessment System")
P("Projek Sarjana Muda (PSM) is the final year project required of every undergraduate "
  "at the Faculty of Computer Science and Information Technology (FSKTM), Universiti "
  "Tun Hussein Onn Malaysia. PSM runs across two consecutive terms (Mohd Najib & Mohd "
  "Nawi, 2026). PSM 1 covers the title proposal and the first half of the work, while "
  "PSM 2 completes the project, the report and the examination.")
P("The academic content of PSM is well defined, but the administration around it is "
  "still carried out manually using spreadsheets, shared documents, messaging groups "
  "and printed forms. Supervisors are matched to students through discussion, capacity "
  "is tracked by hand, milestone deadlines are circulated as documents and marks are "
  "collected on paper forms. PSM 1 and PSM 2 also run in the same semester and draw on "
  "the same pool of academic staff, so coordination effort is high and errors are easy "
  "to make.")
P("Manual administration of this kind has been reported to cause confusion, overlapping "
  "records and delayed confirmations in other faculty processes (Mohd Najib & Mohd "
  "Nawi, 2026), as well as inefficiency and data loss where student activities are "
  "concerned (Hery & Sulaiman, 2026). Therefore, a new system is necessary to "
  "centralise the entire PSM lifecycle. This will improve visibility of progress and "
  "remove the administrative burden produced by manual processes.")

# ---------------------------------------------------------------- 2.3
H("Web Based System")
P("The proposed system is developed as a web based application using modern "
  "technologies such as a stateless application programming interface, a relational "
  "database and a browser based front end. The system adopts an object oriented "
  "approach to ensure modularity and maintainability. A web application is defined as "
  "a software program that runs on a web server and is accessed through a web browser "
  "over the Internet or an intranet, rather than being installed on a local computer "
  "(CIO Wiki, 2023).")
P("The web based approach is chosen because it allows students, supervisors and "
  "coordinators to access the same record anytime and anywhere using only a browser. "
  "Web applications offer advantages of accessibility, easier maintenance and "
  "centralised data, since updates are deployed on the server and are immediately "
  "available to all users (CIO Wiki, 2023). These properties are necessary for an "
  "administrative process that involves several groups of users.")
P("The system also applies role based access control, which is a model for controlling "
  "access to resources where permitted actions are identified with roles rather than "
  "with individual subject identities (NIST, 2022). Role based access is important "
  "because a student, a supervisor, an examiner and a coordinator must each hold "
  "different permissions over the same PSM record (Mohd Najib & Mohd Nawi, 2026).")

# ---------------------------------------------------------------- 2.4
H("Study of Existing Related Systems")
P("This section reviews three web based systems that address administrative problems "
  "closely related to PSM. Each system is described in terms of its function and its "
  "advantages.")

# --- system 1
P("FSKTM Faculty Room Reservation System (BookIT)", bold=True)
P("BookIT is a web based information system developed to replace the manual room "
  "booking process at FSKTM, which caused overlapping reservations and delayed "
  "confirmations (Mohd Najib & Mohd Nawi, 2026). It was developed using the Agile "
  "methodology with HTML, CSS, JavaScript, PHP and MySQL.")
P("< BookIT >", bold=True)
P("The function of BookIT is to allow students, lecturers and administrative staff to "
  "make room reservations through real time availability checking, automated booking "
  "and schedule conflict management. It also provides automated notifications, booking "
  "confirmations and user authentication to protect information integrity (Mohd Najib "
  "& Mohd Nawi, 2026).")
P("The main advantage of BookIT is that it refuses an invalid operation instead of "
  "warning about it, which is the same principle required to stop a supervisor being "
  "assigned beyond capacity. Its second advantage is role based access, since three "
  "different user groups share the same booking record. However, a reservation is a "
  "single step transaction. BookIT does not model a process with several approval "
  "stages, nor does it hold a record that must remain auditable after the process ends.")

# --- system 2  (condensed: three paragraphs)
P("Web-Based UTHM Event Management System (EMS)", bold=True)
P("The UTHM Event Management System was developed to replace the manual handling of "
  "event applications from the approximately sixty registered student clubs and "
  "organisations, which was time consuming and required high effort (Marzele & Abdul "
  "Jalil, 2025). It was built using an object oriented approach and the prototyping "
  "model in PHP over a central MySQL database.")
P("< UTHM EMS >", bold=True)
P("The function of the EMS is to maintain a single database holding all event related "
  "data, so that applications and event records are not scattered across individual "
  "organisers. It supports event application, event data management and fast "
  "information retrieval through a user friendly interface, and the authors report that "
  "it lowers errors caused by human error and is accessible from any location (Marzele "
  "& Abdul Jalil, 2025).")
P("The main advantage of the EMS is its central database, which reduces human error and "
  "makes event data easy to retrieve. However, the EMS manages only a single business "
  "object, the event. It has no concept of a multi stage approval chain, of capacity "
  "limiting across a shared pool of staff, or of assessment against a weighted rubric.")

# --- system 3
P("UTHM Student Representative Council Program Management System", bold=True)
P("This system was developed for the UTHM Student Representative Council (MPP), which "
  "relied on manual processes to manage student activities and suffered inefficiencies, "
  "data loss and organisational delays (Hery & Sulaiman, 2026). It was built with PHP, "
  "MySQL, HTML, CSS and JavaScript.")
P("< MPP Program System >", bold=True)
P("The function of the system is to centralise activity planning, scheduling, execution "
  "tracking and reporting in order to improve coordination between MPP members, "
  "administrators and students (Hery & Sulaiman, 2026).")
P("The main advantage of this system is that it models a complete process rather than a "
  "single object, and it was validated using 43 test cases and User Acceptance Testing "
  "with 13 respondents, achieving 100 percent functional success (Hery & Sulaiman, "
  "2026). However, the authors name notification systems and dynamic role management as "
  "future work, which are exactly the capabilities an administrative process such as "
  "PSM requires.")

# ---------------------------------------------------------------- 2.5 proposed
H("Your Proposed System")
P("The proposed system is a web based PSM Assessment System designed to centralise the "
  "entire lifecycle of final year projects for both PSM 1 and PSM 2.")
P("The system will manage title registration and approval, build and gate the milestone "
  "chain, handle file submissions, assess students using the five official Lampiran "
  "rubrics and release results automatically once every assessor has submitted. It also "
  "provides cohort analytics, workload reports and deadline reminders.")
P("BookIT is reused as a design reference because it enforces constraints that refuse "
  "invalid operations. The existing systems are reused as requirement references because "
  "they show which administrative concerns a single system must cover and which "
  "capabilities they leave unfinished.")

# ---------------------------------------------------------------- 2.6 comparison
H("Comparison with the Existing Systems")
P("A comparison has been conducted between the existing systems, namely BookIT, the "
  "UTHM EMS and the MPP Program Management System, with the proposed system (Mohd Najib "
  "& Mohd Nawi, 2026; Marzele & Abdul Jalil, 2025; Hery & Sulaiman, 2026). The "
  "comparison focuses on the key features related to PSM administration.")
caption("Table 2.1 System's Comparison")

ROWS = [
    ["Purpose", "Room reservation", "Event applications", "Program planning", "Full PSM lifecycle"],
    ["User Authentication", "✔", "✖", "✔", "✔"],
    ["Central Database", "✔", "✔", "✔", "✔"],
    ["Multi-stage Approval Workflow", "✖", "✖", "✖ but limit", "✔"],
    ["Capacity Enforcement", "✔ but limit", "✖", "✖", "✔"],
    ["Title Registration and Panel Decision", "✖", "✖", "✖", "✔"],
    ["Milestone Chain and Gating", "✖", "✖", "✔ but limit", "✔"],
    ["File Submission Management", "✖", "✖", "✖", "✔"],
    ["Automated Weighted Marking", "✖", "✖", "✖", "✔"],
    ["Notifications and Reminders", "✔", "✖", "✖", "✔"],
    ["Reporting and Analytics", "✔ but limit", "✔ but limit", "✔ but limit", "✔"],
    ["User Acceptance Testing", "✔", "✖", "✔", "✔"],
]

t = doc.add_table(rows=1, cols=5)
t.style = doc.styles["Table Grid"]
t.alignment = WD_TABLE_ALIGNMENT.CENTER
tblPr = t._tbl.tblPr
for dup in tblPr.findall(qn("w:tblLayout")):
    tblPr.remove(dup)

WIDTHS = [4.4, 2.7, 2.6, 2.6, 2.6]  # cm, total ~14.9 (portrait text width)
total_tw = int(sum(WIDTHS) / 2.54 * 1440)
tblW = tblPr.find(qn("w:tblW"))
if tblW is None:
    tblW = OxmlElement("w:tblW")
    tblPr.append(tblW)
tblW.set(qn("w:type"), "dxa")
tblW.set(qn("w:w"), str(total_tw))
layout = OxmlElement("w:tblLayout")
layout.set(qn("w:type"), "fixed")
tblPr.append(layout)

HDR = ["Features", "BookIT", "UTHM EMS", "MPP Program System",
       "PSM Assessment System"]


def cell_text(cell, text, bold=False, center=False, bg=None):
    p = cell.paragraphs[0]
    pf = p.paragraph_format
    pf.line_spacing = 1.0
    pf.space_before = Pt(1)
    pf.space_after = Pt(1)
    p.alignment = (WD_ALIGN_PARAGRAPH.CENTER if center
                   else WD_ALIGN_PARAGRAPH.LEFT)
    _run(p, text, bold=bold, size=10)
    if bg:
        tcPr = cell._tc.get_or_add_tcPr()
        shd = OxmlElement("w:shd")
        shd.set(qn("w:val"), "clear")
        shd.set(qn("w:color"), "auto")
        shd.set(qn("w:fill"), bg)
        tcPr.append(shd)


hdr_row = t.rows[0]
trPr = hdr_row._tr.get_or_add_trPr()
th = OxmlElement("w:tblHeader")
th.set(qn("w:val"), "true")
trPr.append(th)
for i, h in enumerate(HDR):
    cell_text(hdr_row.cells[i], h, bold=True, center=True, bg="E7E6E6")

for row in ROWS:
    cells = t.add_row().cells
    for i, val in enumerate(row):
        cell_text(cells[i], val, center=(i > 0))

grid = t._tbl.tblGrid
for i, gc in enumerate(grid.findall(qn("w:gridCol"))):
    gc.set(qn("w:w"), str(int(WIDTHS[i] / 2.54 * 1440)))
for i, w in enumerate(WIDTHS):
    t.columns[i].width = Cm(w)
for r in t.rows:
    for i, w in enumerate(WIDTHS):
        r.cells[i].width = Cm(w)

P()
P("Based on Table 2.1, the existing systems are effective for the single administrative "
  "concern each was built for, but none of them treats a two part academic process as a "
  "single governed record. BookIT is the only system that enforces a constraint, and it "
  "enforces it against time rather than against a person.")
P("The proposed system addresses these limitations by integrating approval workflow, "
  "milestone gating, capacity enforcement and rubric based weighted marking into one "
  "web based platform for both PSM 1 and PSM 2.")

# ---------------------------------------------------------------- 2.7 summary
H("Chapter Summary")
P("This chapter discussed the literature review and the analysis of existing systems "
  "related to PSM administration. It covered the domain case study, the web based "
  "approach and three existing systems, namely BookIT, the UTHM EMS and the MPP "
  "Program Management System. Each system was analysed in terms of its function and "
  "advantages, and a comparison was conducted to evaluate their features against the "
  "PSM Assessment System.")
P("The existing systems are effective in the administrative concerns they were built "
  "for, but they lack a multi stage approval process, capacity enforcement per academic "
  "part and automated weighted marking. Therefore, the proposed system will overcome "
  "these limitations through a web based platform that treats the whole PSM process, "
  "across both parts, as one governed record.")
P("Chapter 3 will discuss the methodology used in the development of the system, "
  "including the system design, tools, techniques and development approach.")

# ---------------------------------------------------------------- references
P()
P("References", bold=True, size=14)
REFS = [
    "CIO Wiki. (2023, August 15). Web Application. CIO Wiki. "
    "https://cio-wiki.org/wiki/Web_Application",
    "Hery, M. I. H., & Sulaiman, R. A. (2026). UTHM Student Representative Council "
    "Program Management System. Applied Information Technology and Computer Science, "
    "7(1), 649-668. https://periodical.uthm.edu.my/index.php/aitcs/article/view/20338",
    "Marzele, M. A. U., & Abdul Jalil, R. (2025). Web-Based UTHM Event Management "
    "System. Applied Information Technology and Computer Science, 6(1), 1782-1799. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/16550",
    "Mohd Najib, M. S. I., & Mohd Nawi, N. (2026). FSKTM Faculty Room Reservation "
    "System. Applied Information Technology and Computer Science, 7(1), 1396-1413. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20330",
    "National Institute of Standards and Technology. (2022). Role-based access control "
    "(RBAC). Computer Security Resource Center Glossary. "
    "https://csrc.nist.gov/glossary/term/role_based_access_control",
    "Universiti Tun Hussein Onn Malaysia. (2025). Applied Information Technology and "
    "Computer Science (AITCS). Retrieved from "
    "https://periodical.uthm.edu.my/index.php/aitcs",
]

ROMAN = ["i", "ii", "iii", "iv", "v", "vi", "vii", "viii", "ix", "x"]
for i, ref in enumerate(REFS):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(0)
    pf.left_indent = Cm(0.75)
    pf.first_line_indent = Cm(-0.75)
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT   # justification stretches the long URLs
    _run(p, ROMAN[i], italic=True)
    _run(p, ". " + ref)

# final section keeps the page-number footer
body.remove(sectPr)
final = copy.deepcopy(FINAL_SECT)
for pg in final.findall(qn("w:pgNumType")):
    final.remove(pg)
body.append(final)

doc.save(OUT)
print("saved:", OUT)
