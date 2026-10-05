"""Build an Excel HIRARC form (UTM/OSHE/BKPPS.001) from the source PDF layout."""
import math
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
from openpyxl.drawing.image import Image as XLImage
from openpyxl.drawing.spreadsheet_drawing import OneCellAnchor, AnchorMarker
from openpyxl.drawing.xdr import XDRPositiveSize2D
from openpyxl.utils.units import pixels_to_EMU, cm_to_EMU
from PIL import Image as PILImage

WS_DIR = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\.workbuddy-ai"
OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\HIRARC FORM_FSKTMPSM_UTHMOSHEBKPPS_001_Oct 2025.xlsx"

# ---------------------------------------------------------------- palette
BLACK   = "000000"
YELLOW  = "FFFF99"          # 1 1 0.6 rg  -> header band
RED     = "C00000"          # the PDF paints placeholders in pure red; slightly
                            # darkened so it stays legible when printed
GREY    = "000000"

thin = Side(style="thin", color=BLACK)
medium = Side(style="medium", color=BLACK)

F_TITLE   = Font(name="Calibri", size=16, bold=True, color=BLACK)
F_SUB     = Font(name="Calibri", size=11, bold=True, color=BLACK)
F_CODE    = Font(name="Calibri", size=7,  color=BLACK)
F_BANNER  = Font(name="Calibri", size=10, bold=True, color=BLACK)
F_LABEL   = Font(name="Calibri", size=8.5, color=BLACK)
F_VALUE   = Font(name="Calibri", size=8.5, color=BLACK)
F_PH      = Font(name="Calibri", size=8.5, color=RED)
F_TH      = Font(name="Calibri", size=8,  bold=True, color=BLACK)
F_TD      = Font(name="Calibri", size=8,  color=BLACK)

L  = Alignment(horizontal="left",   vertical="center", wrap_text=True)
LT = Alignment(horizontal="left",   vertical="top",    wrap_text=True)
C  = Alignment(horizontal="center", vertical="center", wrap_text=True)
LT_NW = Alignment(horizontal="left", vertical="center", wrap_text=False)

wb = Workbook()
ws = wb.active
ws.title = "HIRARC FORM"
ws.sheet_view.showGridLines = False
wb.calculation.fullCalcOnLoad = True

# ---------------------------------------------------------------- geometry
# Column widths reproduced from the PDF's own cell/rule geometry
# (printed pt / 5.25 pt-per-char), which keeps the 11 columns in the same
# proportion as the original form.
# (A is widened from the proportional 5.1 to 5.9 so the "Location" / "Tel No."
#  labels keep their single line, exactly as in the source PDF.)
COLW = {"A": 5.9, "B": 24.1, "C": 14.8, "D": 15.2, "E": 15.3, "F": 8.1,
        "G": 8.3, "H": 8.3, "I": 10.9, "J": 13.5, "K": 13.5}
for col, w in COLW.items():
    ws.column_dimensions[col].width = w


def est_lines(text, width_chars, size=8.0):
    """Conservative wrap estimate so no text is clipped by the row height.

    Column width is measured in characters of the default Calibri 11 font;
    the +0.75 accounts for Excel's cell padding (5 px) at that size.
    """
    if text is None:
        return 1
    if isinstance(text, str) and text.startswith("="):
        return 1                      # formula: judge by its short result
    cpl = max(4.0, (width_chars + 0.75) * (11.0 / size) * 0.95)
    total = 0
    for para in str(text).split("\n"):
        total += max(1, math.ceil(len(para) / cpl)) if para else 1
    return total


def height_for(cells, size=8.0, pad=2.5, minimum=None):
    """cells = [(text, first_col, last_col)] -> row height in points."""
    line_h = size * 1.30
    if minimum is None:
        minimum = line_h + pad
    lines = 1
    for text, c1, c2 in cells:
        w = sum(COLW[get_column_letter(i)] for i in range(c1, c2 + 1))
        lines = max(lines, est_lines(text, w, size))
    return max(minimum, lines * line_h + pad)


def put(ref, value=None, font=F_LABEL, align=L, fill=None):
    c = ws[ref]
    if value is not None:
        c.value = value
    c.font = font
    c.alignment = align
    if fill:
        c.fill = PatternFill("solid", fgColor=fill)
    return c


def merge(rng, value=None, font=F_LABEL, align=L, fill=None):
    ws.merge_cells(rng)
    first = rng.split(":")[0]
    put(first, value, font, align, fill)
    # propagate style across every cell of the merged range
    for row in ws[rng]:
        for c in row:
            c.font = font
            c.alignment = align
            if fill:
                c.fill = PatternFill("solid", fgColor=fill)
    return ws[first]


