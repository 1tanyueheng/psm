"""Rebuild Chapter 3 (Methodology) from the template, with the prototype model.

The template `thesis/c3/Chapter3.docx` is a leftover from a different project:
its text is about hybrid metaheuristics and a system called DPays, and its
"Prototype Model" heading sits above a paragraph that describes the Waterfall
model. The user asked for the content replaced with the prototyping method while
keeping **the paragraphing, the numbering and the formatting**.

So this script edits in place:

    * every run of text is replaced, the paragraph object is not;
    * the number of paragraphs is unchanged, in the same order;
    * headings keep `Heading 2`, the numbered headings keep their `3.x` / `3.x.y`
      literal numbering, captions keep their centring and bold;
    * Table 3.1 keeps its position and its two columns, and its rows are rewritten
      to the research questions this project actually has.

The only structural change is Figure 3.1: the template's picture and its caption
paragraph are replaced by the new prototype figure and a caption in the same
position, because a figure of a different method cannot be kept.
"""
import copy
import docx
from docx.shared import Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH

SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c3\Chapter3.docx"
OUT = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
       r"\Chapter3 - Methodology.docx")
FIG = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
       r"\diagrams\prototype_model.png")

# ---------------------------------------------------------------------------
# Paragraph index -> new text. The indices are the template's own, so the
# paragraph count and order cannot drift.
# ---------------------------------------------------------------------------
TEXT = {
    5: "This chapter outlines the methodology employed in the design, development and "
       "evaluation of the PSM Assessment System. It describes the software process model "
       "selected for the project and justifies that choice, then sets out each phase of "
       "the model in the order it was carried out, together with the activities and "
       "deliverables of each phase. The chapter closes by mapping the project's "
       "objectives to the phases that satisfy them.",

    # 3.2 body: the heading says Prototype Model, so the body must describe
    # prototyping, not waterfall.
    8: "The Prototyping model is a software process model in which a working version of "
       "the system is built early, shown to the client, and refined repeatedly until the "
       "client is satisfied. Unlike the Waterfall model, which follows a straight and "
       "sequential workflow where each phase is completed before the next begins, "
       "prototyping accepts that requirements become clear through use rather than through "
       "documentation alone, and it therefore plans for the initial design to be wrong "
       "(Chong Er Juan & Wahid, 2021). This model was selected for the PSM Assessment "
       "System because the administrative rules it must enforce are not stated anywhere in "
       "a form the developer can read. The official Lampiran forms fix the weightings and "
       "the marking scale, but the workflow around them exists only in the practice of the "
       "coordinator, and the faculty's own senior project reports describe the difficulties "
       "that arise when these working assumptions are never written down "
       "(Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022). A prototype lets the coordinator "
       "see a proposed rule in operation and correct it, which is considerably cheaper than "
       "correcting it after a framework built on the wrong rule has been completed. "
       "Prototyping has been adopted for the same reason by comparable faculty systems, "
       "including the FSKTM supervision system and the assessment systems that followed it "
       "(Chong Er Juan & Wahid, 2021; Che Adnan & Hafit, 2025). This model is illustrated in "
       "Figure 3.1, and it consists of requirement gathering and analysis, quick design, "
       "building a prototype, client evaluation, refining the prototype, and implementation "
       "and maintenance.",

    # 3.2.1
    14: "This phase gathered the requirements of the PSM Assessment System from the official "
        "faculty forms, the faculty's PSM guide and the practice of the coordinator. The "
        "Lampiran A and Lampiran B forms established the registration fields, and the five "
        "assessment Lampiran established the rubrics, their item scales and their weightings "
        "(Universiti Tun Hussein Onn Malaysia, 2025). The phase also reviewed how comparable "
        "faculty systems handled the same domain, which identified the capabilities that the "
        "existing systems already provide and the ones they leave out (Arumugam & Abd Ishak, "
        "2025; Wan Fauzi & Alduais, 2025). It established the functional and non-functional "
        "requirements necessary for the proposed system, which are recorded in Chapter 4. "
        "This phase directly supports Objective 1, which is to design the proposed system "
        "using the object oriented approach, by fixing the scope that the design has to "
        "cover.",

    # 3.2.2
    17: "This phase turned the requirements into a design that could be built and reviewed. "
        "The use cases were modelled, the domain classes were identified and the database "
        "schema was derived from them, so that every rule gathered in the first phase had a "
        "place in the design rather than a place in the developer's memory "
        "(Tam & Abdullah, 2025). The output of this phase is the class diagram, the data "
        "dictionary and the sequence and activity diagrams presented in Chapter 4. Together "
        "with Phase 1, this phase supports Objective 1 by laying the groundwork for a design "
        "that can be reviewed against the requirements before any code is written.",
        #

    # 3.2.3
    20: "In this phase the proposed system was implemented as a working prototype. It was "
        "built as a decoupled web application, with a stateless Laravel application "
        "programming interface over a PostgreSQL database and a React single-page client, "
        "because that separation lets a rule change on the server reach every user without "
        "the client being rebuilt, and because the web based approach is the one the "
        "comparable faculty systems adopted (Arumugam & Abd Ishak, 2025; CIO Wiki, 2023). "
        "Access is controlled by role based access control, so a student, a supervisor, an "
        "examiner and a coordinator each hold different permissions over the same record "
        "(NIST, 2022). The prototype covered the six use cases specified in Chapter 4: login "
        "and authentication, manage title and registration, manage milestone, manage "
        "evaluation, generate report, and manage alert and notification. This phase "
        "directly supports Objective 2, which is to develop the proposed system using a web "
        "based approach for students, supervisors and coordinators.",

    # 3.2.4
    23: "The prototype was then demonstrated to its users and evaluated. The coordinator "
        "walked through the registration, milestone and term-close rules; supervisors "
        "reviewed the acknowledgement, the milestone decision and the evaluation form; and "
        "students worked through registration and submission. Each session was recorded "
        "against a checklist drawn from the requirements of Phase 1, and every rule that was "
        "disputed or found to be wrong was written down rather than corrected on the spot. "
        "Client evaluation is the step that makes prototyping worth its cost, because a rule "
        "that reads correctly in a specification can still be rejected by the person who has "
        "to apply it (Chong Er Juan & Wahid, 2021).",

    # 3.2.5
    26: "The findings of each evaluation were folded back into the prototype and the affected "
        "phases were repeated, which is the feedback loop shown in Figure 3.1. Where a rule "
        "had been modelled wrongly, the correction began at requirement gathering rather than "
        "at the screen, so the change reached the schema, the service and the interface "
        "together instead of being patched into the interface alone. This phase produced the "
        "system described in Chapter 5, and it is the phase that supports Objective 2 "
        "directly, because the requirements were refined by the users themselves rather than "
        "by the developer on their behalf.",

    # 3.3 body
    33: "This chapter has presented the methodology adopted for the design and implementation "
        "of the proposed PSM Assessment System. The Prototyping model was selected because "
        "the administrative rules of PSM are held in the practice of the faculty rather than "
        "in a document, and prototyping is the process that allows those rules to be "
        "discovered and corrected by the people who apply them. The chapter set out the five "
        "phases carried out, from requirement gathering through to implementation and "
        "maintenance, and showed how the objectives of the project are satisfied by the "
        "phases. The next chapter presents the analysis and design produced by those phases.",

    # 30 — the paragraph that introduces Table 3.1. It spoke about research
    # questions; the table maps research objectives.
    30: "To ensure alignment between methodology and evaluation, each research objective "
        "(RO) is mapped to a specific evaluation focus as shown in Table 3.1.",
}

