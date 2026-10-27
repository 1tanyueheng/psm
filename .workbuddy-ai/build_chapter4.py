"""Build Chapter 4 - Analysis and Design, in the faculty's own format.

Three sections, as the supplied reference chapter has them:

    4.1  System Requirement Analysis   module table, functional, non-functional
    4.2  System Analysis               use case diagram, then one block per use
                                       case: specification table with History
                                       Log / Version / Actors / Normal Flow /
                                       Alternative Flow / Exceptions / Related
                                       requirement, and its sequence and
                                       activity diagrams INSIDE that block
    4.3  Design                        architecture, database design with a data
                                       dictionary, interface design, class
                                       diagram

The data dictionary is generated from `.workbuddy-ai/schema.json`, which was
dumped from the live PostgreSQL schema, so the types and sizes are what the
database actually holds.

No ER diagram: the user asked for the class diagram and explicitly not the ERD.
"""
import copy
import json
import os
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.section import WD_ORIENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement
from PIL import Image, ImageDraw, ImageFont

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
DIA = os.path.join(BASE, r"thesis\diagrams")
SCHEMA = os.path.join(BASE, r".workbuddy-ai\schema.json")
OUT = os.path.join(BASE, r"thesis\Chapter 4 - Analysis and Design.docx")

TNR = "Times New Roman"

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
FINAL = copy.deepcopy(list(body.iter(qn("w:sectPr")))[-1])
for ch in list(body):
    if ch is not sectPr:
        body.remove(ch)

_state = {"h2": 0, "h3": 0}


def _run(p, text, bold=False, size=12, italic=False):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
    r.italic = italic
    return r


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12,
      space_after=0, italic=False):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(space_after)
    p.alignment = align
    if text:
        _run(p, text, bold=bold, size=size, italic=italic)
    return p


def _numbered(p, num):
    pPr = p._p.get_or_add_pPr()
    numPr = OxmlElement("w:numPr")
    ilvl = OxmlElement("w:ilvl")
    ilvl.set(qn("w:val"), "0")
    numId = OxmlElement("w:numId")
    numId.set(qn("w:val"), "0")
    numPr.append(ilvl)
    numPr.append(numId)
    pPr.insert(0, numPr)
    _run(p, num, bold=True)


def H1(text, num):
    p = doc.add_paragraph(style="Heading 2")
    _numbered(p, f"{num}\t")
    _run(p, text, bold=True)
    _state["h3"] = 0
    pf = p.paragraph_format
    pf.space_before = Pt(18)
    pf.space_after = Pt(10)
    return p


def H2(text, num):
    p = doc.add_paragraph(style="Heading 3")
    pPr = p._p.get_or_add_pPr()
    numPr = OxmlElement("w:numPr")
    ilvl = OxmlElement("w:ilvl")
    ilvl.set(qn("w:val"), "1")
    numId = OxmlElement("w:numId")
    numId.set(qn("w:val"), "0")
    numPr.append(ilvl)
    numPr.append(numId)
    pPr.insert(0, numPr)
    _run(p, f"{num}\t", bold=True)
    _run(p, text, bold=True)
    pf = p.paragraph_format
    pf.space_before = Pt(14)
    pf.space_after = Pt(8)
    return p


def H3(text):
    """The unnumbered '(i) Use Case 1: ...' heading the reference uses."""
    p = doc.add_paragraph(style="Normal")
    p.paragraph_format.space_before = Pt(14)
    p.paragraph_format.space_after = Pt(6)
    p.paragraph_format.keep_with_next = True
    _run(p, text, bold=True, size=12)
    return p


def caption(text, keep=False):
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(8)
    pf.space_after = Pt(10)
    pf.keep_with_next = keep
    _run(p, text, bold=True)
    return p


def _inside(p):
    pf = p.paragraph_format
    pf.space_before = Pt(0)
    pf.space_after = Pt(0)
    pf.line_spacing = 1.0
    wid = OxmlElement("w:widowControl")
    wid.set(qn("w:val"), "0")
    p._p.get_or_add_pPr().append(wid)
    return p


def landscape():
    p = doc.add_paragraph(style="Normal")
    _inside(p)
    pPr = p._p.get_or_add_pPr()
    new = copy.deepcopy(FINAL)
    for tag, attrs in (("w:pgSz", {"w:w": "16838", "w:h": "11906", "w:orient": "landscape"}),
                       ("w:pgMar", {"w:top": "700", "w:bottom": "700",
                                    "w:left": "700", "w:right": "700",
                                    "w:header": "708", "w:footer": "708",
                                    "w:gutter": "0"})):
        el = new.find(qn(tag))
        if el is None:
            el = OxmlElement(tag)
            new.append(el)
        for k, v in attrs.items():
            el.set(qn(k), v)
    pPr.append(new)


def portrait(paragraph):
    pPr = paragraph._p.get_or_add_pPr()
    new = copy.deepcopy(FINAL)
    el = new.find(qn("w:pgSz"))
    el.set(qn("w:w"), "11906")
    el.set(qn("w:h"), "16838")
    if el.get(qn("w:orient")):
        del el.attrib[qn("w:orient")]
    pPr.append(new)


def figure(png, width_cm, cap, land=False):
    """A figure, optionally on its own landscape page, caption beneath it."""
    if land:
        landscape()
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    _inside(p)
    p.add_run().add_picture(os.path.join(DIA, png), width=Cm(width_cm))
    c = doc.add_paragraph(style="Normal")
    c.alignment = WD_ALIGN_PARAGRAPH.CENTER
    _inside(c)
    c.paragraph_format.space_after = Pt(8)
    _run(c, cap, bold=True)
    if land:
        portrait(c)
    return c


