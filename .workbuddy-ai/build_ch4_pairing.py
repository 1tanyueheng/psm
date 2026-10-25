"""Build Chapter 4 deliverable #2: Manage Pairing use case.

UC-02 Manage Pairing (supervisor <-> student): specification, sequence
diagram and activity diagram.
"""
import copy
import os
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
OUT = os.path.join(BASE, r"thesis\Chapter4 - Manage Pairing Use Case (Sequence and Activity Diagrams).docx")
DIA = os.path.join(BASE, r"thesis\diagrams")

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
FINAL = copy.deepcopy(list(body.iter(qn("w:sectPr")))[-1])
for ch in list(body):
    if ch is not sectPr:
        body.remove(ch)

TNR = "Times New Roman"


def _run(p, text, bold=False, italic=False, size=12):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
    r.italic = italic
    return r


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12, space_after=0):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(space_after)
    p.alignment = align
    if text:
        _run(p, text, bold=bold, size=size)
    return p


_sec = 0


def H(text):
    global _sec
    _sec += 1
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
    _run(p, f"4.{_sec}\t", bold=True)
    _run(p, text, bold=True)
    pf = p.paragraph_format
    pf.space_before = Pt(12)
    pf.space_after = Pt(10)
    return p


def caption(text):
    return P(text, bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=10)


def figure(path, width_cm, cap):
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(6)
    p.paragraph_format.space_after = Pt(2)
    p.add_run().add_picture(path, width=Cm(width_cm))
    caption(cap)
    return p


def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    tcPr.append(shd)


def spec_table(rows, lw=4.2, vw=10.6):
    t = doc.add_table(rows=0, cols=2)
    t.style = doc.styles["Table Grid"]
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblPr = t._tbl.tblPr
    for dup in tblPr.findall(qn("w:tblLayout")):
        tblPr.remove(dup)
    lay = OxmlElement("w:tblLayout")
    lay.set(qn("w:type"), "fixed")
    tblPr.append(lay)
    tw = tblPr.find(qn("w:tblW"))
    if tw is None:
        tw = OxmlElement("w:tblW")
        tblPr.append(tw)
    tw.set(qn("w:type"), "dxa")
    tw.set(qn("w:w"), str(int((lw + vw) / 2.54 * 1440)))

    for label, value in rows:
        cells = t.add_row().cells
        for i, (txt, bold, fill) in enumerate(((label, True, "E7E6E6"),
                                               (value, False, None))):
            para = cells[i].paragraphs[0]
            para.paragraph_format.line_spacing = 1.15
            para.paragraph_format.space_before = Pt(1)
            para.paragraph_format.space_after = Pt(1)
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT
            _run(para, txt, bold=bold, size=10.5)
            if fill:
                shade(cells[i], fill)
        cells[0].width = Cm(lw)
        cells[1].width = Cm(vw)
    for col, w in enumerate((lw, vw)):
        t.columns[col].width = Cm(w)
    return t


