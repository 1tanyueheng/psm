"""
Fills the HIRARC form for TAN YUE HENG (AI240167), PSM student.

Structure learned from the template:
  - Sheet 'HIRARC_COMPLETE THIS FORM' holds Section A (already filled) and the
    Section B table at rows 26-52.
  - B/C are merged per activity; G/H/I/J/K are merged per hazard block (2 rows),
    so each hazard occupies a row pair.
  - Within a pair, the FIRST row carries the category and the SECOND the
    specific detail. D27/D28 have no border between them, so they read as one
    cell split over two lines — which is how the template's own sample uses it.
  - I = G * H (header: "Risk Value (Likelihood x Severity)").
  - J = risk level, matching the sheet's own formula:
        <5 -> 1 (Low), <13 -> 2 (Medium), else 3 (High)
    The reference sheet confirms the bands: Low 1-4, Medium 5-12, High 13-25.
  - F carries one of the existing-control categories from the legend at N17:N23.
  - L is merged across a whole activity.

Writes to a NEW file; the original template is left untouched.
"""

from openpyxl import load_workbook
from openpyxl.styles import Alignment

SRC = r"C:\Users\yuehe\Downloads\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.xlsx"
DST = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025 - FILLED.xlsx"

STUDENT = "TAN YUE HENG /\nOn-going"

# (row_of_pair, category_hazard, detail_hazard, cat_consequence, detail_consequence,
#  existing_control, likelihood, severity, control_measures)
#
# likelihood/severity follow RefOnly_DEFINITION:
#   Likelihood 1 Unlikely .. 5 Highly Likely
#   Severity   1 Minor .. 5 Catastrophic

ACTIVITY_1 = "Software development and coding on a computer for extended periods"
HAZARDS_1 = [
    (
        27,
        "4. Ergonomic",
        "Incorrect sitting posture during long coding sessions",
        "2. Health Implications",
        "Back and neck pain",
        "5. Administrative Controls",
        3, 2,
        "1. Use an ergonomic chair and raise the monitor to eye level\n"
        "2. Stand and stretch for five minutes after every hour of work",
    ),
    (
        29,
        "4. Ergonomic",
        "Prolonged screen exposure causing eye strain",
        "2. Health Implications",
        "Headache and blurred vision",
        "5. Administrative Controls",
        4, 1,
        "1. Practise the 20-20-20 rule: every 20 minutes, look 20 feet away "
        "for 20 seconds\n"
        "2. Keep the room well lit and reduce screen glare",
    ),
]

ACTIVITY_2 = "System testing and running the development server"
HAZARDS_2 = [
    (
        31,
        "1. Physical",
        "Laptop overheating during long automated test runs",
        "1. Safety Implications",
        "Hardware damage and minor burns",
        "4. Engineering Controls",
        2, 2,
        "1. Use a laptop stand with clear ventilation around the fan\n"
        "2. Shut the machine down between long test runs",
    ),
    (
        33,
        "1. Physical",
        "Electrical overload from several devices on one extension plug",
        "1. Safety Implications",
        "Short circuit and fire",
        "4. Engineering Controls",
        2, 3,
        "1. Use a surge-protected extension plug and avoid daisy-chaining\n"
        "2. Inspect cables and plugs for damage before use",
    ),
    (
        35,
        "5. Psychosocial",
        "Sustained deadline pressure and long solitary working hours",
        "2. Health Implications",
        "Mental stress and burnout",
        "5. Administrative Controls",
        3, 2,
        "1. Plan work weekly against the milestone schedule and track progress\n"
        "2. Take regular breaks and keep in contact with the supervisor and peers",
    ),
]

ACTIVITY_3 = "Working late in the laboratory or faculty after office hours"
HAZARDS_3 = [
    (
        37,
        "5. Psychosocial",
        "Working alone late in the laboratory",
        "1. Safety Implications",
        "Personal safety risk: crime or injury",
        "5. Administrative Controls",
        2, 3,
        "1. Avoid working alone after office hours; work in pairs or groups\n"
        "2. Inform the security post and a friend before staying late",
    ),
]