# Captions: literal numbering preserved where the template had it.
CAPTIONS = {
    11: "Figure 3.1 : Prototype model",
    28: "Table 3.1:\tMapping of Research Objectives with Evaluation Focus",

    # Phase headings. The template's Heading 2 style auto-numbers these, so the
    # text must NOT repeat the number: writing "3.2.1 Phase 1: ..." printed the
    # number twice. Only the words after the number are replaced.
    13: "Phase 1: Requirement Gathering and Analysis",
    16: "Phase 2: Quick Design",
    19: "Phase 3: Build a Prototype",
    22: "Phase 4: Client Evaluation",
    25: "Phase 5: Refining the Prototype and Implementation",
}

# Table 3.1's rows, rewritten for this project.
TABLE_ROWS = [
    ("Research Objective", "Evaluation Focus"),
    ("RO1: To design the proposed system using the object oriented approach",
     "Correctness and completeness of the class, use case and database design"),
    ("RO2: To develop the proposed system using a web based approach",
     "Each of the six use cases working through the browser for its actor"),
    ("RO3: To test the proposed system using User Acceptance Testing",
     "Feedback from students, supervisors and the coordinator on the deployed system"),
]


def replace_text(paragraph, text):
    """Replace a paragraph's text, keeping the first run's formatting.

    Paragraph formatting (style, alignment, indentation, spacing) lives on the
    paragraph, not the run, so it is untouched by this.
    """
    runs = paragraph.runs
    if not runs:
        paragraph.add_run(text)
        return
    runs[0].text = text
    for extra in runs[1:]:
        extra._element.getparent().remove(extra._element)


