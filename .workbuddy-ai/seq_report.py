"""Sequence diagram - Generate Report.

Two outputs from one screen, which is what the use case is:

    1  the coordinator asks for the figures   -> the dashboard data comes back
    2  the coordinator asks for the CSV        -> the file is streamed back

Fourteen messages. The old figure split message 5 into six separate
aggregations; here the service's work is one message with the figures it
returns named in the reply, because a reader of a sequence diagram needs the
sequence, not the SQL.

Grounded in `ReportController` (dashboard, exportGrades, exportProjects) and
`ReportingService::dashboardSummary`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_report.png"

XS = [170, 520, 900, 1290, 1700]
HEADS = [
    "Coordinator",
    "Reports UI\n(React SPA)",
    "Report\nController",
    "Reporting\nService",
    "Grades, Milestones\n+ Audit",
]

STEPS = [
    {"kind": "section", "label": "1. On-screen report  ·  figures for a batch, a part and a term"},

    {"a": 0, "b": 1, "kind": "call", "label": "1. Choose the batch, part and term"},
    {"a": 1, "b": 2, "kind": "call", "label": "2. GET /api/reports/dashboard"},
    {"a": 2, "b": 2, "kind": "self",
     "label": "3. Check: the caller may view\ncohort analytics"},
    {"a": 2, "b": 3, "kind": "call", "label": "4. dashboardSummary(filters)"},
    {"a": 3, "b": 4, "kind": "call",
     "label": "5. Aggregate progress, at-risk,\nworkload and mark bands"},
    {"a": 4, "b": 3, "kind": "return", "label": "6. counts and figures"},
    {"a": 2, "b": 1, "kind": "return",
     "label": "7. 200 OK: cohort, marks, at-risk,\nby-part rollup"},
    {"a": 1, "b": 0, "kind": "return", "label": "8. Tables and charts are rendered"},

    {"kind": "section", "label": "2. Export  ·  the same filters, written to a file"},

    {"a": 0, "b": 1, "kind": "call", "label": "9. Click Export CSV"},
    {"a": 1, "b": 2, "kind": "call",
     "label": "10. GET /api/reports/export/grades.csv"},
    {"a": 2, "b": 2, "kind": "self",
     "label": "11. Check: export is narrower than\nviewing — admin or coordinator"},
    {"a": 2, "b": 4, "kind": "call",
     "label": "12. Log the export, then read the\nrows in pages"},
    {"a": 2, "b": 1, "kind": "return", "label": "13. CSV download"},
    {"a": 1, "b": 0, "kind": "return", "label": "14. The file is saved"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