def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    tcPr.append(shd)


def table(headers, rows, widths, header_fill="E7E6E6", size=10.5,
          first_col_bold=False):
    t = doc.add_table(rows=0, cols=len(headers))
    t.style = doc.styles["Table Grid"]
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblPr = t._tbl.tblPr
    for dup in tblPr.findall(qn("w:tblLayout")):
        tblPr.remove(dup)
    lay = OxmlElement("w:tblLayout")
    lay.set(qn("w:type"), "fixed")
    tblPr.append(lay)

    hdr = t.add_row().cells
    for i, h in enumerate(headers):
        para = hdr[i].paragraphs[0]
        para.paragraph_format.line_spacing = 1.15
        para.paragraph_format.space_before = Pt(2)
        para.paragraph_format.space_after = Pt(2)
        _run(para, h, bold=True, size=size)
        shade(hdr[i], header_fill)

    for row in rows:
        cells = t.add_row().cells
        for i, val in enumerate(row):
            para = cells[i].paragraphs[0]
            para.paragraph_format.line_spacing = 1.15
            para.paragraph_format.space_before = Pt(2)
            para.paragraph_format.space_after = Pt(2)
            _run(para, str(val), bold=(first_col_bold and i == 0), size=size)

    for i, w in enumerate(widths):
        t.columns[i].width = Cm(w)
        for row in t.rows:
            row.cells[i].width = Cm(w)
    return t


def spec_table(rows, lw=4.2, vw=12.2):
    """The two-column use-case specification the reference chapter uses."""
    t = doc.add_table(rows=0, cols=2)
    t.style = doc.styles["Table Grid"]
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblPr = t._tbl.tblPr
    for dup in tblPr.findall(qn("w:tblLayout")):
        tblPr.remove(dup)
    lay = OxmlElement("w:tblLayout")
    lay.set(qn("w:type"), "fixed")
    tblPr.append(lay)
    for label, value in rows:
        cells = t.add_row().cells
        for i, (txt, bold, fill) in enumerate(((label, True, "E7E6E6"),
                                               (value, False, None))):
            para = cells[i].paragraphs[0]
            para.paragraph_format.line_spacing = 1.15
            para.paragraph_format.space_before = Pt(2)
            para.paragraph_format.space_after = Pt(2)
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT
            _run(para, txt, bold=bold, size=10.5)
            if fill:
                shade(cells[i], fill)
        cells[0].width = Cm(lw)
        cells[1].width = Cm(vw)
    t.columns[0].width = Cm(lw)
    t.columns[1].width = Cm(vw)
    return t


# =====================================================================
#  TITLE
# =====================================================================
for _ in range(2):
    P()
