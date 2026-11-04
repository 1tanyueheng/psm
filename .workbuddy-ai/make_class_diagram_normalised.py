"""Figure 4.14 as a NORMALISED design, not a mirror of the implementation.

The user's instruction (2026-11-04): build the per-role tables. The running schema
is not being changed — this is the **logical model** the chapter presents, and it
is a deliberate step away from what `information_schema` currently holds.

## What changes, and why it is defensible

Today `users` carries the role, the login state, *and* the staff attributes, and
the two roles with data of their own bolt a profile table onto it. A `lecturer` or
an `admin` has no table at all — they exist as a row in `users` with a string in
`role`.

The normalised form splits the two orthogonal things:

  * **who somebody is** — `User`: credentials, status, login state;
  * **what they are in the faculty** — one table per role, joined 1:1 to `User`.

`User` keeps *only* identity and authentication. `staff_id`, `department` and the
role-specific attributes move onto the role tables. That is second normal form
applied to a table that had a nullable group of columns that only applied to some
rows.

## The cost, stated honestly

This diagram no longer matches the database, and `check_combined_diagram.py`
cannot be run against it — it compares arrows to live foreign keys, and the role
tables do not exist. What replaces that check is
`check_normalised_diagram.py`, which verifies the *internal* consistency of the
design: every class declared has members, every arrow names a declared class, the
role tables each join `User` exactly once, and no role attribute is left on
`User`.

If the implementation is ever brought across, these are the real migrations.
Until then the chapter must say the diagram is the logical design.
"""
import io
import os

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
OUT = os.path.join(BASE, "thesis", "diagrams", "class_diagram.txt")

# ---------------------------------------------------------------------------
# The classes. Everything the implementation has, plus the three missing roles.
# ---------------------------------------------------------------------------
CLASSES = [
    # --- identity ---------------------------------------------------------
    ("User", "users", "UC-01", "Identity"),
    ("Role", "roles", "UC-01", "Identity"),

    # --- one table per role ----------------------------------------------
    ("AdminProfile", "admin_profiles", "UC-02", "Role"),
    ("CoordinatorProfile", "coordinator_profiles", "UC-02", "Role"),
    ("LecturerProfile", "lecturer_profiles", "UC-02", "Role"),
    ("SupervisorProfile", "supervisor_profiles", "UC-02", "Role"),
    ("StudentProfile", "student_profiles", "UC-02", "Role"),

    # --- what a coordinator is scoped to --------------------------------
    ("CoordinatorScope", "coordinator_scopes", "UC-05", "Role"),

    # --- teaching ---------------------------------------------------------
    ("Section", "sections", "UC-04", "Teaching"),
    ("LecturerMark", "lecturer_marks", "UC-04", "Teaching"),

    # --- registration and supervision ------------------------------------
    ("SupervisorAgreement", "supervisor_agreements", "UC-02", "Registration"),
    ("SupervisionAssignment", "supervision_assignments", "UC-02", "Registration"),
    ("StudentEnrolment", "student_enrolments", "UC-02", "Registration"),
    ("ProjectMember", "project_members", "UC-02", "Registration"),
    ("ExaminerPair", "examiner_pairs", "UC-04", "Registration"),
    ("ExaminerAssignment", "examiner_assignments", "UC-04", "Registration"),

    # --- the project and its chain ---------------------------------------
    ("Project", "projects", "UC-03", "Chain"),
    ("Milestone", "milestones", "UC-03", "Chain"),
    ("MilestoneReview", "milestone_reviews", "UC-03", "Chain"),
    ("SubmissionFile", "submission_files", "UC-03", "Chain"),
    ("LampiranBForm", "lampiran_b_forms", "UC-02", "Chain"),

    # --- assessment -------------------------------------------------------
    ("RubricTemplate", "rubric_templates", "UC-04", "Assessment"),
    ("RubricComponent", "rubric_components", "UC-04", "Assessment"),
    ("RubricCriterion", "rubric_criteria", "UC-04", "Assessment"),
    ("AssessmentWindow", "assessment_windows", "UC-04", "Assessment"),
    ("Evaluation", "evaluations", "UC-04", "Assessment"),
    ("EvaluationScore", "evaluation_scores", "UC-04", "Assessment"),

    # --- results ----------------------------------------------------------
    ("FinalGrade", "final_grades", "UC-05", "Results"),
    ("MarkSubmission", "mark_submissions", "UC-05", "Results"),
    ("GradeScheme", "grade_schemes", "UC-05", "Results"),

    # --- term and audit ---------------------------------------------------
    ("AcademicSemester", "academic_semesters", "UC-06", "Term"),
    ("AuditLog", "audit_logs", "UC-01", "Term"),
    ("DatabaseNotification", "notifications", "UC-06", "Term"),
]

