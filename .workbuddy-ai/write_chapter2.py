"""Write CHAPTER 2 - LITERATURE REVIEW for the PSM Management System.

Rebuilds the body of the existing Chapter 2 (keeping its styles, section
layout, headers/footers and page setup) with content that matches the
project described in Chapter 1.
"""
import copy
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - Literature Review.docx"

import os
os.makedirs(os.path.dirname(OUT), exist_ok=True)
doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))

# ------------------------------------------------- capture the section layout
# The original chapter used four sections: portrait body -> landscape page for
# the wide comparison table -> portrait body again -> final section carrying the
# page-number footer, with numbering restarted at 1. Reuse those sectPr blocks so
# the rebuilt chapter paginates exactly like the original template.
_orig_sects = [copy.deepcopy(s) for s in body.iter(qn("w:sectPr"))]
assert len(_orig_sects) == 4, f"expected 4 sections, found {len(_orig_sects)}"
SECT_BEFORE_TABLE, SECT_AFTER_TABLE, SECT_TAIL, FINAL_SECT = _orig_sects


def close_section(sect):
    """Attach sectPr to the most recent paragraph, closing a section there."""
    last_p = body.findall(qn("w:p"))[-1]
    pPr = last_p.find(qn("w:pPr"))
    if pPr is None:
        pPr = OxmlElement("w:pPr")
        last_p.insert(0, pPr)
    for existing in pPr.findall(qn("w:sectPr")):
        pPr.remove(existing)
    pPr.append(copy.deepcopy(sect))


# ---------------------------------------------------------------- clear body
# remove every body-level paragraph/table but keep the trailing sectPr
for child in list(body):
    if child is not sectPr:
        body.remove(child)


def restart_chapter_numbering(start_at=2):
    """Force the Heading 1/2/3 list (numId 1) to begin at `start_at`.

    The chapter file stands alone, so the chapter counter would otherwise
    render "CHAPTER 1" / "1.1". A lvlOverride with a startOverride resets
    level 0, and levels 1-2 follow from it.
    """
    numbering = doc.part.numbering_part.element
    target_num = None
    for num in numbering.findall(qn("w:num")):
        if num.get(qn("w:numId")) == "1":
            target_num = num
            break
    if target_num is None:
        raise RuntimeError("numId 1 not found in numbering part")

    for existing in target_num.findall(qn("w:lvlOverride")):
        target_num.remove(existing)

    lvl = OxmlElement("w:lvlOverride")
    lvl.set(qn("w:ilvl"), "0")
    start = OxmlElement("w:startOverride")
    start.set(qn("w:val"), str(start_at))
    lvl.append(start)
    target_num.append(lvl)


def P(text="", style="Paragraph First and Last", bold_lead=None, align=None):
    p = doc.add_paragraph(style=style)
    if text:
        if bold_lead:
            r = p.add_run(bold_lead)
            r.bold = True
            p.add_run(text)
        else:
            p.add_run(text)
    if align is not None:
        p.alignment = align
    return p


def H(text, level):
    p = doc.add_paragraph(text, style=f"Heading {level}")
    if level == 3:
        # The template's Heading 3 level is labelled "%1.%2.%3 do not use",
        # so sub-sections are written without the auto list number.
        pPr = p._p.get_or_add_pPr()
        numPr = OxmlElement("w:numPr")
        ilvl = OxmlElement("w:ilvl")
        ilvl.set(qn("w:val"), "0")
        numId = OxmlElement("w:numId")
        numId.set(qn("w:val"), "0")
        numPr.append(ilvl)
        numPr.append(numId)
        pPr.insert(0, numPr)
    return p


def blank():
    return doc.add_paragraph("", style="Normal")


def caption(text):
    return doc.add_paragraph(text, style="Caption")


def set_cell_bg(cell, hex_color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_color)
    tcPr.append(shd)


def set_repeat_header(row):
    trPr = row._tr.get_or_add_trPr()
    el = OxmlElement("w:tblHeader")
    el.set(qn("w:val"), "true")
    trPr.append(el)