P("CHAPTER 4", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P()
P("ANALYSIS AND DESIGN", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()

# =====================================================================
#  4.1  SYSTEM REQUIREMENT ANALYSIS
# =====================================================================
H1("System Requirement Analysis", "4.1")
P("System requirement analysis is the process of identifying the needs and the "
  "functionalities that the PSM Assessment System has to provide. The system runs the "
  "whole Projek Sarjana Muda lifecycle for PSM 1 and PSM 2 in parallel: title "
  "registration, supervisor pairing, the milestone chain, rubric assessment, the "
  "decision at the end of a semester, and notification. The analysis in this section "
  "covers the system modules, the functional requirements and the non-functional "
  "requirements.")

caption("Table 4.1: System's Modules", keep=True)
table(
    ["No", "Module", "Description"],
    [
        ["1", "Login and Authentication",
         "Authenticates every user with an email address and a password, issues a session "
         "token and sends the user to the dashboard for their role. Locks an account "
         "after repeated failures."],
        ["2", "Title and Registration",
         "Records Lampiran A: the student's particulars, up to three proposed titles and "
         "the supervisor they have approached. The supervisor acknowledges the form and "
         "chooses the agreed title, which also creates the supervision pairing. Lampiran B "
         "then records the project type, field of study and requirements."],
        ["3", "Manage Milestone",
         "Generates the milestone chain for a project and opens it one step at a time. A "
         "student uploads work to the open milestone and a reviewer approves it or sends "
         "it back. Approval is what opens the next milestone."],
        ["4", "Manage Evaluation",
         "Lets an assessor mark the form allocated to them on a 0 to 5 scale, converts "
         "each item score to the rubric's own weight, and publishes the aggregated mark "
         "when the assessor submits."],
        ["5", "Generate Report",
         "Produces cohort figures for a batch, a PSM part and a semester, and exports the "
         "grade or project list as a CSV file. Every export is recorded in the audit log."],
        ["6", "Alert and Notification",
         "Runs the scheduled deadline reminders that go out seven, three and one days "
         "before a deadline, and provides the notification centre where a user reads, "
         "dismisses or changes which types they receive."],
    ],
    [1.6, 4.4, 10.4],
)

P()
H2("Functional and Non-Functional Requirements", "4.1.1")
P("Functional requirements describe what the system should do: the behaviours and "
  "services it must provide. Table 4.2 lists them by module.")
P("Non-functional requirements describe how the system performs: the constraints, "
  "quality attributes and performance characteristics. Table 4.3 lists them.")

caption("Table 4.2: Functional Requirements", keep=True)
table(
    ["No", "Module", "Requirement"],
    [
        ["1", "Login", "- The system shall authenticate a user with a valid email address and password."],
        ["", "", "- The system shall issue a session token and redirect the user to the dashboard for their role."],
        ["", "", "- The system shall refuse an unknown account, a wrong password, a locked account and an inactive account with the same message."],
        ["", "", "- The system shall lock an account after five failed attempts, and increase the lockout on each repeat."],
        ["2", "Registration", "- The system shall offer only the academic sessions that are open for registration."],
        ["", "", "- The system shall refuse a registration when the student already holds a project, or already has an agreement in flight."],
        ["", "", "- The system shall require the supervisor to serve the student's programme, and shall fail open when a programme is not recorded."],
        ["", "", "- The system shall accept only a title that the student proposed, so the agreed title and the title put to the panel are the same string."],
        ["", "", "- The system shall let a student withdraw a filing until a project has been registered from it."],
        ["3", "Milestone", "- The system shall generate the milestone chain when a project is registered, and open one milestone at a time."],
        ["", "", "- The system shall refuse an upload to a milestone the chain has not opened."],
        ["", "", "- The system shall open the next milestone only when the current one is approved."],
        ["", "", "- The system shall accept a late upload but flag it as late to the reviewer."],
        ["4", "Evaluation", "- The system shall present one 0 to 5 score per rubric criterion."],
        ["", "", "- The system shall convert each item score to the criterion's weight and total each Lampiran as the form defines it."],
        ["", "", "- The system shall refuse a mark before the assessment window for that form is open."],
        ["", "", "- The system shall refuse submission while a criterion is unmarked, and shall not treat an unmarked form as zero."],
        ["", "", "- The system shall publish the final mark to the student when the assessor submits."],
        ["5", "Report", "- The system shall produce cohort progress, at-risk students, workload and mark distribution for a batch, part and semester."],
        ["", "", "- The system shall export the grade or project list as a CSV file."],
        ["", "", "- The system shall record every export in the audit log with its filters."],
        ["6", "Notification", "- The system shall send a deadline reminder seven, three and one days before a milestone is due."],
        ["", "", "- The system shall de-duplicate a reminder so a retried run cannot send it twice."],
        ["", "", "- The system shall let a user read, dismiss and change which notification types they receive, except the types the faculty treats as mandatory."],
    ],
    [1.2, 3.6, 11.6],
)

P()
caption("Table 4.3: Non-Functional Requirements", keep=True)
table(
    ["No", "Category", "Requirement"],
    [
        ["1", "Security", "- Every endpoint shall require an authenticated session token, and every permission shall be checked on the server."],
        ["2", "Security", "- A password shall be stored only as a hash, and a failure message shall not reveal whether an account exists."],
        ["3", "Auditability", "- Every state change that affects a grade, a title or a pairing shall be written to the audit log with its actor, time and before/after values."],
        ["4", "Data integrity", "- A mark shall be computed, never typed in as a final result, so the aggregate and the components cannot disagree."],
        ["5", "Performance", "- A list of a full cohort shall load within two seconds, and a 26-project cohort shall be listed without a per-row query."],
        ["6", "Availability", "- Uploaded evidence shall be stored outside the application container so it survives a redeploy."],
        ["7", "Usability", "- Every refusal shall name its cause rather than returning a generic failure."],
        ["8", "Portability", "- The system shall run in a browser with no client installation."],
    ],
    [1.2, 3.6, 11.6],
)

# =====================================================================
#  4.2  SYSTEM ANALYSIS
# =====================================================================
H1("System Analysis", "4.2")
P("The system has six use cases. Three of the actors are operational — the student, "
  "the supervisor and the coordinator — and two are administrative: the lecturer, who "
  "marks coursework for one teaching section, and the administrator, who configures "
  "the system. An examiner is not a role but a seating: a member of staff is put on a "
  "project's panel, and that is what gives them the evaluation form.")

H2("Use Case Diagram", "4.2.1")
P("Figure 4.1 shows the use cases and the actors that reach them. A student reaches "
  "title and registration, milestones and their own marks; a supervisor reaches the "
  "acknowledgement, the milestone review, the evaluation and the reports; a "
  "coordinator reaches the reports and the notifications in addition.")

figure("usecase_diagram.png", 15.0, "Figure 4.1: Use Case Diagram")

H2("Use Case Specification", "4.2.2")
P("This section specifies each use case in turn. Every specification carries its "
  "history log, its actors, its normal flow, its alternative flows and the "
  "requirement it satisfies, and each is followed by the sequence diagram and the "
  "activity diagram for the same use case.")

# ---------------------------------------------------------------- UC-01
H3("(i)  Use Case 1: Login and Authentication")
P("Table 4.4 shows the specification for Login and Authentication. The use case lets "
  "any user prove who they are and reach the screens their role allows.")
caption("Table 4.4: Login and Authentication Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n2.0.0  Add account lockout and the audit entry."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-01"),
    ("Use Case Name", "Login and Authentication"),
    ("Actors", "Student, Supervisor, Lecturer, Coordinator, Admin."),
    ("Description", "The user signs in with an email address and a password. The system "
                    "checks the credentials, and either issues a session token and sends "
                    "the user to the dashboard for their role, or refuses the attempt."),
    ("Preconditions", "The user holds an account, and the account is active."),
    ("Post conditions", "The user is signed in and holds a session token, or remains on "
                        "the login form and the attempt is recorded."),
    ("Normal Flow",
     "1. The user opens the login page.\n"
     "2. The user enters their email address and password.\n"
     "3. The system checks that both fields are present and that the address is well formed.\n"
     "4. The system finds the account and verifies the password against its hash.\n"
     "5. The system confirms that the account is not locked and is active.\n"
     "6. The system creates a session token and resets the failed attempt counter.\n"
     "7. The system records the sign-in in the audit log.\n"
     "8. The system sends the user to the dashboard for their role."),
    ("Alternative Flow",
     "A1. Missing or malformed input: the system returns a validation error and the user "
     "corrects it.\n"
     "A2. Unknown account: the attempt is recorded and the general failure message is shown.\n"
     "A3. Account locked after repeated failures: the attempt is refused until the lock "
     "expires.\n"
     "A4. Wrong password: the counter increases, and the same general message is shown.\n"
     "A5. Account inactive or suspended: the attempt is refused."),
    ("Exceptions", "E.1 The credential check fails for any reason: the system shows the "
                   "same message for an unknown account and a wrong password, so the form "
                   "cannot be used to discover which addresses exist."),
])
P()
figure("sequence_login.png", 16.4, "Figure 4.2: Sequence Diagram for Login and Authentication")
figure("activity_login.png", 16.4, "Figure 4.3: Activity Diagram for Login and Authentication")

# ---------------------------------------------------------------- UC-02
H3("(ii)  Use Case 2: Manage Title and Registration")
P("Table 4.5 specifies Manage Title and Registration. The use case is the only route "
  "by which a title enters the system and the only route by which a supervision is "
  "created: the student files Lampiran A, the supervisor acknowledges it and chooses "
  "which of the proposed titles will be registered, and Lampiran B records the "
  "requirements before the supervisor signs it.")
caption("Table 4.5: Manage Title and Registration Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n"
                    "2.0.0  Lampiran B becomes a record, and a refused title is re-presented."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-02"),
    ("Use Case Name", "Manage Title and Registration"),
    ("Actors", "Student (primary, for Lampiran A and Lampiran B), Supervisor (primary, "
               "for the acknowledgement and the signature). Coordinator and Admin may view and audit."),
    ("Description", "The student registers for PSM 1 by filing Lampiran A, which records "
                    "their particulars, up to three proposed titles and the supervisor they "
                    "have approached. The supervisor acknowledges the agreement and, in "
                    "doing so, chooses which of the three titles the student will register; "
                    "the acknowledgement is also the moment the supervision is created. The "
                    "student then files Lampiran B, which records the project type, the field "
                    "of study and the software, hardware and technology the project requires, "
                    "and the supervisor signs it. Signing opens the proposal milestone."),
    ("Preconditions", "The student is signed in and an academic term is open for "
                      "registration. The student holds no project and no agreement in flight "
                      "for that term. The named supervisor serves the student's programme and "
                      "has capacity for the part."),
    ("Post conditions", "The student holds an approved agreement naming one agreed title, "
                        "a supervision exists, and the proposal milestone is open. The "
                        "project's title is not fixed here: it becomes the project's title "
                        "only when a panel accepts it."),
    ("Normal Flow",
     "1. The student opens Lampiran A and selects the session from the list of open terms.\n"
     "2. The student enters their particulars and proposes up to three titles.\n"
     "3. The student names a supervisor and submits the form.\n"
     "4. The system confirms that a term is open, and that the student holds no project and no agreement.\n"
     "5. The system validates each proposed title.\n"
     "6. The system confirms that the supervisor serves the student's programme.\n"
     "7. The system creates the agreement with the status pending supervisor and notifies the supervisor.\n"
     "8. The supervisor opens the agreement and chooses one of the proposed titles.\n"
     "9. The system confirms the chosen title is one the student proposed.\n"
     "10. The system creates the supervision and records the agreed title.\n"
     "11. The student files Lampiran B.\n"
     "12. The supervisor signs the form, which opens the proposal milestone."),
    ("Alternative Flow",
     "A1. No term is open, a project already exists, or an agreement is already in flight: "
     "the system refuses the filing and names which of the three applies.\n"
     "A2. A proposed title is unusable: the system refuses it and the student corrects it.\n"
     "A3. The supervisor does not serve the student's programme: the submission is refused. "
     "An unrecorded programme is allowed through, so legacy staff rows are not locked out.\n"
     "A4. The supervisor names a title the student did not propose: the system refuses it, "
     "because the acknowledgement is a choice between the candidates.\n"
     "A5. The supervisor sends Lampiran B back instead of signing: the form returns to the "
     "student as a draft with a reason.\n"
     "A6. The student withdraws the filing before a project is registered from it: the "
     "agreement is cancelled, the supervision is retired and the pending form is cleared.\n"
     "A7. A project has already been registered: withdrawal is refused and unwinding it "
     "becomes a coordinator decision."),
    ("Exceptions", "E.1 A title refused by the panel is re-presented on a new Lampiran B, "
                   "which needs a new signature: a signature on one title does not cover "
                   "another. Nothing is overwritten, so the refusal stays on record."),
])
P()
figure("sequence_title.png", 16.4, "Figure 4.4: Sequence Diagram for Manage Title and Registration")
figure("activity_title.png", 16.4, "Figure 4.5: Activity Diagram for Manage Title and Registration")

