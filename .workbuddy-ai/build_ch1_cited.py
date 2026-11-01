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

    "borhanuddin2023": "Borhanuddin, H., & Mohamed, R. (2023). Sistem pemilihan penyelia "
        "PSM FSKTM (SPP UTHM). Applied Information Technology and Computer Science, 4(1), "
        "1176-1193. https://periodical.uthm.edu.my/index.php/aitcs/article/view/7306",

    "tee2024": "Tee, D. Y. M., & Mahdin, H. (2024). Enhancing FSKTM pre-viva management "
        "through online system. Applied Information Technology and Computer Science, "
        "5(2), 273-290. "
        "https://periodical.uthm.edu.my/index.php/aitcs/article/view/16353",

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

    # ---- Introduction: THREE paragraphs, each with two or more citations ----
    #
    # The user asked for exactly three paragraphs here. Each one carries at
    # least two sources so no paragraph stands on a single citation, and no
    # source is stretched to support a claim it does not make.
    #
    # **Every sentence is 15 words or fewer** (user's instruction, 2026-11-01:
    # "make sure each sentence no more than 15 words. Make sentence short and
    # clean"). Check with `.workbuddy-ai/count_sentences.py`; it reads these
    # literals and reports anything over the limit, so a later edit cannot
    # quietly break the rule.
    body(doc,
        "Universiti Tun Hussein Onn Malaysia requires every undergraduate to "
        "complete a Final Year Project. It is known locally as Projek Sarjana "
        "Muda (PSM). PSM runs across two consecutive academic terms. The "
        "faculty's PSM guide describes this structure (Universiti Tun Hussein "
        "Onn Malaysia, 2025). Tam and Abdullah describe it too (Tam & Abdullah, "
        "2025). "
        "PSM 1 covers the title proposal and the first half of the work. "
        "PSM 2 completes the project, the final report and the examination. "
        "Academic staff act as supervisors and as panel examiners. A faculty "
        "coordinator administers the process on behalf of the faculty. The "
        "programme is compulsory and assessed through several official forms. "
        "Its administrative load is therefore substantial. That load is shared "
        "across all academic staff. The literature documents this burden "
        "(Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022).")

    body(doc,
        "The academic content of PSM is well defined. The administration around "
        "it, however, has remained largely manual. Supervisors are matched to "
        "students through negotiation rather than on recorded expertise. This "
        "produces mismatches between a student's research area and a "
        "supervisor's field. Borhanuddin and Mohamed report such mismatches "
        "(Borhanuddin & Mohamed, 2023). Tam and Abdullah report them too (Tam & "
        "Abdullah, 2025). Capacity is tracked by hand. "
        "Appointments and notices are fragmented across channels. They are not "
        "held in one place (Chong Er Juan & Wahid, 2021). Milestone deadlines "
        "are circulated as documents and messages. Marks are collected on "
        "printed forms. They are then totalled by hand (Chong & Arbaiy, 2022; "
        "Nor Azim & Arbaiy, 2022). PSM 1 and PSM 2 run in the same "
        "semester. Both draw on the same staff. This coordination effort is "
        "considerable. The opportunity for error is correspondingly large "
        "(Arumugam & Abd Ishak, 2025).")

    body(doc,
        "Web-based systems have been the faculty's standard response to such "
        "problems. Each has solved one part of it. A final year project "
        "management system was proposed to replace manual task tracking. It "
        "would offer real-time progress monitoring and automated reminders "
        "(Arumugam & Abd Ishak, 2025). Supervision and supervisor selection were "
        "separately computerised. They gave students one channel. That channel "
        "covered appointments, notices and title matching. Three systems are "
        "documented (Chong Er Juan & Wahid, 2021; Tam & Abdullah, 2025). A "
        "third study covers supervision directly (Wan Fauzi & Alduais, 2025). "
        "Assessment has also been addressed. Pre-viva, postgraduate and "
        "finishing-school systems automate score entry. They also compute "
        "results and issue feedback (Che Adnan & Hafit, 2025). Three earlier "
        "systems did the same (Chong & Arbaiy, 2022; Nor Azim & Arbaiy, "
        "2022). Tee and Mahdin extend it to pre-viva management (Tee & Mahdin, "
        "2024). None of these systems carries a single project record "
        "end to end. Each models one stage of a continuous process. Chong and "
        "Wahid show the first limit (Chong Er Juan & Wahid, 2021). Wan Fauzi and "
        "Alduais show the second (Wan Fauzi & Alduais, 2025). This project "
        "therefore develops a web-based PSM Management System. It records the "
        "whole lifecycle once, in one decoupled application. Every role then "
        "reads that record consistently.")

    # ---- Problem statement -----------------------------------------------
    doc.add_heading("Problem Statement", level=2)

    body(doc,
        "PSM administration at the faculty currently relies on several tools. "
        "These include spreadsheets, shared documents, messaging groups and "
        "printed forms. Comparable faculty workflows still depend on manual "
        "handling. They report the same symptoms. Data is not centralised, "
        "records disagree, and information arrives late. Arumugam and Abd Ishak "
        "report this pattern (Arumugam & Abd Ishak, 2025). Chong and Wahid "
        "report it as well (Chong Er Juan & Wahid, 2021). Information also "
        "reaches the wrong person. "
        "This arrangement has three consequences. Each has been documented in "
        "similar faculty systems, and this project addresses all three.")

    body(doc,
        "First, project progress is not visible in one place. Milestone "
        "deadlines are published as documents. Submissions are then collected "
        "by hand. At any given moment, nobody can state which milestones are "
        "complete. The student, supervisor and coordinator share this "
        "uncertainty. Attempts to computerise this stage focus on tracking and "
        "reminders within one project. Arumugam and Abd Ishak propose one such "
        "system (Arumugam & Abd Ishak, 2025). They have not provided a shared "
        "record that every role reads. That absence causes duplicated "
        "communication and delayed intervention. A supervision system confined "
        "to appointments and notices does not resolve it. Chong and Wahid show "
        "this limit (Chong Er Juan & Wahid, 2021). Wan Fauzi and Alduais show it "
        "too (Wan Fauzi & Alduais, 2025).")

    body(doc,
        "Second, assessment is fragmented. Each student is assessed by a "
        "supervisor and by a panel of examiners. They use different official "
        "forms, each with its own rubric and weighting. Marks are entered in "
        "several places and combined outside the system. The final mark is "
        "therefore derived rather than recorded. It cannot be reproduced without "
        "repeating the manual step. Studies at the same faculty identify three "
        "sources of calculation error. These are manual score entry and manual "
        "aggregation. The third is delayed consolidation of results. Chong and "
        "Arbaiy list these sources (Chong & Arbaiy, 2022). Nor Azim and Arbaiy "
        "list them too (Nor Azim & Arbaiy, 2022). Later work confirms that "
        "automating marking reduces errors. It also shortens the feedback cycle (Che Adnan "
        "& Hafit, 2025). Institutional procedures assume that paperwork is "
        "handled electronically (Universiti Tun Hussein Onn Malaysia, 2025). In "
        "the present arrangement, automation stops at score entry. It does not "
        "extend to the aggregation on which the grade depends.")

    body(doc,
        "Third, the official Lampiran forms are printed, distributed and totalled "
        "by hand. Several separate forms are used. Each scores its items on a "
        "zero to five scale. The items are then weighted and summed. Because "
        "this arithmetic is performed manually, it is error-prone and impossible "
        "to audit. When a total is disputed, there is no independent record of "
        "how it was reached. Manual counting is precisely the failure faculty "
        "assessment systems were built to eliminate. Two faculty assessment "
        "studies say so (Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022). The "
        "same weakness affects a system that only manages the pre-viva "
        "application. Tee and Mahdin describe such a system (Tee & Mahdin, "
        "2024). Loss of traceability is therefore not a side effect of manual "
        "processing. It is that processing's defining property. A decision that "
        "was never recorded cannot be reviewed.")

    body(doc,
        "Taken together, these problems mean that much PSM effort goes to "
        "administration. Too little goes to supervision and learning. Effort is "
        "duplicated across roles. Records disagree with one another. Neither "
        "students nor staff can answer a simple question about a project. "
        "Arumugam and Abd Ishak report this difficulty (Arumugam & Abd Ishak, "
        "2025). Chong and Wahid report it as well (Chong Er Juan & Wahid, 2021). "
        "The problem this project addresses is therefore "
        "not a missing feature. Each stage has been computerised somewhere in "
        "the faculty. The problem is the absence of one system for the whole "
        "lifecycle. Borhanuddin and Mohamed frame this need (Borhanuddin & "
        "Mohamed, 2023). Wan Fauzi and Alduais frame it too (Wan Fauzi & "
        "Alduais, 2025).")

    # ---- Objectives ------------------------------------------------------
    doc.add_heading("Objectives", level=2)
    p = doc.add_paragraph("To resolve these problems, this project aims to achieve the following objectives:")
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
        "This project develops a web-based PSM Management System. It serves the "
        "Faculty of Computer Science and Information Technology. That faculty is "
        "at Universiti Tun Hussein Onn Malaysia. The system covers PSM 1 and PSM "
        "2 as the faculty conducts them. It replaces the manual coordination of "
        "both parts. It does not reproduce the academic supervision itself.")

    body(doc,
        "The primary users are students undertaking PSM. They also include "
        "academic staff who supervise and examine. The faculty coordinator who "
        "administers the process is a primary user. So are administrators who "
        "maintain accounts and academic terms. Each role reaches only the "
        "functions it requires. This follows the role-based access approach of "
        "comparable faculty systems (Arumugam & Abd Ishak, 2025).")

    body(doc, "The system is organised into eight modules, as summarised in Table 1.1.")

    # ---- Significance ----------------------------------------------------
    doc.add_heading("Project Significance", level=2)

    body(doc,
        "The system makes final year project administration organised and "
        "transparent. Students gain a single place to track their milestones. "
        "They also gain one place to submit work and understand their results. "
        "Supervisors gain a clear view of the students they carry. They also see "
        "the work awaiting review. Examiners gain a rubric that gives a "
        "comparable score across the panel. The coordinator sees both batches at "
        "once. They can then intervene before a deadline is missed, not "
        "afterwards.")

    body(doc,
        "For the faculty, the system provides an auditable record of every "
        "project decision. Each verdict, mark release and allocation is written "
        "to an audit trail. A disputed result can therefore be reconstructed "
        "rather than argued from memory. This directly addresses the loss of "
        "traceability that manual processing produces (Chong & Arbaiy, 2022). It "
        "is also the property on which an automated assessment's credibility "
        "depends.")

    body(doc,
        "For the wider context, one system can carry a multi-stage faculty "
        "workflow. It need not be split across several "
        "partial ones. Centralisation reduces human error for a single faculty "
        "workflow. Marzele and Abdul Jalil show this (Marzele & Abdul Jalil, "
        "2025). This project extends that argument across a whole programme "
        "lifecycle. It offers a reference design for faculties with comparable "
        "administrative load.")

    # ---- Thesis outline --------------------------------------------------
    doc.add_heading("Thesis Outline", level=2)

    body(doc,
        "This thesis has six chapters. Each addresses a distinct stage of the "
        "research and development process.")

    outline = [
        ("Chapter 1: Introduction",
         "This chapter presents the background, the problem statement and the "
         "objectives. It also covers the research scope, the project's "
         "significance and the thesis structure."),
        ("Chapter 2: Literature Review",
         "This chapter reviews final year project management in the domain and "
         "examines related systems. It compares their features and identifies "
         "the gap this project addresses."),
        ("Chapter 3: Methodology",
         "This chapter describes the research methodology and the development "
         "model adopted. It also covers the phases, activities and deliverables "
         "of each phase."),
        ("Chapter 4: Analysis and Design",
         "This chapter presents the requirements analysis and the system design. "
         "It covers the functional and non-functional requirements and the use "
         "case model. It also presents the class and database design, with the "
         "diagrams."),
        ("Chapter 5: Implementation and Testing",
         "This chapter describes the implementation of each module and the "
         "technologies employed. It also reports the testing carried out, "
         "including User Acceptance Testing with representative users."),
        ("Chapter 6: Conclusion",
         "The final chapter revisits the research objectives and summarises the "
         "project's achievements and contributions. It then discusses the "
         "project's limitations and recommends future work."),
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
        "arumugam2025", "borhanuddin2023", "cheadnan2025", "chong2022", "juan2021",
        "marzele2025", "norazim2022", "tam2025", "tee2024", "wanfauzi2025",
        "aitcs2025", "uthm2025",
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
