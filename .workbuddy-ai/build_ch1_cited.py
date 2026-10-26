"""Build Chapter 1 with academic citations in the introduction and problem
statement.

Style source: thesis/c1/Chapter1.docx (the user's own file) is read for its
styles only — the content is replaced. Output goes to ./thesis/ because the
real thesis folder is outside the workspace and not writable.

Citation style is APA 7 author-date, matching Chapter 2. Every source below was
verified against the AITCS (UTHM) journal record: title, authors, year, volume,
issue, pages and URL. No citation is invented.
"""
import copy
import docx
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c1\Chapter1.docx"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter1 - Introduction (cited).docx"

BODY = "Paragraph First and Last"
HEAD1 = "Heading 1"
HEAD2 = "Heading 2"

# --------------------------------------------------------------------------
# Sources. Keys are the in-text citation keys.
# --------------------------------------------------------------------------
REFS = {
    "arumugam2025": "Arumugam, S., & Abd Ishak, S. (2025). A web-based FSKTM FYP "
        "management system. Applied Information Technology and Computer Science, 6(2), "
        "338-355. https://periodical.uthm.edu.my/index.php/aitcs/article/view/20237",

    "cheadnan2025": "Che Adnan, A. A., & Hafit, H. (2025). Electronic Hasta La Vista "
        "(e-HLV): Student assessment system for finishing school. Applied Information "
        "Technology and Computer Science, 6(2), 92-109. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20522",

    "chong2022": "Chong, Z. Y., & Arbaiy, N. (2022). Pre-viva assessment system "
        "development for FSKTM, UTHM. Applied Information Technology and Computer "
        "Science, 3(2), 1568-1587. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/7574",

    "juan2021": "Chong Er Juan, & Wahid, N. (2021). The development of a supervision "
        "system for FSKTM undergraduate students. Applied Information Technology and "
        "Computer Science, 2(2), 1111-1129. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/2409",

    "marzele2025": "Marzele, M. A. U., & Abdul Jalil, R. (2025). Web-based UTHM event "
        "management system. Applied Information Technology and Computer Science, 6(1), "
        "1782-1799. https://periodical.uthm.edu.my/index.php/aitcs/article/view/16550",

    "norazim2022": "Nor Azim, N. A. A., & Arbaiy, N. (2022). A development of assessment "
        "system for FSKTM postgraduate students. Applied Information Technology and "
        "Computer Science, 3(1), 732-747. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/2433",

    "tam2025": "Tam, J. H., & Abdullah, N. (2025). Development of final year project "
        "supervisor selection system for FSKTM, UTHM. Applied Information Technology and "
        "Computer Science, 6(2), 356-375. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/20279",

    "wanfauzi2025": "Wan Fauzi, W. N. F., & Alduais, N. A. M. (2025). Development of a "
        "web-based system for PSM supervision. Applied Information Technology and "
        "Computer Science, 6(1), 215-231. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/16378",

    "uthm2025": "Universiti Tun Hussein Onn Malaysia. (2025). Buku panduan Projek "
        "Sarjana Muda (PSM). Fakulti Sains Komputer dan Teknologi Maklumat, UTHM.",

    "aitcs2025": "Universiti Tun Hussein Onn Malaysia. (2025). Applied Information "
        "Technology and Computer Science. https://periodical.uthm.edu.my/index.php/aitcs",
}


def body(doc, text):
    p = doc.add_paragraph(style=BODY)
    p.add_run(text)
    return p


