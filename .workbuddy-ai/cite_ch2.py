"""Add citations to Chapter 2 sections 2.2 and 2.3.

The user asked for citations added without changing paragraphing, numbering or
formatting. Each of the ten paragraphs in 2.2 and 2.3 is a single run, so the
edit is to the **text of that run only**: the paragraph object, its style, its
alignment and its spacing are not touched, and no paragraph is added, removed
or split.

Every citation added is one the source actually supports. Where the existing
text already made a claim that had no citation, a source is attached to that
claim; where a new citation would have needed a claim the source does not make,
none was added.

Sources:
  Arumugam & Abd Ishak (2025)  FFMS; waterfall methodology; UAT
  Borhanuddin & Mohamed (2023) manual supervisor-selection form
  Che Adnan & Hafit (2025)     assessment-system inefficiencies
  Chong & Arbaiy (2022)        manual calculation in FSKTM assessment
  Chong Er Juan & Wahid (2021) supervision system, prototypes
  Nor Azim & Arbaiy (2022)     FSKTM assessment forms
  Tam & Abdullah (2025)        supervisor selection, FSKTM
  Tee & Mahdin (2024)          manual pre-viva application
  Wan Fauzi & Alduais (2025)   PSM supervision system
  Universiti Tun Hussein Onn Malaysia (2025) faculty PSM guide
  NIST (2022)                  role-based access control
  CIO Wiki (2023)              web application definition
"""
import docx

DOC = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - PSM Assessment System v2.docx"

# index -> new text for the paragraph's single run
EDITS = {
    # 2.2 ---------------------------------------------------------------
    8: "Projek Sarjana Muda (PSM) is the final year project required of every "
       "undergraduate at the Faculty of Computer Science and Information Technology "
       "(FSKTM), Universiti Tun Hussein Onn Malaysia. PSM runs across two consecutive "
       "terms (Tam & Abdullah, 2025). PSM 1 covers the title proposal and the first half "
       "of the work, while PSM 2 completes the project, the report and the examination "
       "(Universiti Tun Hussein Onn Malaysia, 2025).",

    9: "The academic content of PSM is well defined, but the administration around it is "
       "still carried out manually using spreadsheets, shared documents, messaging "
       "groups and printed forms (Arumugam & Abd Ishak, 2025). Supervisors are matched to "
       "students through discussion, capacity is tracked by hand, milestone deadlines are "
       "circulated as documents and marks are collected on paper forms (Borhanuddin & "
       "Mohamed, 2023; Chong & Arbaiy, 2022). PSM 1 and PSM 2 also run in the same "
       "semester and draw on the same pool of academic staff, so coordination effort is "
       "high and errors are easy to make (Nor Azim & Arbaiy, 2022).",

    10: "Manual administration of this kind has been reported to cause time consuming "
        "task tracking, inefficient communication and delays in project administering "
        "(Arumugam & Abd Ishak, 2025), which produces resource wastage, faults in manual "
        "assessment data and delay in providing results (Che Adnan & Hafit, 2025), as well "
        "as mismatches between student research and supervisor expertise (Borhanuddin & "
        "Mohamed, 2023; Tam & Abdullah, 2025). Therefore, a new system is necessary to "
        "centralise the entire PSM lifecycle. This will improve visibility of progress and "
        "remove the administrative burden produced by manual processes, replacing "
        "disconnected manual processes with one authoritative record "
        "(Arumugam & Abd Ishak, 2025).",

    # 2.3 ---------------------------------------------------------------
    12: "The proposed system is developed as a web based application using modern "
        "technologies such as a stateless application programming interface, a relational "
        "database and a browser based front end. It follows the same web based approach "
        "that comparable faculty systems adopted when they replaced manual handling with "
        "an online platform (Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025). The "
        "system adopts an object oriented approach to ensure modularity and "
        "maintainability. A web application is defined as a software program that runs on "
        "a web server and is accessed through a web browser over the Internet or an "
        "intranet, rather than being installed on a local computer (CIO Wiki, 2023).",

    13: "The web based approach is chosen because it allows students, supervisors and "
        "coordinators to access the same record anytime and anywhere using only a browser. "
        "Web applications offer advantages of accessibility, easier maintenance and "
        "centralised data, since updates are deployed on the server and are immediately "
        "available to all users (CIO Wiki, 2023). These properties are necessary for an "
        "administrative process that involves several groups of users, because each group "
        "otherwise works from its own copy and no record is authoritative (Arumugam & Abd "
        "Ishak, 2025).",

    14: "The system also applies role based access control, which is a model for "
        "controlling access to resources where permitted actions are identified with roles "
        "rather than with individual subject identities (NIST, 2022). Role based access is "
        "important because a student, a supervisor, an examiner and a coordinator must "
        "each hold different permissions over the same PSM record (Arumugam & Abd Ishak, "
        "2025; Wan Fauzi & Alduais, 2025). It also removes the need for the periodic "
        "one-to-many announcements that manual coordination relies on when it has no way "
        "of addressing a role directly (Chong Er Juan & Wahid, 2021).",
}