def build_table(header, rows, widths_cm=None, font_pt=9):
    from docx.shared import Cm as _Cm
    t = doc.add_table(rows=1, cols=len(header))
    t.style = doc.styles["Table Grid"]
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False          # fixed layout, so the widths below are honoured

    # one tblLayout only, plus an explicit table width equal to the sum of the
    # columns; with tblW="auto" Word ignores the grid and re-fits the table
    tblPr = t._tbl.tblPr
    for dup in tblPr.findall(qn("w:tblLayout")):
        tblPr.remove(dup)
    layout = OxmlElement("w:tblLayout")
    layout.set(qn("w:type"), "fixed")
    tblPr.append(layout)
    if widths_cm:
        total_tw = int(sum(widths_cm) / 2.54 * 1440)
        tblW = tblPr.find(qn("w:tblW"))
        if tblW is None:
            tblW = OxmlElement("w:tblW")
            tblPr.append(tblW)
        tblW.set(qn("w:type"), "dxa")
        tblW.set(qn("w:w"), str(total_tw))

    def tight(cell):
        """Trim the template's paragraph spacing inside a cell."""
        for para in cell.paragraphs:
            pf = para.paragraph_format
            pf.space_before = Pt(1)
            pf.space_after = Pt(1)
            pf.line_spacing = 1.0

    hdr = t.rows[0]
    for i, h in enumerate(header):
        cell = hdr.cells[i]
        para = cell.paragraphs[0]
        para.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run = para.add_run(h)
        run.bold = True
        run.font.size = Pt(font_pt)
        set_cell_bg(cell, "D9E2F3")
        tight(cell)
    set_repeat_header(hdr)

    for row in rows:
        cells = t.add_row().cells
        for i, val in enumerate(row):
            para = cells[i].paragraphs[0]
            para.alignment = (WD_ALIGN_PARAGRAPH.CENTER if i == 0
                              else WD_ALIGN_PARAGRAPH.LEFT)
            run = para.add_run(str(val))
            run.font.size = Pt(font_pt)
            tight(cells[i])

    if widths_cm:
        # set on the grid, the columns and every cell, otherwise Word
        # recomputes the layout from the defaults
        grid = t._tbl.tblGrid
        for i, gc in enumerate(grid.findall(qn("w:gridCol"))):
            gc.set(qn("w:w"), str(int(widths_cm[i] / 2.54 * 1440)))
        for i, w in enumerate(widths_cm):
            t.columns[i].width = _Cm(w)
        for r in t.rows:
            for i, w in enumerate(widths_cm):
                r.cells[i].width = _Cm(w)
    return t


# ================================================================ CHAPTER 2
# Heading 1 auto-numbering renders "CHAPTER N". This chapter is a standalone
# file, so the Heading 1 counter is restarted at 2 via a lvlOverride below.
h1 = doc.add_paragraph(style="Heading 1")
h1.add_run().add_break()
r = h1.add_run()
r.add_break()
r.add_text("LITERATURE REVIEW")
restart_chapter_numbering(2)

# ---------------------------------------------------------------- 2.1
H("Introduction", 2)
P("This chapter reviews the literature and the existing systems that provide the "
  "foundation for the PSM Management System developed in this project. The review is "
  "organised in four parts. The first part establishes the domain background: it "
  "explains what final year project administration involves at the Faculty of Computer "
  "Science and Information Technology (FSKTM), Universiti Tun Hussein Onn Malaysia "
  "(UTHM), why it is a distinct problem, and what the literature identifies as the "
  "recurring weaknesses of manual administration. The second part examines three "
  "existing web-based systems that address closely related administrative problems. "
  "The third part compares the three systems against the requirements of the proposed "
  "PSM Management System, and the fourth part concludes with the gap that the proposed "
  "system fills.")
P("The existing systems reviewed in Section 2.3 are taken from Applied Information "
  "Technology and Computer Science (AITCS), the peer-reviewed periodical published by "
  "FSKTM UTHM. This is a deliberate choice. The systems published there were developed "
  "for the same faculty, the same student population and the same administrative "
  "constraints as the proposed system, so their strengths and their limitations "
  "transfer directly to this project.")

# ---------------------------------------------------------------- 2.2
H("Domain Background", 2)

H("Final Year Project Administration at FSKTM", 3)
P("Projek Sarjana Muda (PSM) is the final year project that every undergraduate student "
  "at FSKTM must complete in order to graduate. It is not a single subject. It is a "
  "process that runs across two consecutive academic terms, involves several distinct "
  "groups of people, and is governed by a formal set of rules published by the faculty. "
  "PSM 1 covers the proposal of a project title and the first half of the work. PSM 2 "
  "completes the implementation, the final report and the examination.")
P("The process is administered by four groups of actors. Students submit titles, accept "
  "agreements, deliver milestone work and upload reports. Academic staff supervise "
  "students and, in a separate capacity, sit on examination panels. A faculty "
  "coordinator administers the whole cohort, allocating supervisors and seating panels. "
  "An administrator manages accounts and roles. Each of these actors needs a different "
  "view of the same underlying record, and each view is bound by different permissions.")