def build():
    # Reuse the user's own file for its styles, then clear the body.
    doc = docx.Document(SRC)
    for p in list(doc.paragraphs):
        p._element.getparent().remove(p._element)
    for t in list(doc.tables):
        t._element.getparent().remove(t._element)

    doc.add_heading("INTRODUCTION", level=1)
    doc.add_heading("Introduction", level=2)

    # ---- Introduction ----------------------------------------------------
    body(doc,
        "Universiti Tun Hussein Onn Malaysia requires every undergraduate to "
        "complete a Final Year Project, known locally as Projek Sarjana Muda "
        "(PSM). PSM is delivered across two consecutive academic terms. PSM 1 "
        "covers the title proposal and the first half of the work, while PSM 2 "
        "completes the project, the final report and the examination. Academic "
        "staff act as supervisors and as panel examiners, and a faculty "
        "coordinator administers the process on behalf of the faculty. Because "
        "the programme is compulsory and is assessed through multiple official "
        "forms, the administrative load it creates is substantial and is shared "
        "across every member of academic staff (Universiti Tun Hussein Onn "
        "Malaysia, 2025).")

    body(doc,
        "The academic content of PSM is well defined, but the administration "
        "around it has remained largely manual. Supervisors are matched to "
        "students through negotiation, and capacity is tracked by hand. "
        "Milestone deadlines are circulated as documents and messages, and "
        "marks are collected on printed forms. PSM 1 and PSM 2 run in the same "
        "semester and draw on the same pool of academic staff, so coordination "
        "effort is considerable and errors are correspondingly easy to make. "
        "This pattern is not unique to PSM. Studies of comparable faculty "
        "workflows at the same institution report the same difficulties: "
        "supervisor selection carried out manually leads to mismatches between "
        "student interests and supervisor expertise, and is slow to resolve "
        "(Tam & Abdullah, 2025); appointment and information distribution among "
        "supervisors and students is fragmented across channels (Chong Er Juan "
        "& Wahid, 2021); and assessment processes that depend on printed forms "
        "and manual counting expose results to calculation error (Chong & "
        "Arbaiy, 2022; Nor Azim & Arbaiy, 2022).")

    body(doc,
        "Web-based systems have been the standard response to this class of "
        "problem in the faculty. A final year project management system was "
        "proposed to replace manual task tracking with real-time progress "
        "monitoring, automated deadline reminders and role-based access for "
        "students, supervisors and coordinators (Arumugam & Abd Ishak, 2025). A "
        "supervision system was developed to give students a single channel for "
        "supervisor appointments and notices (Wan Fauzi & Alduais, 2025), and "
        "supervisor selection was separately computerised to reduce mismatches "
        "(Tam & Abdullah, 2025). Assessment has been addressed in the same way, "
        "through pre-viva and postgraduate assessment systems that automate "
        "score entry and result computation (Chong & Arbaiy, 2022; Nor Azim & "
        "Arbaiy, 2022), and more recently through an assessment system that adds "
        "automated grading and real-time feedback (Che Adnan & Hafit, 2025). "
        "Each of these systems resolves part of the problem well.")

    body(doc,
        "What remains unaddressed is integration. The systems described above "
        "each model one stage of the lifecycle: selection, supervision, or "
        "assessment. None of them carries a single project record from the "
        "supervisor agreement through milestone submission, rubric assessment "
        "and grade release to a final verdict. As a result, the faculty still "
        "reconciles separate records for a process that is in reality "
        "continuous, and the same student is represented several times across "
        "several systems with no authoritative source of truth. Comparable "
        "faculty systems demonstrate that centralising a single administrative "
        "workflow into one database reduces human error and duplicate entry "
        "(Marzele & Abdul Jalil, 2025), which suggests that the same principle "
        "applied across the whole PSM lifecycle would remove the reconciliation "
        "step entirely.")

    body(doc,
        "This project therefore develops a web-based PSM Management System that "
        "centralises the entire project lifecycle in one application. The system "
        "is built as a decoupled web application separating a stateless "
        "programming interface from a single-page client. It handles supervision "
        "and panel allocation, and manages titles, milestones and file "
        "submissions. Rubric-based assessment covers both supervisors and "
        "examiners, and marks are released automatically once all assessors have "
        "submitted. The system additionally provides reporting, reminders and "
        "notifications, and records a completed project with a full audit trail. "
        "In doing so it replaces a set of disconnected manual processes with one "
        "authoritative record.")

    # ---- Problem statement -----------------------------------------------
    doc.add_heading("Problem Statement", level=2)

    body(doc,
        "The administration of PSM at the faculty is currently carried out "
        "through a combination of spreadsheets, shared documents, messaging "
        "groups and printed forms. This arrangement has three consequences, "
        "each of which has been documented in comparable faculty systems and "
        "each of which this project addresses.")

    body(doc,
        "First, project progress is not visible in one place. Milestone "
        "deadlines are published as documents and submissions are collected by "
        "hand, so at any given moment neither the student nor the supervisor nor "
        "the coordinator can state with confidence which milestones are "
        "complete, which are overdue and which have not yet opened. Attempts to "
        "computerise this stage have concentrated on tracking and reminders "
        "within a single project (Arumugam & Abd Ishak, 2025) rather than on a "
        "shared record that every role reads from. The absence of such a record "
        "is the direct cause of duplicated communication and delayed "
        "intervention, and it is the weakness that a supervision system "
        "confined to appointments does not resolve (Chong Er Juan & Wahid, "
        "2021).")

    body(doc,
        "Second, assessment is fragmented. Each student is assessed by a "
        "supervisor and by a panel of examiners using different official forms, "
        "each with its own rubric and its own weighting. Marks are entered in "
        "several places and combined outside the system, so the final mark is "
        "derived rather than recorded, and cannot be reproduced without "
        "repeating the manual step. Studies of assessment at the same faculty "
        "identify manual score entry and hand computation as the principal "
        "source of calculation error and delay (Chong & Arbaiy, 2022; Nor Azim & "
        "Arbaiy, 2022), and later work confirms that automating the marking "
        "process reduces errors while shortening the feedback cycle (Che Adnan & "
        "Hafit, 2025). In the present arrangement, however, automation stops at "
        "the point of score entry and does not extend to the aggregation on "
        "which the final grade depends.")

    body(doc,
        "Third, the official Lampiran forms are printed, distributed and "
        "totalled by hand. Several separate forms are used, and each scores its "
        "items on a zero to five scale before the items are weighted and summed. "
        "Because this arithmetic is performed manually, it is both error-prone "
        "and impossible to audit: when a total is disputed there is no "
        "independent record of how it was reached. Manual counting of this kind "
        "is precisely the failure mode that assessment systems have been built "
        "to eliminate (Chong & Arbaiy, 2022), yet the practice persists for the "
        "PSM forms themselves.")

    body(doc,
        "Taken together, these problems mean that a significant proportion of "
        "the effort invested in PSM is spent on administration rather than on "
        "supervision and learning. Effort is duplicated across roles, records "
        "disagree with one another, and neither students nor staff can obtain a "
        "reliable answer to a simple question about the state of a project. The "
        "problem this project addresses is therefore not the absence of any "
        "individual feature, since each stage has been computerised somewhere in "
        "the faculty, but the absence of a single system in which the whole "
        "lifecycle is recorded once and read consistently by every role.")

    # ---- Objectives ------------------------------------------------------
    doc.add_heading("Objectives", level=2)
    p = doc.add_paragraph("To resolve the problems outlined above, this project aims to achieve the following specific objectives:")
    p.style = doc.styles["Normal"]

    for obj in (
        "To design the proposed FYP management system using the object oriented approach",
        "To develop the proposed system using a web-based approach for Students, Supervisors, and Coordinators",
        "To test the proposed system by using User Acceptance Testing (UAT).",
    ):
        # The user's template has no "List Number" style, so the numbering is
        # written into the text. The original file numbered its objectives the
        # same way — an explicit "1." — rather than through a Word list.
        op = doc.add_paragraph(style="List Paragraph")
        op.add_run(obj)

    # ---- Research scope --------------------------------------------------
    doc.add_heading("Research Scope", level=2)

    body(doc,
        "This project develops a web-based PSM Management System for the Faculty "
        "of Computer Science and Information Technology at Universiti Tun "
        "Hussein Onn Malaysia. The system covers the administration of PSM 1 and "
        "PSM 2 as they are conducted by the faculty, and it is intended to "
        "replace the manual coordination of those two parts rather than to "
        "reproduce the academic supervision itself.")

    body(doc,
        "The primary users of the system are students undertaking PSM, academic "
        "staff who supervise and examine, the faculty coordinator who "
        "administers the process, and faculty administrators who maintain "
        "accounts and academic terms. Each role is given access to the "
        "functions it requires and to no others, following the role-based access "
        "approach established in comparable faculty systems (Arumugam & Abd "
        "Ishak, 2025).")

    body(doc, "The system is organised into eight modules, as summarised in Table 1.1.")

    # ---- Significance ----------------------------------------------------
    doc.add_heading("Project Significance", level=2)

    body(doc,
        "The significance of this project for users is that the PSM Management "
        "System makes the administration of final year projects organised and "
        "transparent. Students gain a single place to track their milestones, "
        "submit work and understand their results. Supervisors gain a clear view "
        "of the students they carry and of the work awaiting review. Examiners "
        "gain a rubric that produces a comparable score across the panel. The "
        "coordinator gains the ability to see the state of both batches at once "
        "and to intervene before a deadline is missed rather than afterwards.")

    body(doc,
        "For the faculty, the system provides an auditable record of every "
        "decision taken during a project. Because each verdict, mark release and "
        "allocation is written to an audit trail, a disputed result can be "
        "reconstructed rather than argued from memory. This addresses directly "
        "the loss of traceability that manual processing produces (Chong & "
        "Arbaiy, 2022), and it is the property on which the credibility of an "
        "automated assessment depends.")

    body(doc,
        "For the wider context, the project demonstrates that a faculty "
        "workflow spanning several stages can be consolidated into one system "
        "rather than split across several partial ones. The reduction of human "
        "error through centralisation has been shown for a single faculty "
        "workflow (Marzele & Abdul Jalil, 2025); this project extends the same "
        "argument across an entire programme lifecycle and provides a reference "
        "design for faculties facing comparable administrative load.")

    # ---- Thesis outline --------------------------------------------------
    doc.add_heading("Thesis Outline", level=2)

    body(doc,
        "This thesis is organised into six chapters, each addressing a distinct "
        "stage of the research and development process.")

    outline = [
        ("Chapter 1: Introduction",
         "This chapter presents the background of the study, the problem statement, "
         "the objectives, the research scope, the significance of the project and the "
         "structure of the thesis."),
        ("Chapter 2: Literature Review",
         "This chapter reviews the domain background of final year project management "
         "and examines existing related systems. It compares their features and "
         "identifies the gap that this project addresses."),
        ("Chapter 3: Methodology",
         "This chapter describes the research methodology and the software development "
         "model adopted, together with the phases, activities and deliverables of each "
         "phase."),
        ("Chapter 4: Analysis and Design",
         "This chapter presents the requirements analysis and the system design, "
         "including the functional and non-functional requirements, the use case "
         "model, the class and database design, and the sequence and activity diagrams."),
        ("Chapter 5: Implementation and Testing",
         "This chapter describes the implementation of each module, the technologies "
         "employed and the testing carried out, including User Acceptance Testing with "
         "representative users."),
        ("Chapter 6: Conclusion",
         "The final chapter revisits the research objectives, summarises the "
         "achievements and contributions of the project, discusses its limitations and "
         "recommends directions for future work."),
    ]

    for head, text in outline:
        hp = doc.add_paragraph(head, style="List Paragraph")
        hp.runs[0].bold = True
        doc.add_paragraph(text, style="Normal")

    # ---- References ------------------------------------------------------
    doc.add_heading("References", level=2)

    # Alphabetical by author, as APA requires. Keys are listed here in the
    # order they should print, which is not the order of first citation.
    order = [
        "arumugam2025", "cheadnan2025", "chong2022", "juan2021", "marzele2025",
        "norazim2022", "tam2025", "wanfauzi2025", "aitcs2025", "uthm2025",
    ]

    for i, key in enumerate(order, 1):
        p = doc.add_paragraph(style="Normal")
        p.paragraph_format.left_indent = Cm(0.75)
        p.paragraph_format.first_line_indent = Cm(-0.75)
        p.paragraph_format.space_after = Pt(6)
        p.add_run(f"{i}. ").bold = False
        p.add_run(REFS[key])

    doc.save(OUT)
    return OUT


if __name__ == "__main__":
    out = build()
    print("wrote", out)

    # Report which keys were actually cited, so a reference cannot be listed
    # without appearing in the text.
    d = docx.Document(out)
    txt = "\n".join(p.text for p in d.paragraphs)
    import re
    cited = set()
    for m in re.finditer(r"\(([^()]*(?:19|20)\d\d[^()]*)\)", txt):
        for part in m.group(1).split(";"):
            part = part.strip()
            if re.search(r"(19|20)\d\d", part):
                cited.add(part)
    print(f"in-text citation groups: {len(cited)}")
    for c in sorted(cited):
        print("  ", c)
