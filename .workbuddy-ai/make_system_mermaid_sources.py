"""The system-wide Mermaid sources: the class diagram (all 41 models) and the
interaction diagram that shows how the services move them.

`class_diagram.py` draws 12 classes by hand for Figure 4.14. That is the right
figure *for the chapter*, but it is not the whole system, and the difference
matters when someone asks "what does this thing actually consist of". This script
covers every model in `backend/app/Models` and every service in
`backend/app/Services`, in the Mermaid subset that

  * renders in mermaid.live, and
  * survives draw.io's Mermaid importer, which only converts a subset of shapes.

Two things are therefore avoided on purpose:

  1. **No `namespace` blocks.** draw.io's importer does not convert them, and
     the classes arrive as one ungrouped pile. Each class carries its group in
     its own name instead (`People__User`), and the near-side relations give the
     layout its columns.
  2. **No attributes and no methods.** 41 classes with members is unreadable at
     print width. Membership is in the relation labels.

Output: `thesis/diagrams/system_class.txt` and `system_interaction.txt`, for
pasting into the Mermaid editor. Both are also in `docs/ARCHITECTURE.md`.

Relationship keys are the column that carries the foreign key; cardinality is
what the code enforces (checked against the migrations on 2026-11-01).
"""
import io
import os

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
DIA = os.path.join(BASE, "thesis", "diagrams")

# ---------------------------------------------------------------- class diagram

CLASSES = [
    # People and access
    ("People__User", "User"),
    ("People__StudentProfile", "StudentProfile"),
    ("People__SupervisorProfile", "SupervisorProfile"),
    ("People__StudentEnrolment", "StudentEnrolment"),
    ("People__CoordinatorScope", "CoordinatorScope"),
    ("People__ExpertiseArea", "ExpertiseArea"),
    ("People__Section", "Section"),
    ("People__LecturerMark", "LecturerMark"),
    # Supervision
    ("Supervision__SupervisorAgreement", "SupervisorAgreement"),
    ("Supervision__SupervisionAssignment", "SupervisionAssignment"),
    ("Supervision__ExaminerPair", "ExaminerPair"),
    ("Supervision__ExaminerAssignment", "ExaminerAssignment"),
    ("Supervision__ProjectMember", "ProjectMember"),
    # Project and its chain
    ("Chain__Project", "Project"),
    ("Chain__LampiranBForm", "LampiranBForm"),
    ("Chain__Milestone", "Milestone"),
    ("Chain__MilestoneTemplate", "MilestoneTemplate"),
    ("Chain__MilestoneTemplateItem", "MilestoneTemplateItem"),
    ("Chain__MilestoneSchedule", "MilestoneSchedule"),
    ("Chain__SubmissionFile", "SubmissionFile"),
    ("Chain__SubmissionEvent", "SubmissionEvent"),
    ("Chain__MilestoneReview", "MilestoneReview"),
    # Assessment
    ("Marking__RubricTemplate", "RubricTemplate"),
    ("Marking__RubricComponent", "RubricComponent"),
    ("Marking__RubricCriterion", "RubricCriterion"),
    ("Marking__AssessmentWindow", "AssessmentWindow"),
    ("Marking__Evaluation", "Evaluation"),
    ("Marking__EvaluationScore", "EvaluationScore"),
    ("Marking__MarkSubmission", "MarkSubmission"),
    ("Marking__FinalGrade", "FinalGrade"),
    ("Marking__GradeScheme", "GradeScheme"),
    # Term
    ("Term__AcademicSemester", "AcademicSemester"),
    # Output
    ("Output__DatabaseNotification", "DatabaseNotification"),
    ("Output__ReminderDispatch", "ReminderDispatch"),
    ("Output__AuditLog", "AuditLog"),
    # Reporting
    ("Report__Leaderboard", "Leaderboard"),
    ("Report__LeaderboardEntry", "LeaderboardEntry"),
    ("Report__LeaderboardSetting", "LeaderboardSetting"),
    # Dormant
    ("Archive__ArchivedProject", "ArchivedProject"),
]