P("Two properties of PSM make its administration harder than it first appears. The first "
  "is that PSM 1 and PSM 2 run concurrently within the same academic semester. The "
  "second is that both batches draw on the same pool of academic staff. A lecturer may "
  "therefore be supervising students from both batches at once. Any capacity rule that "
  "counts a supervisor's load without distinguishing the two batches will misjudge "
  "whether that supervisor is available, and will either over-allocate staff or block "
  "students who could legitimately be assigned.")

H("The Rules That Govern the Process", 3)
P("PSM is a rules-governed process rather than a free-form workflow, and the rules are "
  "the reason a paper-based process struggles. Supervision capacity is capped per "
  "academic staff member. Title registration passes through a defined sequence of "
  "states: a student files three candidate titles, the supervisor acknowledges one, and "
  "a panel reviews the proposal before it reaches the coordinator for a final decision. "
  "Milestones are generated in a fixed chain for each part of PSM, and a milestone "
  "cannot open until the preceding one has been approved.")
P("Assessment is bound by equally specific rules. Five official Lampiran forms are used: "
  "E and I in PSM 1, and G, H and J in PSM 2. Each form scores its own rubric items on a "
  "zero to five scale and converts every item into a weighted mark. The weights are not "
  "round numbers. A PSM 2 student accumulates forty-one weighted marks and a PSM 1 "
  "student accumulates twenty-four, and the final grade is released only once every "
  "assessor has submitted. A single missing form therefore holds back the result for the "
  "entire cohort.")

H("The Software Engineering Perspective", 3)
P("From a software engineering standpoint, PSM administration is an instance of a wider "
  "class of problem: a process whose rules are well defined, but whose enforcement "
  "depends entirely on human diligence. The literature on administrative information "
  "systems consistently frames this as a shift from document-centred to record-centred "
  "management. In a document-centred process, the authoritative state of a case is "
  "distributed across spreadsheets, shared drives, message threads and printed forms, "
  "and consistency between them is maintained by people. In a record-centred process, "
  "there is one record per case, every state change is a transaction against that "
  "record, and the rules are expressed as constraints that the system enforces.")
P("This distinction directly explains the failures described in the problem statement of "
  "Chapter 1. When the rule is enforced by software, an over-capacity supervisor cannot "
  "be assigned, a milestone cannot be approved out of sequence, and a weighted mark "
  "cannot be mistotalled. When the same rule is enforced by a person remembering it, all "
  "three of those failures become possible, and become likely as the cohort grows.")
P("A second theme in the literature is the separation of concerns in a web application. "
  "A decoupled architecture, in which a stateless application programming interface "
  "serves independent front-end clients, allows the same underlying record to be "
  "presented differently to a student, a supervisor and a coordinator without "
  "duplicating the business logic. The interface layer answers only the question of what "
  "a given actor is permitted to see; the rules themselves live in one place. This is "
  "the architectural principle adopted by the proposed system.")

H("Related Work and Recurring Themes", 3)
P("Studies of similar administrative systems in Malaysian public universities report a "
  "consistent set of findings. The first is that manual administration does not fail "
  "catastrophically; it fails incrementally, through duplicated entry, late "
  "identification of problems and growing administrative overhead. The second is that "
  "the largest single benefit reported after digitisation is not speed but visibility: "
  "the ability to answer the question of what is outstanding without contacting anyone.")
P("The third finding concerns adoption. Systems that are built for a single narrow task "
  "are adopted quickly but are then duplicated, so a faculty ends up with several "
  "unrelated tools that do not share a record. Systems that attempt to cover an entire "
  "process in one step are adopted slowly and often abandoned. A successful "
  "administrative system is usually one that treats a complete business process, rather "
  "than a single task, as its unit of design.")

# ---------------------------------------------------------------- 2.3
H("Problem Study of Existing Related Systems", 2)
P("This section examines three published systems that address administrative problems "
  "closely related to PSM. Each was developed at FSKTM UTHM and published in AITCS. For "
  "each system the review records its purpose, its development approach, its key "
  "features, and its limitations with respect to the requirements of this project.")

H("System 1: Web-Based UTHM Event Management System", 3)
P("Marzele and Abdul Jalil (2025) developed the Web-Based UTHM Event Management System "
  "(EMS) to replace the manual handling of event applications submitted by the "
  "approximately sixty officially registered student clubs and organisations at UTHM. "
  "The problem they identified mirrors the PSM problem closely: a recurring "
  "administrative workload, spread across many organisers, that was previously handled "
  "by hand and that consumed a disproportionate amount of student time.")
