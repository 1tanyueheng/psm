"""Rewrite Chapter 2 with three DIRECT PSM/FYP management systems.

Systems compared (all FSKTM UTHM, all AITCS):
  1. A Web-Based FSKTM FYP Management System (FFMS) - Arumugam & Abd Ishak (2025)
  2. Development of a Web-Based System for PSM Supervision - Wan Fauzi & Alduais (2025)
  3. Final Year Project Supervisor Selection System for FSKTM, UTHM - Tam & Abdullah (2025)

Style contract (from the supplied example):
  body       Normal, Times New Roman 12pt, justified, 1.5 line spacing
  section    Heading 2, literal "2.x" + tab + bold title (numbering disabled)
  system     bold name paragraph, then "< Name >" marker
  table      Table Grid, grey header, tick/cross
  references italic roman numerals
"""
import copy
import os
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - PSM Assessment System v2.docx"
os.makedirs(os.path.dirname(OUT), exist_ok=True)

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
_orig_sects = [copy.deepcopy(s) for s in body.iter(qn("w:sectPr"))]
FINAL_SECT = _orig_sects[-1]

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


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12, italic=False):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(0)
    p.alignment = align
    if text:
        _run(p, text, bold=bold, italic=italic, size=size)
    return p


def H(text):
    """Section heading: literal 2.x number, list numbering disabled."""
    global _sec_no
    _sec_no += 1
    p = doc.add_paragraph(style="Heading 2")
    pPr = p._p.get_or_add_pPr()
    numPr = OxmlElement("w:numPr")
    ilvl = OxmlElement("w:ilvl")
    ilvl.set(qn("w:val"), "0")
    numId = OxmlElement("w:numId")
    numId.set(qn("w:val"), "0")
    numPr.append(ilvl)
    numPr.append(numId)
    pPr.insert(0, numPr)
    _run(p, f"2.{_sec_no}\t", bold=True)
    _run(p, text, bold=True)
    pf = p.paragraph_format
    pf.space_before = Pt(12)
    pf.space_after = Pt(12)
    return p


# ================================================================ title
P("CHAPTER 2", bold=True)
P()
P()
P("LITERATURE REVIEW", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()

# ---------------------------------------------------------------- 2.1
H("Introduction")
P("This chapter presents the literature review related to the development of the PSM "
  "Assessment System. It discusses the domain case study, the web based approach, three "
  "existing final year project systems and the comparison between the existing systems "
  "and the proposed system to justify the need for the new system.")

# ---------------------------------------------------------------- 2.2
H("Domain Case Study: PSM Assessment System")
P("Projek Sarjana Muda (PSM) is the final year project required of every undergraduate "
  "at the Faculty of Computer Science and Information Technology (FSKTM), Universiti "
  "Tun Hussein Onn Malaysia. PSM runs across two consecutive terms (Tam & Abdullah, "
  "2025). PSM 1 covers the title proposal and the first half of the work, while PSM 2 "
  "completes the project, the report and the examination.")
P("The academic content of PSM is well defined, but the administration around it is "
  "still carried out manually using spreadsheets, shared documents, messaging groups "
  "and printed forms. Supervisors are matched to students through discussion, capacity "
  "is tracked by hand, milestone deadlines are circulated as documents and marks are "
  "collected on paper forms. PSM 1 and PSM 2 also run in the same semester and draw on "
  "the same pool of academic staff, so coordination effort is high and errors are easy "
  "to make.")
P("Manual administration of this kind has been reported to cause time consuming task "
  "tracking, inefficient communication and delays in project administering (Arumugam & "
  "Abd Ishak, 2025), as well as mismatches between student research and supervisor "
  "expertise (Tam & Abdullah, 2025). Therefore, a new system is necessary to centralise "
  "the entire PSM lifecycle. This will improve visibility of progress and remove the "
  "administrative burden produced by manual processes.")

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
  "different permissions over the same PSM record (Arumugam & Abd Ishak, 2025).")

# ---------------------------------------------------------------- 2.4
H("Study of Existing Related Systems")
P("This section reviews three existing systems that manage final year project activity "
  "at FSKTM UTHM, which is the same domain as the proposed system. Each system is "
  "described in terms of its function and its advantages.")

# --- system 1: FFMS
P("A Web-Based FSKTM FYP Management System (FFMS)", bold=True)
P("The FSKTM FYP Management System was developed to streamline final year project "
  "management for final year students at FSKTM, in order to resolve time consuming "
  "manual task tracking, inefficient communication and delays in project administering "
  "(Arumugam & Abd Ishak, 2025). It was developed using the waterfall methodology with "
  "detailed user acceptance testing.")