# ---------------------------------------------------------------- UC-03
H3("(iii)  Use Case 3: Manage Milestone")
P("Table 4.6 specifies Manage Milestone. The rule the use case turns on is that "
  "approving a milestone is what opens the next one, so the chain cannot run ahead "
  "of the work.")
caption("Table 4.6: Manage Milestone Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n"
                    "2.0.0  The supervisor endorses the proposal before the panel sees it."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-03"),
    ("Use Case Name", "Manage Milestone"),
    ("Actors", "Student (submits), Supervisor (reviews), Coordinator and Admin (review, "
               "deadline), Panel (the proposal's verdict)."),
    ("Description", "The system generates a milestone chain for a project and opens its "
                    "milestones in order. A student submits work to the open milestone and a "
                    "reviewer approves it or requests a revision. Approval opens the next "
                    "milestone; a revision request reopens the same one."),
    ("Preconditions", "The project is registered, its chain has been generated, and the "
                      "milestone is open."),
    ("Post conditions", "The milestone is approved and the next one is open, or it has been "
                        "returned to the student with the revision count increased."),
    ("Normal Flow",
     "1. The coordinator registers the project and the chain is generated.\n"
     "2. The first milestone is opened.\n"
     "3. The student uploads the work.\n"
     "4. The system confirms that the chain has opened the milestone.\n"
     "5. The supervisor opens the submitted work.\n"
     "6. The supervisor approves it or requests a revision.\n"
     "7. The system confirms that the actor may review the milestone and that the status allows the move.\n"
     "8. The system saves the status, the reviewer and the comment.\n"
     "9. The system opens the next milestone in the chain.\n"
     "10. The system notifies the student."),
    ("Alternative Flow",
     "A1. The upload reaches a milestone the chain has not opened: the system refuses it.\n"
     "A2. The supervisor requests a revision: the status becomes rejected, the revision "
     "counter increases, and the milestone reopens for a new upload.\n"
     "A3. The supervisor sends the proposal back: the same transition is used, and the "
     "chain does not advance.\n"
     "A4. The milestone is the proposal: approving it does not open the chain, because its "
     "verdict belongs to the panel."),
    ("Exceptions", "E.1 No milestone remains after an approval: the project is recorded as "
                   "complete for that part."),
])
P()
figure("sequence_milestone.png", 16.4, "Figure 4.6: Sequence Diagram for Manage Milestone")
figure("activity_milestone.png", 16.4, "Figure 4.7: Activity Diagram for Manage Milestone")