def outline(rng, edge="all", style="thin"):
    """Apply a border box to a range; edge=None removes borders."""
    s = None if style is None else (medium if style == "medium" else thin)
    for row in ws[rng]:
        for c in row:
            if s is None:
                c.border = Border()
            else:
                c.border = Border(left=s, right=s, top=s, bottom=s)


def edge_border(ref, left=False, right=False, top=False, bottom=False, style="thin"):
    s = medium if style == "medium" else thin
    ws[ref].border = Border(left=s if left else None,
                            right=s if right else None,
                            top=s if top else None,
                            bottom=s if bottom else None)


# ================================================================ rows 1-4
ws.row_dimensions[1].height = 12
ws.row_dimensions[2].height = 28
ws.row_dimensions[3].height = 20
ws.row_dimensions[4].height = 15

merge("A1:K1", "UTHM/OSHE/BKPPS.001(2025)", F_CODE,
      Alignment(horizontal="right", vertical="center"))
merge("A2:K2", None)
merge("A3:K3", "HIRARC FORM", F_TITLE, C)
merge("A4:K4", "UNIVERSITI TUN HUSSEIN ONN MALAYSIA", F_SUB, C)

# ============================================================ SECTION A
ws.row_dimensions[5].height = 5
ws.row_dimensions[6].height = 14
merge("A6:K6", "SECTION A: GENERAL INFORMATION", F_BANNER, L)

secA = [
    # (label, value, extra_label, is_placeholder)
    ("Faculty",
     ": Faculty of Computer Science and Information Technology", None, False),
    ("Office / Laboratory / Workshop / Store Name",
     ": (your programme, e.g. Bachelor of Computer Science (Web Technology) "
     "with Honors - BIW)", None, True),
    ("Location", ": (e.g., Around the faculty, hostel, company/organization - "
     "if applicable)", "Block", True),
    (None, ": N/A", "Room Number", False),
    ("Head of Office / Lab Manager / PSM Coordinator / Internship "
     "Coordinator / WBL Coordinator",
     ": Dr. Norfaradilla binti Wahid (PSM Coordinator)", None, False),
    ("Tel No.", ": N/A", "Office", False),
    (None, ": (your mobile number)", "Mobile Phone", True),
]

r = 7
for idx, (label, value, sub, ph) in enumerate(secA):
    if idx == 2:      # Location / Block  (label spans two rows)
        ws.row_dimensions[r].height = height_for([(value, 3, 11)], 8.5, 2.5, 13.5)
        merge(f"A{r}:A{r+1}", "Location", F_LABEL, C)
        merge(f"B{r}:B{r}", sub, F_LABEL, C)
        merge(f"C{r}:K{r}", value, F_PH if ph else F_VALUE, L)
    elif idx == 3:    # Room Number row
        ws.row_dimensions[r].height = height_for([(value, 3, 11)], 8.5, 2.5, 13.5)
        merge(f"B{r}:B{r}", sub, F_LABEL, C)
        merge(f"C{r}:K{r}", value, F_PH if ph else F_VALUE, L)
    elif idx == 5:    # Tel No. / Office (label spans two rows)
        ws.row_dimensions[r].height = height_for([(value, 3, 11)], 8.5, 2.5, 13.5)
        merge(f"A{r}:A{r+1}", "Tel No.", F_LABEL, C)
        merge(f"B{r}:B{r}", sub, F_LABEL, C)
        merge(f"C{r}:K{r}", value, F_PH if ph else F_VALUE, L)
    elif idx == 6:    # Mobile Phone row
        ws.row_dimensions[r].height = height_for([(value, 3, 11)], 8.5, 2.5, 13.5)
        merge(f"B{r}:B{r}", sub, F_LABEL, C)
        merge(f"C{r}:K{r}", value, F_PH if ph else F_VALUE, L)
    else:
        ws.row_dimensions[r].height = height_for(
            [(label, 1, 2), (value, 3, 11)], 8.5, 2.5, 13.5)
        merge(f"A{r}:B{r}", label, F_LABEL, L)
        merge(f"C{r}:K{r}", value, F_PH if ph else F_VALUE, L)
    r += 1

SEC_A_TOP, SEC_A_BOT = 7, 13
outline(f"A{SEC_A_TOP}:K{SEC_A_BOT}")
# the two vertically-merged label cells have no internal rule
edge_border("A9",  left=True, right=True, top=True,  bottom=False)
edge_border("A10", left=True, right=True, top=False, bottom=True)
edge_border("A12", left=True, right=True, top=True,  bottom=False)
edge_border("A13", left=True, right=True, top=False, bottom=True)