# New entries for the reference list, in the document's existing numbered style.
NEW_REFS = [
    "Borhanuddin, H., & Mohamed, R. (2023). Sistem Pemilihan Penyelia PSM FSKTM (SPP "
    "UTHM). Applied Information Technology and Computer Science, 4(1), 1176-1193. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/7306",

    "Che Adnan, A. A., & Hafit, H. (2025). Electronic Hasta La Vista (e-HLV): Student "
    "Assessment System for Finishing School. Applied Information Technology and Computer "
    "Science, 6(2), 92-109. https://periodical.uthm.edu.my/index.php/aitcs/article/view/20522",

    "Chong, Z. Y., & Arbaiy, N. (2022). Pre-Viva Assessment System Development for FSKTM, "
    "UTHM. Applied Information Technology and Computer Science, 3(2), 1568-1587. "
    "https://periodical.uthm.edu.my/index.php/aitcs/article/view/7574",

    "Nor Azim, N. A. A., & Arbaiy, N. (2022). A Development of Assessment System for "
    "FSKTM Postgraduate Students. Applied Information Technology and Computer Science, "
    "3(1), 732-747. https://periodical.uthm.edu.my/index.php/aitcs/article/view/2433",

    "Universiti Tun Hussein Onn Malaysia. (2025). Buku panduan Projek Sarjana Muda (PSM). "
    "Fakulti Sains Komputer dan Teknologi Maklumat, UTHM.",

    "Chong Er Juan, & Wahid, N. (2021). The Development of a Supervision System for FSKTM "
    "Undergraduate Students. Applied Information Technology and Computer Science, 2(2), "
    "1111-1129. https://periodical.uthm.edu.my/index.php/aitcs/article/view/2409",

    "Tee, D. Y. M., & Mahdin, H. (2024). Enhancing FSKTM Pre-Viva Management Through "
    "Online System. Applied Information Technology and Computer Science, 5(2), 273-290. "
    "https://periodical.uthm.edu.my/en/index.php/aitcs/article/view/16353",
]


def main():
    doc = docx.Document(DOC)

    for idx, text in EDITS.items():
        p = doc.paragraphs[idx]
        assert len(p.runs) == 1, f"paragraph {idx} has {len(p.runs)} runs"
        before_style = (p.style.name, p.alignment, p.paragraph_format.first_line_indent)
        p.runs[0].text = text
        after_style = (p.style.name, p.alignment, p.paragraph_format.first_line_indent)
        assert before_style == after_style, f"formatting changed on paragraph {idx}"
        print(f"rewrote paragraph {idx} ({len(text.split())} words)")

    # Append the new references after the last one, copying its formatting so the
    # numbering style of the list is preserved.
    paras = doc.paragraphs
    ref_start = next(i for i, p in enumerate(paras) if p.text.strip() == "References")
    last_ref = max(i for i in range(ref_start + 1, len(paras))
                   if paras[i].text.strip())

    template = paras[last_ref]
    roman = ["vi.", "vii.", "viii.", "ix.", "x.", "xi.", "xii."]
    parent = template._element.getparent()

    cursor = template._element
    for i, ref in enumerate(NEW_REFS):
        # Deep-copy the template paragraph, then replace its first run's text and
        # drop any further runs. Copying the element keeps style, indentation and
        # run font exactly as the list already has them.
        import copy as _copy
        new_el = _copy.deepcopy(template._element)
        cursor.addnext(new_el)
        new_p = docx.text.paragraph.Paragraph(new_el, template._parent)
        for extra in new_p.runs[1:]:
            extra._element.getparent().remove(extra._element)
        new_p.runs[0].text = f"{roman[i]}\t{ref}"
        cursor = new_el
        print(f"added reference {roman[i]}")

    doc.save(DOC)
    print("saved", DOC)


if __name__ == "__main__":
    main()
