"""Rewrite Chapter 2 for short sentences (max 15 words) and a better 2.2 title.

Two changes, both from the user on 2026-11-01:

  1. **2.2 is retitled.** "Domain Case Study and Web Based System" names a
     technology (the web), not the study area. The template's own domain header
     is "Domain Case Study: <the domain>", and the domain here is the management
     of the final year project lifecycle, so the section becomes that. The
     heading is written by run, because these headings carry their literal "2.2"
     in the first run and a tab in the second (see merge_ch2_sections.py).

  2. **Every sentence in the chapter body is 15 words or fewer.** Same rule as
     Chapter 1. The 63-word sentence in 2.2 alone was five ideas joined by
     commas.

Run once; it is idempotent in the sense that the new text is written from this
file rather than repaired in place, so running it twice changes nothing.
"""
import docx

PATH = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
        r"\Chapter2 - PSM Assessment System v2.docx")

TITLE_2_2 = "Domain Case Study: Final Year Project Lifecycle Management"

# Body paragraphs, keyed by their index in doc.paragraphs. Every index was
# confirmed by reading the file first; an assert below re-checks the old text so
# a re-run against a changed document fails loudly instead of writing text over
# the wrong paragraph.
NEW = {
    # ---- 2.2 Domain case study -------------------------------------
    8: (
        "Projek Sarjana Muda (PSM) is the final year project for every "
        "undergraduate. It is required by the Faculty of Computer Science and "
        "Information Technology (FSKTM). That faculty is at Universiti Tun "
        "Hussein Onn Malaysia. PSM runs across two consecutive terms "
        "(Tam & Abdullah, 2025). PSM 1 covers the title proposal and the first "
        "half of the work. PSM 2 completes the project, the report and the "
        "examination (Universiti Tun Hussein Onn Malaysia, 2025)."
    ),
    9: (
        "The academic content of PSM is well defined. The administration around "
        "it is still carried out manually. It uses spreadsheets, shared "
        "documents, messaging groups and printed forms (Arumugam & Abd Ishak, "
        "2025). Supervisors are matched to students through discussion. "
        "Capacity is tracked by hand. Milestone deadlines are circulated as "
        "documents. Marks are collected on paper forms (Borhanuddin & Mohamed, "
        "2023; Chong & Arbaiy, 2022). PSM 1 and PSM 2 run in the same semester. "
        "Both draw on the same pool of academic staff. Coordination effort is "
        "therefore high. Errors are easy to make (Nor Azim & Arbaiy, 2022)."
    ),
    10: (
        "Manual administration of this kind has reported consequences. It "
        "causes time consuming task tracking and inefficient communication "
        "(Arumugam & Abd Ishak, 2025). It also delays project administering. It "
        "wastes resources and faults manual assessment data. Results are then "
        "delayed (Che Adnan & Hafit, 2025). Student research can also mismatch "
        "supervisor expertise (Borhanuddin & Mohamed, 2023; Tam & Abdullah, "
        "2025). A new system is therefore necessary. It must centralise the "
        "entire PSM lifecycle. This will improve visibility of progress. It will "
        "also remove the burden of manual processes (Arumugam & Abd Ishak, "
        "2025). Disconnected manual processes will become one authoritative "
        "record."
    ),
    11: (
        "The proposed system is developed as a web based application. It uses "
        "modern technologies, including a stateless application programming "
        "interface. Its data is stored in a relational database. Its front end "
        "runs in a browser. It follows the same web based approach as "
        "comparable faculty systems. Those systems replaced manual handling "
        "with an online platform (Arumugam & Abd Ishak, 2025; Wan Fauzi & "
        "Alduais, 2025). The system adopts an object oriented approach. This "
        "ensures modularity and maintainability. A web application is a "
        "software program that runs on a web server. Users access it through a "
        "browser over the Internet or an intranet. It is not installed on a "
        "local computer (CIO Wiki, 2023)."
    ),
    12: (
        "The web based approach is chosen for three reasons. It lets students, "
        "supervisors and coordinators share one record. They reach it anytime "
        "and anywhere using only a browser. Web applications also offer "
        "accessibility, easier maintenance and centralised data (CIO Wiki, "
        "2023). Updates are deployed on the server. They are then immediately "
        "available to all users. These properties are necessary for an "
        "administrative process with several groups of users. Otherwise each "
        "group works from its own copy. No record is authoritative (Arumugam & "
        "Abd Ishak, 2025)."
    ),
    13: (
        "The system also applies role based access control. This model "
        "controls access to resources by role. Permitted actions are identified "
        "with roles, not individual subject identities (NIST, 2022). Role based "
        "access matters because four groups share one PSM record. A student, "
        "supervisor, examiner and coordinator all hold different permissions "
        "(Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025). It also "
        "removes the need for periodic one-to-many announcements. Manual "
        "coordination relies on those when it cannot address a role directly "
        "(Chong Er Juan & Wahid, 2021)."
    ),
}

# The old opening words, asserted before overwriting, so a paragraph is never
# replaced by the wrong text.
EXPECT = {
    8:  "Projek Sarjana Muda (PSM) is the final year project",
    9:  "The academic content of PSM is well defined",
    10: "Manual administration of this kind",
    11: "The proposed system is developed as a web based application",
    12: "The web based approach is chosen because",
    13: "The system also applies role based access control",
}


def replace_paragraph(paragraph, text):
    """Replace a paragraph's text, keeping the first run's formatting."""
    runs = paragraph.runs
    if not runs:
        paragraph.add_run(text)
        return
    runs[0].text = text
    for extra in runs[1:]:
        extra._element.getparent().remove(extra._element)


def set_heading_title(paragraph, number, title):
    """Write "2.x<TAB>Title" into a Heading 2, keeping run formatting."""
    runs = paragraph.runs
    if len(runs) >= 2:
        runs[0].text = f"{number}\t"
        runs[1].text = title
        for extra in runs[2:]:
            extra._element.getparent().remove(extra._element)
    elif len(runs) == 1:
        runs[0].text = f"{number}\t{title}"
    else:
        paragraph.add_run(f"{number}\t{title}")


def main():
    doc = docx.Document(PATH)
    paras = doc.paragraphs

    # --- the heading ---------------------------------------------------
    heads = [p for p in paras if p.style.name == "Heading 2"]
    assert len(heads) == 6, f"expected 6 Heading 2s, found {len(heads)}"
    domain = heads[1]
    assert "Domain Case Study" in domain.text, domain.text
    print(f"retitling: {domain.text.strip()!r}")
    set_heading_title(domain, "2.2", TITLE_2_2)

    # --- the body -------------------------------------------------------
    for idx, text in NEW.items():
        p = doc.paragraphs[idx]
        assert p.text.strip().startswith(EXPECT[idx]), (idx, p.text[:80])
        replace_paragraph(p, text)
        print(f"  paragraph {idx}: {len(p.text.split())} words")

    doc.save(PATH)
    print(f"\nwrote {PATH}")


if __name__ == "__main__":
    main()