# ---------------------------------------------------------------------------
# Attributes. `User` loses every role attribute; the role tables gain them.
# ---------------------------------------------------------------------------
ATTRIBUTES = {
    # Identity only: credentials, status, login state, contact. No staff_id, no
    # department, no programme — those belong to a role.
    "User": ["id : bigint <<PK>>", "name : varchar(255)", "email : varchar(255)",
             "password : varchar(255)", "roleId : bigint <<FK>>", "status : varchar(255)",
             "phone : varchar(32)", "lastLoginAt : timestamp",
             "failedLoginAttempts : smallint", "lockedUntil : timestamp",
             "mustChangePassword : boolean"],
    "Role": ["id : bigint <<PK>>", "code : varchar(16)", "label : varchar(64)",
             "description : text"],

    "AdminProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                     "staffId : varchar(32)", "department : varchar(128)",
                     "scope : varchar(32)"],
    "CoordinatorProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                           "staffId : varchar(32)", "department : varchar(128)",
                           "isHead : boolean"],
    "LecturerProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                        "staffId : varchar(32)", "department : varchar(128)",
                        "courseCode : varchar(16)"],
    "SupervisorProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                          "staffId : varchar(32)", "department : varchar(128)",
                          "programCode : varchar(8)", "academicTitle : varchar(64)",
                          "capacityPsm1 : smallint", "capacityPsm2 : smallint"],
    "StudentProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                       "matricNo : varchar(32)", "programCode : varchar(8)",
                       "batch : varchar(32)", "yearOfStudy : smallint",
                       "sectionId : bigint <<FK>>",
                       "academicSemesterId : bigint <<FK>>"],

    "CoordinatorScope": ["id : bigint <<PK>>", "coordinatorProfileId : bigint <<FK>>",
                         "program : varchar(128)"],

    "Section": ["id : bigint <<PK>>", "code : varchar(16)", "name : varchar(128)",
                "courseCode : varchar(16)", "lecturerProfileId : bigint <<FK>>",
                "academicSemesterId : bigint <<FK>>"],
    "LecturerMark": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                     "sectionId : bigint <<FK>>", "academicSemesterId : bigint <<FK>>",
                     "affective : numeric", "cognitive : numeric",
                     "psychomotor : numeric", "enteredByUserId : bigint <<FK>>"],

    "SupervisorAgreement": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                            "supervisorProfileId : bigint <<FK>>",
                            "psmPart : varchar(8)", "proposedTitle1 : varchar(255)",
                            "agreedTitle : varchar(255)", "status : varchar(255)",
                            "studentSignedAt : timestamp",
                            "supervisorAcknowledgedAt : timestamp"],
    "SupervisionAssignment": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                              "supervisorProfileId : bigint <<FK>>",
                              "psmPart : varchar(8)", "isActive : boolean",
                              "effectiveFrom : date", "effectiveUntil : date"],
    "StudentEnrolment": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                         "academicSemesterId : bigint <<FK>>",
                         "projectId : bigint <<FK>>", "psmPart : varchar(8)",
                         "attemptNo : smallint", "status : varchar(32)",
                         "repeatsEnrolmentId : bigint <<FK>>"],
    "ProjectMember": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                      "studentProfileId : bigint <<FK>>", "isLeader : boolean"],
    "ExaminerPair": ["id : bigint <<PK>>", "name : varchar(64)", "psmPart : varchar(8)",
                     "examiner1ProfileId : bigint <<FK>>",
                     "examiner2ProfileId : bigint <<FK>>", "isActive : boolean"],
    "ExaminerAssignment": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                           "examinerProfileId : bigint <<FK>>",
                           "studentProfileId : bigint <<FK>>",
                           "examinerPairId : bigint <<FK>>",
                           "panelRole : varchar(32)", "isActive : boolean"],

    "Project": ["id : bigint <<PK>>", "code : varchar(32)", "title : varchar(255)",
                "psmPart : varchar(8)", "status : varchar(255)",
                "agreementId : bigint <<FK>>", "academicSemesterId : bigint <<FK>>"],
    "Milestone": ["id : bigint <<PK>>", "projectId : bigint <<FK>>", "code : varchar(32)",
                  "attempt : smallint", "status : varchar(32)", "dueAt : date"],
    "MilestoneReview": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                        "reviewerProfileId : bigint <<FK>>", "roundNo : smallint",
                        "ackReceived : boolean", "ackReviewed : boolean",
                        "verdict : varchar(32)", "totalScore : numeric",
                        "maxScore : numeric"],
    "SubmissionFile": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                       "uploadedByUserId : bigint <<FK>>", "originalName : varchar(255)",
                       "revisionNo : smallint", "isCurrent : boolean"],
    "LampiranBForm": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                      "milestoneId : bigint <<FK>>",
                      "supervisorProfileId : bigint <<FK>>", "revisionNo : smallint",
                      "title : varchar(255)", "status : varchar(32)",
                      "rejectedReason : text"],

    "RubricTemplate": ["id : bigint <<PK>>", "formCode : varchar(8)",
                       "psmPart : varchar(20)", "assessorType : varchar(20)",
                       "version : smallint", "totalMarks : numeric",
                       "isPublished : boolean"],
    "RubricComponent": ["id : bigint <<PK>>", "rubricTemplateId : bigint <<FK>>",
                        "code : varchar(32)", "title : varchar(255)",
                        "weightPercent : numeric", "sequence : smallint"],
    "RubricCriterion": ["id : bigint <<PK>>", "rubricComponentId : bigint <<FK>>",
                        "code : varchar(32)", "title : varchar(255)",
                        "weightPercent : numeric", "maxMarks : numeric"],
    "AssessmentWindow": ["id : bigint <<PK>>", "formCode : varchar(16)",
                         "psmPart : varchar(16)", "status : varchar(16)",
                         "academicSemesterId : bigint <<FK>>",
                         "openedAt : timestamp", "closedAt : timestamp"],
    "Evaluation": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                   "assessorProfileId : bigint <<FK>>",
                   "rubricTemplateId : bigint <<FK>>", "assessorType : varchar",
                   "status : varchar", "rawScore : numeric", "maxScore : numeric",
                   "scorePercent : numeric"],
    "EvaluationScore": ["id : bigint <<PK>>", "evaluationId : bigint <<FK>>",
                        "rubricComponentId : bigint <<FK>>",
                        "rubricCriterionId : bigint <<FK>>",
                        "itemScore : smallint", "marks : numeric"],

    "FinalGrade": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                   "studentProfileId : bigint <<FK>>", "finalMark : numeric",
                   "releasedAt : timestamp", "releasedByUserId : bigint <<FK>>"],
    "MarkSubmission": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                       "studentProfileId : bigint <<FK>>",
                       "finalGradeId : bigint <<FK>>", "status : varchar(255)",
                       "expectedPanelSize : smallint"],
    "GradeScheme": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                    "passMark : numeric"],

    "AcademicSemester": ["id : bigint <<PK>>", "name : varchar(255)",
                         "isActive : boolean", "startsAt : date", "endsAt : date"],
    "AuditLog": ["id : bigint <<PK>>", "actorUserId : bigint <<FK>>",
                 "action : varchar(64)", "subjectType : varchar(64)",
                 "properties : json", "createdAt : timestamp"],
    "DatabaseNotification": ["id : uuid <<PK>>", "userId : bigint <<FK>>",
                             "type : varchar(255)", "readAt : timestamp"],
}

