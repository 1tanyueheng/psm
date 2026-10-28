"""Merge Chapter 2 sections 2.2 and 2.3, and renumber the rest.

The section numbers in this document are **literal text**, not automatic: each
`Heading 2` has its first run set to "2.2" followed by a tab. That is why
deleting a heading does not renumber anything, and why the renumbering has to be
written into the runs explicitly. (An earlier attempt assumed Word supplied the
numbers from `numPr` and produced headings with no number at all.)

What the merge does, in order:

    1. every heading in the file loses its literal number, keeping its tab;
    2. the "2.3  Web Based System" heading is deleted, so its three body
       paragraphs fall at the end of the merged domain study;
    3. the domain study is retitled;
    4. the existing-systems section takes the 2.3 slot and the title the user
       asked for;
    5. the remaining headings are numbered 2.1 ... 2.6 in document order.

No body paragraph is deleted, reordered or reworded.
"""
import re
import docx

PATH = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
        r"\Chapter2 - PSM Assessment System v2.docx")

NUM = re.compile(r"^\s*2\.\d+\s*\t?\s*")


def set_heading(paragraph, number, title):
    """Write "2.x<TAB>Title" into a Heading 2, keeping the run formatting."""
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


def strip_number(paragraph):
    """Drop a literal "2.x" prefix but keep the tab Run."""
    if paragraph.runs:
        paragraph.runs[0].text = "\t"


def main():
    doc = docx.Document(PATH)
    paras = doc.paragraphs
    n_before = len([p for p in paras if p.text.strip()])

    # 1. delete the Web Based System heading
    web = paras[11]
    assert web.style.name == "Heading 2" and "Web Based" in web.text, web.text
    web._element.getparent().remove(web._element)
    print(f"removed heading: {web.text.strip()!r}")

    paras = doc.paragraphs
    heads = [p for p in paras if p.style.name == "Heading 2"]
    print(f"headings remaining: {len(heads)}")

    # 2. titles
    TITLES = [
        "Introduction",
        "Domain Case Study and Web Based System",
        "Problem Study of Existing Related Systems",
        "Your Proposed System",
        "Comparison with the Existing Systems",
        "Chapter Summary",
    ]
    assert len(heads) == len(TITLES), (len(heads), len(TITLES))

    # 3. number them 2.1 .. 2.6 in order
    for i, (p, title) in enumerate(zip(heads, TITLES), start=1):
        set_heading(p, f"2.{i}", title)
        print(f"  2.{i}\t{title}")

    doc.save(PATH)

    # --- verify -----------------------------------------------------------
    check = docx.Document(PATH)
    n_after = len([p for p in check.paragraphs if p.text.strip()])
    h_after = [p.text.strip() for p in check.paragraphs
               if p.style.name == "Heading 2"]
    print()
    print(f"non-empty paragraphs: {n_before} -> {n_after} (expected {n_before - 1})")
    assert n_after == n_before - 1
    assert h_after == [f"2.{i}\t{t}" for i, t in enumerate(TITLES, start=1)], h_after


if __name__ == "__main__":
    main()
