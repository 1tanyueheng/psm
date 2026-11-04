"""Figure 4.14 — the class diagram, widened to the tables the use cases use.

The twelve-class version answered "what are the nouns". The supervisor's note was
that it does not show the *relationships*, and they were right: twelve classes
and thirteen arrows cannot show a schema with **102 foreign keys**, and the parts
a reader cannot see — the panel pair, the assessment window, the R7/rubric
cascade, the enrolment's repeat link — are exactly the parts the use cases turn
on.

This file generates a Mermaid `classDiagram` from three declarations that are
kept honest by `.workbuddy-ai/check_class_diagram.py`:

  * **CLASSES**  — one entry per table, with the use case that needs it;
  * **ATTRIBUTES** — only the columns that carry meaning for that use case;
  * **RELATIONS** — one line per foreign key, with the cardinality the schema
    actually enforces.

It does not read the database. That would make the diagram a dump: every
`created_by`, `updated_at` and audit column would appear and the eleven
relationships a reader needs would drown. The check is what keeps it true — it
compares the arrows against the live foreign keys and fails on a missing one.

Mermaid limits, both learnt in this thesis:
  * no `namespace` blocks (draw.io drops them) — the group prefix in each id does
    the same job in the source;
  * members are drawn without `{ }` braces so a type like `varchar(16)` needs no
    quoting.
"""
import io
import os

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
OUT = os.path.join(BASE, "thesis", "diagrams", "class_diagram.txt")

# ---------------------------------------------------------------------------
# The classes. (alias, table, use case that needs it, group)
# ---------------------------------------------------------------------------
CLASSES = [
    # --- People and access ------------------------------------------------
    ("User",              "users",                    "UC-01", "People"),
    ("StudentProfile",    "student_profiles",         "UC-02", "People"),
    ("SupervisorProfile", "supervisor_profiles",      "UC-02", "People"),
    ("CoordinatorScope",  "coordinator_scopes",       "UC-05", "People"),
    ("Section",           "sections",                 "UC-04", "People"),
    ("LecturerMark",      "lecturer_marks",           "UC-04", "People"),

    # --- Registration and supervision (UC-02) ------------------------------
    ("SupervisorAgreement",      "supervisor_agreements",      "UC-02", "Registration"),
    ("SupervisionAssignment",    "supervision_assignments",    "UC-02", "Registration"),
    ("ExaminerPair",             "examiner_pairs",             "UC-04", "Registration"),
    ("ExaminerAssignment",       "examiner_assignments",       "UC-04", "Registration"),
    ("ProjectMember",            "project_members",            "UC-02", "Registration"),
    ("StudentEnrolment",         "student_enrolments",          "UC-02", "Registration"),

    # --- The project and its chain (UC-03) ---------------------------------
    ("Project",            "projects",                   "UC-03", "Chain"),
    ("Milestone",          "milestones",                 "UC-03", "Chain"),
    ("MilestoneTemplate",  "milestone_templates",        "UC-03", "Chain"),
    ("MilestoneTemplateItem", "milestone_template_items", "UC-03", "Chain"),
    ("MilestoneSchedule",  "milestone_schedules",        "UC-03", "Chain"),
    ("LampiranBForm",      "lampiran_b_forms",           "UC-02", "Chain"),
    ("SubmissionFile",     "submission_files",           "UC-03", "Chain"),
    ("SubmissionEvent",    "submission_events",          "UC-03", "Chain"),
    ("MilestoneReview",    "milestone_reviews",          "UC-03", "Chain"),

    # --- Assessment (UC-04) ------------------------------------------------
    ("RubricTemplate",     "rubric_templates",           "UC-04", "Assessment"),
    ("RubricComponent",    "rubric_components",          "UC-04", "Assessment"),
    ("RubricCriterion",    "rubric_criteria",            "UC-04", "Assessment"),
    ("AssessmentWindow",   "assessment_windows",         "UC-04", "Assessment"),
    ("Evaluation",         "evaluations",                "UC-04", "Assessment"),
    ("EvaluationScore",    "evaluation_scores",          "UC-04", "Assessment"),

    # --- Results and reporting (UC-05) -------------------------------------
    ("FinalGrade",         "final_grades",               "UC-05", "Results"),
    ("MarkSubmission",     "mark_submissions",           "UC-05", "Results"),
    ("GradeScheme",        "grade_schemes",              "UC-05", "Results"),
    ("Leaderboard",        "leaderboards",               "UC-05", "Results"),
    ("LeaderboardEntry",   "leaderboard_entries",        "UC-05", "Results"),

    # --- Term and audit (UC-06 / UC-01) ------------------------------------
    ("AcademicSemester",   "academic_semesters",         "UC-06", "Term"),
    ("DatabaseNotification", "notifications",            "UC-06", "Term"),
    ("ReminderDispatch",   "reminder_dispatches",        "UC-06", "Term"),
    ("AuditLog",           "audit_logs",                 "UC-01", "Term"),
]