P("< FFMS >", bold=True)
P("The function of FFMS is to provide real time progress tracking, automated deadline "
  "reminders, role based access for students, supervisors and project coordinators, and "
  "productive reporting mechanisms. The aim is to improve operational efficiency, "
  "enhance interactivity and ensure timely project completion (Arumugam & Abd Ishak, "
  "2025).")
P("The main advantage of FFMS is that it is the closest existing system to the proposed "
  "one, because it already covers progress tracking, reminders and role based access in "
  "the same faculty. However, FFMS stops at tracking and reporting. It does not assess "
  "students, so it has no weighted rubric marking, no gated milestone approval chain "
  "across PSM 1 and PSM 2, and no mechanism to release a result only after every "
  "assessor has submitted.")

# --- system 2: PSM Supervision
P("Development of a Web-Based System for PSM Supervision", bold=True)
P("This system was developed to manage PSM supervision, allowing a student to select a "
  "potential supervisor from the system and to check the availability of that "
  "supervisor (Wan Fauzi & Alduais, 2025). It was developed using the waterfall model "
  "with HTML, CSS, JavaScript and PHP, and was evaluated using test plans for each "
  "module and user acceptance testing.")
P("< PSM Supervision System >", bold=True)
P("The function of the system is to let a supervisor track the progress of the student "
  "through the report submission features in the system. Based on the testing, the "
  "feedback from the target users showed that they were satisfied with the "
  "functionality and the interface (Wan Fauzi & Alduais, 2025).")
P("The main advantage of this system is that it handles the student to supervisor "
  "matching stage that PSM begins with, and its authors name real time chat between "
  "student and supervisor as future work. However, it covers only supervision and "
  "report submission. It does not enforce supervision capacity, does not manage title "
  "registration or panel decisions, and performs no rubric based assessment.")

# --- system 3: Supervisor Selection
P("Final Year Project Supervisor Selection System for FSKTM, UTHM", bold=True)
P("This system was developed because the selection of a suitable supervisor is a "
  "crucial starting point for a final year project, yet FSKTM still relied on manual "
  "selection methods that were time consuming and produced mismatches between student "
  "research and supervisor expertise (Tam & Abdullah, 2025). It was developed using the "
  "agile methodology in a client server structure.")
P("< FYP Supervisor Selection System >", bold=True)
P("The function of the system is to handle the student's application request and the "
  "supervisor's response, while simplifying the process of finding a supervisor. It "
  "also helps the faculty coordinator monitor the latest supervisor and student "
  "matching information (Tam & Abdullah, 2025).")
P("The main advantage of this system is that it makes the matching stage systematic and "
  "gives the coordinator visibility of who is paired with whom. However, it addresses "
  "only one step of the PSM lifecycle. Once a supervisor is selected, the system has "
  "nothing further to offer: it does not track milestones, manage submissions, or "
  "assess students against the official rubrics.")

# ---------------------------------------------------------------- 2.5 proposed
H("Your Proposed System")
P("The proposed system is a web based PSM Assessment System designed to centralise the "
  "entire lifecycle of final year projects for both PSM 1 and PSM 2.")
P("Comparing with the three existing systems, this system extends them rather than "
  "repeating them. It keeps progress tracking, notifications and role based access as "
  "established by FFMS, and it keeps supervision matching as established by the PSM "
  "Supervision System and the Supervisor Selection System. What none of the three "
  "provides is assessment.")
P("The system therefore focuses on three capabilities. First, it performs automated "
  "weighted marking of the five official Lampiran rubrics, converting rubric scores "
  "into marks using the fixed non round weights that are currently calculated by hand. "
  "Second, it builds and gates the milestone chain, so that a milestone cannot open "
  "until the preceding one has been approved. Third, it releases a final result only "
  "after every assessor has submitted, which removes the delay caused when a single "
  "form is missing.")

# ---------------------------------------------------------------- 2.6 comparison
H("Comparison with the Existing Systems")
P("A comparison has been conducted between the existing systems, namely FFMS, the PSM "
  "Supervision System and the Supervisor Selection System, with the proposed system "
  "(Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025; Tam & Abdullah, 2025). The "
  "comparison focuses on the key features related to PSM administration and assessment.")
P("Table 2.1 System's Comparison", bold=True)

ROWS = [
    ["Domain", "FYP management", "PSM supervision", "Supervisor matching", "PSM assessment"],
    ["User Authentication", "✔", "✔", "✔", "✔"],
    ["Role-Based Access", "✔", "✔ but limit", "✔ but limit", "✔"],
    ["Supervisor Selection / Matching", "✖", "✔", "✔", "✔"],
    ["Capacity Enforcement", "✖", "✖", "✖ but limit", "✔"],
    ["Title Registration and Panel Decision", "✖", "✖", "✖", "✔"],
    ["Progress Tracking", "✔", "✔", "✖", "✔"],
    ["Milestone Chain and Gating", "✖", "✖", "✖", "✔"],
    ["File Submission Management", "✔ but limit", "✔ but limit", "✖", "✔"],
    ["Automated Weighted Marking", "✖", "✖", "✖", "✔"],
    ["Result Release after All Assessors", "✖", "✖", "✖", "✔"],
    ["Notifications and Reminders", "✔", "✔ but limit", "✔ but limit", "✔"],
    ["Reporting and Analytics", "✔", "✖", "✔ but limit", "✔"],
    ["User Acceptance Testing", "✔", "✔", "✔", "✔"],
]