# ---------------------------------------------------------------- UC-04
H3("(iv)  Use Case 4: Manage Evaluation")
P("Table 4.7 specifies Manage Evaluation. It separates the two things an assessor "
  "does: saving a mark, which is editable and repeatable, and submitting the form, "
  "which is final and publishes the result.")
caption("Table 4.7: Manage Evaluation Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n"
                    "2.0.0  Mark entry moves to the 0-5 scale, and the release step is removed."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-04"),
    ("Use Case Name", "Manage Evaluation"),
    ("Actors", "Assessor, being the supervisor, an examiner or a lecturer (primary); "
               "Coordinator (allocates the forms)."),
    ("Description", "The assessor opens the form allocated to them and marks each "
                    "criterion on a 0 to 5 scale. The system converts each item score to the "
                    "rubric's own weight, so a Lampiran still totals what the form says while "
                    "the marker answers on one scale. When every criterion is marked the "
                    "assessor submits, which locks the form, recomputes the project's "
                    "aggregate and makes the mark visible in the same step."),
    ("Preconditions", "The coordinator has allocated the form to the assessor and has "
                      "opened the assessment window for that form. The milestones the form "
                      "depends on are approved."),
    ("Post conditions", "The form is submitted and locked, the aggregate has been "
                        "recomputed, and the student can see the mark. The item scores, the "
                        "converted marks, the submitter and the time are all retained."),
    ("Normal Flow",
     "1. The assessor opens an allocated form.\n"
     "2. The system loads the rubric and the existing scores.\n"
     "3. The system shows one 0 to 5 select per criterion.\n"
     "4. The assessor chooses a score for each criterion and may add a comment.\n"
     "5. The system confirms that the form is editable and that its own window is open.\n"
     "6. The system converts each item score to the criterion's weight and stores both.\n"
     "7. The assessor submits the form.\n"
     "8. The system confirms that every criterion is marked.\n"
     "9. The system locks the form, recomputes the final mark and publishes it.\n"
     "10. The system notifies the coordinator and the student."),
    ("Alternative Flow",
     "A1. The form's own window is not open: the mark is refused. The window is per form, "
     "so opening Lampiran E does not open Lampiran I.\n"
     "A2. The form is already submitted: it is locked and refuses the change.\n"
     "A3. An item score is outside 0 to 5: it is refused and the assessor corrects it.\n"
     "A4. A criterion is unmarked at submission: the submission is refused and each "
     "unmarked criterion is named.\n"
     "A5. A dependent milestone is not approved: submission is refused.\n"
     "A6. The assessor declares a conflict of interest: the form is marked recused and the "
     "coordinators are notified so a replacement can be allocated."),
    ("Exceptions", "E.1 A student who did not attend is assessed as zero, which the "
                   "assessor types. The system never assumes a zero: an incomplete form is "
                   "refused, and an assessed zero is complete."),
])
P()
figure("sequence_evaluation.png", 16.4, "Figure 4.8: Sequence Diagram for Manage Evaluation")
figure("activity_evaluation.png", 16.4, "Figure 4.9: Activity Diagram for Manage Evaluation")

# ---------------------------------------------------------------- UC-05
H3("(v)  Use Case 5: Generate Report")
P("Table 4.8 specifies Generate Report. Every report is built from the same three "
  "filters — the batch, the PSM part and the academic semester — and produces either "
  "figures for the screen or a CSV file.")