# ---------------------------------------------------------------------------
# Attributes. Only the ones a use case reads or writes.
# ---------------------------------------------------------------------------
ATTRIBUTES = {
    "User": ["id : bigint <<PK>>", "name : varchar(255)", "email : varchar(255)",
             "password : varchar(255)", "role : varchar(255)", "status : varchar(255)",
             "failedLoginAttempts : smallint"],
    "StudentProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                       "matricNo : varchar(32)", "programCode : varchar(8)",
                       "yearOfStudy : smallint", "sectionId : bigint <<FK>>"],
    "SupervisorProfile": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                          "programCode : varchar(8)", "capacityPsm1 : smallint",
                          "capacityPsm2 : smallint"],
    "Section": ["id : bigint <<PK>>", "code : varchar(16)", "name : varchar(128)",
                "courseCode : varchar(16)", "lecturerId : bigint <<FK>>",
                "academicSemesterId : bigint <<FK>>"],
    "LecturerMark": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                     "sectionId : bigint <<FK>>", "academicSemesterId : bigint <<FK>>",
                     "affective : numeric", "cognitive : numeric",
                     "psychomotor : numeric", "enteredBy : bigint <<FK>>"],
    "CoordinatorScope": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                         "psmPart : varchar(8)", "programme : varchar(8)"],

    "SupervisorAgreement": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                            "supervisorProfileId : bigint <<FK>>",
                            "psmPart : varchar(8)", "proposedTitle1 : varchar(255)",
                            "agreedTitle : varchar(255)", "status : varchar(255)",
                            "studentSignedAt : timestamp",
                            "supervisorAcknowledgedAt : timestamp"],
    "SupervisionAssignment": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                              "supervisorProfileId : bigint <<FK>>",
                              "psmPart : varchar(8)", "isActive : boolean",
                              "effectiveFrom : date", "effectiveUntil : date",
                              "endedAt : timestamp"],
    "ExaminerPair": ["id : bigint <<PK>>", "name : varchar(64)", "psmPart : varchar(8)",
                     "examiner1Id : bigint <<FK>>", "examiner2Id : bigint <<FK>>",
                     "isActive : boolean"],
    "ExaminerAssignment": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                           "examinerId : bigint <<FK>>",
                           "examinerPairId : bigint <<FK>>",
                           "panelRole : varchar(32)", "isActive : boolean"],
    "ProjectMember": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                      "studentProfileId : bigint <<FK>>", "isLeader : boolean"],
    "StudentEnrolment": ["id : bigint <<PK>>", "studentProfileId : bigint <<FK>>",
                         "academicSemesterId : bigint <<FK>>",
                         "projectId : bigint <<FK>>", "psmPart : varchar(8)",
                         "attemptNo : smallint", "status : varchar(32)",
                         "repeatsEnrolmentId : bigint <<FK>>"],

    "Project": ["id : bigint <<PK>>", "code : varchar(32)", "title : varchar(255)",
                "psmPart : varchar(8)", "status : varchar(255)",
                "agreementId : bigint <<FK>>",
                "academicSemesterId : bigint <<FK>>"],
    "Milestone": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                  "milestoneTemplateItemId : bigint <<FK>>", "code : varchar(32)",
                  "attempt : smallint", "status : varchar(32)", "dueAt : date",
                  "lampiranCBy : bigint <<FK>>"],
    "MilestoneTemplate": ["id : bigint <<PK>>", "psmPart : varchar(8)",
                          "version : smallint", "isActive : boolean"],
    "MilestoneTemplateItem": ["id : bigint <<PK>>", "milestoneTemplateId : bigint <<FK>>",
                              "code : varchar(32)", "sequence : smallint",
                              "weight : numeric"],
    "MilestoneSchedule": ["id : bigint <<PK>>", "academicSemesterId : bigint <<FK>>",
                          "psmPart : varchar(8)", "code : varchar(32)",
                          "opensAt : date", "dueAt : date"],
    "LampiranBForm": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                      "milestoneId : bigint <<FK>>", "revisionNo : smallint",
                      "title : varchar(255)", "status : varchar(32)",
                      "rejectedReason : text", "supersededById : bigint <<FK>>"],
    "SubmissionFile": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                       "uploadedBy : bigint <<FK>>", "originalName : varchar(255)",
                       "revisionNo : smallint", "isCurrent : boolean",
                       "supersededById : bigint <<FK>>"],
    "SubmissionEvent": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                        "actorId : bigint <<FK>>", "event : varchar(64)",
                        "fromStatus : varchar(32)", "toStatus : varchar(32)"],
    "MilestoneReview": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                        "reviewerId : bigint <<FK>>", "roundNo : smallint",
                        "ackReceived : boolean", "ackReviewed : boolean",
                        "verdict : varchar(32)", "totalScore : numeric",
                        "maxScore : numeric"],

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
                   "assessorId : bigint <<FK>>",
                   "rubricTemplateId : bigint <<FK>>", "assessorType : varchar",
                   "psmPart : varchar", "status : varchar", "rawScore : numeric",
                   "maxScore : numeric", "scorePercent : numeric",
                   "finalScore : numeric", "isLateAssessment : boolean",
                   "submittedAt : timestamp", "releasedAt : timestamp"],
    "EvaluationScore": ["id : bigint <<PK>>", "evaluationId : bigint <<FK>>",
                        "rubricComponentId : bigint <<FK>>",
                        "rubricCriterionId : bigint <<FK>>",
                        "itemScore : smallint", "marks : numeric"],

    "FinalGrade": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                   "studentProfileId : bigint <<FK>>", "finalMark : numeric",
                   "releasedAt : timestamp", "releasedBy : bigint <<FK>>"],
    "MarkSubmission": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                       "studentProfileId : bigint <<FK>>",
                       "finalGradeId : bigint <<FK>>", "status : varchar(255)",
                       "expectedPanelSize : smallint"],
    "GradeScheme": ["id : bigint <<PK>>", "projectId : bigint <<FK>>",
                    "passMark : numeric"],
    "Leaderboard": ["id : bigint <<PK>>", "psmPart : varchar(8)",
                    "status : varchar(32)", "publishedAt : timestamp"],
    "LeaderboardEntry": ["id : bigint <<PK>>", "leaderboardId : bigint <<FK>>",
                         "projectId : bigint <<FK>>", "finalGradeId : bigint <<FK>>",
                         "rank : smallint", "score : numeric"],

    "AcademicSemester": ["id : bigint <<PK>>", "name : varchar(255)",
                         "isActive : boolean", "startsAt : date", "endsAt : date"],
    "DatabaseNotification": ["id : uuid <<PK>>", "type : varchar(255)",
                             "notifiableType : varchar(255)",
                             "notifiableId : bigint", "data : text",
                             "readAt : timestamp"],
    "ReminderDispatch": ["id : bigint <<PK>>", "milestoneId : bigint <<FK>>",
                         "userId : bigint <<FK>>", "wave : varchar(32)",
                         "sentAt : timestamp"],
    "AuditLog": ["id : bigint <<PK>>", "userId : bigint <<FK>>",
                 "action : varchar(64)", "subjectType : varchar(64)",
                 "properties : json", "createdAt : timestamp"],
}