# ============================================================ SECTION B
ws.row_dimensions[14].height = 5
ws.row_dimensions[15].height = 14
merge("A15:K15", "SECTION B: HIRARC FORM", F_BANNER, L)

r = 16
# 16 - Work Process / Location
ws.row_dimensions[r].height = height_for(
    [(": General Office", 4, 11), ("Work Process / Location", 1, 3)], 8.5, 2.5, 13.5)
merge(f"A{r}:C{r}", "Work Process / Location", F_LABEL, L)
merge(f"D{r}:K{r}", ": General Office", F_VALUE, L)

# 17 - Prepared by
r = 17
ws.row_dimensions[r].height = 13.5
merge(f"A{r}:C{r}", "Prepared by", F_LABEL, L)
merge(f"D{r}:K{r}", ": (your full name, matric No)", F_PH, L)

# 18 - Reviewed and Approved By
r = 18
lab18 = ("Reviewed and Approved By (Head of Department / Unit Head / "
         "Lab Manager / SLO / PSM or LI or WBL / Supervisor)\n"
         "(Signature, Name, and Position)")
val18 = (": (your PSM supervisor name)\n\n"
         "___________________________ (supervisor's signature)")
ws.row_dimensions[r].height = height_for([(lab18, 1, 3), (val18, 4, 11)], 8.5, 2.5, 13.5)
merge(f"A{r}:C{r}", lab18, F_LABEL, L)
merge(f"D{r}:K{r}", val18, F_PH, L)

# 19 - Date / Review Date
r = 19
ws.row_dimensions[r].height = height_for(
    [(": (the date you filled in the form)", 4, 6),
     (": (date supervisor review and sign the form)", 10, 11)], 8.5, 2.5, 13.5)
merge(f"A{r}:C{r}", "Date", F_LABEL, L)
merge(f"D{r}:F{r}", ": (the date you filled in the form)", F_PH, L)
merge(f"G{r}:I{r}", "Review Date", F_LABEL, L)
merge(f"J{r}:K{r}", ": (date supervisor review and sign the form)", F_PH, L)

SEC_B_TOP, SEC_B_BOT = 16, 19
outline(f"A{SEC_B_TOP}:K{SEC_B_BOT}")

# ------------------------------------------------------------------ table
HDR_BANNER = 20
HDR_ROW = 21
FIRST_DATA = 22
N_DATA = 5
LAST_DATA = FIRST_DATA + N_DATA - 1

ws.row_dimensions[HDR_BANNER].height = 14
merge(f"A{HDR_BANNER}:D{HDR_BANNER}", "HAZARD IDENTIFICATION", F_BANNER, C, YELLOW)
merge(f"E{HDR_BANNER}:I{HDR_BANNER}", "RISK ANALYSIS", F_BANNER, C, YELLOW)
merge(f"J{HDR_BANNER}:K{HDR_BANNER}", "RISK CONTROL", F_BANNER, C, YELLOW)

HEADERS = {
    "A": "No.",
    "B": "Activity / Machine / Process / Material / Workplace Environment",
    "C": "A.\nHazard",
    "D": "B.\nPotential Consequence",
    "E": "C.\nExisting Risk Control",
    "F": "D.\nLikelihood",
    "G": "E.\nSeverity",
    "H": "F.\nRisk Value\n(Likelihood x Severity)",
    "I": "G.\nRisk Level",
    "J": "Control Measures\nImplemented/To be Implemented",
    "K": "Responsible Person/ Status",
}
head_lines = max(est_lines(t, COLW[c], 8.0) for c, t in HEADERS.items())
ws.row_dimensions[HDR_ROW].height = max(30.0, (head_lines + 1) * 10.4 + 2.5)
for col, text in HEADERS.items():
    put(f"{col}{HDR_ROW}", text, F_TH, C, YELLOW)

