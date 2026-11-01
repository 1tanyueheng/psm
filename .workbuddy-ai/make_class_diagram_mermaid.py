"""Figure 4.14 as a Mermaid class diagram — the same twelve classes `class_diagram.py`
draws, with the same columns, members and multiplicities, in the form the user now
works in (draw.io).

`system_class.txt` covers all 39 models. This is the *chapter* figure and stays at
the twelve the chapter's data dictionary defines: Table 4.11.. shows exactly these.

Paste into draw.io → Arrange → Insert → Advanced → Mermaid.

Two rules, both learnt the hard way (`PROGRESS.md` §3A.0a):
  * no `namespace` blocks — draw.io drops them and the classes arrive ungrouped;
  * the init block sits before `classDiagram` and carries no `themeCSS`.

Member order follows the box on the PNG: attributes, then operations.
"""
import io
import os

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
OUT = os.path.join(BASE, "thesis", "diagrams", "class_diagram.txt")

SOURCE = """%% Figure 4.14 - PSM Assessment System class diagram.
%% The twelve classes of the Chapter 4 data dictionary. Paste into mermaid.live,
%% or draw.io -> Arrange > Insert > Advanced > Mermaid (choose DIRECTORY).
%%{init: {
  'theme': 'base',
  'themeVariables': {
    'fontFamily': 'Times New Roman, serif',
    'fontSize': '14px',
    'primaryColor': '#ffffff',
    'primaryTextColor': '#1a1a1a',
    'primaryBorderColor': '#1a1a1a',
    'lineColor': '#333333',
    'edgeLabelBackground': '#ffffff',
    'classText': '#1a1a1a'
  }
}}%%
classDiagram
    direction TB

    class User {
        -id : bigint <<PK>>
        -name : varchar(255)
        -email : varchar(255)
        -password : varchar(255)
        -role : varchar(255)
        -status : varchar(255)
        -failedLoginAttempts : smallint
        +hasRole(role) : bool
        +canAssess() : bool
    }

    class StudentProfile {
        -id : bigint <<PK>>
        -userId : bigint <<FK>>
        -matricNo : varchar(32)
        -programme : varchar(8)
        -yearOfStudy : smallint
        +label() : string
    }

    class SupervisorProfile {
        -id : bigint <<PK>>
        -userId : bigint <<FK>>
        -programme : varchar(8)
        -capacityPsm1 : smallint
        -capacityPsm2 : smallint
        +canSuperviseProgramme(p) : bool
    }

    class StudentEnrolment {
        -id : bigint <<PK>>
        -studentProfileId : bigint <<FK>>
        -psmPart : varchar(8)
        -attemptNo : smallint
        -status : varchar(32)
        +place(semester) : void
        +settle(passed) : void
    }

    class Project {
        -id : bigint <<PK>>
        -code : varchar(32)
        -title : varchar(255)
        -psmPart : varchar(8)
        -status : varchar(255)
        -agreementId : bigint <<FK>>
        +isActive() : bool
        +isPassed() : bool
    }

    class Milestone {
        -id : bigint <<PK>>
        -projectId : bigint <<FK>>
        -code : varchar(32)
        -attempt : smallint
        -status : varchar(32)
        -dueAt : timestamp
        +acceptsSubmission() : bool
        +lateness() : array
    }

    class LampiranBForm {
        -id : bigint <<PK>>
        -projectId : bigint <<FK>>
        -revisionNo : smallint
        -title : varchar(255)
        -status : varchar(32)
        -rejectedReason : text
        +scopeCurrent() : Builder
    }

    class SupervisionAssignment {
        -id : bigint <<PK>>
        -studentProfileId : bigint <<FK>>
        -supervisorProfileId : bigint <<FK>>
        -psmPart : varchar(8)
        -isActive : bool
        +retire() : void
    }

    class Evaluation {
        -id : bigint <<PK>>
        -projectId : bigint <<FK>>
        -assessorId : bigint <<FK>>
        -status : varchar(32)
        -scorePercent : numeric
        +recalculateTotals() : void
    }

    class EvaluationScore {
        -id : bigint <<PK>>
        -evaluationId : bigint <<FK>>
        -criterionCode : varchar(32)
        -marks : numeric
        +weight() : numeric
    }

    class FinalGrade {
        -id : bigint <<PK>>
        -projectId : bigint <<FK>>
        -finalMark : numeric
        -releasedAt : timestamp
        +isPassed() : bool
    }

    class AuditLog {
        -id : bigint <<PK>>
        -actorId : bigint <<FK>>
        -action : varchar(64)
        -subjectType : varchar(64)
        +log(action, subject) : void
    }

    %% User is specialised: this edge is read right-to-left, "User is a StudentProfile".
    StudentProfile --|> User
    SupervisorProfile --|> User

    %% The left column.
    StudentProfile "1" --> "0..*" StudentEnrolment : enrolled by
    StudentProfile "1" --> "0..1" Project : registers
    StudentEnrolment "0..1" --> "1" Project : becomes

    %% Supervision sits between the supervisor and the project.
    SupervisorProfile "1" --> "0..*" SupervisionAssignment : takes
    SupervisionAssignment "0..*" --> "1" Project : on

    %% The project carries its chain and its filings.
    Project "1" --> "1..*" Milestone : carries
    Project "1" --> "1..*" LampiranBForm : filed as

    %% The marking chain.
    Project "1..*" --> "1" Evaluation : assessed by
    Project "1" --> "1" FinalGrade : results in
    Evaluation "1" --> "1..*" EvaluationScore : scores
    EvaluationScore ..> FinalGrade : sums into

    %% AuditLog is drawn but has no relation on the PNG either.
"""


def main():
    io.open(OUT, "w", encoding="utf-8", newline="\n").write(SOURCE)

    # --- self-check: every id on an edge must be a declared class
    declared, used, edges = set(), set(), []
    in_block = False
    for raw in SOURCE.splitlines():
        s = raw.strip()
        if s.startswith("class ") and s.endswith("{"):
            declared.add(s.split()[1])
            in_block = True
            continue
        if in_block:
            if s == "}":
                in_block = False
            continue
        if "--> " in s or "--|>" in s or "..>" in s:
            parts = s.split()
            # A <card> <arrow> <card> B : label   |   A <arrow> B
            arrow_at = next(i for i, p in enumerate(parts)
                            if p in ("-->", "--|>", "..>", "o--", "*--", "<|--"))
            used.add(parts[0])
            used.add(parts[arrow_at + 2] if parts[arrow_at + 1].startswith('"')
                     else parts[arrow_at + 1])
            edges.append(s)

    print(f"classes declared : {len(declared)}  (expected 12)")
    print(f"relations        : {len(edges)}  (expected 13)")
    print(f"used but not declared: {sorted(used - declared) or 'none'}")
    print(f"declared, no relation: {sorted(declared - used) or 'none'}  (AuditLog is expected here)")
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