# (a, edge, label, b)
#   --|>  inheritance            *--   composition (the part cannot outlive it)
#   o--   aggregation            -->   association
#   ..>   dependency
RELATIONS = [
    # --- people
    ("People__User", "||--o|", "profile", "People__StudentProfile"),
    ("People__User", "||--o|", "profile", "People__SupervisorProfile"),
    ("People__User", "||--o{", "appointed to", "People__Section"),
    ("People__User", "||--o{", "scopes", "People__CoordinatorScope"),
    ("People__User", "||--o{", "audit entries", "Output__AuditLog"),
    ("People__User", "||--o{", "notifications", "Output__DatabaseNotification"),
    ("People__SupervisorProfile", "||--o{", "expertise", "People__ExpertiseArea"),

    # --- enrolment and its term
    ("People__StudentProfile", "||--o{", "enrols", "People__StudentEnrolment"),
    ("Term__AcademicSemester", "||--o{", "enrolments", "People__StudentEnrolment"),
    ("Term__AcademicSemester", "||--o{", "sections, one per code", "People__Section"),
    ("People__Section", "||--o{", "coursework", "People__LecturerMark"),
    ("People__StudentEnrolment", "||--o{", "coursework", "People__LecturerMark"),

    # --- supervision and the panel
    ("People__StudentProfile", "||--o{", "agreements", "Supervision__SupervisorAgreement"),
    ("People__SupervisorProfile", "||--o{", "agreements", "Supervision__SupervisorAgreement"),
    ("People__StudentEnrolment", "||--o|", "placement", "Chain__Project"),
    ("People__StudentProfile", "||--o{", "projects leading", "Chain__Project"),
    ("People__SupervisorProfile", "||--o{", "supervises", "Supervision__SupervisionAssignment"),
    ("Chain__Project", "||--o{", "supervisions", "Supervision__SupervisionAssignment"),
    ("Chain__Project", "||--o{", "members", "Supervision__ProjectMember"),
    ("Chain__Project", "||--o{", "panel seatings", "Supervision__ExaminerAssignment"),
    ("Supervision__ExaminerPair", "||--o{", "seats", "Supervision__ExaminerAssignment"),

    # --- the milestone chain
    ("Chain__Project", "||--o{", "chain", "Chain__Milestone"),
    ("Chain__Project", "||--o{", "filings", "Chain__LampiranBForm"),
    ("Chain__MilestoneTemplate", "||--o{", "ordered steps", "Chain__MilestoneTemplateItem"),
    ("Chain__MilestoneTemplateItem", "||--o{", "instances", "Chain__Milestone"),
    ("People__StudentEnrolment", "||--o{", "deadlines", "Chain__MilestoneSchedule"),
    ("Chain__Milestone", "||--o{", "files", "Chain__SubmissionFile"),
    ("Chain__Milestone", "||--o{", "events", "Chain__SubmissionEvent"),
    ("Chain__Milestone", "||--o{", "reviews", "Chain__MilestoneReview"),

    # --- the marking chain
    ("Marking__RubricTemplate", "*--", "components", "Marking__RubricComponent"),
    ("Marking__RubricComponent", "*--", "criteria", "Marking__RubricCriterion"),
    ("People__StudentEnrolment", "||--o{", "form", "Marking__Evaluation"),
    ("People__SupervisorProfile", "||--o{", "assesses", "Marking__Evaluation"),
    ("Marking__RubricComponent", "||--o{", "instance", "Marking__Evaluation"),
    ("Term__AcademicSemester", "||--o{", "windows", "Marking__AssessmentWindow"),
    ("Marking__RubricComponent", "||--o{", "window", "Marking__AssessmentWindow"),
    ("Marking__Evaluation", "*--", "scores", "Marking__EvaluationScore"),
    ("Marking__RubricCriterion", "||--o{", "scores", "Marking__EvaluationScore"),
    ("Marking__Evaluation", "||--o{", "submissions", "Marking__MarkSubmission"),
    ("People__StudentEnrolment", "||--o|", "final mark", "Marking__FinalGrade"),
    ("Marking__GradeScheme", "||--o{", "grades", "Marking__FinalGrade"),

    # --- reminders and reporting
    ("Chain__Milestone", "||--o{", "reminders", "Output__ReminderDispatch"),
    ("Report__Leaderboard", "*--", "entries", "Report__LeaderboardEntry"),
    ("Report__LeaderboardSetting", "-->", "configures", "Report__Leaderboard"),
    ("Chain__Project", "-->", "archived copy", "Archive__ArchivedProject"),
]

def class_source():
    out = [
        "%% PSM Assessment System - domain class diagram.",
        f"%% {len(CLASSES)} classes, one per model in backend/app/Models, and",
        f"%% {len(RELATIONS)} relations. Paste into mermaid.live, or draw.io ->",
        "%% Arrange > Insert > Advanced > Mermaid (choose DIRECTORY, not EXTERNAL).",
        "%% The part before '__' in each id is only for sorting the source.",
        "%%{init: {",
        "  'theme': 'base',",
        "  'themeVariables': {",
        "    'fontFamily': 'Times New Roman, serif',",
        "    'fontSize': '14px',",
        "    'primaryColor': '#ffffff',",
        "    'primaryTextColor': '#1a1a1a',",
        "    'primaryBorderColor': '#1a1a1a',",
        "    'lineColor': '#333333',",
        "    'edgeLabelBackground': '#ffffff',",
        "    'clusterBkg': '#ffffff',",
        "    'clusterBorder': '#8c8c8c'",
        "  }",
        "}}%%",
        "classDiagram",
        "    direction TB",
        "",
    ]
    for alias, name in CLASSES:
        out.append(f"    class {alias}[\"{name}\"]")
    out.append("")
    for a, edge, label, b in RELATIONS:
        out.append(f"    {a} {edge} : {label} {b}")
    out.append("")
    return "\n".join(out)

# ---------------------------------------------------------- interaction diagram