# ---------------------------------------------------------------------------
# Relations: one line per foreign key that the use cases turn on.
#
#   (left, cardinality-left, cardinality-right, right, label, kind)
#   kind: assoc, comp (the part cannot outlive the whole), agg, inherit
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# Relations, in the three parts Mermaid's grammar actually takes:
#
#   (left, left_cardinality, arrow, right_cardinality, right, label, kind)
#
# **This used to be written as `A ||--o{ B`, which is crow's-foot notation from
# an ER grammar, not Mermaid's class grammar.** Mermaid renders it as two
# unrelated classes with no line between them, which is exactly what the user
# saw in draw.io: "so many single class with no relation". The documented form
# is a bare arrow with QUOTED cardinalities either side —
# `Customer "1" --> "*" Order : places` — so that is what is emitted now.
#
# Arrow kinds: `-->` association, `*--` composition, `o--` aggregation,
# `..>` dependency, `--|>` inheritance.
# ---------------------------------------------------------------------------
C = {                      # crow's foot -> (left cardinality, right cardinality)
    "||--o{": ("1", "0..*"), "||--|{": ("1", "1..*"), "||--o|": ("1", "0..1"),
    "|o--o{": ("0..1", "0..*"), "|o--o|": ("0..1", "0..1"), "|o--|{": ("0..1", "1..*"),
    "*--": ("1", "0..*"),
}