# ---------------------------------------------------------------- data
DATA = [
    dict(
        no=1,
        activity="Using desktop computer at workstation",
        hazard="Prolonged sitting; awkward posture; monitor glare",
        consequence="Musculoskeletal pain, eye fatigue, headache",
        existing="Adjustable chair with backrest; ceiling lighting; monitor at desk level",
        L=3, S=2,
        control="Adjust chair and monitor height; 20-20-20 eye breaks; stretch hourly",
        resp="Student / Lab User",
    ),
    dict(
        no=2,
        activity="Connecting / disconnecting power cables and extension leads",
        hazard="Damaged insulation; exposed wiring; overloaded socket",
        consequence="Electric shock, short circuit, fire damage to equipment",
        existing="Certified 3-pin plugs; socket outlets with ELCB protection",
        L=3, S=4,
        control="Inspect cables before use; no daisy-chaining; pull by the plug head",
        resp="Student / Lab Technician",
    ),
    dict(
        no=3,
        activity="Moving between office, computer lab and stairways",
        hazard="Wet or slippery floor; uneven surface; obstructed walkway",
        consequence="Slip, trip or fall causing sprain, cut or fracture",
        existing="Non-slip floor tiles; wet floor sign; handrails on stairways",
        L=3, S=3,
        control="Keep walkways clear; hold the handrail; report spills immediately",
        resp="Student / Housekeeping",
    ),
    dict(
        no=4,
        activity="Operating the shared printer / photocopier",
        hazard="Hot fuser unit; moving rollers; toner dust",
        consequence="Minor burns, skin / eye irritation",
        existing="Machine enclosure with interlock; SOP displayed",
        L=2, S=2,
        control="Follow SOP; do not open covers while running; wear gloves for toner",
        resp="Student / Lab Technician",
    ),
    dict(
        no=5,
        activity="Working alone outside normal working hours",
        hazard="No immediate help in an emergency; limited supervision",
        consequence="Delayed first aid / evacuation; security risk",
        existing="Access card control; emergency contact list at the door",
        L=2, S=4,
        control="Inform supervisor; work in pairs where possible; keep phone charged",
        resp="Student / PSM Supervisor",
    ),
]

for i, d in enumerate(DATA):
    r = FIRST_DATA + i
    ws.row_dimensions[r].height = height_for(
        [(d["activity"], 2, 2), (d["hazard"], 3, 3), (d["consequence"], 4, 4),
         (d["existing"], 5, 5), (d["control"], 10, 10), (d["resp"], 11, 11)],
        8.0, 2.5, 13.5)
    put(f"A{r}", d["no"], F_TD, C)
    put(f"B{r}", d["activity"], F_TD, LT)
    put(f"C{r}", d["hazard"], F_TD, LT)
    put(f"D{r}", d["consequence"], F_TD, LT)
    put(f"E{r}", d["existing"], F_TD, LT)
    put(f"F{r}", d["L"], F_TD, C)
    put(f"G{r}", d["S"], F_TD, C)
    put(f"H{r}", f"=F{r}*G{r}", F_TD, C)
    put(f"I{r}",
        f'=IF(OR(F{r}="",G{r}=""),"",IF(H{r}<=4,"Low",'
        f'IF(H{r}<=9,"Medium",IF(H{r}<=15,"High","Extreme"))))',
        F_TD, C)
    put(f"J{r}", d["control"], F_TD, LT)
    put(f"K{r}", d["resp"], F_TD, LT)

outline(f"A{HDR_BANNER}:K{LAST_DATA}")
# thick rule between the column header row and the first data row (as in the PDF)
for col in COLW:
    edge_border(f"{col}{HDR_ROW}", left=True, right=True, top=True, bottom=True,
                style="medium")

# ------------------------------------------------------------------ logo
logo_src = WS_DIR + r"\logo.jpg"
with PILImage.open(logo_src) as im:
    logo_w_px, logo_h_px = im.size
# printed size in the PDF: 90.04 x 26.20 pt  ->  px at 96 dpi
logo_w_px = int(round(90.038 * 96 / 72))
logo_h_px = int(round(26.203 * 96 / 72))
img = XLImage(logo_src)
img.width = logo_w_px
img.height = logo_h_px

total_px = sum(COLW[c] * 7 + 5 for c in COLW)
img.anchor = OneCellAnchor(
    _from=AnchorMarker(col=0, colOff=pixels_to_EMU((total_px - logo_w_px) / 2),
                       row=1, rowOff=pixels_to_EMU(2)),
    ext=XDRPositiveSize2D(pixels_to_EMU(logo_w_px), pixels_to_EMU(logo_h_px)),
)
ws.add_image(img)

# ------------------------------------------------------------------ print
ws.print_area = f"A1:K{LAST_DATA}"
ws.page_setup.orientation = "landscape"
ws.page_setup.paperSize = ws.PAPERSIZE_A4
ws.page_setup.fitToWidth = 1
ws.page_setup.fitToHeight = 1
ws.sheet_properties.pageSetUpPr.fitToPage = True
ws.print_options.horizontalCentered = True
ws.page_margins.left = ws.page_margins.right = 0.3
ws.page_margins.top = ws.page_margins.bottom = 0.3
ws.page_margins.header = ws.page_margins.footer = 0.15

wb.save(OUT)
print("saved:", OUT)
print("last row:", LAST_DATA, "cols:", len(COLW))