# =====================================================================
P("CHAPTER 4", bold=True)
P()
P()
P("ANALYSIS AND DESIGN", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P("Use Case Specification and UML Diagrams", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P("Manage Pairing", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P()

# ---------------------------------------------------------------- 4.1
H("Introduction")
P("This document presents the detailed design of the second use case of the PSM "
  "Assessment System, namely Manage Pairing, which covers assigning a supervisor to a "
  "student and removing that assignment. It is specified in Table 4.2, then expressed "
  "as a sequence diagram in Figure 4.3 and an activity diagram in Figure 4.4.")

# ---------------------------------------------------------------- 4.2
H("Use Case Specification")
P("Manage Pairing allows a coordinator to create, view and end the pairing between a "
  "student and a supervisor. Creating a pairing is not a simple insert: the system "
  "applies six ordered checks before any record is written, so that an invalid pairing "
  "is refused with the reason that actually blocked it.")

caption("Table 4.2: Use Case Specification for Manage Pairing")
spec_table([
    ("Use Case ID", "UC-02"),
    ("Use Case Name", "Manage Pairing"),
    ("Actor", "Coordinator (primary), Admin. Supervisors and Students may view their "
              "own pairings only."),
    ("Description", "The coordinator assigns a supervisor to a student for a PSM part, "
                    "or removes an existing pairing. Every new pairing is validated "
                    "against the supervisor's workload limit for that part before it is "
                    "saved, and every change is written to the audit log."),
    ("Pre-condition", "The coordinator is signed in. The student and the supervisor both "
                      "exist in the system, and the student is enrolled in the term the "
                      "pairing is being made for."),
    ("Trigger", "The coordinator selects a student on the Pairing screen and chooses "
                "either Assign or Remove."),
    ("Main Flow",
     "1. The coordinator opens the Pairing screen and selects a student.\n"
     "2. The coordinator chooses Assign.\n"
     "3. The system lists the available supervisors.\n"
     "4. The coordinator selects a supervisor and the PSM part (PSM1, PSM2 or BOTH).\n"
     "5. The system validates the request and resolves the PSM part.\n"
     "6. The system checks that the supervisor is accepting students.\n"
     "7. The system checks that the supervisor has capacity for that part.\n"
     "8. The system checks that the student still has a free supervisor slot.\n"
     "9. The system confirms the pairing is not already active.\n"
     "10. The system creates the pairing record.\n"
     "11. The system records the assignment in the audit log.\n"
     "12. The system notifies the student that a supervisor has been assigned.\n"
     "13. The system confirms that the pairing has been created."),
    ("Alternative Flow",
     "A1. Co-supervisor requested: at step 5, the system refuses the request because "
     "the system supports only a primary supervisor.\n"
     "A2. Unknown PSM part: at step 5, the system refuses the request and lists the "
     "accepted values.\n"
     "A3. Supervisor not accepting: at step 6, the system refuses the request and names "
     "the supervisor.\n"
     "A4. Programme mismatch: the student's programme is not one this supervisor may "
     "supervise, so the system refuses the request before any capacity check.\n"
     "A5. Capacity full: at step 7, the system refuses the request and reports the "
     "per-part figures for PSM 1 and PSM 2 separately, so the coordinator can see which "
     "batch is full. When the part is BOTH, both caps are checked.\n"
     "A6. Student already at the maximum: at step 8, the system refuses the request.\n"
     "A7. Duplicate pairing: at step 9, the system refuses the request because the "
     "pairing is already active for that part.\n"
     "A8. Semester mismatch: if a semester is supplied that is not the student's "
     "enrolment term, the system refuses the request."),
    ("Post-condition",
     "On success, a new supervision assignment exists with is_active set to true, the "
     "action is recorded in the audit log, and the student is notified. On removal, the "
     "assignment is deactivated rather than deleted, so historical reports and the "
     "audit trail remain intact, and the student is notified that the pairing has "
     "changed. On failure, nothing is written."),
])

P()
P("Two design decisions in Table 4.2 are worth noting. First, the programme check "
  "(A4) is deliberately placed before the capacity check (A5). A coordinator who is "
  "told that a supervisor is full will look for a free slot that does not exist for "
  "that student, whereas the programme is a property of who the student is and cannot "
  "change. Second, removal deactivates the assignment rather than deleting the row "
  "(post-condition), because the marks, agreements and submissions that refer to the "
  "pairing must remain traceable after it ends.")

# ---------------------------------------------------------------- 4.3
H("Sequence Diagram")
P("The sequence diagram in Figure 4.3 shows the pairing as an ordered exchange of "
  "messages. Six lifelines take part: the coordinator, the Pairing interface, the "
  "assignment controller, the student and supervisor model with its database, the "
  "assignment service, and the audit and notification component.")

figure(os.path.join(DIA, "sequence_pairing.png"), 12.8,
       "Figure 4.3: Sequence Diagram for Manage Pairing")

P("Messages 1 to 4 carry the coordinator's choice to the controller and validate the "
  "request. Messages 5 and 6 resolve the PSM part, defaulting to the student's own "
  "project part so that a PSM 2 student does not consume a PSM 1 slot. Message 7 hands "
  "the request to the assignment service, which owns the business rules.")
P("Messages 8 to 15 are the six guards. Guards 1 to 3 are cheap checks on the request "
  "itself: the role must be primary, the part must be recognised, and the supervisor "
  "must be accepting students. Guard 4 refuses a student whose programme the supervisor "
  "does not serve. Guards 5 and 6 require the database: message 10 reads the "
  "supervisor's current load for that term and part, message 11 returns the figures, "
  "and messages 13 and 14 check whether this exact pairing is already active. Any "
  "guard that fails throws an exception, which the controller returns as a 422 "
  "response naming the specific reason.")
P("When every guard passes, message 16 opens a database transaction and message 17 "
  "inserts the assignment. Messages 19 to 21 record the assignment in the audit log, "
  "warn the supervisor if the part has now reached its limit, and notify the student. "
  "Messages 22 to 24 return the assignment to the controller, which responds with 201 "
  "Created and refreshes the pairing list and capacity bar.")

# ---------------------------------------------------------------- 4.4
H("Activity Diagram")
P("The activity diagram in Figure 4.4 shows the same use case as a workflow. It begins "
  "with a single decision between the two actions the coordinator may take, Assign and "
  "Remove, and then follows the chosen branch.")

figure(os.path.join(DIA, "activity_pairing.png"), 14.2,
       "Figure 4.4: Activity Diagram for Manage Pairing")

P("On the Assign branch, the coordinator chooses a supervisor from the pool and a PSM "
  "part. The workflow then passes through seven decisions in a fixed order: role is "
  "primary, PSM part valid, supervisor accepting, programme matches, supervisor has "
  "room in the part, student has spare slots, and the pairing is not already active. "
  "Each decision corresponds to one of the alternative flows A1 to A7 in Table 4.2, "
  "and each has its own rejection message on the right of the diagram. Only when all "
  "seven pass does the workflow open the transaction, insert the record, write the "
  "audit entry, warn the supervisor if the part is now full, and notify the student.")
P("On the Remove branch, which runs down the right of the diagram, the coordinator selects an existing pairing "
  "and confirms removal. The workflow deactivates the record instead of deleting it, "
  "records the change with its before and after values, and notifies the student. Both "
  "branches then converge and the workflow reaches the end node. The failure paths on "
  "the Assign branch are collected beside the decisions to show that a rejected guard "
  "ends the attempt without writing anything, which is the behaviour described in the "
  "post-condition of Table 4.2.")

# ---------------------------------------------------------------- 4.5
H("Chapter Summary")
P("This document has specified the Manage Pairing use case and presented its sequence "
  "diagram and activity diagram. The specification defines two actors, a thirteen step "
  "main flow, eight alternative flows covering the guards and the semester check, and "
  "the post-conditions for assignment and removal. The sequence diagram shows the same "
  "flow as message exchanges between six objects, and the activity diagram shows it as "
  "a workflow with a top-level choice between Assign and Remove and seven ordering "
  "decisions on the Assign branch.")

body.remove(sectPr)
body.append(FINAL)
doc.save(OUT)
print("saved:", OUT)
