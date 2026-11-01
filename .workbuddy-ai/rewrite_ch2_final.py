"""Final pass on Chapter 2: the eight sentences still over 15 words.

Each one is too long only because of the citation bracket, or because a list of
names pushed it over. Repaired by moving the citation into its own short
sentence, the same way Chapter 1 was handled.
"""
import docx

PATH = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
        r"\Chapter2 - PSM Assessment System v2.docx")

FIXES = [
    # (find, replace) applied to the whole paragraph text, asserted to be present.
    # ---- 2.2 ----
    ("PSM 2 completes the project, the report and the examination (Universiti "
     "Tun Hussein Onn Malaysia, 2025).",
     "PSM 2 completes the project, the report and the examination. The "
     "faculty's guide describes this structure (Universiti Tun Hussein Onn "
     "Malaysia, 2025)."),

    ("It follows the same web based approach as comparable faculty systems. "
     "Those systems replaced manual handling with an online platform "
     "(Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025).",
     "It follows the same web based approach as comparable faculty systems. "
     "Those systems replaced manual handling with an online platform. Arumugam "
     "and Abd Ishak report one (Arumugam & Abd Ishak, 2025). Wan Fauzi and "
     "Alduais report another (Wan Fauzi & Alduais, 2025)."),

    ("Role based access matters because four groups share one PSM record. A "
     "student, supervisor, examiner and coordinator all hold different "
     "permissions (Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025).",
     "Role based access matters because four groups share one PSM record. Each "
     "group holds different permissions. These groups are student, supervisor, "
     "examiner and coordinator. Both studies report this need (Arumugam & Abd "
     "Ishak, 2025; Wan Fauzi & Alduais, 2025)."),

    ("It also removes the need for periodic one-to-many announcements. Manual "
     "coordination relies on those when it cannot address a role directly "
     "(Chong Er Juan & Wahid, 2021).",
     "It also removes the need for periodic one-to-many announcements. Manual "
     "coordination relies on those to address a group. Chong and Wahid describe "
     "this limit (Chong Er Juan & Wahid, 2021)."),

    # ---- 2.5 ----
    ("A comparison was conducted between three existing systems and the "
     "proposed system (Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025; "
     "Tam & Abdullah, 2025). The three are FFMS, the PSM Supervision System and "
     "the Supervisor Selection System.",
     "A comparison was conducted between three existing systems and the "
     "proposed one. The three are FFMS, the PSM Supervision System and the "
     "Supervisor Selection System. Each is documented in the studies cited here "
     "(Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, 2025). Tam and Abdullah "
     "document the third (Tam & Abdullah, 2025)."),

    ("Based on Table 2.1, all three existing systems manage one part of a final "
     "year project.",
     "Based on Table 2.1, all three systems manage one part. Each covers only "
     "part of a final year project."),

    # ---- 2.6 ----
    ("It also covered three existing systems, namely FFMS, the PSM Supervision "
     "System and the Supervisor Selection System.",
     "It also covered three existing systems. These were FFMS and the PSM "
     "Supervision System. The third was the Supervisor Selection System."),

    ("The proposed system therefore overcomes these limitations. It offers a web "
     "based platform that treats the whole PSM process as one governed record, "
     "across both parts.",
     "The proposed system therefore overcomes these limitations. It offers a "
     "web based platform for the whole PSM process. That platform treats both "
     "parts as one governed record."),
]


def replace_paragraph(paragraph, text):
    runs = paragraph.runs
    if not runs:
        paragraph.add_run(text)
        return
    runs[0].text = text
    for extra in runs[1:]:
        extra._element.getparent().remove(extra._element)


def main():
    doc = docx.Document(PATH)
    applied = 0

    for find, repl in FIXES:
        hit = False
        for p in doc.paragraphs:
            if find in p.text:
                replace_paragraph(p, p.text.replace(find, repl))
                applied += 1
                hit = True
                break
        assert hit, f"not found: {find[:70]}"

    # One leftover from the previous run, which lengthened a sentence it was
    # meant to shorten.
    for find, repl in [
        ("Each is documented in the studies cited here (Arumugam & Abd Ishak, "
         "2025; Wan Fauzi & Alduais, 2025).",
         "Two are documented in the studies cited here (Arumugam & Abd Ishak, "
         "2025; Wan Fauzi & Alduais, 2025)."),
        ("Two are documented in the studies cited here (Arumugam & Abd Ishak, "
         "2025; Wan Fauzi & Alduais, 2025).",
         "Arumugam and Abd Ishak document one (Arumugam & Abd Ishak, 2025). "
         "Wan Fauzi and Alduais document another (Wan Fauzi & Alduais, 2025)."),
    ]:
        for p in doc.paragraphs:
            if find in p.text:
                replace_paragraph(p, p.text.replace(find, repl))
                applied += 1
                break

    doc.save(PATH)
    print(f"applied {applied} fixes to {PATH}")


if __name__ == "__main__":
    main()
