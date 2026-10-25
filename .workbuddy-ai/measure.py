from docx import Document
from docx.shared import Emu
d = Document(r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\Chapter2 - Literature Review.docx")
for i, s in enumerate(d.sections):
    uw = Emu(s.page_width - s.left_margin - s.right_margin).cm
    uh = Emu(s.page_height - s.top_margin - s.bottom_margin).cm
    print(f"sec{i}: page {Emu(s.page_width).cm:.2f} x {Emu(s.page_height).cm:.2f} cm | "
          f"USABLE {uw:.2f} x {uh:.2f} cm")
