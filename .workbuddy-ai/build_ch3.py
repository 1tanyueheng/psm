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
#
# **Every sentence is 15 words or fewer** (the user's instruction, 2026-11-01).
# Check with `.workbuddy-ai/check_docx_sentences.py`. The captions, Figure 3.1
# and Table 3.1 are deliberately untouched — only the prose between them.
# ---------------------------------------------------------------------------
TEXT = {
    5: "This chapter outlines the methodology used for this project. It covers "
       "the software process model selected and why. It then sets out each "
       "phase of that model in order. The activities and deliverables of each "
       "phase are given. The chapter closes by mapping the objectives to the "
       "phases that satisfy them.",

    # 3.2 body: the heading says Prototype Model, so the body must describe
    # prototyping, not waterfall.
    8: "The Prototyping model builds a working version of the system early. It "
       "shows that version to the client. It then refines the version until the "
       "client is satisfied. The Waterfall model works differently. It follows "
       "a straight, sequential workflow, and each phase is completed before the "
       "next begins. Prototyping accepts that requirements become clear through "
       "use (Chong Er Juan & Wahid, 2021). It does not assume that "
       "documentation alone settles them. It therefore expects the first design "
       "to be wrong. This model was selected for the PSM Assessment System. Its "
       "administrative rules are not stated in a form the developer can read. "
       "The official Lampiran forms fix the weightings and the marking scale. "
       "The workflow around them exists only in the coordinator's practice. The "
       "faculty's own reports describe the difficulty this causes. Two "
       "studies cover it (Chong & Arbaiy, 2022; Nor Azim & Arbaiy, 2022). "
       "Working assumptions are never written down. A prototype lets the coordinator see a rule in "
       "operation. They can then correct it. That is much cheaper than "
       "correcting a framework built on the wrong rule. Comparable faculty "
       "systems adopted prototyping for the same reason. Two are named here. "
       "One is the FSKTM supervision system (Chong Er Juan & Wahid, 2021). "
       "The other is a later assessment system (Che Adnan & Hafit, 2025). Figure 3.1 "
       "illustrates this model. It has six activities, starting with "
       "requirement gathering and analysis. Quick design and building a "
       "prototype follow. The last three are client evaluation, refining the "
       "prototype, and implementation and maintenance.",

    # 3.2.1
    14: "This phase gathered the system's requirements from three sources. "
        "These were the official faculty forms, the faculty's PSM guide and the "
        "coordinator's practice. Lampiran A and Lampiran B fixed the "
        "registration fields. The five assessment Lampiran fixed the rubrics. "
        "They also fixed the item scales and the weightings. The faculty's "
        "PSM guide records these (Universiti Tun Hussein Onn Malaysia, 2025). The phase also reviewed comparable faculty systems. "
        "That review identified the capabilities those systems already "
        "provide. It also identified the ones they leave out. Arumugam and Abd "
        "Ishak document two such systems (Arumugam & Abd Ishak, 2025). Wan "
        "Fauzi and Alduais document a third (Wan Fauzi & Alduais, 2025). The phase "
        "then fixed the functional and non-functional requirements. These are "
        "recorded in Chapter 4. This phase supports Objective 1. That objective "
        "is to design the system with an object oriented approach. This phase "
        "does so by fixing the scope the design must cover.",

    # 3.2.2
    17: "This phase turned the requirements into a reviewable design. The use "
        "cases were modelled. The domain classes were identified, and the "
        "database schema was derived from them. Every rule from Phase 1 then "
        "had a place in the design. None of it stayed in the developer's memory "
        "(Tam & Abdullah, 2025). The output is three artefacts, all in "
        "Chapter 4. These are the class diagram, the data dictionary, and the "
        "sequence and activity diagrams. Together with Phase 1, this phase "
        "supports Objective 1. It lays the groundwork for a design that can be "
        "reviewed against the requirements. No code is written before that "
        "review.",

    # 3.2.3
    20: "In this phase the system was implemented as a working prototype. It is "
        "a decoupled web application. A stateless Laravel application "
        "programming interface sits over a PostgreSQL database. A React "
        "single-page client is the front end. That separation has two benefits. "
        "A rule change on the server reaches every user. The client does not "
        "need rebuilding. The web based approach is also the one comparable "
        "faculty systems adopted. Arumugam and Abd Ishak are the example "
        "(Arumugam & Abd Ishak, 2025). The CIO Wiki gives the same reason "
        "(CIO Wiki, 2023). "
        "Access is controlled by role based access control (NIST, 2022). A "
        "student, a supervisor, an examiner and a coordinator share one record. "
        "Each holds different permissions over it. The prototype covered the "
        "six use cases specified in Chapter 4. These are login and "
        "authentication and manage title and registration. They also include "
        "manage milestone and manage evaluation. The last two are generate "
        "report and manage alert and notification. This phase directly supports "
        "Objective 2. That objective is to develop the system with a web based "
        "approach. It serves students, supervisors and coordinators.",

    # 3.2.4
    23: "The prototype was then demonstrated to its users and evaluated. Three "
        "groups took part. The coordinator walked through the registration, "
        "milestone and term-close rules. Supervisors reviewed the "
        "acknowledgement and the evaluation form. They also reviewed the "
        "milestone decision. Students worked through registration and "
        "submission. Each session was recorded against a checklist. The "
        "checklist was drawn from the Phase 1 requirements. Every disputed rule "
        "was written down. No rule was corrected on the spot. The same applied "
        "to rules found to be wrong. Client evaluation justifies prototyping's "
        "cost. A rule can read correctly in a specification. The person who has "
        "to apply it can still reject it. Chong and Wahid report this "
        "outcome (Chong Er Juan & Wahid, 2021).",

    # 3.2.5
    26: "The findings of each evaluation were folded back into the prototype. "
        "The affected phases were then repeated. Figure 3.1 shows this feedback "
        "loop. Where a rule had been modelled wrongly, the correction began at "
        "requirement gathering. It did not begin at the screen. The change "
        "therefore reached the schema, the service and the interface together. "
        "It was not patched into the interface alone. This phase produced the "
        "system described in Chapter 5. It is also the phase that supports "
        "Objective 2 directly. The users themselves refined the requirements. "
        "The developer did not do it on their behalf.",

    # 3.3 body
    33: "This chapter has presented the methodology adopted for this project. "
        "The Prototyping model was selected for one reason. The administrative "
        "rules of PSM live in faculty practice, not in a document. Prototyping "
        "is the process that lets those rules be discovered. It also lets them "
        "be corrected by the people who apply them. The chapter set out the "
        "five phases carried out. These run from requirement gathering through "
        "to implementation and maintenance. It also showed how the objectives "
        "are satisfied by those phases. The next chapter presents the analysis "
        "and design produced by them.",

    # 30 — the paragraph that introduces Table 3.1. It spoke about research
    # questions; the table maps research objectives.
    30: "Each research objective (RO) is mapped to one evaluation focus. "
        "Table 3.1 shows this mapping. It keeps the methodology and the "
        "evaluation aligned.",
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
