"""Build Chapter 4 deliverable #5: Manage Alert and Notification (UC-05)."""
import copy
import os
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
SRC = r"C:\Users\yuehe\Desktop\study\fyp\thesis\c2\Chapter2.docx"
OUT = os.path.join(BASE, r"thesis\Chapter4 - Manage Alert and Notification Use Case (Sequence and Activity Diagrams).docx")
DIA = os.path.join(BASE, r"thesis\diagrams")

doc = Document(SRC)
body = doc.element.body
sectPr = body.find(qn("w:sectPr"))
FINAL = copy.deepcopy(list(body.iter(qn("w:sectPr")))[-1])
for ch in list(body):
    if ch is not sectPr:
        body.remove(ch)

TNR = "Times New Roman"


def _run(p, text, bold=False, size=12):
    r = p.add_run(text)
    r.font.name = TNR
    r._element.rPr.rFonts.set(qn("w:eastAsia"), TNR)
    r.font.size = Pt(size)
    r.bold = bold
    return r


def P(text="", bold=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=12, space_after=0):
    p = doc.add_paragraph(style="Normal")
    pf = p.paragraph_format
    pf.line_spacing = 1.5
    pf.space_before = Pt(0)
    pf.space_after = Pt(space_after)
    p.alignment = align
    if text:
        _run(p, text, bold=bold, size=size)
    return p


_sec = 0


def H(text):
    global _sec
    _sec += 1
    p = doc.add_paragraph(style="Heading 2")
    pPr = p._p.get_or_add_pPr()
    numPr = OxmlElement("w:numPr")
    ilvl = OxmlElement("w:ilvl")
    ilvl.set(qn("w:val"), "0")
    numId = OxmlElement("w:numId")
    numId.set(qn("w:val"), "0")
    numPr.append(ilvl)
    numPr.append(numId)
    pPr.insert(0, numPr)
    _run(p, f"4.{_sec}\t", bold=True)
    _run(p, text, bold=True)
    pf = p.paragraph_format
    pf.space_before = Pt(12)
    pf.space_after = Pt(10)
    return p


def caption(text):
    return P(text, bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=10)


def figure(path, width_cm, cap):
    p = doc.add_paragraph(style="Normal")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(6)
    p.paragraph_format.space_after = Pt(2)
    p.add_run().add_picture(path, width=Cm(width_cm))
    caption(cap)
    return p


def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    tcPr.append(shd)


def spec_table(rows, lw=4.2, vw=10.6):
    t = doc.add_table(rows=0, cols=2)
    t.style = doc.styles["Table Grid"]
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblPr = t._tbl.tblPr
    for dup in tblPr.findall(qn("w:tblLayout")):
        tblPr.remove(dup)
    lay = OxmlElement("w:tblLayout")
    lay.set(qn("w:type"), "fixed")
    tblPr.append(lay)
    tw = tblPr.find(qn("w:tblW"))
    if tw is None:
        tw = OxmlElement("w:tblW")
        tblPr.append(tw)
    tw.set(qn("w:type"), "dxa")
    tw.set(qn("w:w"), str(int((lw + vw) / 2.54 * 1440)))

    for label, value in rows:
        cells = t.add_row().cells
        for i, (txt, bold, fill) in enumerate(((label, True, "E7E6E6"),
                                               (value, False, None))):
            para = cells[i].paragraphs[0]
            para.paragraph_format.line_spacing = 1.15
            para.paragraph_format.space_before = Pt(1)
            para.paragraph_format.space_after = Pt(1)
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT
            _run(para, txt, bold=bold, size=10.5)
            if fill:
                shade(cells[i], fill)
        cells[0].width = Cm(lw)
        cells[1].width = Cm(vw)
    for col, w in enumerate((lw, vw)):
        t.columns[col].width = Cm(w)
    return t