P("The system was developed using an object-oriented approach and the prototyping model, "
  "built in PHP over a central MySQL database. Its design goal was to maintain a single "
  "database holding all event-related data, so that applications and event records are "
  "not scattered across individual organisers. The system centralises event application, "
  "supports the tasks and procedures required for event data management, and makes data "
  "retrieval straightforward. The authors report that it reduces human error and is "
  "accessible from any location through a user-friendly interface.")
P("The strengths of this system are its clear problem framing and its insistence on a "
  "central database. The limitation, from the perspective of this project, is scope. The "
  "EMS manages a single business object, the event. It has no concept of a multi-stage "
  "approval chain, of capacity limiting across a shared pool of staff, or of assessment "
  "against a weighted rubric. Its prototyping approach also implies that requirements "
  "were refined empirically rather than specified in advance, which is appropriate for "
  "an event system but difficult for a process whose rules are externally mandated.")

H("System 2: FSKTM Faculty Room Reservation System (BookIT)", 3)
P("Mohd Najib and Mohd Nawi (2026) addressed a different but structurally similar "
  "problem: room booking at FSKTM still relied on manual methods, producing overlapping "
  "reservations, confusion and delayed confirmations, and the difficulty increased as "
  "the number of users grew. Their system, BookIT, is a web-based information system "
  "that modernises and centralises the booking process for students, lecturers and "
  "administrative staff.")
P("BookIT was developed with the Agile methodology, iterating through requirements "
  "gathering, development, testing and maintenance. The interface uses HTML, CSS and "
  "JavaScript, with PHP and MySQL on the server. Its features are notably closer to the "
  "requirements of an administrative process than those of the EMS: it provides "
  "real-time availability, automated booking, schedule conflict management, and "
  "automated notifications and booking confirmations. It also implements user "
  "authentication and data encryption to protect information integrity. The authors "
  "report improvements in booking efficiency, a reduction in manual errors, a faster "
  "approval process and reduced administrative workload.")
P("This system contributes two ideas that the proposed PSM system adopts. The first is "
  "conflict detection: an operation must be refused if it violates a constraint, which "
  "is the same principle as refusing a supervision assignment once capacity is "
  "exhausted. The second is role-differentiated access, since students, lecturers and "
  "administrative staff each hold different permissions over the same booking record. "
  "The limitation is that a reservation is a single-step transaction. BookIT does not "
  "model a process with multiple approval stages, comments and revisions, nor does it "
  "hold a record that must remain auditable after the process has been completed.")

H("System 3: UTHM Student Representative Council Program Management System", 3)
P("Hery and Sulaiman (2026) developed a web-based program management system for the UTHM "
  "Student Representative Council (MPP). The MPP relied on manual processes to manage "
  "student activities, which produced inefficiencies, data loss and organisational "
  "delay. The system centralises activity planning, scheduling, execution tracking and "
  "reporting, in order to improve coordination between MPP members, administrators and "
  "students.")
P("The system was built with PHP, MySQL, HTML/CSS and JavaScript, and was validated more "
  "thoroughly than the two systems above. The authors report systematic testing through "
  "43 test cases and a User Acceptance Testing exercise with 13 respondents drawn from "
  "multiple user roles. The results show 100 percent functional success, with 92.3 "
  "percent of users rating the system as excellent. The authors are also explicit about "
  "what the system does not yet do, naming notification systems and dynamic role "
  "management as future enhancements.")
P("This system is the closest of the three to the proposed project in two respects. It "
  "models a process rather than a single object, covering planning through to reporting, "
  "and it validates the system through User Acceptance Testing across several roles, "
  "which is also the testing approach adopted in this project. Its acknowledged "
  "limitations, however, identify exactly the capabilities that PSM requires: without a "
  "notification system there is no automated reminder of an approaching deadline, and "
  "without dynamic role management the permissions of a coordinator, a supervisor and an "
  "examiner cannot be expressed independently over the same record.")

# ---------------------------------------------------------------- comparison table
# The wide table goes on its own landscape page, exactly as the original
# chapter did: close the portrait section, emit caption + table, then reopen a
# portrait section for the discussion that follows.
close_section(SECT_BEFORE_TABLE)

caption("Table 2.1: Comparison of Existing Systems against the Proposed PSM Management System")

HEADER = ["Aspect", "EMS (Marzele & Abdul Jalil, 2025)",
          "BookIT (Mohd Najib & Mohd Nawi, 2026)",
          "MPP Program System (Hery & Sulaiman, 2026)",
          "Proposed PSM Management System"]