INTERACTION = """%%{init: {
  'theme': 'base',
  'sequence': {
    'mirrorActors': false
  },
  'themeCSS': '.actor-line { stroke-dasharray: 4, 4; }',
  'themeVariables': {
    'fontFamily': 'Times New Roman, serif',
    'fontSize': '15px',
    'primaryColor': '#ffffff',
    'primaryTextColor': '#1a1a1a',
    'primaryBorderColor': '#1a1a1a',
    'lineColor': '#333333',
    'signalColor': '#333333',
    'signalTextColor': '#1a1a1a',
    'actorBkg': '#ffffff',
    'actorBorder': '#1a1a1a',
    'actorTextColor': '#1a1a1a',
    'actorLineColor': '#8c8c8c',
    'labelBoxBkgColor': '#f4f4f4',
    'labelBoxBorderColor': '#1a1a1a',
    'labelTextColor': '#1a1a1a',
    'noteBkgColor': '#ffffff',
    'noteBorderColor': '#8c8c8c',
    'sequenceNumberColor': '#1a1a1a',
    'actorPadding': 25,
    'messageMargin': 35
  }
}}%%
sequenceDiagram
    autonumber
    actor C as Coordinator
    participant UI as React SPA
    participant API as Route + Policy
    participant SVC as Service layer
    participant DB as PostgreSQL<br/>(Supabase)
    participant N as Notification<br/>dispatcher

    Note over UI,DB: One request, one template. Every use case is<br/>a controller that asks a service, which owns the rule.

    C->>UI: Place a student in PSM 1 for the term
    UI->>API: POST /api/enrolments
    API->>API: StudentEnrolmentPolicy + Role gate
    API->>SVC: EnrolmentService::enrol()

    alt The student already holds the other part this term
        SVC-->>API: 422 refused, naming the other part and the term
        API-->>UI: 422 refused
        UI-->>C: The rule is stated, not implied
    else PSM 1 in an earlier term only
        SVC->>DB: Save the enrolment, attempt_no = 1
        SVC->>DB: Write the audit row (enrolment.created)
        API-->>UI: 201 Created
    end

    C->>UI: Put a supervisor on the student
    UI->>API: POST /api/assignments
    API->>SVC: AssignmentService::assignSupervisor()
    SVC->>DB: Six ordered guards, programme before capacity
    SVC->>DB: Save the supervision, retire the old seating
    SVC->>N: Queue the acknowledgement reminder
    API-->>UI: 201 Created

    Note over API,N: Every write passes the same four gates,<br/>so the rules cannot be bypassed by a screen.

    C->>UI: Open the assessment window
    UI->>API: POST /api/assessment-windows/{id}/open
    API->>SVC: AssessmentWindowService::open()
    SVC->>DB: One evaluation row per assessor, per rubric
    SVC->>N: Notify every assessor who now has a form
    API-->>UI: 200 OK, N forms created

    C->>UI: Release the mark once every assessor has submitted
    UI->>API: POST /api/evaluations/release
    API->>SVC: MarkVisibilityService::release()
    SVC->>DB: Recompute weightedTotal, rescale to 0-100
    SVC->>DB: Save final_mark and its breakdown
    SVC->>N: Notify the student the mark is visible
    API-->>UI: 200 OK, the definition of the contribution's third claim

    Note over SVC,N: At term close TermCloseService derives<br/>completed or failed from final_mark >= 40,<br/>then settles enrolments and releases seats.
"""


def main():
    os.makedirs(DIA, exist_ok=True)

    cls = class_source()
    p1 = os.path.join(DIA, "system_class.txt")
    with io.open(p1, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(cls)

    p2 = os.path.join(DIA, "system_interaction.txt")
    with io.open(p2, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(INTERACTION)

    # --- self-checks: a diagram the importer cannot read is worse than none
    body = cls.split("classDiagram", 1)[1]
    declared = set()
    used = set()
    for line in body.splitlines():
        s = line.strip()
        if not s or s.startswith("%%"):
            continue
        if s.startswith("class "):
            declared.add(s.split()[1].split("[")[0])
            continue
        if s.startswith(("direction", "classDef", "style")):
            continue
        # `A <edge> : label B` -> the label may be several words, so split at
        # the cardinality token and at the last token, not on every space.
        parts = s.split()
        if len(parts) >= 4 and parts[1] in {
            "||--o{", "||--o|", "||--||", "*--", "o--", "-->", "..>", "--|>"
        }:
            assert parts[2] == ":", f"missing ':' in {s}"
            used.add(parts[0])
            used.add(parts[-1])
            continue
        raise SystemExit(f"UNPARSED class line: {s}")

    print(f"classes declared : {len(declared)}")
    print(f"relations        : {len(RELATIONS)}")
    print(f"used but not declared: {sorted(used - declared) or 'none'}")
    print(f"declared, no relation: {sorted(declared - used) or 'none'}")
    print(f"wrote {p1}")
    print(f"wrote {p2}")


if __name__ == "__main__":
    main()
