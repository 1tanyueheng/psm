"""Fix the last Chapter 2 sentence still over 15 words.

The previous pass lengthened this one borrowed phrase while shortening others,
so it needs its own edit. Kept separate so the earlier script stays a record of
what it did.
"""
import docx

PATH = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
        r"\Chapter2 - PSM Assessment System v2.docx")

FIND = ("Each is documented in the studies cited here (Arumugam & Abd Ishak, "
        "2025; Wan Fauzi & Alduais, 2025).")

REPL = ("Arumugam and Abd Ishak document one (Arumugam & Abd Ishak, 2025). "
        "Wan Fauzi and Alduais document another (Wan Fauzi & Alduais, 2025).")


def main():
    doc = docx.Document(PATH)
    changed = 0

    for p in doc.paragraphs:
        if FIND in p.text:
            new = p.text.replace(FIND, REPL)
            runs = p.runs
            runs[0].text = new
            for extra in runs[1:]:
                extra._element.getparent().remove(extra._element)
            changed += 1

    assert changed == 1, f"expected 1 paragraph, changed {changed}"
    doc.save(PATH)
    print(f"fixed {changed} paragraph")


if __name__ == "__main__":
    main()