ROWS = [
    ["Purpose", "Manage event applications for student clubs",
     "Manage faculty room reservations",
     "Manage student program planning and reporting",
     "Manage the full PSM 1 and PSM 2 lifecycle"],
    ["Development approach", "Prototyping model, object-oriented",
     "Agile", "Systematic development with formal testing",
     "Waterfall model, object-oriented"],
    ["Technology", "PHP, MySQL",
     "HTML, CSS, JavaScript, PHP, MySQL",
     "PHP, MySQL, HTML/CSS, JavaScript",
     "Decoupled API with relational database"],
    ["User roles", "Event organisers and students",
     "Students, lecturers, administrative staff",
     "MPP members, administrators, students",
     "Student, supervisor, examiner, coordinator, administrator"],
    ["Central database", "Yes", "Yes", "Yes", "Yes"],
    ["Multi-stage approval workflow", "No", "Partial - approval of a request",
     "Partial", "Yes - title registration, milestones and report acceptance"],
    ["Capacity or quota enforcement", "No", "Yes - time-slot conflicts",
     "No", "Yes - per-part supervision capacity"],
    ["Automated weighted marking", "No", "No", "No",
     "Yes - the five Lampiran rubrics"],
    ["Notifications and reminders", "No", "Yes", "No (named as future work)",
     "Yes - events and scheduled deadline reminders"],
    ["Reporting and analytics", "Search and retrieval only", "Booking reports",
     "Reporting", "Cohort analytics and workload reports"],
    ["User Acceptance Testing", "Not reported", "Reported", "13 respondents, 43 test cases",
     "Planned with representative users"],
]

table = build_table(HEADER, ROWS,
                    widths_cm=[3.3, 4.7, 5.0, 5.2, 6.2], font_pt=8.5)

# The section must close AFTER the table, so the break goes on an empty
# paragraph that follows it (not on the caption that precedes it).
blank()
close_section(SECT_AFTER_TABLE)

P("Table 2.1 makes the position of the proposed system clear. All four systems share the "
  "same starting point, which is a central database replacing scattered documents, and "
  "all four share the same end users in a Malaysian public university. The three existing "
  "systems each solve one administrative concern well: event records, conflicting "
  "reservations, or program tracking. None of them treats a two-part academic process as "
  "a single governed object.")
P("The comparison also exposes two capabilities that are specific to PSM and that no "
  "existing system provides. The first is automated weighted marking. The Lampiran "
  "rubrics convert rubric scores into marks through a fixed set of non-round weights, and "
  "no reviewed system performs that conversion or governs the release of a final grade. "
  "The second is per-part supervision capacity. Only BookIT enforces a capacity rule, and "
  "it enforces it against time rather than against a person; PSM requires the limit to be "
  "applied to each academic staff member separately for PSM 1 and for PSM 2, because both "
  "batches run concurrently and share the same staff.")

# ---------------------------------------------------------------- 2.4
H("Chapter Summary", 2)
P("This chapter has reviewed the domain of final year project administration and three "
  "existing web-based systems that address closely related problems at FSKTM UTHM. The "
  "domain background established that PSM is a rules-governed, multi-actor process that "
  "runs two concurrent batches over one pool of academic staff, and that its rules are "
  "specific enough for a manual process to fail incrementally rather than loudly.")
P("The review of the EMS, BookIT and the MPP Program Management System identified three "
  "capabilities worth adopting: a single central record, constraint enforcement that "
  "refuses an invalid operation rather than warning about it, and role-differentiated "
  "access over one shared record. It also identified the common limitations of those "
  "systems. Each addresses a single concern, none models a multi-stage approval process "
  "with an auditable history, and none performs rubric-based weighted marking.")
P("The gap that this project addresses is therefore a system that treats the whole PSM "
  "process, across both parts, as one governed record. The system must enforce "
  "supervision capacity per part, generate and gate the milestone chain, replace the five "
  "printed Lampiran forms with automated weighted marking, and release a result only when "
  "every assessor has submitted. Chapter 3 describes the methodology adopted to build it.")

# final section: carries the page-number footer
# The template restarts numbering at 1 here. In this standalone chapter file
# that would print "1" on the last page, so numbering simply continues; when
# the chapter is pasted into the full thesis the section break still applies.
body.remove(sectPr)
final = copy.deepcopy(FINAL_SECT)
for pg in final.findall(qn("w:pgNumType")):
    final.remove(pg)
body.append(final)

doc.save(OUT)
print("saved:", OUT)