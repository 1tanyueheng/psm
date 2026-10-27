"""Rebuild the two Chapter 4 deliverables that were missing (UC-06 and UC-07).

Replaces `build_ch4_pairing.py`, which is deleted along with its figures: Manage
Pairing is no longer one of the six use cases, so the numbering is now

    UC-01 Login and Authentication      Table 4.1  Fig 4.1 / 4.2
    UC-02 Manage Title and Registration Table 4.2  Fig 4.3 / 4.4
    UC-03 Manage Milestone              Table 4.3  Fig 4.5 / 4.6
    UC-04 Manage Evaluation             Table 4.4  Fig 4.7 / 4.8
    UC-05 Generate Report               Table 4.5  Fig 4.9 / 4.10
    UC-06 Manage Alert and Notification Table 4.6  Fig 4.11 / 4.12 (+ 4.13)

and every other `build_ch4_*.py` needs the same shift. This script carries the
new numbers and writes both new documents in one run, so the two cannot drift.

Style is taken from `build_ch4_report.py` unchanged: Chapter 2 as the template,
cleared body, trailing sectPr kept, TNR 12pt, 1.5 spacing, Heading 2 with
literal numbering.
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
DIA = os.path.join(BASE, r"thesis\diagrams")

TNR = "Times New Roman"


def build(spec):
    doc = Document(SRC)
    body = doc.element.body
    sectPr = body.find(qn("w:sectPr"))
    FINAL = copy.deepcopy(list(body.iter(qn("w:sectPr")))[-1])
    for ch in list(body):
        if ch is not sectPr:
            body.remove(ch)

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

    state = {"sec": 0}

    def landscape():
        """A landscape section for a figure drawn wide.

        A Word section break is stored as the sectPr of the **last** paragraph
        of that section. So this paragraph opens the landscape section and the
        paragraph that follows it carries `portrait()` at its end, closing it.

        The heading, the introduction prose and the figure all go INSIDE this
        section, and both the figure and its caption are `keepNext`-free,
        zero-spacing, single-line paragraphs. The caption must follow the
        picture on the same page, so it is pinned to the same section rather
        than left in the next one — an earlier version ended the section on the
        figure paragraph itself, and LibreOffice then put the caption on a page
        of its own.

        `w:sectPr` must be the LAST child of `w:pPr`, after spacing, or Word
        rejects the file.
        """
        p = doc.add_paragraph(style="Normal")
        pf = p.paragraph_format
        pf.space_before = Pt(0)
        pf.space_after = Pt(0)
        pf.line_spacing = 1.0
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
        """Close the landscape section on the last paragraph of that section.

        The old page size has to be written here as well. A sectPr carries only
        the page size of the section it closes, so restoring it without an
        explicit `pgSz` left the *next* section inheriting landscape — the
        second half of every figure came out taller than the page, and the
        document grew a page for each half.
        """
        pPr = paragraph._p.get_or_add_pPr()
        new = copy.deepcopy(FINAL)
        el = new.find(qn("w:pgSz"))
        if el is None:
            el = OxmlElement("w:pgSz")
            new.insert(0, el)
        el.set(qn("w:w"), "11906")
        el.set(qn("w:h"), "16838")
        if el.get(qn("w:orient")):
            del el.attrib[qn("w:orient")]
        pPr.append(new)

    def _inside(p, after_pt=0):
        """Layout for a paragraph inside a landscape figure section.

        Zero spacing, single line spacing and no widow/orphan control, so the
        image and its caption stay on one page. `w:sectPr` for the section is
        written by `portrait()` afterwards and must remain the last child of
        `w:pPr`, after `w:spacing`.
        """
        pf = p.paragraph_format
        pf.space_before = Pt(0)
        pf.space_after = Pt(after_pt)
        pf.line_spacing = 1.0
        widow = OxmlElement("w:widowControl")
        widow.set(qn("w:val"), "0")
        p._p.get_or_add_pPr().append(widow)
        return p

    def H(text, tight=False):
        state["sec"] += 1
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
        _run(p, f"4.{state['sec']}\t", bold=True)
        _run(p, text, bold=True)
        pf = p.paragraph_format
        pf.space_before = Pt(0 if tight else 12)
        pf.space_after = Pt(10 if tight else 10)
        if tight:
            _inside(p, after_pt=10)
        return p

    def caption(text, tight=False):
        p = P(text, bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=10)
        return _inside(p, after_pt=10) if tight else p

    def figure(path, width_cm, cap):
        p = doc.add_paragraph(style="Normal")
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        _inside(p)
        p.add_run().add_picture(path, width=Cm(width_cm))
        return caption(cap, tight=True)

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

    # ---------------------------------------------------------------- title
    P("CHAPTER 4", bold=True)
    P()
    P()
    P("ANALYSIS AND DESIGN", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
    P()
    P("Use Case Specification and UML Diagrams", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
    P(spec["name"], bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
    P()
    P()

    H("Introduction")
    P(spec["intro"])

    H("Use Case Specification")
    P(spec["spec_intro"])
    caption(spec["table_caption"])
    spec_table(spec["rows"])
    P()
    for para in spec["after_table"]:
        P(para)

    # A landscape section per figure. Once the figures were simplified to about
    # 4:3 they fit a landscape page comfortably, and landscape is what lets them
    # be printed at a readable size.
    for heading, intro, figures, width, after in (
        ("Sequence Diagram", spec["seq_intro"], spec["seq_figures"], spec["seq_width"], spec["seq_after"]),
        ("Activity Diagram", spec["act_intro"], spec["act_figures"], spec["act_width"], spec["act_after"]),
    ):
        landscape()
        H(heading, tight=True)
        for para in intro:
            _inside(P(para))
        for png, cap in figures:
            figure(os.path.join(DIA, png), width, cap)
        portrait(P(after[0]))
        for para in after[1:]:
            P(para)

    H("Chapter Summary")
    P(spec["summary"])

    body.remove(sectPr)
    body.append(FINAL)
    doc.save(spec["out"])
    print("saved:", spec["out"])


# =====================================================================
TITLE = {
    "name": "Manage Title and Registration",
    "out": os.path.join(BASE, r"thesis\Chapter4 - Manage Title and Registration Use Case (Sequence and Activity Diagrams).docx"),
    "intro": "This document presents the detailed design of the second use case of the PSM "
             "Assessment System, namely Manage Title and Registration. The use case covers "
             "everything that happens between a student deciding to register and a project "
             "existing under an agreed title: the student files Lampiran A, the supervisor "
             "acknowledges it and chooses which of the proposed titles will be registered, "
             "and Lampiran B records the requirements before the supervisor signs it. It is "
             "specified in Table 4.2, then expressed as a sequence diagram in Figure 4.3 and "
             "an activity diagram in Figure 4.4.",
    "spec_intro": "Manage Title and Registration is the only route by which a title enters the "
                  "system, and the only route by which a supervision pairing is created. It is "
                  "one use case rather than two because the three documents are one chain: the "
                  "agreement cannot be acknowledged without a title to agree to, and Lampiran B "
                  "cannot be filed until the agreement is acknowledged. Table 4.2 summarises the "
                  "use case.",
    "table_caption": "Table 4.2: Use Case Specification for Manage Title and Registration",
    "rows": [
        ("Use Case ID", "UC-02"),
        ("Use Case Name", "Manage Title and Registration"),
        ("Actor", "Student (primary, for Lampiran A and Lampiran B), Supervisor (primary, for "
                  "the acknowledgement and the signature), Coordinator and Admin (view and "
                  "audit)."),
        ("Description", "The student registers for PSM 1 by filing Lampiran A, which records "
                        "their particulars, up to three proposed titles and the supervisor they "
                        "have approached. The supervisor acknowledges the agreement and, in "
                        "doing so, chooses which of the three proposed titles the student will "
                        "register. The acknowledgement is also the moment the supervision "
                        "pairing is created. The student then files Lampiran B, which records "
                        "the project type, the field of study and the software, hardware and "
                        "technology the project requires, and the supervisor signs it. Signing "
                        "opens the proposal milestone and the title proceeds to the panel."),
        ("Pre-condition", "The student is signed in and an academic term is open for "
                          "registration. The student holds no project and no agreement in "
                          "flight for that term. The named supervisor exists, serves the "
                          "student's programme and has capacity for the part."),
        ("Trigger", "The student opens the registration form and submits Lampiran A."),
        ("Main Flow",
         "1. The student opens Lampiran A and selects the session from a list of terms.\n"
         "2. The student enters their particulars and proposes up to three titles.\n"
         "3. The student names a supervisor and submits the form.\n"
         "4. The system confirms that a term is open for registration.\n"
         "5. The system confirms that the student has no project and no open agreement.\n"
         "6. The system validates each proposed title.\n"
         "7. The system confirms that the supervisor serves the student's programme.\n"
         "8. The system creates the agreement with the status pending supervisor.\n"
         "9. The system notifies the supervisor that a Lampiran A awaits them.\n"
         "10. The supervisor opens the agreement and chooses one of the proposed titles.\n"
         "11. The system confirms the chosen title is one the student proposed.\n"
         "12. The system creates the supervision assignment.\n"
         "13. The system records the agreed title and marks the agreement approved.\n"
         "14. The student fills Lampiran B and submits it.\n"
         "15. The supervisor signs the form.\n"
         "16. The system opens the proposal milestone."),
        ("Alternative Flow",
         "A1. No term is open, a project already exists, or an agreement is already in "
         "flight: the system refuses the Lampiran A with 422 and names which of the three "
         "applies.\n"
         "A2. A proposed title is unusable, being shorter than ten characters, fewer than "
         "three words, or a placeholder: the system refuses it and the student corrects it.\n"
         "A3. The named supervisor does not serve the student's programme: the system "
         "refuses the submission. An unrecorded programme is allowed through, so that legacy "
         "staff rows are not locked out.\n"
         "A4. The supervisor names a title the student did not propose: the system refuses "
         "it, because the acknowledgement is a choice between the candidates and a different "
         "title needs a new Lampiran A.\n"
         "A5. The supervisor sends Lampiran B back instead of signing it: the form returns "
         "to the student as a draft with a reason, and the student re-files it.\n"
         "A6. The student withdraws the filing before a project has been registered from "
         "it: the agreement is cancelled, the supervision is retired so the supervisor's "
         "capacity is released, and the pending form is cleared. Nothing is deleted.\n"
         "A7. A project has already been registered from the agreement: withdrawal is "
         "refused, and unwinding it becomes a coordinator decision."),
        ("Post-condition",
         "On success, the student holds an approved agreement naming one agreed title, a "
         "supervision pairing exists, and Lampiran B has been signed. The proposal milestone "
         "is open and the title goes to the panel at the title defence. The project's title "
         "is not fixed by this use case: it becomes the project's title only when a panel "
         "accepts it."),
    ],
    "after_table": [
        "Three rules in Table 4.2 carry most of the weight. First, the agreed title is "
        "chosen and not typed (A4): Part C of Lampiran A offers the three candidates as a "
        "list, so the title the supervisor agrees to and the title the panel is later asked "
        "to consider are always the same string. Second, acknowledgement is where the "
        "pairing is created, not a second approval step, which is why the supervisor judges "
        "the supervision while the panel judges the title. Third, withdrawal is a student's "
        "act and never a deletion (A6, A7): the agreement keeps its timestamps and its "
        "proposed titles, because the faculty's file has to show what was submitted and "
        "when.",
        "The programme check in A3 deliberately fails open. A staff row with no recorded "
        "programme permits the pairing, so legacy records are not locked out; the "
        "consequence is that a staff row with no programme enforces nothing, which is why "
        "every programme is seeded with four staff.",
    ],
    "seq_figures": [
        ("sequence_title.png",
         "Figure 4.3: Sequence Diagram for Manage Title and Registration"),
    ],
    # 2210x1888. A portrait page leaves 14.5 cm of drawing height once the
    # caption has its line, so 15.5 cm is the widest that keeps picture, caption
    # and the four bands on one page. The figure is deliberately close to 4:3
    # for this reason: a taller drawing has to shrink to fit and its labels with
    # it, which is why the first draft was unreadable in Word.
    "seq_width": 15.5,
    "seq_intro": [
        "The sequence diagram in Figure 4.3 shows the whole chain across six lifelines: the "
        "student or supervisor, the registration interface, the registration controller, the "
        "registration service, the agreement and supervision data, and the Lampiran B and "
        "milestone data. It is divided into four bands because four things happen, in "
        "sequence, and each has its own rule.",
        "The diagram is drawn at the level of one message per thing that changes hands. A "
        "request and its reply are a single exchange, and the controller handing work to the "
        "service is not drawn as a separate call. Table 4.2 states the individual checks; the "
        "diagram carries the shape of the flow.",
    ],
    "seq_after": [
        "Message 2 files Lampiran A. Before the agreement is written, message 3 applies every "
        "check the registration has to pass — an open term, no project and no agreement "
        "already in flight, a usable title, and a supervisor who serves the student's "
        "programme — and message 4 writes the record only if all of them hold. Message 5 "
        "reports back and notifies the supervisor, which is how the second actor learns there "
        "is something to acknowledge.",
        "The acknowledgement in the second band is the step the faculty's process turns on. "
        "Message 8 requires the agreement to still be waiting and the chosen title to be one "
        "the student proposed, and message 9 does the two things that must happen together: "
        "the supervision is created and the agreed title is recorded. The third band files "
        "and signs Lampiran B; its signature is the last message that changes anything, "
        "because signing is what opens the proposal. The fourth band is the only way back out "
        "— a withdrawal, which is refused once a project has been registered from the filing.",
    ],
    "act_figures": [
        ("activity_title.png", "Figure 4.4: Activity Diagram for Manage Title and Registration"),
    ],
    # 2200x2323; 16.2 cm is the widest that still fits the portrait page.
    "act_width": 16.2,
    "act_intro": [
        "The activity diagram in Figure 4.4 shows the same use case as a workflow. The "
        "filing route runs down the centre through four decisions, each of which corresponds "
        "to an alternative flow in Table 4.2, and the withdrawal route branches to the left "
        "from the point where a Lampiran B is filed but no project exists. Both converge on "
        "one end node.",
    ],
    "act_after": [
        "Each decision in the centre is a refusal that names its own cause rather than "
        "returning a generic error: whether the student may register at all, whether the "
        "proposed titles are usable, whether the supervisor serves the student's programme, "
        "and whether the supervisor's chosen title is one the student proposed. The "
        "withdrawal branch keeps its own guard, refusing once a Lampiran B has registered a "
        "project, and its success path cancels the agreement and retires the pairing so the "
        "supervisor's capacity comes back.",
        "The final decision is the supervisor's signature. The Yes path is the only path on "
        "which the proposal milestone opens, which is the point of the whole use case: no "
        "title reaches a panel until a supervisor has agreed to supervise it.",
    ],
    "summary": "This document has specified the Manage Title and Registration use case and "
               "presented its sequence diagram and activity diagram. The specification "
               "defines two primary actors, a sixteen step main flow and seven alternative "
               "flows. The sequence diagram shows the four stages of the chain as message "
               "exchanges across six objects, and the activity diagram shows the filing route "
               "with its four guards beside a withdrawal route that is deliberately narrow.",
}

EVALUATION = {
    "name": "Manage Evaluation",
    "out": os.path.join(BASE, r"thesis\Chapter4 - Manage Evaluation Use Case (Sequence and Activity Diagrams).docx"),
    "intro": "This document presents the detailed design of the fourth use case of the PSM "
             "Assessment System, namely Manage Evaluation. The use case covers the assessor "
             "marking one evaluation form against its rubric, from opening the form to the "
             "moment the mark becomes visible to the student. It is specified in Table 4.4, "
             "then expressed as a sequence diagram in Figure 4.7 and an activity diagram in "
             "Figure 4.8.",
    "spec_intro": "Manage Evaluation is the only route by which a mark enters the system. It "
                  "is deliberately small: a coordinator allocates forms, each assessor marks "
                  "their own, and every assessor's contribution is aggregated into the "
                  "project's final mark. Table 4.4 summarises the use case.",
    "table_caption": "Table 4.4: Use Case Specification for Manage Evaluation",
    "rows": [
        ("Use Case ID", "UC-04"),
        ("Use Case Name", "Manage Evaluation"),
        ("Actor", "Assessor, being the supervisor, an examiner or a lecturer (primary); "
                  "Coordinator (allocates forms). The student may read their own released "
                  "mark."),
        ("Description", "The assessor opens the evaluation form the coordinator allocated to "
                        "them and marks each criterion on a 0 to 5 scale. The system converts "
                        "each item score to the rubric's own weight, so Lampiran J still "
                        "totals 40 while the marker answers on a 0 to 5 scale. When every "
                        "criterion is marked the assessor submits the form, which locks it, "
                        "recomputes the project's aggregate and makes the mark visible in the "
                        "same step."),
        ("Pre-condition", "The coordinator has allocated the form to the assessor and has "
                          "opened the assessment window for that form. The milestones the "
                          "form depends on are approved."),
        ("Trigger", "The assessor opens a form from their dashboard."),
        ("Main Flow",
         "1. The assessor opens an allocated form.\n"
         "2. The system confirms the assessor may read the form.\n"
         "3. The system loads the rubric snapshot and the student's score rows.\n"
         "4. The system renders one 0 to 5 select per criterion.\n"
         "5. The assessor chooses a score for each criterion and may add a comment.\n"
         "6. The system confirms the form is still editable.\n"
         "7. The system confirms the form's own assessment window is open.\n"
         "8. The system converts each item score to the criterion's weight.\n"
         "9. The system stores the weighted mark and keeps the item score beside it.\n"
         "10. The assessor submits the form.\n"
         "11. The system confirms that every criterion has been marked.\n"
         "12. The system recomputes the form's percentage.\n"
         "13. The system marks the form submitted and records whether it was late.\n"
         "14. The system recomputes the project's final mark.\n"
         "15. The system reconciles the release flag so the student can see the mark.\n"
         "16. The system notifies the coordinator and the student."),
        ("Alternative Flow",
         "A1. The form's assessment window is not open: the system refuses the mark with "
         "422. The window is per form, so opening Lampiran E does not open Lampiran I.\n"
         "A2. The form is no longer editable, being already submitted: the system refuses "
         "the change. Only the assessor who holds the form may mark it, and a submitted form "
         "is locked.\n"
         "A3. An item score is outside 0 to 5: the system refuses it and the assessor "
         "corrects the score.\n"
         "A4. The assessor submits with a criterion unmarked: the system refuses and names "
         "each unmarked criterion. An unmarked form is not a zero.\n"
         "A5. A milestone the form depends on is not approved: submission is refused, "
         "because the form would certify work that has not been accepted.\n"
         "A6. The assessor declares a conflict of interest: the form is marked recused and "
         "the coordinators are notified so a replacement can be allocated. A recusal is "
         "final."),
        ("Post-condition",
         "On success, the form is submitted and locked, the project's aggregate has been "
         "recomputed and the student can see their mark. The item scores, the converted "
         "marks, the submitter and the submission time are all retained. A student who did "
         "not attend is assessed as zero, which the assessor types; the system never assumes "
         "a zero."),
    ],
    "after_table": [
        "Two rules in Table 4.4 replaced features that no longer exist and must not be "
        "reintroduced. First, there is no release step: submitting the form recomputes the "
        "aggregate and reconciles visibility in one call (step 15), because a separate "
        "Release button let the aggregate and the flag that exposes it be changed "
        "independently. Second, there is no moderation: a coordinator does not alter marks, "
        "so if a mark is genuinely wrong the assessor revises it rather than a second party "
        "editing the number.",
        "The distinction in A4 matters for the same reason. A form with an unmarked "
        "criterion is incomplete, and an incomplete form is refused; a student who did not "
        "attend is complete and carries zeroes. Treating the two alike would either publish "
        "a mark that was never given or block a legitimate fail.",
    ],
    "seq_figures": [
        ("sequence_evaluation.png",
         "Figure 4.7: Sequence Diagram for Manage Evaluation"),
    ],
    # 1950x1844, close to 4:3 on purpose; 17.7 cm fills the portrait page.
    "seq_width": 17.7,
    "seq_intro": [
        "The sequence diagram in Figure 4.7 shows the use case across five lifelines: the "
        "assessor, the evaluation interface, the evaluation controller, the evaluation "
        "service, and the data the mark is read from and published to. It is divided into "
        "marking, saving and submitting.",
        "As in Figure 4.3 the level is one message per thing that changes hands. The two "
        "verbs of the use case — saving a mark and submitting the form — are the two bands "
        "that end in a round trip, and each carries its gate on the message that enforces it.",
    ],
    "seq_after": [
        "Marking is a read. Messages 2 to 4 fetch the form with its rubric and the student's "
        "existing scores, and message 5 returns them, which is what lets the interface draw "
        "one select per criterion. Saving is where the arithmetic happens: message 9 confirms "
        "that the form is still editable and that its own assessment window is open, and "
        "message 11 writes two numbers. The weighted mark is what the criterion is worth; the "
        "item score beside it is what the assessor actually answered. Both are kept because "
        "they answer different questions, and because converting one to the other is the only "
        "step in the system where a mark changes units.",
        "Submitting is the band that must not grow a second signature. Message 16 refuses an "
        "incomplete form, a form whose milestones are not approved, and a closed window; "
        "message 17 then locks the form, recomputes the project's final mark and publishes it "
        "in the same call, so the aggregate and the flag that exposes it can never disagree. "
        "Message 18 tells the coordinator the batch has moved, and message 20 is the student "
        "seeing the mark. There is no message to a moderator and none to a release step, "
        "because neither exists.",
    ],
    "act_figures": [
        ("activity_evaluation.png", "Figure 4.8: Activity Diagram for Manage Evaluation"),
    ],
    # 1960x1914; 16.2 cm fills the portrait page.
    "act_width": 16.2,
    "act_intro": [
        "The activity diagram in Figure 4.8 shows the same use case as a workflow. The "
        "marking route runs down the centre through four decisions, each corresponding to an "
        "alternative flow, and each refusal leaves into a single column on the right. The "
        "assessor's own conflict-of-interest declaration is the one branch that leaves from "
        "the spine itself, and it joins the column at the end node rather than returning to "
        "the marking route.",
    ],
    "act_after": [
        "The first two decisions are gates rather than validations: the form's own "
        "assessment window must be open (A1), and the form must not have been recused (A2). "
        "The third is a correction loop: a score outside 0 to 5 is refused and the assessor "
        "marks again. The fourth is the completeness check (A4), and it is the last thing "
        "between the assessor and a published mark.",
        "After that decision the route is straight and short on purpose. The form's "
        "percentage is recomputed, the form is locked, the project's final mark is "
        "recalculated and the release flag is reconciled in the same pass, and only then is "
        "anybody notified. The step labelled \"no Release step exists\" is drawn deliberately: "
        "it is the point at which an earlier design expected a coordinator to press Release, "
        "and nothing there any more.",
    ],
    "summary": "This document has specified the Manage Evaluation use case and presented its "
               "sequence diagram and activity diagram. The specification defines the assessor "
               "as the only actor who marks, a sixteen step main flow and six alternative "
               "flows. The sequence diagram shows marking, saving and submitting as message "
               "exchanges across six objects, and the activity diagram shows the workflow "
               "with its two gates, a correction loop and the completeness check that "
               "separates a mark from a guess.",
}


if __name__ == "__main__":
    build(TITLE)
    build(EVALUATION)