RELATIONS = [
    # --- People (UC-01, UC-02) -----------------------------------------------
    ("StudentProfile", "|", "{", "User", "is a", "inherit"),
    ("SupervisorProfile", "|", "{", "User", "is a", "inherit"),
    ("StudentProfile", "||--o{", "", "StudentEnrolment", "holds", "assoc"),
    ("StudentProfile", "||--o|", "", "Section", "sits in", "assoc"),
    ("User", "||--o{", "", "Section", "lectures", "assoc"),
    ("User", "||--o{", "", "LecturerMark", "keys", "assoc"),
    ("Section", "||--o{", "", "LecturerMark", "records", "assoc"),
    ("StudentProfile", "||--o{", "", "LecturerMark", "earns", "assoc"),
    ("User", "||--o{", "", "CoordinatorScope", "scoped as", "assoc"),
    ("User", "||--o{", "", "AuditLog", "acts", "assoc"),

    # --- Registration and supervision (UC-02) --------------------------------
    ("StudentProfile", "||--o{", "", "SupervisorAgreement", "files", "assoc"),
    ("SupervisorProfile", "|o--o{", "", "SupervisorAgreement", "signs", "assoc"),
    ("SupervisorAgreement", "||--o|", "", "Project", "becomes", "assoc"),
    ("StudentProfile", "||--o{", "", "SupervisionAssignment", "paired by", "assoc"),
    ("SupervisorProfile", "||--o{", "", "SupervisionAssignment", "supervises", "assoc"),
    ("SupervisorAgreement", "|o--o|", "", "SupervisionAssignment", "confirmed by", "assoc"),
    ("StudentEnrolment", "||--o|", "", "Project", "starts", "assoc"),
    ("StudentEnrolment", "|o--o{", "", "StudentEnrolment", "repeats", "assoc"),
    ("Project", "||--o{", "", "ProjectMember", "has", "comp"),
    ("StudentProfile", "||--o{", "", "ProjectMember", "takes part as", "assoc"),

    # --- The project's chain (UC-03) -----------------------------------------
    ("Project", "||--o{", "", "Milestone", "carries", "comp"),
    ("Project", "||--o{", "", "LampiranBForm", "files", "comp"),
    ("Milestone", "|o--o{", "", "LampiranBForm", "carried by", "assoc"),
    ("SupervisorProfile", "|o--o{", "", "LampiranBForm", "files for", "assoc"),
    ("LampiranBForm", "|o--o|", "", "LampiranBForm", "superseded by", "assoc"),
    ("MilestoneTemplateItem", "||--o{", "", "Milestone", "instanced as", "assoc"),
    ("MilestoneTemplate", "*--", "", "MilestoneTemplateItem", "orders", "comp"),
    ("Milestone", "*--", "", "SubmissionFile", "holds", "comp"),
    ("Milestone", "*--", "", "SubmissionEvent", "logs", "comp"),
    ("Milestone", "*--", "", "MilestoneReview", "reviewed by", "comp"),
    ("Milestone", "*--", "", "ReminderDispatch", "reminds", "comp"),
    ("User", "||--o{", "", "SubmissionFile", "uploads", "assoc"),
    ("User", "||--o{", "", "SubmissionEvent", "triggers", "assoc"),
    ("User", "|o--o{", "", "MilestoneReview", "reviews", "assoc"),
    ("User", "||--o{", "", "ReminderDispatch", "receives", "assoc"),
    # `notifications` has no foreign key to `users`: Laravel stores the owner as
    # `notifiable_id` behind a polymorphic `notifiable_type`. The association is
    # real (UC-06 reads it every time the centre loads), so it is drawn — and
    # declared in DECLARED_WITHOUT_FK so the checker does not call it invented.
    # Without it the class is an island on the page, which was the user's other
    # complaint.
    ("User", "||--o{", "", "DatabaseNotification", "is notified", "assoc"),
    ("AcademicSemester", "||--o{", "", "MilestoneSchedule", "schedules", "assoc"),

    # --- Assessment (UC-04) ---------------------------------------------------
    ("RubricTemplate", "*--", "", "RubricComponent", "contains", "comp"),
    ("RubricComponent", "*--", "", "RubricCriterion", "breaks into", "comp"),
    ("Project", "||--o{", "", "Evaluation", "assessed by", "comp"),
    ("User", "||--o{", "", "Evaluation", "assesses", "assoc"),
    ("RubricTemplate", "||--o{", "", "Evaluation", "governs", "assoc"),
    ("Evaluation", "*--", "", "EvaluationScore", "scores", "comp"),
    ("RubricComponent", "|o--o{", "", "EvaluationScore", "measured by", "assoc"),
    ("RubricCriterion", "|o--o{", "", "EvaluationScore", "measured by", "assoc"),
    ("AcademicSemester", "||--o{", "", "AssessmentWindow", "runs", "assoc"),
    ("Project", "||--o{", "", "ExaminerAssignment", "panel of", "comp"),
    ("User", "||--o{", "", "ExaminerAssignment", "examines", "assoc"),
    ("StudentProfile", "|o--o{", "", "ExaminerAssignment", "seated for", "assoc"),
    ("ExaminerPair", "|o--o{", "", "ExaminerAssignment", "seats", "assoc"),
    ("AcademicSemester", "|o--o{", "", "ExaminerPair", "pairs in", "assoc"),
    # Two foreign keys to `users` on one row: `examiner_1_id` and `examiner_2_id`.
    ("User", "2", "||", "ExaminerPair", "examiner 1 / examiner 2", "assoc"),

    # --- Results and reporting (UC-05) ----------------------------------------
    ("Project", "||--o|", "", "FinalGrade", "results in", "assoc"),
    ("StudentProfile", "||--o{", "", "FinalGrade", "awarded", "assoc"),
    ("User", "|o--o{", "", "FinalGrade", "releases", "assoc"),
    ("Project", "||--o{", "", "MarkSubmission", "closed by", "comp"),
    ("StudentProfile", "||--o{", "", "MarkSubmission", "submits", "assoc"),
    ("FinalGrade", "|o--o{", "", "MarkSubmission", "published by", "assoc"),
    ("Project", "||--o{", "", "GradeScheme", "graded under", "assoc"),
    ("Leaderboard", "*--", "", "LeaderboardEntry", "ranks", "comp"),
    ("FinalGrade", "|o--o{", "", "LeaderboardEntry", "scores", "assoc"),
    ("Project", "|o--o{", "", "LeaderboardEntry", "listed as", "assoc"),

    # --- Term ----------------------------------------------------------------
    ("AcademicSemester", "||--o{", "", "StudentEnrolment", "enrols", "assoc"),
    ("AcademicSemester", "||--o{", "", "Section", "provisions", "assoc"),
    ("AcademicSemester", "|o--o{", "", "Project", "placed in", "assoc"),
    ("AcademicSemester", "|o--o{", "", "StudentProfile", "cohort of", "assoc"),
    ("AcademicSemester", "|o--o{", "", "SupervisorAgreement", "filed in", "assoc"),
    ("AcademicSemester", "|o--o{", "", "LecturerMark", "keyed in", "assoc"),
    ("AcademicSemester", "|o--o{", "", "MarkSubmission", "closed in", "assoc"),
]

