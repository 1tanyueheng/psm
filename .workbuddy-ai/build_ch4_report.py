"""Build Chapter 4 deliverable #4: Generate Report use case (UC-04)."""
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
OUT = os.path.join(BASE, r"thesis\Chapter4 - Generate Report Use Case (Sequence and Activity Diagrams).docx")
DIA = os.path.join(BASE, r"thesis\diagrams")

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
FINAL = copy.deepcopy(list(body.iter(qn("w:sectPr")))[-1])
for ch in list(body):
    if ch is not sectPr:
        body.remove(ch)

TNR = "Times New Roman"


def _run(p, text, bold=False, size=12):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
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
P("Generate Report", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P()

# ---------------------------------------------------------------- 4.1
H("Introduction")
P("This document presents the detailed design of the fifth use case of the PSM "
  "Assessment System, namely Generate Report. The use case covers the coordinator's "
  "dashboard, which summarises the progress, risk and marks of a cohort, and the CSV "
  "exports that the coordinator downloads for the faculty. It is specified in Table "
  "4.4, then expressed as a sequence diagram in Figure 4.9 and an activity diagram in "
  "Figure 4.10.")

# ---------------------------------------------------------------- 4.2
H("Use Case Specification")
P("Generate Report answers questions about a whole batch rather than about one project, "
  "so every report is built from the same three filters: the batch, the PSM part and "
  "the academic semester. The system produces two kinds of output from those filters. "
  "The on-screen report returns figures as JSON for the dashboard to render, and the "
  "export produces a CSV file. Table 4.5 summarises the use case.")

caption("Table 4.5: Use Case Specification for Generate Report")
spec_table([
    ("Use Case ID", "UC-04"),
    ("Use Case Name", "Generate Report"),
    ("Actor", "Coordinator (primary), Admin."),
    ("Description", "The coordinator selects a batch, a PSM part and a semester, and "
                    "the system produces cohort analytics. The reports cover cohort "
                    "progress, students at risk, supervisor and examiner workload, "
                    "mark distribution and milestone breakdown. The coordinator may "
                    "also export the grade or project list as a CSV file."),
    ("Pre-condition", "The coordinator is signed in and holds the analytics "
                      "permission. Projects and assessments exist for the selected "
                      "filters."),
    ("Trigger", "The coordinator opens the Reports screen, or clicks Export."),
    ("Main Flow",
     "1. The coordinator opens the Reports screen.\n"
     "2. The coordinator chooses the batch, the PSM part and the semester.\n"
     "3. The coordinator requests the report.\n"
     "4. The system checks the analytics permission.\n"
     "5. The system validates the filters and resolves the semester.\n"
     "6. The system runs the aggregate query for the chosen filters.\n"
     "7. The system computes cohort progress from the milestone statuses.\n"
     "8. The system counts submissions awaiting review.\n"
     "9. The system identifies the students at risk.\n"
     "10. The system computes the mark distribution.\n"
     "11. The system builds a rollup for each PSM part.\n"
     "12. The system returns the figures as JSON.\n"
     "13. The interface renders the tables and charts."),
    ("Alternative Flow",
     "A1. Permission refused: the system returns 403 Forbidden because the user may "
     "not view cohort analytics.\n"
     "A2. Invalid filter: the system rejects a semester that does not exist or a PSM "
     "part other than PSM1 or PSM2.\n"
     "A3. Export chosen: the system checks the export permission, which is narrower "
     "than the analytics permission and is limited to coordinators and administrators.\n"
     "A4. No released grades: the mark distribution returns empty bands and null "
     "statistics rather than zero, because no released mark is not the same as a mark "
     "of zero.\n"
     "A5. Grade without a final mark: the row is included in the export with a blank "
     "verdict rather than marked Fail, and it is excluded from the displayed pass rate."),
    ("Post-condition",
     "On success, the coordinator sees the figures for the selected filters, or "
     "receives a CSV file. Every export is recorded in the audit log with the term, "
     "batch and part that were used. No underlying project or assessment data is "
     "modified."),
])

P()
P("Two rules in Table 4.5 are worth noting because they are easy to get wrong. First, "
  "the on-screen pass rate counts released results only (A4), because a withheld mark is "
  "not a published one, while the exported spreadsheet deliberately includes provisional "
  "rows so that the coordinator has a working extract. The export therefore carries a "
  "Status column beside the pass or fail verdict, so that anyone recomputing the rate "
  "from the file filters on released rows. Second, a grade with no final mark is left "
  "blank rather than marked Fail (A5): no mark is not a failure.")

# ---------------------------------------------------------------- 4.3
H("Sequence Diagram")
P("The sequence diagram in Figure 4.9 shows both outputs from the same screen. Five "
  "lifelines take part: the coordinator, the reports interface, the report controller, "
  "the reporting service, and the grades and milestones the figures are read from. The "
  "diagram is divided into the on-screen report and the CSV export.")

figure(os.path.join(DIA, "sequence_report.png"), 16.4,
       "Figure 4.9: Sequence Diagram for Generate Report")

P("Messages 1 and 2 carry the filter selection to the controller, which checks the "
  "analytics permission in message 3 and hands the work to the reporting service in "
  "message 4. Message 5 is the service's own work — it aggregates progress, the students "
  "at risk, supervisor load and the mark bands in one pass — and message 6 returns the "
  "figures the interface renders. Splitting that work into one message per aggregation "
  "would say more about the query and less about the flow.")
P("The lower half is the export. Message 10 is the same filters written to a file, "
  "message 11 applies the export permission, which is narrower than the analytics "
  "permission, and message 12 logs the export before any row leaves the system. The "
  "rows are read in pages rather than all at once, because a full cohort export runs to "
  "thousands of lines and the whole set would not fit comfortably in memory.")

# ---------------------------------------------------------------- 4.4
H("Activity Diagram")
P("The activity diagram in Figure 4.10 shows the same use case as a workflow. After the "
  "permission check, a single decision splits the flow into the on-screen report, which "
  "continues down the centre, and the export, which runs to the right. Both converge on "
  "one end node.")

figure(os.path.join(DIA, "activity_report.png"), 16.4,
       "Figure 4.10: Activity Diagram for Generate Report")

P("The first decision is the permission check, which corresponds to alternative flow "
  "A1: a user without the analytics permission receives 403 Forbidden and the workflow "
  "stops. The action that follows computes every figure in one step, because the "
  "individual aggregations are a detail of how the numbers are produced rather than "
  "separate steps of the flow. The second decision is the choice of output.")
P("On the view branch the system renders the tables and charts for the coordinator. On "
  "the export branch it writes the CSV in pages and records the export in the audit log, "
  "so an extract cannot leave the system without a record of who took it and with which "
  "filters.")

# ---------------------------------------------------------------- 4.5
H("Chapter Summary")
P("This document has specified the Generate Report use case and presented its sequence "
  "diagram and activity diagram. The specification defines the actors, a thirteen step "
  "main flow, five alternative flows and the post-conditions for both the on-screen "
  "report and the export. The sequence diagram shows the two outputs from one screen as "
  "message exchanges across five objects, and the activity diagram shows the flow as a "
  "workflow with a permission check and a choice of output.")

body.remove(sectPr)
body.append(FINAL)
doc.save(OUT)
print("saved:", OUT)
