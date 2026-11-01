"""Build Chapter 4 - Analysis and Design, in the requested format.

Two sources:

  * **The subtopic list comes from the faculty's own template**,
    `thesis/c4/Chapter4.docx`: System Requirement Analysis (modules, functional,
    non-functional), then User Requirement Analysis (use case diagram, use case
    specification), then the class diagram, then a chapter summary.

  * **The layout of a use case specification comes from the user's example**,
    `Downloads/chapter4part2.docx`. Its tables have four columns â€” label, value,
    then the value merged across the remaining two â€” and the value column carries
    the numbered flow, the alternative flow and the exceptions. Two rows sit at
    the foot of each table for the "Sequence Diagram" and "Activity Diagram",
    so the two figures belong to the specification rather than floating beside
    it.

What the example does **not** carry, and this chapter keeps because the template
asks for it: the `Related requirement` block (ID / Requirement / Priority) at the
foot of each specification, and the database and interface design.

The data dictionary is generated from `.workbuddy-ai/schema.json`, dumped from
the live PostgreSQL schema, so the types and sizes are what the database holds.

No ER diagram: the user asked for the class diagram and explicitly not the ERD.
"""
import copy
import json
import os
import re

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c4\Chapter4.docx"
DIA = os.path.join(BASE, r"thesis\diagrams")
SCHEMA = os.path.join(BASE, r".workbuddy-ai\schema.json")
OUT = os.path.join(BASE, r"thesis\Chapter4 - Analysis and Design.docx")

TNR = "Times New Roman"
DATE_CREATED = "28.10.2025"
DATE_REVISED = "1.11.2025"

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
for ch in list(body):
    if ch is not sectPr:
        body.remove(ch)

_state = {"uc": 0, "figure": 0, "table": 0}

# The template's heading styles carry automatic numbering (numPr), and the
# numbers used here are written into the heading text instead — "4.1" followed
# by a tab, which is how the faculty's chapters are numbered. Both together
# printed "1.1  4.1.1" on one line. The style-level numbering is removed so only
# the literal number shows, and headings are not indented by it either.
for _style in ("Heading 1", "Heading 2", "Heading 3"):
    _pPr = doc.styles[_style].element.find(qn("w:pPr"))
    if _pPr is not None:
        for _num in _pPr.findall(qn("w:numPr")):
            _pPr.remove(_num)


# ---------------------------------------------------------------------------
# Primitives
# ---------------------------------------------------------------------------
def _run(p, text, bold=False, size=12, italic=False):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
    r.italic = italic
    return r


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12,
      space_after=0, italic=False, indent=None):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(space_after)
    if indent is not None:
        pf.left_indent = Cm(indent)
    p.alignment = align
    if text:
        _run(p, text, bold=bold, size=size, italic=italic)
    return p


def H1(text, num=None):
    p = doc.add_heading(level=1)
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    p.paragraph_format.space_before = Pt(18)
    p.paragraph_format.space_after = Pt(8)
    p.paragraph_format.line_spacing = 1.5
    _run(p, f"{num}\t{text}" if num else text, bold=True, size=14)
    return p


def H2(text, num=None):
    p = doc.add_heading(level=2)
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    p.paragraph_format.space_before = Pt(12)
    p.paragraph_format.space_after = Pt(6)
    p.paragraph_format.line_spacing = 1.5
    _run(p, f"{num}\t{text}" if num else text, bold=True, size=13)
    return p


def H3(text):
    p = doc.add_heading(level=3)
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    p.paragraph_format.space_before = Pt(10)
    p.paragraph_format.space_after = Pt(4)
    p.paragraph_format.line_spacing = 1.5
    _run(p, text, bold=True, size=12)
    return p


def caption(text):
    p = doc.add_paragraph(style="Normal")
    p.paragraph_format.space_before = Pt(6)
    p.paragraph_format.space_after = Pt(4)
    p.paragraph_format.line_spacing = 1.0
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    _run(p, text, bold=True, size=11)
    return p


def _shade(cell, fill="D9D9D9"):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    tcPr.append(shd)


def _cell(cell, text, bold=False, size=10.5, align=WD_ALIGN_PARAGRAPH.LEFT):
    cell.text = ""
    p = cell.paragraphs[0]
    p.alignment = align
    pf = p.paragraph_format
    pf.space_before = Pt(2)
    pf.space_after = Pt(2)
    pf.line_spacing = 1.0
    lines = str(text).split("\n")
    for i, line in enumerate(lines):
        if i:
            p = cell.add_paragraph()
            p.alignment = align
            pf = p.paragraph_format
            pf.space_before = Pt(0)
            pf.space_after = Pt(2)
            pf.line_spacing = 1.0
        _run(p, line, bold=bold, size=size)


def _grid(table):
    t = table._tbl
    tblPr = t.tblPr
    borders = OxmlElement("w:tblBorders")
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "single")
        el.set(qn("w:sz"), "4")
        el.set(qn("w:space"), "0")
        el.set(qn("w:color"), "000000")
        borders.append(el)
    tblPr.append(borders)


def _repeat_header(row):
    trPr = row._tr.get_or_add_trPr()
    el = OxmlElement("w:tblHeader")
    el.set(qn("w:val"), "true")
    trPr.append(el)


def table(headers, rows, widths, size=10):
    t = doc.add_table(rows=1, cols=len(headers))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False
    _grid(t)
    hdr = t.rows[0]
    _repeat_header(hdr)
    for i, h in enumerate(headers):
        _cell(hdr.cells[i], h, bold=True, size=size, align=WD_ALIGN_PARAGRAPH.CENTER)
        _shade(hdr.cells[i])
    for r in rows:
        row = t.add_row()
        for i, v in enumerate(r):
            _cell(row.cells[i], v, size=size)
    for i, w in enumerate(widths):
        t.columns[i].width = Cm(w)
        for row in t.rows:
            row.cells[i].width = Cm(w)
    return t