caption("Table 4.8: Generate Report Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n"
                    "2.0.0  Add the CSV export and its audit entry."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-05"),
    ("Use Case Name", "Generate Report"),
    ("Actors", "Coordinator (primary), Admin."),
    ("Description", "The coordinator selects a batch, a PSM part and a semester, and the "
                    "system produces cohort analytics: progress, students at risk, supervisor "
                    "and examiner workload, mark distribution and milestone breakdown. The "
                    "coordinator may also export the grade or project list as a CSV file."),
    ("Preconditions", "The coordinator is signed in and holds the analytics permission. "
                      "Projects and assessments exist for the chosen filters."),
    ("Post conditions", "The coordinator sees the figures for the chosen filters, or "
                        "receives a CSV file. The export is recorded in the audit log. No "
                        "underlying data is modified."),
    ("Normal Flow",
     "1. The coordinator opens the report screen.\n"
     "2. The coordinator chooses the batch, the PSM part and the semester.\n"
     "3. The system confirms that the caller may view cohort analytics.\n"
     "4. The system aggregates progress, the students at risk, workload and the mark bands.\n"
     "5. The system returns the figures and the interface renders them.\n"
     "6. For an export, the system applies the narrower export permission.\n"
     "7. The system records the export and streams the rows.\n"
     "8. The coordinator saves the file."),
    ("Alternative Flow",
     "A1. The caller may not view analytics: the system returns 403 Forbidden.\n"
     "A2. A filter names a semester that does not exist: the filters are rejected.\n"
     "A3. Export is chosen: the export permission is narrower than the analytics "
     "permission, and is limited to coordinators and administrators.\n"
     "A4. No grade has been released: the mark distribution returns empty bands rather "
     "than zero, because no mark is not a mark of zero."),
    ("Exceptions", "E.1 A grade without a final mark is exported with a blank verdict "
                   "rather than as a fail, and is excluded from the displayed pass rate."),
])
P()
figure("sequence_report.png", 16.4, "Figure 4.10: Sequence Diagram for Generate Report")
figure("activity_report.png", 16.4, "Figure 4.11: Activity Diagram for Generate Report")

# ---------------------------------------------------------------- UC-06
H3("(vi)  Use Case 6: Manage Alert and Notification")
P("Table 4.9 specifies Manage Alert and Notification. It has two halves that never "
  "call each other: the scheduled reminder, which the system drives, and the "
  "notification centre, which the user drives.")
caption("Table 4.9: Manage Alert and Notification Use Case Specification", keep=True)
spec_table([
    ("History Log", "1.0.0  Create initial use case.\n"
                    "2.0.0  Add the de-duplication ledger and the preference rules."),
    ("Version", "2.0.0"),
    ("Use Case ID", "UC-06"),
    ("Use Case Name", "Manage Alert and Notification"),
    ("Actors", "System scheduler (primary, for the reminders); every signed-in user "
               "(for the notification centre)."),
    ("Description", "The system sends deadline reminders seven, three and one days before "
                    "a milestone is due, and records each one so a retried run cannot send "
                    "it twice. Every user has a notification centre where they read and "
                    "dismiss items and choose which types they receive."),
    ("Preconditions", "Milestones with deadlines exist for the reminder run. The user is "
                      "signed in for the centre."),
    ("Post conditions", "A reminder has been sent once per recipient per wave, and is "
                        "recorded in the ledger. Changes to preferences are saved."),
    ("Normal Flow",
     "1. The scheduled command runs.\n"
     "2. The system finds the milestones due in seven, three or one days.\n"
     "3. The system checks that each user is active, has not opted out, and has not already been sent this wave.\n"
     "4. The system writes the in-app record and sends the mail.\n"
     "5. The system records the dispatch in the ledger.\n"
     "6. The system moves to the next wave or finishes.\n"
     "7. The user opens the notification centre and reads or dismisses an item.\n"
     "8. The user may change which notification types they receive."),
    ("Alternative Flow",
     "A1. Nothing is due in a wave: the run moves to the next wave.\n"
     "A2. The wave has already been sent to this recipient: it is skipped, which is what "
     "keeps a retried run safe.\n"
     "A3. The user has opted out of that type, or is inactive: nothing is sent.\n"
     "A4. The user turns off a type the faculty treats as mandatory: the change is ignored, "
     "so those notifications always arrive."),
    ("Exceptions", "E.1 A mail transport failure leaves the ledger entry unwritten, so the "
                   "next run retries the same recipient rather than losing the reminder."),
])
P()
figure("sequence_notification.png", 16.4, "Figure 4.12: Sequence Diagram for Manage Alert and Notification")
figure("activity_notification.png", 16.4, "Figure 4.13: Activity Diagram for Manage Alert and Notification")

# =====================================================================
#  4.3  DESIGN
# =====================================================================
H1("Design", "4.3")
P("Once the requirements were analysed, the project moved to design. This section "
  "covers the system architecture, the database design, the interface design and the "
  "class model, so that the structure of the system can be reviewed before it is "
  "described in implementation.")

H2("System Architecture", "4.3.1")
P("The system is a three-tier web application. The browser runs a single-page "
  "application written in React; the server is a Laravel API that holds every rule; "
  "and the data lives in PostgreSQL, with uploaded evidence in object storage. The "
  "client holds no business rule — every permission, every guard and every "
  "calculation is enforced on the server, so a compromised browser cannot grant "
  "itself a mark.")

H2("Database Design", "4.3.2")
P("The database has eighteen tables. The schema is summarised below with the primary "
  "and foreign keys, and the eight core tables are then described in full. A data "
  "dictionary for the remaining ten would repeat the same three columns — an "
  "identifier, a foreign key and a timestamp — without adding to the design.")