# =====================================================================
P("CHAPTER 4", bold=True)
P()
P()
P("ANALYSIS AND DESIGN", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P("Use Case Specification and UML Diagrams", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P("Manage Alert and Notification", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER)
P()
P()

# ---------------------------------------------------------------- 4.1
H("Introduction")
P("This document presents the detailed design of the sixth use case of the PSM "
  "Assessment System, namely Manage Alert and Notification. The use case has two "
  "independent flows. The first is system driven: a scheduled command sends deadline "
  "reminders to students as a deadline approaches. The second is user driven: every "
  "user has a notification centre where alerts are read, dismissed and configured. It "
  "is specified in Table 4.6, then expressed as a sequence diagram in Figure 4.13 and "
  "two activity diagrams in Figures 4.10 and 4.11.")

# ---------------------------------------------------------------- 4.2
H("Use Case Specification")
P("The system recognises eighteen notification types, grouped into milestones, "
  "reminders, grading, registration, accounts and recognition. Each type carries its "
  "own default channels. Three types are marked urgent and cannot be switched off, "
  "because missing a deadline is not a preference. Table 4.6 summarises the use case.")

caption("Table 4.6: Use Case Specification for Manage Alert and Notification")
spec_table([
    ("Use Case ID", "UC-05"),
    ("Use Case Name", "Manage Alert and Notification"),
    ("Actor", "System (scheduler, primary for reminders). Student, Supervisor, "
              "Coordinator and Admin (all users, for the notification centre)."),
    ("Description", "The system sends deadline reminders as a milestone approaches, "
                    "and records each dispatch so a retried run cannot send twice. "
                    "Every user can view their notifications, mark them as read, "
                    "dismiss them, and choose which types they receive."),
    ("Pre-condition", "Users have accounts. Milestones exist with a deadline and a "
                      "student roster. The scheduler is running."),
    ("Trigger", "The scheduled reminder command runs, or a user opens the "
                "notification centre."),
    ("Main Flow",
     "1. The scheduler runs the deadline reminder command.\n"
     "2. The system reads the reminder waves, by default 7, 3 and 1 days.\n"
     "3. For each wave, the system finds the milestones due in that many days.\n"
     "4. The system collects the students on each project.\n"
     "5. The system builds the reminder message and marks it urgent at one day.\n"
     "6. For each student, the system checks whether this wave was already sent.\n"
     "7. The system checks that the account is active and has not opted out.\n"
     "8. The system resolves the channels for the type, mail and in-app.\n"
     "9. The system sends the notification and stores the in-app record.\n"
     "10. The system records the dispatch in the reminder ledger.\n"
     "11. On the final wave, the system also informs the supervisors.\n"
     "12. The command prints a summary of how many reminders were sent.\n"
     "13. Separately, a user opens the notification centre and sees the unread "
     "count and the latest five.\n"
     "14. The user reads, dismisses or configures notifications."),
    ("Alternative Flow",
     "A1. Nothing due: at step 3, when no milestone falls on that wave the system "
     "skips to the next wave.\n"
     "A2. Already sent: at step 6, the reminder ledger shows this user has already "
     "received this wave, so the dispatch is skipped and no duplicate is sent.\n"
     "A3. Opted out: at step 7, a user who has disabled this type receives nothing.\n"
     "A4. Account inactive: a suspended or inactive account is never notified, so a "
     "dead mailbox is not woken.\n"
     "A5. Send failure: a mail failure for one recipient is logged and the run "
     "continues, so one bad address cannot stop a whole reminder wave.\n"
     "A6. Digest mode: when the command runs in digest mode, only users who chose "
     "digest-only are included.\n"
     "A7. Urgent type disabled: at step 14, an attempt to switch off an urgent type "
     "is ignored, because urgent types are forced on.\n"
     "A8. Dismiss: a user may delete a notification, which removes it from their own "
     "list without affecting anyone else."),
    ("Post-condition",
     "On a successful wave, each eligible user has an in-app notification and possibly "
     "a mail, and a ledger row exists for that user, milestone, wave and type, so a "
     "retry cannot send again. Read state and preferences belong to the individual "
     "user and are not shared. No milestone or project data is modified."),
])

P()
P("The reminder ledger is the part of this use case worth noting. A scheduled command "
  "that fails halfway is normally retried by the scheduler, and without a record of "
  "what was already sent, every retry would mail the same students again. Recording "
  "each dispatch in the ledger makes the command safe to run repeatedly (A2), which in "
  "turn is what allows it to be retried at all.")

# ---------------------------------------------------------------- 4.3
H("Sequence Diagram")
P("The sequence diagram in Figure 4.13 shows the reminder dispatch path, which is the "
  "more involved of the two flows. Six lifelines take part: the scheduler, the reminder "
  "command, the notification dispatcher, the notification and mail transport, the user "
  "model with its preferences, and the reminder ledger. It is divided into the scheduled "
  "run and the reader.")

figure(os.path.join(DIA, "sequence_notification.png"), 16.4,
       "Figure 4.13: Sequence Diagram for Manage Alert and Notification")

P("Messages 2 and 3 select the work: the command asks for the milestones due in seven, "
  "three or one days and gets back the milestones and the students on them. Message 9 "
  "calls the dispatcher once per recipient, and messages 5 and 6 are the de-duplication "
  "check against the ledger. If the wave has already gone to this person, nothing is "
  "sent and the run moves on. Otherwise message 7 writes the in-app record and the mail, "
  "and message 8 records the dispatch, so a retried run finds it and skips it too.")
P("Message 11 is the reader's side of the same use case: opening the notification "
  "centre, dismissing an item, or changing which types arrive at all, and message 12 "
  "returns the unread count and the latest five.")

# ---------------------------------------------------------------- 4.4
H("Activity Diagram")
P("The use case has two independent halves, so they are drawn side by side in Figure "
  "4.12 within their own frames. The left frame is the scheduled reminder run, which the "
  "system drives; the right frame is the notification centre, which the user drives. "
  "They are kept separate because they are triggered by different actors at different "
  "times and neither leads to the other.")

figure(os.path.join(DIA, "activity_notification.png"), 16.4,
       "Figure 4.12: Activity Diagram for Manage Alert and Notification")

P("In the reminder frame the command finds what is due, checks each user is active, has "
  "not opted out and has not already been sent this wave, then sends and records the "
  "dispatch. Both refusals lead to the same place, the next wave, which is what keeps "
  "the command safe to retry: nothing is sent twice and nothing is re-sent after a "
  "failure.")
P("In the centre frame the user opens the notification centre and either deals with a "
  "notification, which refreshes the unread badge, or changes which types they receive. "
  "An attempt to switch off an urgent type has no effect, so the three types the faculty "
  "treats as mandatory always arrive.")

# ---------------------------------------------------------------- 4.5
H("Chapter Summary")
P("This document has specified the Manage Alert and Notification use case and presented "
  "its sequence diagram and its activity diagram. The specification defines the actors, "
  "a fourteen step main flow, eight alternative flows and the post-conditions for a "
  "reminder wave. The sequence diagram shows the reminder dispatch including the "
  "de-duplication ledger, and the activity diagram shows the scheduled run and the "
  "notification centre side by side as two independent flows.")

body.remove(sectPr)
body.append(FINAL)
doc.save(OUT)
print("saved:", OUT)
