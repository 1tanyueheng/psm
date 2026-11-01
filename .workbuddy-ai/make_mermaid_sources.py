"""Write the Mermaid sequence sources for the remaining four use cases.

One theme block, identical to `login.txt` and `title.txt`, so all six figures in
Chapter 4 match. Each diagram carries the use case's alternative flows as `alt`
branches, labelled with the same A.n numbers the specification table uses — that
is the mismatch the user found: the table listed A.1..A.n and the diagram showed
none of them.

Output is `thesis/diagrams/<usecase>.txt`, for pasting into the Mermaid editor.
"""
import io
import os

BASE = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system"
DIA = os.path.join(BASE, "thesis", "diagrams")

THEME = """%%{init: {
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
"""

MILESTONE = """    actor S as Student
    participant UI as Milestone UI<br/>(React SPA)
    participant C as Milestone<br/>Controller
    participant SVC as Milestone<br/>Service
    participant M as Milestone row
    participant SUP as Supervisor

    S->>UI: Open the project's milestone list
    UI->>C: GET /api/milestones?project_id=...
    C->>M: Load the chain in order
    M-->>C: one row per step, with its status
    C-->>UI: 200 OK, the chain

    S->>UI: Upload the work to the open milestone
    UI->>C: POST /api/milestones/{id}/submit
    C->>SVC: submit(milestone, files)

    alt A.1 The chain has not opened this milestone
        SVC-->>C: 422 refused
        C-->>UI: 422 refused, the milestone is still locked
    else Open
        SVC->>M: Save the submission, status = submitted
        SVC->>M: Record the lateness flag, if any
        C-->>UI: 201 Created
    end

    SUP->>UI: Open the submission
    UI->>C: GET /api/milestones/{id}
    C-->>UI: the milestone and its files
    SUP->>UI: Approve, or request a revision
    UI->>C: POST /api/milestones/{id}/review
    C->>SVC: review(milestone, decision)

    alt A.2 The supervisor requests a revision
        SVC->>M: status = rejected, revision_count + 1
        SVC-->>C: the milestone reopens for a new upload
        C-->>UI: 200 OK, back to a locked chain position
    else A.3 The proposal is sent back
        SVC->>M: status = rejected, attempt unchanged
        C-->>UI: 200 OK, the chain does not advance
    else A.4 The milestone is the proposal and the panel approves
        SVC->>M: status = approved
        Note over SVC,M: Approving the proposal does not open the chain,<br/>because its verdict belongs to the panel
    else A non-proposal milestone is approved
        SVC->>M: status = approved
        SVC->>M: Open the next milestone in the chain
        C-->>UI: 200 OK
    end
"""

EVALUATION = """    actor A as Assessor<br/>(supervisor, examiner, lecturer)
    participant UI as Evaluation UI<br/>(React SPA)
    participant C as Evaluation<br/>Controller
    participant SVC as Evaluation<br/>Service
    participant R as Rubric, Scores,<br/>Vitality + Audit

    A->>UI: Open the allocated form
    UI->>C: GET /api/evaluations/{id}
    C->>SVC: load the rubric and the existing scores
    SVC-->>C: rubric criteria and their weights
    C-->>UI: 200 OK, one 0-5 select per criterion

    A->>UI: Choose a score for each criterion
    UI->>C: PUT /api/evaluations/{id}/marks
    C->>SVC: saveMark(evaluation, marks)

    alt A.1 This form's assessment window is not open
        SVC-->>C: 422 refused
        C-->>UI: 422 refused, this form is not open yet
    else A.3 A score is outside 0 to 5
        SVC-->>C: 422 refused
        C-->>UI: 422 refused, the assessor corrects it
    else Valid
        SVC->>R: Convert each item score to the criterion's weight
        SVC->>R: Store the item score beside the converted mark
        C-->>UI: 200 OK, marks saved
    end

    A->>UI: Submit the form
    UI->>C: POST /api/evaluations/{id}/submit
    C->>SVC: submit(evaluation)

    alt A.2 The form is already submitted
        SVC-->>C: 422 refused, the form is locked
    else A.4 A criterion is unmarked
        SVC-->>C: 422 refused, naming each unmarked criterion
    else A.5 A dependent milestone is not approved
        SVC-->>C: 422 refused
    else A.6 The assessor declares a conflict of interest
        SVC->>R: Mark the form recused
        SVC->>R: Notify the coordinators, so a replacement can be allocated
    else Every criterion marked
        SVC->>R: Lock the form, recompute and publish the final mark
        C-->>UI: 200 OK
        UI-->>A: The student can now see the mark
    end
"""

REPORT = """    actor CO as Coordinator
    participant UI as Report UI<br/>(React SPA)
    participant C as Report<br/>Controller
    participant SVC as Analytics<br/>Service
    participant DB as Projects, Marks,<br/>Milestones

    CO->>UI: Choose the batch, the PSM part and the term
    UI->>C: GET /api/reports/cohort
    C->>SVC: check the analytics permission on the server

    alt A.1 The caller may not view analytics
        C-->>UI: 403 Forbidden
    else A.2 A filter names a term that does not exist
        C-->>UI: 422 refused
    else Permitted, with valid filters
        SVC->>DB: Aggregate progress, at-risk, workload and mark bands
        DB-->>SVC: the figures
        SVC-->>C: the figures
        C-->>UI: 200 OK, the tables and charts

        alt A.4 No grade has been released yet
            Note over SVC,DB: Empty mark bands rather than zero,<br/>because no mark is not a mark of zero
        else Grades have been released
            SVC->>DB: Include the released marks
        end
    end

    CO->>UI: Export the grade or project list
    UI->>C: GET /api/reports/export
    C->>SVC: apply the narrower export permission

    alt A.3 The caller may not export
        C-->>UI: 403 Forbidden
    else Permitted
        SVC->>DB: Stream the rows as CSV
        SVC->>DB: Record the export and its filters in the audit log
        C-->>UI: the CSV file
    end
"""

NOTIFICATION = """    participant SCH as Scheduler<br/>(scheduled command)
    participant NS as Notification<br/>Service
    participant DB as Milestones, Users,<br/>Ledger
    participant M as Mail transport
    actor U as User

    SCH->>NS: Run the reminder sweep
    NS->>DB: Find milestones due in seven, three or one days
    DB-->>NS: the milestones and their owners
    NS->>DB: Read each user's preferences and status

    alt A.1 Nothing is due in this wave
        NS-->>SCH: nothing to send, move to the next wave
    else A.2 This recipient was already sent this wave
        Note over NS,DB: The ledger entry exists, so a retried run<br/>cannot send the same reminder twice
        NS-->>SCH: skipped
    else A.3 The user opted out of this type, or is inactive
        NS-->>SCH: nothing sent to that user
    else Send
        NS->>DB: Write the in-app notification
        NS->>M: Send the mail
        M-->>NS: accepted
        NS->>DB: Record the dispatch in the ledger
        NS-->>SCH: sent
    end

    U->>NS: Open the notification centre
    NS->>DB: Read the user's unread items
    DB-->>NS: the items
    NS-->>U: the list
    U->>NS: Dismiss an item, or change the types they receive
    NS->>DB: Save the dismissal or the preference
    NS-->>U: confirmed
"""

FILES = {
    "milestone": MILESTONE,
    "evaluation": EVALUATION,
    "report": REPORT,
    "notification": NOTIFICATION,
}

for name, body in FILES.items():
    path = os.path.join(DIA, f"{name}.txt")
    io.open(path, "w", encoding="utf-8").write(THEME + body)
    print("wrote", path)