P("Schema summary:", italic=True)
for i, line in enumerate([
    "users (PK id)", "student_profiles (PK id, FK user_id)",
    "supervisor_profiles (PK id, FK user_id)", "academic_semesters (PK id)",
    "supervisor_agreements (PK id, FK student_profile_id, FK supervisor_profile_id)",
    "projects (PK id, FK agreement_id, FK academic_semester_id)",
    "lampiran_b_forms (PK id, FK project_id)",
    "milestones (PK id, FK project_id)", "submission_files (PK id, FK milestone_id)",
    "supervision_assignments (PK id, FK student_profile_id, FK supervisor_profile_id)",
    "examiner_assignments (PK id, FK project_id, FK examiner_id)",
    "evaluations (PK id, FK project_id, FK assessor_id)",
    "evaluation_scores (PK id, FK evaluation_id)",
    "mark_submissions (PK id, FK project_id, FK student_profile_id)",
    "final_grades (PK id, FK project_id)",
    "student_enrolments (PK id, FK student_profile_id, FK academic_semester_id)",
    "sections (PK id, FK academic_semester_id)", "lecturer_marks (PK id, FK section_id)",
    "notifications (PK id, FK user_id)", "audit_logs (PK id, FK actor_id)",
], 1):
    P(f"{i}.    {line}")

# ---------------------------------------------------------------- dictionary
schema = json.load(open(SCHEMA, encoding="utf-8"))

DESCRIPTIONS = {
    "users": ("User", "Stores every account: students, supervisors, lecturers, "
                      "coordinators and administrators. The role column decides what a "
                      "session may reach, and the lockout columns implement the "
                      "authentication rule."),
    "projects": ("Project", "One row per registered project. It carries the agreed title, "
                            "the PSM part and the status, and it is the row every other "
                            "record hangs from."),
    "milestones": ("Milestone", "One row per milestone of a project's chain. The attempt "
                                "column lets a milestone code repeat when a title has to be "
                                "re-presented."),
    "evaluations": ("Evaluation", "One row per assessor per project: the form the assessor "
                                  "holds, its rubric snapshot and its status."),
    "evaluation_scores": ("EvaluationScore", "One row per criterion of one evaluation. The "
                                             "marks column holds the weighted mark and "
                                             "item_score holds the 0-5 answer, so the two "
                                             "can always be reconciled."),
    "mark_submissions": ("MarkSubmission", "One row per project, student and part: the unit "
                                           "that says whether every assessor has filed, and "
                                           "whether the mark is locked."),
    "final_grades": ("FinalGrade", "One row per project. The aggregate of every assessor's "
                                   "form, and the number the pass mark is applied to."),
    "student_enrolments": ("StudentEnrolment", "One row per student, part and semester, "
                                                "with the attempt number. This is where a "
                                                "student's term lives."),
}

caption("Table 4.10: Data Dictionary — core tables", keep=True)

# Column descriptions. Generated from the column name where the name says
# enough, and written out by hand where it does not: a data dictionary whose
# Descriptions column is empty is a table of names, which the reader already has.
COLUMN_NOTES = {
    "id": "Unique identifier",
    "user_id": "Links to the users row for this profile",
    "student_profile_id": "Links to the student",
    "supervisor_profile_id": "Links to the supervisor",
    "project_id": "Links to the project",
    "evaluation_id": "Links to the evaluation this score belongs to",
    "agreement_id": "Links to the Lampiran A agreement it was registered from",
    "academic_semester_id": "Links to the term",
    "created_by": "The user who created the row",
    "approved_by": "The user who approved it",
    "name": "Full name",
    "email": "Sign-in address; unique",
    "password": "Password hash, never the password",
    "role": "student, supervisor, lecturer, coordinator or admin",
    "status": "Lifecycle state of the row",
    "programme": "One of BIS, BIM, BIP, BIW, BIT",
    "matric_no": "Student matriculation number",
    "year_of_study": "Year the student is in, per Lampiran A",
    "code": "Short display code, unique within its scope",
    "title": "The title as agreed or proposed",
    "psm_part": "PSM1 or PSM2",
    "attempt": "Which re-presentation of this milestone this is",
    "attempt_no": "Which attempt at the part this enrolment is",
    "revision_no": "Which Lampiran B filing this is",
    "due_at": "Deadline; shared by every student",
    "opens_at": "When the chain opened this milestone",
    "submitted_at": "When the work was handed in",
    "approved_at": "When it was approved",
    "locked_at": "When the mark was locked",
    "locked_until": "Sign-in is refused until this moment",
    "failed_login_attempts": "Consecutive failures; drives the lockout",
    "failed_login_attempts_dummy": "",
    "final_mark": "The aggregate the pass mark is applied to",
    "released_at": "When the mark became visible to the student",
    "is_active": "False once the row is retired",
    "score_percent": "The form's own percentage",
    "marks": "The weighted mark for one criterion",
    "criterion_code": "Which rubric criterion this score is for",
    "rejected_reason": "The panel's reason for refusing the title",
    "capacity_psm1": "PSM 1 supervisees this supervisor will take",
    "capacity_psm2": "PSM 2 supervisees this supervisor will take",
}