def _keep_with_next(p):
    """Mark a paragraph to stay on the same page as the one after it."""
    pPr = p._p.get_or_add_pPr()
    for existing in pPr.findall(qn("w:keepNext")):
        pPr.remove(existing)
    el = OxmlElement("w:keepNext")
    pPr.insert(0, el)


def figure(png, width_cm, cap):
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(8)
    p.paragraph_format.space_after = Pt(2)
    # A figure and its caption stay together: a caption alone on the next page
    # is the fault the diagram-row orphans already showed once.
    _keep_with_next(p)
    p.add_run().add_picture(os.path.join(DIA, png), width=Cm(width_cm))
    c = doc.add_paragraph(style="Normal")
    c.alignment = WD_ALIGN_PARAGRAPH.CENTER
    c.paragraph_format.space_after = Pt(10)
    c.paragraph_format.line_spacing = 1.0
    _run(c, cap, bold=False, size=11)


# ---------------------------------------------------------------------------
# The use case specification table, in the example's layout
# ---------------------------------------------------------------------------
def spec_table(uc):
    """One specification table: four columns, values merged 1-3.

    The example's shape, kept exactly:
        History Log | 1.0.0 | text (merged across columns 2 and 3)
        ... one row per version
        Version / Use Case ID / Use Case Name
        Created By | name | Updated By | name
        Date Created | date | Last Revision Date | date
        Actors / Description / Preconditions / Post conditions
        Normal Flow / Alternative Flow / Exceptions
        Related requirement | ID | Requirement | Priority
        (Sequence Diagram:) / (Activity Diagram:)
    """
    t = doc.add_table(rows=0, cols=4)
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False
    _grid(t)

    def row(label, value, merge=True, bold_label=True):
        r = t.add_row()
        _cell(r.cells[0], label, bold=bold_label)
        if merge:
            merged = r.cells[1].merge(r.cells[3])
            _cell(merged, value)
        else:
            _cell(r.cells[1], value)
        return r

    # history log: one row per version, value merged
    for version, note in uc["history"]:
        row("History Log", f"{version}\n{note}" if note else version)

    row("Version", uc["version"])
    row("Use Case ID", uc["id"])
    row("Use Case Name", uc["name"])

    r = t.add_row()
    _cell(r.cells[0], "Created By", bold=True)
    _cell(r.cells[1], uc.get("created_by", "Tan Yue Heng"))
    _cell(r.cells[2], "Updated By", bold=True)
    _cell(r.cells[3], uc.get("updated_by", "Tan Yue Heng"))

    r = t.add_row()
    _cell(r.cells[0], "Date Created", bold=True)
    _cell(r.cells[1], DATE_CREATED)
    _cell(r.cells[2], "Last Revision Date", bold=True)
    _cell(r.cells[3], DATE_REVISED)

    for label, key in (
        ("Actors", "actors"),
        ("Description", "description"),
        ("Preconditions", "preconditions"),
        ("Post conditions", "post"),
        ("Normal Flow", "normal"),
        ("Alternative Flow", "alternative"),
        ("Exceptions", "exceptions"),
    ):
        row(label, uc[key])

    # Related requirement block: header then one row per requirement.
    r = t.add_row()
    _cell(r.cells[0], "Related requirement", bold=True)
    for i, h in enumerate(("ID", "Requirement", "Priority")):
        _cell(r.cells[i + 1], h, bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
        _shade(r.cells[i + 1])
    for req_id, req_text, priority in uc["requirements"]:
        r = t.add_row()
        _cell(r.cells[0], "")
        _cell(r.cells[1], req_id)
        _cell(r.cells[2], req_text)
        _cell(r.cells[3], priority)

    # Deliberately no "Sequence Diagram:" / "Activity Diagram:" rows here.
    #
    # The example puts them at the foot of each table, and an earlier draft of
    # this chapter copied that. In Word the table splits across pages, so those
    # two rows were left stranded at the top of a page with both diagrams on the
    # next one — a label pointing at nothing. The diagrams follow immediately
    # beneath the table with captions that name the use case, which says the same
    # thing without a row that can be orphaned.

    for i, w in enumerate((3.4, 2.6, 6.6, 2.4)):
        t.columns[i].width = Cm(w)
        for rr in t.rows:
            try:
                rr.cells[i].width = Cm(w)
            except IndexError:
                pass
    return t


def use_case(uc, seq_png, act_png):
    """A numbered sub-heading, its table, and the two diagrams it names.

    Table and figure numbers continue the chapter-wide sequence started by the
    requirement tables and the use case diagram, so nothing restarts at 4.1.
    """
    _state["uc"] += 1
    H3(f"Use Case {_state['uc']}: {uc['name']}")
    if uc.get("intro"):
        P(uc["intro"])
    _state["table"] += 1
    caption(f"Table 4.{_state['table']}: {uc['name']} Use Case Specification")
    spec_table(uc)
    P()
    _state["figure"] += 1
    figure(seq_png, 15.5, f"Figure 4.{_state['figure']}: Sequence Diagram for {uc['name']}")
    _state["figure"] += 1
    figure(act_png, 15.5, f"Figure 4.{_state['figure']}: Activity Diagram for {uc['name']}")


# ===========================================================================
#  4.1  SYSTEM REQUIREMENT ANALYSIS
# ===========================================================================
H1("System Requirement Analysis", "4.1")
P("System requirement analysis identifies what the PSM Assessment System must do. "
  "The system runs the whole Projek Sarjana Muda lifecycle. It covers PSM 1 and "
  "PSM 2 in parallel. That lifecycle has six parts. These are title registration "
  "and supervisor pairing. They also include the milestone chain and rubric "
  "assessment. The last two are the end-of-semester decision and notification. "
  "The analysis here covers the modules and both kinds of requirement.")
P()

caption("Table 4.1: System's Modules")
table(
    ["No", "Module", "Description"],
    [
        ["1", "Login and Authentication",
         "Authenticates a user with an email address and a password. Issues a session "
         "token and sends the user to the dashboard for their role. Locks an account "
         "after repeated failures."],
        ["2", "Title and Registration",
         "Records Lampiran A: the student's particulars, up to three proposed titles "
         "and the supervisor they approached. The supervisor acknowledges the form "
         "and chooses the agreed title, which also creates the supervision. Lampiran "
         "B then records the project's requirements before the supervisor signs it."],
        ["3", "Manage Milestone",
         "Generates the milestone chain for a project and opens it one step at a time. "
         "A student uploads work to the open milestone and a reviewer approves it or "
         "sends it back. Approval is what opens the next milestone."],
        ["4", "Manage Evaluation",
         "Lets an assessor mark the form allocated to them on a 0 to 5 scale. Converts "
         "each item score to the rubric's own weight, and publishes the aggregated "
         "mark when the assessor submits."],
        ["5", "Generate Report",
         "Produces cohort figures for a batch, a PSM part and a semester. Exports the "
         "grade or project list as a CSV file. Every export is recorded in the audit "
         "log."],
        ["6", "Alert and Notification",
         "Runs the scheduled deadline reminders seven, three and one days before a "
         "deadline. Provides the notification centre where a user reads, dismisses or "
         "changes which types they receive."],
    ],
    widths=(1.2, 4.0, 9.7),
)
P()

H2("Functional Requirement", "4.1.1")
P("Functional requirements describe what the system should do. They name the "
  "behaviours and services the system must provide. Table 4.2 lists them by module.")
P()
caption("Table 4.2: Functional Requirements")
table(
    ["No", "Module", "Requirement"],
    [
        ["1", "Login",
         "- The system shall authenticate a user with a valid email address and password.\n"
         "- The system shall issue a session token and send the user to their role's dashboard.\n"
         "- The system shall refuse an unknown account, a wrong password, a locked account and an inactive account with the same message.\n"
         "- The system shall lock an account after five failed attempts, and increase the lockout on each repeat."],
        ["2", "Registration",
         "- The system shall offer only the academic sessions that are open for registration.\n"
         "- The system shall refuse a registration when the student already holds a project, or an agreement in flight.\n"
         "- The system shall require the supervisor to serve the student's programme, and fail open when a programme is not recorded.\n"
         "- The system shall accept only a title the student proposed, so the agreed title and the title put to the panel are one string.\n"
         "- The system shall let a student withdraw a filing until a project has been registered from it.\n"
         "- The system shall refuse PSM 1 and PSM 2 in the same term for one student."],
        ["3", "Milestone",
         "- The system shall generate the milestone chain when a project is registered, and open one milestone at a time.\n"
         "- The system shall refuse an upload to a milestone the chain has not opened.\n"
         "- The system shall open the next milestone only when the current one is approved.\n"
         "- The system shall accept a late upload but flag it as late to the reviewer.\n"
         "- The system shall require the supervisor to endorse the proposal before the panel decides the title."],
        ["4", "Evaluation",
         "- The system shall present one 0 to 5 score per rubric criterion.\n"
         "- The system shall convert each item score to the criterion's weight and total each Lampiran as the form defines it.\n"
         "- The system shall refuse a mark before the assessment window for that form is open.\n"
         "- The system shall refuse submission while a criterion is unmarked, and shall not treat an unmarked form as zero.\n"
         "- The system shall publish the final mark to the student when the assessor submits."],
        ["5", "Report",
         "- The system shall produce cohort progress, at-risk students, workload and mark distribution for a batch, part and semester.\n"
         "- The system shall export the grade or project list as a CSV file.\n"
         "- The system shall record every export in the audit log with its filters."],
        ["6", "Notification",
         "- The system shall send a deadline reminder seven, three and one days before a milestone is due.\n"
         "- The system shall de-duplicate a reminder so a retried run cannot send it twice.\n"
         "- The system shall let a user read, dismiss and change which notification types they receive, except the mandatory ones."],
    ],
    widths=(1.2, 2.8, 10.9),
)
P()

H2("Non-Functional Requirement", "4.1.2")
P("Non-functional requirements describe how the system performs. They name the "
  "constraints, quality attributes and performance characteristics. Table 4.3 "
  "lists them.")
P()
caption("Table 4.3: Non-Functional Requirements")
table(
    ["No", "Category", "Requirement"],
    [
        ["1", "Security",
         "- Every endpoint shall require an authenticated session token, and every permission shall be checked on the server."],
        ["2", "Security",
         "- A password shall be stored only as a hash, and a failure message shall not reveal whether an account exists."],
        ["3", "Auditability",
         "- Every state change affecting a grade, a title or a pairing shall be written to the audit log with its actor, time and before/after values."],
        ["4", "Data integrity",
         "- A mark shall be computed, never typed in as a final result, so the aggregate and the components cannot disagree."],
        ["5", "Performance",
         "- A list of a full cohort shall load within two seconds, and a cohort shall be listed without a per-row query."],
        ["6", "Availability",
         "- Uploaded evidence shall be stored outside the application container so it survives a redeploy."],
        ["7", "Usability",
         "- Every refusal shall name its cause rather than returning a generic failure."],
        ["8", "Portability",
         "- The system shall run in a browser with no client installation."],
    ],
    widths=(1.2, 3.2, 10.5),
)
P()


# ===========================================================================
#  4.2  USER REQUIREMENT ANALYSIS
# ===========================================================================
H1("User Requirement Analysis", "4.2")
P("User requirement analysis describes what the users need. It also shows how they "
  "reach it. This section presents the use case diagram first. It then specifies "
  "each use case in the required format.")

H2("Use Case Diagram", "4.2.1")
P("Figure 4.1 shows the use cases and the actors that reach them. A student reaches "
  "login, title and registration, milestones, evaluation and reports. A supervisor "
  "reaches login, the acknowledgement, the milestone review and the evaluation. A "
  "lecturer reaches login and coursework entry. A coordinator and an admin reach "
  "every use case. Pairing is not a use case of its own. It is a step inside title "
  "and registration.")
P()
figure("usecase_diagram.png", 15.5, "Figure 4.1: Use Case Diagram")
P()


# ---------------------------------------------------------------------------
#  4.2.2  Use case specification
# ---------------------------------------------------------------------------
H2("Use Case Specification", "4.2.2")
P("This section specifies each use case in turn. Every specification carries its "
  "history log and its actors. It also carries its normal flow, its alternative "
  "flows and its exceptions. Each table ends with the requirements it satisfies. "
  "Both diagrams for the use case follow beneath the table.")

USE_CASES = [
    # ---------------------------------------------------------------- UC-01
    dict(
        id="UC-01",
        name="Login and Authentication",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nAdded account lockout and the audit entry.")],
        actors="Student, Supervisor, Lecturer, Coordinator, Admin.",
        description=("The user signs in with an email address and a password. The system "
                     "checks the credentials and either issues a session token and sends "
                     "the user to the dashboard for their role, or refuses the attempt."),
        preconditions="The user holds an account, and the account is active.",
        post=("The user is signed in and holds a session token, or remains on the login "
              "form and the attempt is recorded."),
        normal=("1.0 User Sign In\n\n"
                "1. The user opens the login page.\n"
                "2. The user enters an email address and a password.\n"
                "3. The system checks that both fields are present and that the address is well formed.\n"
                "4. The system finds the account and verifies the password against its hash.\n"
                "5. The system confirms that the account is not locked and is active.\n"
                "6. The system creates a session token and resets the failed attempt counter.\n"
                "7. The system records the sign-in in the audit log.\n"
                "8. The system sends the user to the dashboard for their role."),
        alternative=("A.1 Missing or malformed input\n\n"
                     "1. The system returns a validation error.\n"
                     "2. The user corrects the entry and resubmits.\n\n"
                     "A.2 Unknown account or wrong password\n\n"
                     "1. The system records the attempt.\n"
                     "2. The system shows the general failure message.\n\n"
                     "A.3 Account locked after repeated failures\n\n"
                     "1. The system refuses the attempt until the lock expires.\n\n"
                     "A.4 Account inactive or suspended\n\n"
                     "1. The system refuses the attempt."),
        exceptions=("E.1 The credential check fails for any reason\n"
                    "The system shows the same message for an unknown account and a wrong "
                    "password, so the form cannot be used to discover which addresses exist."),
        requirements=[
            ("FR01", "The system shall authenticate a user with a valid email address and password.", "Basic"),
            ("FR02", "The system shall issue a session token and send the user to their role's dashboard.", "Basic"),
            ("FR03", "The system shall refuse every failed sign-in with one message, and lock an account after five attempts.", "Basic"),
        ],
        seq="sequence_login.png",
        act="activity_login.png",
    ),
    # ---------------------------------------------------------------- UC-02
    dict(
        id="UC-02",
        name="Manage Title and Registration",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nLampiran B becomes a record, and a refused title is re-presented.")],
        actors="Student (primary), Supervisor (primary). Coordinator and Admin may view and audit.",
        description=("The student registers for PSM 1 by filing Lampiran A. That form "
                     "records their particulars, up to three proposed titles and the "
                     "supervisor they approached. The supervisor acknowledges the agreement "
                     "and chooses which title the student will register. The "
                     "acknowledgement also creates the supervision. The student then files "
                     "Lampiran B, and the supervisor signs it. Signing opens the proposal "
                     "milestone."),
        preconditions=("The student is signed in and an academic term is open for "
                       "registration. The student holds no project and no agreement in "
                       "flight for that term. The named supervisor serves the student's "
                       "programme and has capacity for the part."),
        post=("The student holds an approved agreement naming one agreed title. A "
              "supervision exists, and the proposal milestone is open. The project's title "
              "is not fixed here: it becomes the project's title only when a panel accepts it."),
        normal=("1.0 Manage Title and Registration\n\n"
                "1. The student opens Lampiran A and selects the session from the open terms.\n"
                "2. The student enters their particulars and proposes up to three titles.\n"
                "3. The student names a supervisor and submits the form.\n"
                "4. The system confirms that a term is open, and that the student holds no project and no agreement.\n"
                "5. The system validates each proposed title.\n"
                "6. The system confirms that the supervisor serves the student's programme.\n"
                "7. The system creates the agreement and notifies the supervisor.\n"
                "8. The supervisor opens the agreement and chooses one proposed title.\n"
                "9. The system confirms that the chosen title is one the student proposed.\n"
                "10. The system creates the supervision and records the agreed title.\n"
                "11. The student files Lampiran B.\n"
                "12. The supervisor signs the form, which opens the proposal milestone."),
        alternative=("A.1 No term is open, or a project or agreement already exists\n\n"
                     "1. The system refuses the filing and names which condition applies.\n\n"
                     "A.2 A proposed title is unusable\n\n"
                     "1. The system refuses it and the student corrects it.\n\n"
                     "A.3 The supervisor does not serve the student's programme\n\n"
                     "1. The submission is refused.\n"
                     "2. An unrecorded programme is allowed through, so legacy staff are not locked out.\n\n"
                     "A.4 The supervisor names a title the student did not propose\n\n"
                     "1. The system refuses it, because the acknowledgement is a choice between the candidates.\n\n"
                     "A.5 The supervisor sends Lampiran B back\n\n"
                     "1. The form returns to the student as a draft with a reason.\n\n"
                     "A.6 The student withdraws the filing before a project is registered\n\n"
                     "1. The agreement is cancelled and the supervision is retired.\n"
                     "2. The pending form is cleared."),
        exceptions=("E.1 A title refused by the panel is re-presented on a new Lampiran B\n"
                    "The new form needs a new signature, because a signature on one title "
                    "does not cover another. Nothing is overwritten, so the refusal stays on record."),
        requirements=[
            ("FR04", "The system shall offer only the academic sessions that are open for registration.", "Basic"),
            ("FR05", "The system shall require the supervisor to serve the student's programme, failing open when none is recorded.", "Basic"),
            ("FR06", "The system shall accept only a title the student proposed as the agreed title.", "Basic"),
            ("FR07", "The system shall let a student withdraw a filing until a project is registered from it.", "Average"),
        ],
        seq="sequence_title.png",
        act="activity_title.png",
    ),
    # ---------------------------------------------------------------- UC-03
    dict(
        id="UC-03",
        name="Manage Milestone",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nThe supervisor endorses the proposal before the panel sees it.")],
        actors="Student (submits), Supervisor (reviews), Coordinator and Admin (deadline), Panel (the proposal's verdict).",
        description=("The system generates a milestone chain for a project and opens its "
                     "milestones in order. A student submits work to the open milestone, and "
                     "a reviewer approves it or requests a revision. Approval opens the next "
                     "milestone. A revision request reopens the same one."),
        preconditions="The project is registered, its chain has been generated, and the milestone is open.",
        post=("The milestone is approved and the next one is open, or it has been returned "
              "to the student with the revision count increased."),
        normal=("1.0 Submit and Review a Milestone\n\n"
                "1. The coordinator registers the project and the chain is generated.\n"
                "2. The system opens the first milestone.\n"
                "3. The student uploads the work.\n"
                "4. The system confirms that the chain has opened the milestone.\n"
                "5. The supervisor opens the submitted work.\n"
                "6. The supervisor approves it or requests a revision.\n"
                "7. The system confirms that the actor may review and that the status allows the move.\n"
                "8. The system saves the status, the reviewer and the comment.\n"
                "9. The system opens the next milestone in the chain.\n"
                "10. The system notifies the student."),
        alternative=("A.1 The upload reaches a milestone the chain has not opened\n\n"
                     "1. The system refuses it.\n\n"
                     "A.2 The supervisor requests a revision\n\n"
                     "1. The status becomes rejected and the revision counter increases.\n"
                     "2. The milestone reopens for a new upload.\n\n"
                     "A.3 The supervisor sends the proposal back\n\n"
                     "1. The same transition is used.\n"
                     "2. The chain does not advance.\n\n"
                     "A.4 The milestone is the proposal\n\n"
                     "1. Approving it does not open the chain, because its verdict belongs to the panel."),
        exceptions=("E.1 No milestone remains after an approval\n"
                    "The project is recorded as complete for that part."),
        requirements=[
            ("FR08", "The system shall generate the chain when a project is registered, and open one milestone at a time.", "Basic"),
            ("FR09", "The system shall refuse an upload to a milestone the chain has not opened.", "Basic"),
            ("FR10", "The system shall open the next milestone only when the current one is approved.", "Basic"),
            ("FR11", "The system shall accept a late upload but flag it as late to the reviewer.", "Average"),
        ],
        seq="sequence_milestone.png",
        act="activity_milestone.png",
    ),
    # ---------------------------------------------------------------- UC-04
    dict(
        id="UC-04",
        name="Manage Evaluation",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nMark entry moves to the 0 to 5 scale, and the release step is removed.")],
        actors="Assessor, being the supervisor, an examiner or a lecturer (primary). Coordinator allocates the forms.",
        description=("The assessor opens the form allocated to them and marks each "
                     "criterion on a 0 to 5 scale. The system converts each item score to "
                     "the rubric's own weight, so a Lampiran still totals what the form says "
                     "while the marker answers on one scale. When every criterion is marked "
                     "the assessor submits. That locks the form, recomputes the project's "
                     "aggregate and makes the mark visible in the same step."),
        preconditions=("The coordinator has allocated the form to the assessor and opened "
                       "the assessment window for that form. The milestones the form depends "
                       "on are approved."),
        post=("The form is submitted and locked, and the aggregate has been recomputed. "
              "The student can see the mark. The item scores, the converted marks, the "
              "submitter and the time are all retained."),
        normal=("1.0 Mark and Submit a Form\n\n"
                "1. The assessor opens an allocated form.\n"
                "2. The system loads the rubric and the existing scores.\n"
                "3. The system shows one 0 to 5 select per criterion.\n"
                "4. The assessor chooses a score for each criterion and may add a comment.\n"
                "5. The system confirms that the form is editable and that its window is open.\n"
                "6. The system converts each item score to the criterion's weight and stores both.\n"
                "7. The assessor submits the form.\n"
                "8. The system confirms that every criterion is marked.\n"
                "9. The system locks the form, recomputes the final mark and publishes it.\n"
                "10. The system notifies the coordinator and the student."),
        alternative=("A.1 The form's own window is not open\n\n"
                     "1. The mark is refused. The window is per form, so opening Lampiran E does not open Lampiran I.\n\n"
                     "A.2 The form is already submitted\n\n"
                     "1. It is locked and refuses the change.\n\n"
                     "A.3 An item score is outside 0 to 5\n\n"
                     "1. It is refused and the assessor corrects it.\n\n"
                     "A.4 A criterion is unmarked at submission\n\n"
                     "1. The submission is refused and each unmarked criterion is named.\n\n"
                     "A.5 A dependent milestone is not approved\n\n"
                     "1. The submission is refused.\n\n"
                     "A.6 The assessor declares a conflict of interest\n\n"
                     "1. The form is marked recused and the coordinators are notified, so a replacement can be allocated."),
        exceptions=("E.1 A student who did not attend is assessed as zero\n"
                    "The assessor types that zero. The system never assumes one, because an "
                    "incomplete form is refused while an assessed zero is complete."),
        requirements=[
            ("FR12", "The system shall present one 0 to 5 score per rubric criterion.", "Basic"),
            ("FR13", "The system shall convert each item score to the criterion's weight and total each Lampiran as the form defines it.", "Basic"),
            ("FR14", "The system shall refuse a mark before that form's assessment window is open.", "Basic"),
            ("FR15", "The system shall publish the final mark to the student when the assessor submits.", "Basic"),
        ],
        seq="sequence_evaluation.png",
        act="activity_evaluation.png",
    ),
    # ---------------------------------------------------------------- UC-05
    dict(
        id="UC-05",
        name="Generate Report",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nAdded the CSV export and its audit entry.")],
        actors="Coordinator (primary), Admin.",
        description=("The coordinator selects a batch, a PSM part and a semester. The "
                     "system then produces cohort analytics. These cover progress, students "
                     "at risk, supervisor and examiner workload, mark distribution and the "
                     "milestone breakdown. The coordinator may also export the grade or "
                     "project list as a CSV file."),
        preconditions=("The coordinator is signed in and holds the analytics permission. "
                       "Projects and assessments exist for the chosen filters."),
        post=("The coordinator sees figures for the chosen filters, or receives a CSV "
              "file. The export is recorded in the audit log. No underlying data is modified."),
        normal=("1.0 Generate a Report\n\n"
                "1. The coordinator opens the report screen.\n"
                "2. The coordinator chooses the batch, the PSM part and the semester.\n"
                "3. The system confirms that the caller may view cohort analytics.\n"
                "4. The system aggregates progress, students at risk, workload and mark bands.\n"
                "5. The system returns the figures and the interface renders them.\n"
                "6. For an export, the system applies the narrower export permission.\n"
                "7. The system records the export and streams the rows.\n"
                "8. The coordinator saves the file."),
        alternative=("A.1 The caller may not view analytics\n\n"
                     "1. The system returns 403 Forbidden.\n\n"
                     "A.2 A filter names a semester that does not exist\n\n"
                     "1. The filters are rejected.\n\n"
                     "A.3 The coordinator chooses an export\n\n"
                     "1. The export permission is narrower, and is limited to coordinators and administrators.\n\n"
                     "A.4 No grade has been released\n\n"
                     "1. The mark distribution returns empty bands rather than zero, because no mark is not a mark of zero."),
        exceptions=("E.1 A grade without a final mark is exported\n"
                    "It carries a blank verdict rather than a fail, and is excluded from "
                    "the displayed pass rate."),
        requirements=[
            ("FR16", "The system shall produce cohort progress, at-risk students, workload and mark distribution.", "Basic"),
            ("FR17", "The system shall export the grade or project list as a CSV file.", "Basic"),
            ("FR18", "The system shall record every export in the audit log with its filters.", "Average"),
        ],
        seq="sequence_report.png",
        act="activity_report.png",
    ),
    # ---------------------------------------------------------------- UC-06
    dict(
        id="UC-06",
        name="Manage Alert and Notification",
        version="2.0.0",
        history=[("1.0.0", "Create initial use case."),
                 ("2.0.0", "Added history log.\nAdded the de-duplication ledger and the preference rules.")],
        actors="System scheduler (primary, for the reminders). Every signed-in user (for the notification centre).",
        description=("The system sends deadline reminders seven, three and one days before "
                     "a milestone is due. It records each one so a retried run cannot send it "
                     "twice. Every user has a notification centre where they read and dismiss "
                     "items and choose which types they receive."),
        preconditions="Milestones with deadlines exist for the reminder run. The user is signed in for the centre.",
        post=("A reminder has been sent once per recipient per wave, and is recorded in "
              "the ledger. Changes to preferences are saved."),
        normal=("1.0 Send a Deadline Reminder\n\n"
                "1. The scheduled command runs.\n"
                "2. The system finds the milestones due in seven, three or one days.\n"
                "3. The system checks that each user is active, has not opted out, and has not already been sent this wave.\n"
                "4. The system writes the in-app record and sends the mail.\n"
                "5. The system records the dispatch in the ledger.\n"
                "6. The system moves to the next wave or finishes.\n\n"
                "2.0 Use the Notification Centre\n\n"
                "1. The user opens the notification centre.\n"
                "2. The user reads or dismisses an item.\n"
                "3. The user may change which notification types they receive."),
        alternative=("A.1 Nothing is due in a wave\n\n"
                     "1. The run moves to the next wave.\n\n"
                     "A.2 The wave was already sent to this recipient\n\n"
                     "1. It is skipped, which is what keeps a retried run safe.\n\n"
                     "A.3 The user opted out of that type, or is inactive\n\n"
                     "1. Nothing is sent.\n\n"
                     "A.4 The user turns off a type the faculty treats as mandatory\n\n"
                     "1. The change is ignored, so those notifications always arrive."),
        exceptions=("E.1 A mail transport failure occurs\n"
                    "The ledger entry stays unwritten, so the next run retries the same "
                    "recipient rather than losing the reminder."),
        requirements=[
            ("FR19", "The system shall send a deadline reminder seven, three and one days before a milestone is due.", "Basic"),
            ("FR20", "The system shall de-duplicate a reminder so a retried run cannot send it twice.", "Basic"),
            ("FR21", "The system shall let a user read, dismiss and choose notification types, except mandatory ones.", "Average"),
        ],
        seq="sequence_notification.png",
        act="activity_notification.png",
    ),
]

for uc in USE_CASES:
    P()
    use_case(uc, uc["seq"], uc["act"])



# ===========================================================================
#  4.2.3  CLASS DIAGRAM
# ===========================================================================
H2("Class Diagram", "4.2.3")
P("The class diagram shows the domain classes and their associations. Twelve "
  "classes carry the lifecycle. A project holds milestones and members. A milestone "
  "holds submissions and its review. A project holds a final grade and a set of "
  "mark submissions. Behind these sit four records. These are the Lampiran B "
  "filings, the enrolments, the supervisions and the panel seats.")
P()
figure("class_diagram.png", 15.5, "Figure 4.2: Class Diagram")
P()


# ===========================================================================
#  4.3  DATABASE DESIGN
# ===========================================================================
H1("Database Design", "4.3")
P("The database is PostgreSQL. It holds one schema for the whole lifecycle. Every "
  "rule the analysis describes is enforced here too. A rule that can be a "
  "constraint is written as one. An application bug cannot then write a state the "
  "analysis forbids. The data dictionary below is generated from the live schema. "
  "Its types and sizes are therefore what the database holds.")

H2("Schema Summary", "4.3.1")
P("Table 4.10 lists the core tables and what each one is for.")
P()
caption("Table 4.10: Core Tables")
table(
    ["No", "Table", "Purpose"],
    [
        ["1", "users", "One row per account, with the role that decides what the account may reach."],
        ["2", "student_profiles", "A student's particulars: matric number, programme, batch and the term of their intake."],
        ["3", "supervisor_profiles", "An academic's staff number, programme ownership and supervision capacity."],
        ["4", "projects", "The project itself, including its PSM part and the title a panel has accepted."],
        ["5", "supervisor_agreements", "Lampiran A: what the student filed, and the supervisor's acknowledgement of it."],
        ["6", "lampiran_b_forms", "Every Lampiran B filing, revision by revision, with the panel's reason for any refusal."],
        ["7", "milestones", "One row per step of a project's chain, including its attempt number and its status."],
        ["8", "student_enrolments", "A student's place in one PSM part for one term. This is where a student's term lives."],
    ],
    widths=(1.2, 4.2, 9.5),
)
P()

EN = {
    "AUTO": "auto incrementing identifier",
    "reference": "foreign key",
}

DESC = {
    "users": {
        "id": "Primary key",
        "name": "Full name",
        "email": "Sign-in address, unique",
        "role": "student, supervisor, lecturer, coordinator or admin",
        "status": "active, inactive, suspended or pending",
        "password": "Bcrypt hash; never the plain text",
        "must_change_password": "Forces a password change on next sign-in",
        "last_login_at": "Last successful sign-in",
        "failed_login_attempts": "Consecutive failures, reset on success",
        "locked_until": "Lockout expiry after repeated failures",
    },
    "student_profiles": {
        "id": "Primary key",
        "user_id": "The account this profile belongs to",
        "student_id": "Matric number, unique",
        "program_code": "BIS, BIM, BIP, BIW or BIT",
        "batch": "Cohort year",
        "academic_semester_id": "The term of the intake",
        "faculty": "Always FSKTM",
    },
    "supervisor_profiles": {
        "id": "Primary key",
        "user_id": "The account this profile belongs to",
        "staff_no": "Staff number, unique",
        "programme": "The programme this academic may supervise and examine",
        "max_supervisees": "Overall supervision capacity",
        "max_supervisees_psm1": "PSM 1 capacity, overriding the overall figure",
        "max_supervisees_psm2": "PSM 2 capacity, overriding the overall figure",
        "is_accepting_students": "Whether new students may be allocated",
    },
    "projects": {
        "id": "Primary key",
        "code": "Human-readable project code",
        "title": "The last title a panel accepted",
        "psm_part": "PSM1 or PSM2",
        "category": "The chain template this project follows",
        "status": "Lifecycle status, derived at term close",
        "academic_semester_id": "The term the project runs in",
        "student_profile_id": "The student who leads it",
        "archived_at": "Retired project marker",
    },
    "supervisor_agreements": {
        "id": "Primary key",
        "student_profile_id": "The student filing Lampiran A",
        "supervisor_profile_id": "The supervisor approached",
        "academic_semester_id": "The term registered for",
        "psm_part": "The part registered for",
        "status": "pending, acknowledged, withdrawn or registered",
        "agreed_title": "The title the supervisor chose from the proposals",
        "acknowledged_at": "When the supervisor acknowledged",
    },
    "lampiran_b_forms": {
        "id": "Primary key",
        "project_id": "The project this filing belongs to",
        "revision_no": "Which filing this is: 1, 2, 3 and so on",
        "title": "The title proposed on this form",
        "status": "draft, submitted, endorsed, accepted or rejected",
        "rejected_reason": "The panel's objection, kept for ever",
        "superseded_by_id": "The next filing, when there is one",
        "milestone_id": "The proposal milestone that carries it",
    },
    "milestones": {
        "id": "Primary key",
        "project_id": "The project whose chain this belongs to",
        "code": "lampiran_a, proposal, chapter_1 and so on",
        "status": "locked, open, submitted, reviewed, approved or rejected",
        "attempt": "Which round of the milestone this is",
        "due_at": "The shared deadline for every student",
        "revision_count": "How many times a revision was requested",
        "approved_at": "When it was approved",
    },
    "student_enrolments": {
        "id": "Primary key",
        "student_profile_id": "The student enrolled",
        "academic_semester_id": "The term of the attempt; null while a repeat is unplaced",
        "psm_part": "PSM1 or PSM2",
        "attempt_no": "Which attempt at this part",
        "status": "enrolling, active, completed, failed or withdrawn",
        "project_id": "The project this attempt runs",
        "repeats_enrolment_id": "The failed attempt this one repeats",
    },
}

if os.path.exists(SCHEMA):
    schema = json.load(open(SCHEMA, encoding="utf-8"))
    columns = schema["columns"]
    primary = {t: set(v) for t, v in schema["primary"].items()}
    foreign = schema["foreign"]

    table_no = 11
    # Each table gets its own numbered subsection. The earlier draft wrote
    # "4.3.2" on all eight, which made them look like one section repeated.
    dd_no = 2
    for name in DESC:
        H2(f"{name.replace('_', ' ').title()} Table", f"4.3.{dd_no}")
        dd_no += 1
        caption(f"Table 4.{table_no}: {name} table")
        rows = []
        pk = primary.get(name, {"id"})
        fks = foreign.get(name, {})
        for col in columns.get(name, []):
            cname = col["name"]
            ctype = (col.get("type") or "").lower()
            if col.get("size") and col["size"] not in ("", "null"):
                ctype += f"({col['size']})"
            key = ""
            if cname in pk:
                key = "PK"
            elif cname in fks:
                key = "FK"
            null = "Yes" if col.get("null") else "No"
            desc = DESC[name].get(cname, "")
            if not desc:
                desc = "—"
            rows.append([cname, ctype, key, null, desc])
        table(
            ["Column", "Type", "Key", "Null", "Description"],
            rows,
            widths=(3.6, 3.0, 1.0, 1.1, 6.2),
            size=9,
        )
        P()
        table_no += 1
else:
    P("The schema dump was not found, so the data dictionary has not been generated.")


# ===========================================================================
#  4.4  INTERFACE DESIGN
# ===========================================================================
H1("Interface Design", "4.4")
P("The interface is a single-page React client. It is organised by role. A user "
  "therefore sees only the screens their role allows. Every screen uses the same "
  "four components. These are a page header, a filter bar, a data table and a "
  "form. Three screens are described here. They are login, Lampiran A and "
  "Lampiran B.")

H2("Login Screen", "4.4.1")
P("The login screen asks for an email address and a password. It is the only "
  "screen reachable without a session. It also decides which dashboard the user "
  "lands on.")
P()
figure("Login.png", 12.5, "Figure 4.3: Login Screen")
P()

H2("Lampiran A Registration Screen", "4.4.2")
P("The Lampiran A screen records the student's particulars and up to three "
  "proposed titles. It also records the supervisor they approached. The session "
  "is a dropdown of open terms. The supervisor list offers only staff of the "
  "student's programme. It also offers only those with capacity.")
P()
figure("Lampiran_A_-_Registration.png", 12.5, "Figure 4.4: Lampiran A Registration Screen")
P()

H2("Lampiran B Filing Screen", "4.4.3")
P("The Lampiran B screen records the project type and the field of study. It also "
  "records the software, hardware and technology required. The supervisor signs "
  "it here. Signing opens the proposal milestone.")
P()
figure("Lampiran_B_-_Filed.png", 12.5, "Figure 4.5: Lampiran B Filing Screen")
P()


# ===========================================================================
#  4.5  CHAPTER SUMMARY
# ===========================================================================
H1("Chapter Summary", "4.5")
P("This chapter presented the analysis and design of the system. It began with "
  "the system modules. It then gave the functional and non-functional "
  "requirements. The users' requirements followed. These were the use case "
  "diagram and six specifications. Each specification carried its history log and "
  "its actors. Each also carried its flows and exceptions, followed by both "
  "diagrams.")
P("The design was then presented. The class diagram described the domain classes. "
  "The database design gave the core tables. It also gave a data dictionary for "
  "eight tables. The interface design described three screens. These carry "
  "registration and the Lampiran forms.")
P("The next chapter presents the implementation. It also reports the testing "
  "carried out, including User Acceptance Testing.")


def renumber():
    """Renumber every Table and Figure caption from its position in the document.

    Hand-tracked counters drifted: the use case tables restarted at 4.1 while the
    requirement tables above them had already used 4.1–4.3, and the class diagram
    came out as Figure 4.2 twice. One pass over the finished document makes the
    order the only thing that decides a number, which is also what a reader sees.
    """
    t_no = 0
    f_no = 0
    for p in doc.paragraphs:
        text = (p.text or "").strip()
        # A caption is "Label 4.N:" — a short line whose number is followed by a
        # colon. Prose that merely mentions a figure ("Figure 4.1 shows ...") is
        # not a caption, and counting it pushed every later figure up by one.
        is_table = bool(re.match(r"^Table 4\.\d+:", text))
        is_figure = bool(re.match(r"^Figure 4\.\d+:", text))
        new = None
        if is_table:
            t_no += 1
            new = re.sub(r"^Table 4\.\d+", f"Table 4.{t_no}", text)
        elif is_figure:
            f_no += 1
            new = re.sub(r"^Figure 4\.\d+", f"Figure 4.{f_no}", text)
        if new and new != text:
            for r in p.runs[1:]:
                r._element.getparent().remove(r._element)
            if p.runs:
                p.runs[0].text = new
            else:
                _run(p, new, bold=False, size=11)


def fix_cross_references():
    """Point the prose at the numbers the captions ended up with.

    A sentence that says "Table 4.10 lists the core tables" has to name the
    table that actually follows it, so the reference is rewritten from the
    caption it precedes rather than from a number typed into the prose.
    """
    paras = doc.paragraphs
    for i, p in enumerate(paras):
        text = (p.text or "").strip()
        if not text.startswith(("Table 4.", "Figure 4.")) or ":" not in text:
            continue
        label = text.split(":")[0].strip()          # "Table 4.11"
        # look back for the closest prose paragraph mentioning the old number
        for j in range(i - 1, max(i - 4, -1), -1):
            prev = paras[j]
            ptext = (prev.text or "").strip()
            if not ptext:
                continue
            m = re.search(r"(Table|Figure) 4\.(\d+)", ptext)
            if m and f"{m.group(1)} 4.{m.group(2)}" != label:
                old = m.group(0)
                if ptext.startswith(old) or text.startswith(old):
                    continue
                new_text = ptext.replace(old, label, 1)
                for r in prev.runs[1:]:
                    r._element.getparent().remove(r._element)
                if prev.runs:
                    prev.runs[0].text = new_text
                else:
                    _run(prev, new_text)
            break


renumber()
fix_cross_references()

doc.save(OUT)
print("wrote", OUT)