t = doc.add_table(rows=1, cols=5)
t.style = doc.styles["Table Grid"]
t.alignment = WD_TABLE_ALIGNMENT.CENTER
tblPr = t._tbl.tblPr
for dup in tblPr.findall(qn("w:tblLayout")):
    tblPr.remove(dup)

WIDTHS = [4.3, 2.7, 2.6, 2.7, 2.6]
tblW = tblPr.find(qn("w:tblW"))
if tblW is None:
    tblW = OxmlElement("w:tblW")
    tblPr.append(tblW)
tblW.set(qn("w:type"), "dxa")
tblW.set(qn("w:w"), str(int(sum(WIDTHS) / 2.54 * 1440)))
layout = OxmlElement("w:tblLayout")
layout.set(qn("w:type"), "fixed")
tblPr.append(layout)

HDR = ["Features", "FFMS", "PSM Supervision", "Supervisor Selection",
       "PSM Assessment System"]


def cell_text(cell, text, bold=False, center=False, bg=None):
    p = cell.paragraphs[0]
    pf = p.paragraph_format
    pf.line_spacing = 1.0
    pf.space_before = Pt(1)
    pf.space_after = Pt(1)
    p.alignment = (WD_ALIGN_PARAGRAPH.CENTER if center else WD_ALIGN_PARAGRAPH.LEFT)
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
P("Based on Table 2.1, all three existing systems manage one part of a final year "
  "project, and each does that part well. FFMS covers tracking and reminders, the PSM "
  "Supervision System covers matching and report submission, and the Supervisor "
  "Selection System covers matching alone. None of them treats the full two part PSM "
  "process, including its assessment, as a single governed record.")
P("The gap is clearest in the last three assessment rows. No existing system performs "
  "automated weighted marking, gates the milestone chain, or releases a result only "
  "after every assessor has submitted. These three capabilities are what the proposed "
  "system adds to the domain.")

# ---------------------------------------------------------------- 2.7 summary
H("Chapter Summary")
P("This chapter discussed the literature review and the analysis of existing systems "
  "related to final year project management at FSKTM UTHM. It covered the domain case "
  "study, the web based approach and three existing systems, namely FFMS, the PSM "
  "Supervision System and the Supervisor Selection System. Each system was analysed in "
  "terms of its function and advantages, and a comparison was conducted to evaluate "
  "their features against the PSM Assessment System.")
P("The existing systems are effective in the parts of the lifecycle they were built for, "
  "but each covers only one stage. None of them performs rubric based weighted marking, "
  "milestone gating or controlled result release. Therefore, the proposed system will "
  "overcome these limitations through a web based platform that treats the whole PSM "
  "process, across both parts, as one governed record.")
P("Chapter 3 will discuss the methodology used in the development of the system, "
  "including the system design, tools, techniques and development approach.")

# ---------------------------------------------------------------- references
P()
P("References", bold=True, size=14)
REFS = [
    "Arumugam, S., & Abd Ishak, S. (2025). A Web-Based FSKTM FYP Management System. "
    "Applied Information Technology and Computer Science, 6(2), 338-355. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20237",
    "CIO Wiki. (2023, August 15). Web Application. CIO Wiki. "
    "https://cio-wiki.org/wiki/Web_Application",
    "National Institute of Standards and Technology. (2022). Role-based access control "
    "(RBAC). Computer Security Resource Center Glossary. "
    "https://csrc.nist.gov/glossary/term/role_based_access_control",
    "Tam, J. H., & Abdullah, N. (2025). Development of Final Year Project Supervisor "
    "Selection System for FSKTM, UTHM. Applied Information Technology and Computer "
    "Science, 6(2), 356-375. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20279",
    "Wan Fauzi, W. N. F., & Alduais, N. A. M. (2025). Development of a Web-Based System "
    "for PSM Supervision. Applied Information Technology and Computer Science, 6(1), "
    "215-231. https://periodical.uthm.edu.my/index.php/aitcs/article/view/16378",
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
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    _run(p, ROMAN[i], italic=True)
    _run(p, ". " + ref)

body.remove(sectPr)
final = copy.deepcopy(FINAL_SECT)
for pg in final.findall(qn("w:pgNumType")):
    final.remove(pg)
body.append(final)

doc.save(OUT)
print("saved:", OUT)