def main():
    doc = docx.Document(SRC)
    before = [(p.style.name, p.alignment) for p in doc.paragraphs]

    # --- text -------------------------------------------------------------
    for idx, text in TEXT.items():
        p = doc.paragraphs[idx]
        replace_text(p, text)
        print(f"paragraph {idx}: {len(text.split())} words")

    for idx, text in CAPTIONS.items():
        replace_text(doc.paragraphs[idx], text)
        print(f"caption {idx}: {text}")

    # --- Figure 3.1 -------------------------------------------------------
    # The template's caption paragraph is index 11. Its picture lives in one of
    # the empty paragraphs just above it; find it, empty it, and put the new
    # figure there so the caption keeps its place.
    target = None
    for i in range(9, 12):
        if doc.paragraphs[i]._p.findall(".//" + docx.oxml.ns.qn("w:drawing")):
            target = doc.paragraphs[i]
            break
    if target is None:
        target = doc.paragraphs[10]
        for r in list(target.runs):
            r._element.getparent().remove(r._element)
    else:
        for r in list(target.runs):
            r._element.getparent().remove(r._element)

    target.alignment = WD_ALIGN_PARAGRAPH.CENTER
    target.add_run().add_picture(FIG, width=Cm(15.0))
    print("figure replaced with prototype_model.png")

    # --- Table 3.1 --------------------------------------------------------
    #
    # The template table's data rows carried vertical merges (the old RQ1-RQ5
    # list was grouped), so writing cell text alone left the merged remnants
    # ("Comparison with other hybrid metaheuristics") below the new rows. Each
    # data row is therefore rebuilt from scratch: same row count, same two
    # columns, same cell widths, but no merged cells and no leftover text.
    t = doc.tables[0]
    assert len(t.columns) == 2, f"expected 2 columns, found {len(t.columns)}"

    # Strip every vertical merge across the whole table first: a vMerge
    # continuation row is a phantom of the template's grouping and is what left
    # "RQ1 / Effectiveness of dynamic algorithm-to-cluster mapping" behind after
    # the text was rewritten.
    for tc in t._tbl.iter(docx.oxml.ns.qn("w:tc")):
        for vmerge in tc.findall(docx.oxml.ns.qn("w:vMerge")):
            tc.remove(vmerge)

    # Now drop every row after the header and build the data rows fresh.
    for tr in list(t._tbl.tr_lst)[1:]:
        t._tbl.remove(tr)

    # Header cells: same table, the wording this project needs.
    replace_text(t.rows[0].cells[0].paragraphs[0], TABLE_ROWS[0][0])
    replace_text(t.rows[0].cells[1].paragraphs[0], TABLE_ROWS[0][1])

    for a, b in TABLE_ROWS[1:]:
        row = t.add_row()
        replace_text(row.cells[0].paragraphs[0], a)
        replace_text(row.cells[1].paragraphs[0], b)
        for cell in row.cells:
            for vmerge in cell._tc.findall(docx.oxml.ns.qn("w:vMerge")):
                cell._tc.remove(vmerge)

    print(f"table 3.1: header + {len(TABLE_ROWS) - 1} rows rebuilt, no merged cells")

    doc.save(OUT)

    after = [(p.style.name, p.alignment) for p in docx.Document(OUT).paragraphs]
    assert len(before) == len(after), "paragraph count changed"
    print(f"paragraph count unchanged: {len(after)}")
    print("saved", OUT)


if __name__ == "__main__":
    main()