def risk_level(value: int) -> int:
    """Matches the sheet's own formula at S11."""
    if value < 5:
        return 1
    return 2 if value < 13 else 3


def fill_hazard(ws, block, responsible_row):
    """
    `responsible_row` is the anchor cell of the merged L block for this
    activity, or None. L is merged across a whole activity, so only its first
    cell is writable — assigning to the others raises on a MergedCell.
    """
    (row, haz_cat, haz_detail, con_cat, con_detail,
     existing, likelihood, severity, measures) = block

    value = likelihood * severity
    level = risk_level(value)

    # Category on the first row of the pair, detail on the second.
    ws[f"D{row}"] = haz_cat
    ws[f"D{row + 1}"] = haz_detail
    ws[f"E{row}"] = con_cat
    ws[f"E{row + 1}"] = con_detail

    ws[f"F{row}"] = existing
    ws[f"G{row}"] = likelihood
    ws[f"H{row}"] = severity
    ws[f"I{row}"] = value
    ws[f"J{row}"] = level
    ws[f"K{row}"] = measures

    if responsible_row is not None:
        ws[f"L{responsible_row}"] = STUDENT

    # Centre and wrap everything written, so the text sits in the cell the way
    # the template's own rows do.
    for coord in (f"D{row}", f"D{row + 1}", f"E{row}", f"E{row + 1}",
                  f"F{row}", f"G{row}", f"H{row}", f"I{row}", f"J{row}"):
        ws[coord].alignment = Alignment(
            horizontal="center", vertical="center", wrap_text=True
        )

    # K is a numbered list, so it reads better left-aligned.
    ws[f"K{row}"].alignment = Alignment(
        horizontal="left", vertical="center", wrap_text=True
    )

    # Give the pair enough height for the wrapped text.
    ws.row_dimensions[row].height = 16
    ws.row_dimensions[row + 1].height = 50


def main():
    wb = load_workbook(SRC, data_only=False)
    ws = wb["HIRARC_COMPLETE THIS FORM"]

    # --- Section B: the activity table ---------------------------------
    ws["C27"] = ACTIVITY_1
    ws["C31"] = ACTIVITY_2
    ws["C37"] = ACTIVITY_3

    for block in HAZARDS_1:
        fill_hazard(ws, block, 27)
    for block in HAZARDS_2:
        fill_hazard(ws, block, 31)
    for block in HAZARDS_3:
        fill_hazard(ws, block, 37)

    # C holds the activity name over the whole merged block.
    for coord in ("C27", "C31", "C37"):
        ws[coord].alignment = Alignment(
            horizontal="center", vertical="center", wrap_text=True
        )

    # --- Review date ---------------------------------------------------
    # K23 held the placeholder text "DATE". A HIRARC is normally reviewed when
    # conditions change or at the end of the activity; six months is a
    # conventional interval and the user can change it.
    ws["K23"] = ": 12/04/2027"

    wb.save(DST)
    print(f"written: {DST}")

    # --- report what was written ---------------------------------------
    check = load_workbook(DST, data_only=False)
    cws = check["HIRARC_COMPLETE THIS FORM"]
    print("\n=== Section B, rows 27-38 ===")
    for r in range(27, 39):
        cells = []
        for col in "CDEFGHIJKL":
            v = cws[f"{col}{r}"].value
            if v is not None:
                cells.append(f"{col}={str(v)[:38]}".replace("\n", " | "))
        if cells:
            print(f"  r{r}: " + "  ".join(cells))

    print("\n=== risk values (I must equal G x H) ===")
    for r in (27, 29, 31, 33, 35, 37):
        g = cws[f"G{r}"].value
        h = cws[f"H{r}"].value
        i = cws[f"I{r}"].value
        j = cws[f"J{r}"].value
        ok = "OK" if (g and h and i == g * h) else "CHECK"
        lvl = risk_level(g * h) if (g and h) else None
        jok = "OK" if j == lvl else f"expected {lvl}"
        print(f"  r{r}: G={g} H={h} I={i} J={j}  [{ok}; level {jok}]")

    print(f"\n  K23 (review date) = {cws['K23'].value!r}")


if __name__ == "__main__":
    main()