# Foreign keys that exist in the schema but are NOT drawn, with the reason. The
# checker reads this list so an omission is a decision on the record.
NOT_DRAWN = {
    ("student_enrolments", "enrolled_by"):   "who created the enrolment — an audit trail, "
                                             "not a relationship a use case reads",
    ("final_grades", "released_by"):         "drawn as `releases` from User",
    ("notifications", "*"):                  "polymorphic (`notifiable_type`), not a foreign key",
    ("examiner_pairs", "examiner_1_id"):     "drawn as one `examiner 1 / examiner 2` edge",
    ("examiner_pairs", "examiner_2_id"):     "drawn as one `examiner 1 / examiner 2` edge",
}

# Pairs the diagram draws that no foreign key backs, and why. An arrow without a
# constraint is a claim about the design, so each one has to be argued for here.
DECLARED_WITHOUT_FK = {
    ("users", "notifications"):
        "the notification's owner is `notifiable_id` behind a polymorphic type; "
        "Laravel's own table has no FK and the relationship is real",
}

USE_CASES = ["UC-01", "UC-02", "UC-03", "UC-04", "UC-05", "UC-06"]


def render():
    by_use_case = {}
    for alias, _table, uc, _group in CLASSES:
        by_use_case.setdefault(uc, []).append(alias)

    out = [
        "%% Figure 4.14 - PSM Assessment System class diagram.",
        "%% One class per table the six use cases read or write, with the",
        "%% associations the schema enforces. Generated by",
        "%% .workbuddy-ai/make_class_diagram.py; checked against the live",
        "%% foreign keys by check_class_diagram.py.",
        "%%{init: {",
        "  'theme': 'base',",
        "  'themeVariables': {",
        "    'fontFamily': 'Times New Roman, serif',",
        "    'fontSize': '13px',",
        "    'primaryColor': '#ffffff',",
        "    'primaryTextColor': '#1a1a1a',",
        "    'primaryBorderColor': '#1a1a1a',",
        "    'lineColor': '#333333',",
        "    'edgeLabelBackground': '#ffffff'",
        "  }",
        "}}%%",
        "classDiagram",
        "    direction TB",
        "",
    ]

    # A class can serve more than one use case (`Section` is placed by UC-02 and
    # keyed by UC-04). It is drawn once, under the first, and the comment names
    # the others — a class declared twice would give Mermaid two shapes.
    drawn = set()

    for uc in USE_CASES:
        members = [c for c in CLASSES if c[2] == uc and c[0] not in drawn]

        if not members:
            continue

        out.append(f"    %% ======== {uc} ========")
        out.append("")

        for alias, table, uc_of, group in members:
            others = sorted({c[2] for c in CLASSES if c[0] == alias} - {uc})
            also = f" (also {', '.join(others)})" if others else ""

            out.append(f"    %% {group} - table `{table}`{also}")
            # Braces, not brackets: Mermaid documents `class Animal{ ... }`.
            # `class Animal[ ... ]` is not a class body in any version and is
            # dropped, which leaves an empty box with no members.
            out.append(f"    class {alias}{{")
            for member in ATTRIBUTES[alias]:
                out.append(f"        {member}")
            out.append("    }")
            out.append("")

            drawn.add(alias)

    out.append("    %% ---- Associations ----")
    out.append("    %% Mermaid's documented form: a bare arrow with quoted")
    out.append("    %% cardinalities either side. Crow's-foot tokens (`||--o{`)")
    out.append("    %% belong to an ER grammar and render as NO LINE AT ALL here.")
    out.append("    %% `*--` is composition: the part cannot outlive the whole.")
    out.append("")

    for rel in RELATIONS:
        left, card, _spare, right, label, rel_kind = rel

        if rel_kind == "inherit":
            out.append(f"    {right} <|-- {left} : {label}")
            continue

        # `User 2 || ExaminerPair` is the one relation with two foreign keys to
        # the same class; it is written by hand because neither cardinality map
        # has a "two" in it.
        if card == "2" and _spare == "||":
            out.append(f'    {left} "2" --> "1" {right} : {label}')
            continue

        left_card, right_card = C[card]
        arrow = {"assoc": "-->", "comp": "*--", "agg": "o--"}.get(rel_kind, "-->")

        out.append(f'    {left} "{left_card}" {arrow} "{right_card}" {right} : {label}')

    out.append("")
    return "\n".join(out)


def main():
    text = render()
    io.open(OUT, "w", encoding="utf-8", newline="\n").write(text)

    # --- self-checks that do not need the database
    aliases = {a for a, *_ in CLASSES}
    tables = {t for _, t, *_ in CLASSES}

    missing_attr = [a for a, _, _, _ in CLASSES if a not in ATTRIBUTES]
    stray_attr = [a for a in ATTRIBUTES if a not in aliases]
    bad = []

    for left, card, kind, right, label, rel_kind in RELATIONS:
        if left not in aliases:
            bad.append(f"relation names unknown class {left}")
        if right not in aliases:
            bad.append(f"relation names unknown class {right}")

    print(f"classes   : {len(CLASSES)}")
    print(f"relations : {len(RELATIONS)}")
    print(f"covered   : {', '.join(sorted({uc for _a, _t, uc, _g in CLASSES}))}")
    print(f"missing attributes: {missing_attr or 'none'}")
    print(f"stray attributes  : {stray_attr or 'none'}")
    print(f"bad relations     : {bad or 'none'}")
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