# ---------------------------------------------------------------------------
# Relations. Cardinality tokens are re-used from make_class_diagram.C.
# ---------------------------------------------------------------------------
RELATIONS = [
    # --- identity to role, 1:1 each --------------------------------------
    ("User", "||--o|", "Role", "has", "assoc"),
    ("User", "||--o|", "AdminProfile", "is an administrator", "assoc"),
    ("User", "||--o|", "CoordinatorProfile", "is a coordinator", "assoc"),
    ("User", "||--o|", "LecturerProfile", "is a lecturer", "assoc"),
    ("User", "||--o|", "SupervisorProfile", "is a supervisor", "assoc"),
    ("User", "||--o|", "StudentProfile", "is a student", "assoc"),

    # --- the coordinator's scope now hangs off the profile ---------------
    ("CoordinatorProfile", "||--o{", "CoordinatorScope", "covers", "comp"),

    # --- teaching ----------------------------------------------------------
    ("LecturerProfile", "|o--o{", "Section", "teaches", "assoc"),
    ("Section", "||--o{", "LecturerMark", "records", "assoc"),
    ("StudentProfile", "||--o{", "LecturerMark", "earns", "assoc"),
    ("StudentProfile", "||--o|", "Section", "sits in", "assoc"),

    # --- registration ------------------------------------------------------
    ("StudentProfile", "||--o{", "StudentEnrolment", "holds", "assoc"),
    ("AcademicSemester", "||--o{", "StudentEnrolment", "enrols", "assoc"),
    ("StudentEnrolment", "||--o|", "Project", "starts", "assoc"),
    ("StudentEnrolment", "|o--o{", "StudentEnrolment", "repeats", "assoc"),
    ("StudentProfile", "||--o{", "SupervisorAgreement", "files", "assoc"),
    ("SupervisorProfile", "||--o{", "SupervisorAgreement", "signs", "assoc"),
    ("SupervisorAgreement", "||--o|", "Project", "becomes", "assoc"),
    ("StudentProfile", "||--o{", "SupervisionAssignment", "paired by", "assoc"),
    ("SupervisorProfile", "||--o{", "SupervisionAssignment", "supervises", "assoc"),
    ("Project", "||--o{", "ProjectMember", "has", "comp"),
    ("StudentProfile", "||--o{", "ProjectMember", "takes part as", "assoc"),

    # --- the chain ----------------------------------------------------------
    ("Project", "||--o{", "Milestone", "carries", "comp"),
    ("Project", "||--o{", "LampiranBForm", "files", "comp"),
    ("Milestone", "|o--o{", "LampiranBForm", "carried by", "assoc"),
    ("SupervisorProfile", "||--o{", "LampiranBForm", "files for", "assoc"),
    ("Milestone", "*--", "SubmissionFile", "holds", "comp"),
    ("Milestone", "*--", "MilestoneReview", "reviewed by", "comp"),
    ("User", "||--o{", "SubmissionFile", "uploads", "assoc"),
    ("SupervisorProfile", "|o--o{", "MilestoneReview", "reviews", "assoc"),

    # --- assessment ---------------------------------------------------------
    ("RubricTemplate", "*--", "RubricComponent", "contains", "comp"),
    ("RubricComponent", "*--", "RubricCriterion", "breaks into", "comp"),
    ("Project", "||--o{", "Evaluation", "assessed by", "comp"),
    ("SupervisorProfile", "||--o{", "Evaluation", "assesses", "assoc"),
    ("RubricTemplate", "||--o{", "Evaluation", "governs", "assoc"),
    ("Evaluation", "*--", "EvaluationScore", "scores", "comp"),
    ("RubricComponent", "|o--o{", "EvaluationScore", "measured by", "assoc"),
    ("RubricCriterion", "|o--o{", "EvaluationScore", "measured by", "assoc"),
    ("AcademicSemester", "||--o{", "AssessmentWindow", "runs", "assoc"),
    ("Project", "||--o{", "ExaminerAssignment", "panel of", "comp"),
    ("SupervisorProfile", "||--o{", "ExaminerAssignment", "examines", "assoc"),
    ("StudentProfile", "|o--o{", "ExaminerAssignment", "seated for", "assoc"),
    ("ExaminerPair", "|o--o{", "ExaminerAssignment", "seats", "assoc"),
    ("AcademicSemester", "|o--o{", "ExaminerPair", "pairs in", "assoc"),
    ("SupervisorProfile", "*--", "ExaminerPair", "examiner 1 / examiner 2", "comp"),

    # --- results -------------------------------------------------------------
    ("Project", "||--o|", "FinalGrade", "results in", "assoc"),
    ("StudentProfile", "||--o{", "FinalGrade", "awarded", "assoc"),
    ("Project", "||--o{", "MarkSubmission", "closed by", "comp"),
    ("StudentProfile", "||--o{", "MarkSubmission", "submits", "assoc"),
    ("FinalGrade", "|o--o{", "MarkSubmission", "published by", "assoc"),
    ("Project", "||--o{", "GradeScheme", "graded under", "assoc"),

    # --- the actor columns, which stay on the identity table --------------
    ("User", "|o--o{", "Project", "created or approved by *", "assoc"),
    ("User", "|o--o{", "SupervisorAgreement", "decided by *", "assoc"),
    ("User", "|o--o{", "SupervisionAssignment", "assigned by *", "assoc"),
    ("User", "|o--o{", "ExaminerPair", "created by *", "assoc"),
    ("User", "|o--o{", "ExaminerAssignment", "assigned by *", "assoc"),
    ("User", "|o--o{", "LampiranBForm", "rejected by *", "assoc"),
    ("User", "|o--o{", "AssessmentWindow", "opened, closed or created by *", "assoc"),
    ("User", "|o--o{", "RubricTemplate", "created by *", "assoc"),
    ("User", "|o--o{", "GradeScheme", "created by *", "assoc"),
    ("User", "|o--o{", "MarkSubmission", "opened or locked by *", "assoc"),
    ("User", "|o--o{", "FinalGrade", "releases or locks *", "assoc"),
    ("User", "||--o{", "AuditLog", "acts", "assoc"),
    ("User", "||--o{", "DatabaseNotification", "receives", "assoc"),
    ("User", "||--o{", "LecturerMark", "keys", "assoc"),
    ("AcademicSemester", "||--o{", "Section", "provisions", "assoc"),
    ("AcademicSemester", "||--o{", "StudentProfile", "cohort of", "assoc"),
    ("AcademicSemester", "|o--o{", "Project", "placed in", "assoc"),
    ("AcademicSemester", "|o--o{", "MarkSubmission", "closed in", "assoc"),
]