for idx, (key, (name, blurb)) in enumerate(DESCRIPTIONS.items()):
    cols = schema["columns"].get(key) or []
    pks = set(schema["primary"].get(key) or [])
    fks = schema["foreign"].get(key) or {}

    H3(f"{name} table")
    P(f"The {name} table, shown in Table 4.{11 + idx}: {blurb}")

    rows = []
    for c in cols:
        if c["name"] in pks:
            k = "Primary"
        elif c["name"] in fks:
            k = "Foreign"
        else:
            k = "None"
        t = (c["type"].replace("CHARACTER VARYING", "VARCHAR")
                       .replace("TIMESTAMP WITHOUT TIME ZONE", "TIMESTAMP")
                       .replace("DOUBLE PRECISION", "NUMERIC")
                       .replace("WITHOUT TIME ZONE", ""))
        if c["name"] in COLUMN_NOTES:
            note = COLUMN_NOTES[c["name"]]
        elif c["name"].endswith("_at"):
            note = "When " + c["name"][:-3].replace("_", " ") + " happened"
        elif c["name"] in fks:
            note = f"Links to {fks[c['name']]}"
        else:
            note = c["name"].replace("_", " ").capitalize()
        rows.append([c["name"], t, c["size"] or "-", k, note])

    caption(f"Table 4.{11 + idx}: {name} table", keep=True)
    table(["Attributes", "Data Type", "Size", "Key", "Descriptions"],
          rows, [4.6, 3.0, 1.4, 2.0, 5.4])

H2("Interface Design", "4.3.3")
P("The following screens carry the six use cases. They are built with the React "
  "single-page application shell, so a user moves between them without a page load, "
  "and each screen shows only the actions the signed-in role may take.")
P("The login screen accepts an email address and a password and, on failure, shows one "
  "message for every cause, so it cannot be used to discover which addresses exist. "
  "The registration screen collects Lampiran A, with the session as a list of the open "
  "terms rather than a free-text field. The milestone screen shows the chain, the work "
  "submitted to the open milestone and the reviewer's decision. The evaluation screen "
  "shows one 0-5 select per rubric criterion with the form's window and status. The "
  "report screen shows the cohort figures with the three filters, and the export "
  "button beside them.")
P("Each screen is captured from the running system for Chapter 5, where the "
  "implementation is described. They are not reproduced here: this chapter is the "
  "design, and the design of a screen is its fields and the rule each field obeys, "
  "which the use-case specifications above already record.", italic=True)

# ------------------------------------------------------------------ interfaces
def screen(name, rows):
    """A captioned screen sketch in the reference chapter's style."""
    w, h = 1500, 900
    im = Image.new("RGB", (w * 2, h * 2), "white")
    dr = ImageDraw.Draw(im)

    def X(v):
        return int(v * 2)

    dr.rectangle([X(6), X(6), X(w - 6), X(h - 6)], outline="#1A1A1A", width=X(2))
    dr.rectangle([X(6), X(6), X(w - 6), X(88)], fill="#F2F2F2", outline="#1A1A1A",
                 width=X(1))
    dr.text((X(34), X(34)), name, font=F_UI, fill="#1A1A1A")
    y = 130
    for label, value in rows:
        dr.text((X(40), X(y)), label, font=F_UI_L, fill="#595959")
        dr.rectangle([X(40), X(y + 34), X(700), X(y + 34 + 62)], outline="#9A9A9A",
                     width=X(2))
        dr.text((X(58), X(y + 48)), value, font=F_UI, fill="#1A1A1A")
        y += 130
    dr.rectangle([X(40), X(y + 20), X(300), X(y + 96)], fill="#1A1A1A")
    dr.text((X(170), X(y + 50)), "Submit", font=F_UI_B, fill="white", anchor="ma")
    im.resize((w, h), Image.LANCZOS).save(os.path.join(DIA, name.replace(" ", "_") + ".png"))
    return name.replace(" ", "_") + ".png"


F_UI = ImageFont.truetype(r"C:\Windows\Fonts\arial.ttf", 30)
F_UI_L = ImageFont.truetype(r"C:\Windows\Fonts\arial.ttf", 24)
F_UI_B = ImageFont.truetype(r"C:\Windows\Fonts\arialbd.ttf", 28)

fig = 15
for label, rows in (
    ("Login", [("Email address", "student@psm.test"), ("Password", "********")]),
    ("Lampiran A - Registration",
     [("Academic session", "2025/2026 Semester II"),
      ("Proposed title 1", "Flood prediction for Batu Pahat"),
      ("Supervisor", "Dr. Tan Wei Ming")]),
    ("Lampiran B - Filed",
     [("Title", "Flood prediction for Batu Pahat"), ("Project type", "System"),
      ("Field of study", "Software Engineering")]),
):
    png = screen(label, rows)
    figure(png, 14.0, f"Figure 4.{fig}: {label}")
    fig += 1

H2("Class Diagram", "4.3.4")
P("Figure 4.14 shows the twelve classes that carry the domain. A User is specialised "
  "into a StudentProfile or a SupervisorProfile. A StudentProfile is enrolled in a "
  "semester and registers one Project; a Project carries a chain of Milestones, the "
  "LampiranBForm filings and its Evaluation forms, and is attached to the "
  "SupervisionAssignment that names who supervises it. Each Evaluation holds one "
  "EvaluationScore per criterion, and those scores aggregate into the single "
  "FinalGrade, which is the number the pass mark is applied to.")
P("The separation that matters most is between Project and Milestone: a project is the "
  "thing that has a title and a supervisor, and its milestones are the evidence that "
  "moves it forward. Nothing about a mark is stored on the project, because the mark is "
  "computed from the forms every time and only the FinalGrade is written.")

figure("class_diagram.png", 16.4, "Figure 4.14: Class Diagram")

# --------------------------------------------------------------------- save
body.remove(sectPr)
body.append(FINAL)
doc.save(OUT)
print("saved:", OUT)