CARD = {
    "||--o{": ("1", "0..*"), "||--|{": ("1", "1..*"), "||--o|": ("1", "0..1"),
    "|o--o{": ("0..1", "0..*"), "|o--o|": ("0..1", "0..1"), "*--": ("1", "0..*"),
}

THEME = """%%{init: {
  'theme': 'base',
  'themeVariables': {
    'fontFamily': 'Times New Roman, serif',
    'fontSize': '12px',
    'primaryColor': '#ffffff',
    'primaryTextColor': '#1a1a1a',
    'primaryBorderColor': '#1a1a1a',
    'lineColor': '#333333',
    'edgeLabelBackground': '#ffffff'
  }
}}%%
classDiagram
    direction TB
"""


def render():
    out = [
        "%% Figure 4.14 - PSM Assessment System class diagram (normalised design).",
        "%%",
        "%% The logical model: `User` holds identity and authentication only, and",
        "%% every role has its own table joined 1:1 to it. This differs from the",
        "%% implementing schema, where `users` also carries staff attributes and",
        "%% only student and supervisor have a profile table. The chapter presents",
        "%% this as the design; `class_diagram_implemented.txt` is what runs.",
        "%%",
        "%% Generated by .workbuddy-ai/make_class_diagram_normalised.py.",
        "",
        THEME.rstrip(),
        "",
    ]

    drawn = set()
    for alias, table, uc, group in CLASSES:
        if alias in drawn:
            continue

        out.append(f"    %% {uc} - {group} - table `{table}`")
        out.append(f"    class {alias}{{")
        for member in ATTRIBUTES[alias]:
            out.append(f"        {member}")
        out.append("    }")
        out.append("")
        drawn.add(alias)

    out.append("    %% ---- Associations ----")
    out.append("    %% Every role table joins User 1:1. `*` marks a row where several")
    out.append("    %% `*_by` columns point at the same class.")
    out.append("")

    for left, card, right, label, kind in RELATIONS:
        if kind == "inherit":
            out.append(f"    {right} <|-- {left} : {label}")
            continue

        left_card, right_card = CARD[card]
        arrow = {"assoc": "-->", "comp": "*--", "agg": "o--"}.get(kind, "-->")
        out.append(f'    {left} "{left_card}" {arrow} "{right_card}" {right} : {label}')

    out.append("")
    return "\n".join(out)


def main():
    text = render()
    io.open(OUT, "w", encoding="utf-8", newline="\n").write(text)

    print(f"classes   : {len(CLASSES)}")
    print(f"relations : {len(RELATIONS)}")
    roles = [a for a, _t, _uc, g in CLASSES if g == "Role" and a != "CoordinatorScope"]
    print(f"role tables: {', '.join(roles)}")
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
