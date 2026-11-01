"""Bring every Chapter 4 prose paragraph within the 15-word sentence limit.

The specification tables and the requirement lists are untouched: their entries
are a single clause each and are read as a list, not as prose. What this script
rewrites is the connecting text between the tables and figures, where the long
sentences actually live.
"""
import io
import re

P = ".workbuddy-ai/build_chapter4_v2.py"
t = io.open(P, encoding="utf-8").read()

REPL = [
    # ---- 4.1 -------------------------------------------------------
    ('"System requirement analysis is the process of identifying the needs and the "\n'
     '  "functionalities that the PSM Assessment System has to provide. The system runs "\n'
     '  "the whole Projek Sarjana Muda lifecycle for PSM 1 and PSM 2 in parallel. That "\n'
     '  "lifecycle covers title registration, supervisor pairing, the milestone chain, "\n'
     '  "rubric assessment, the decision at the end of a semester and notification. The "\n'
     '  "analysis here covers the system modules, the functional requirements and the "\n'
     '  "non-functional requirements."',
     '"System requirement analysis identifies what the PSM Assessment System must do. "\n'
     '  "The system runs the whole Projek Sarjana Muda lifecycle. It covers PSM 1 and "\n'
     '  "PSM 2 in parallel. That lifecycle has six parts. These are title registration "\n'
     '  "and supervisor pairing. They also include the milestone chain and rubric "\n'
     '  "assessment. The last two are the end-of-semester decision and notification. "\n'
     '  "The analysis here covers the modules and both kinds of requirement."'),

    # ---- 4.2 -------------------------------------------------------
    ('"User requirement analysis describes what the users need from the system and how "\n'
     '  "they reach it. This section presents the use case diagram, and then specifies "\n'
     '  "each use case in the format the faculty requires."',
     '"User requirement analysis describes what the users need. It also shows how they "\n'
     '  "reach it. This section presents the use case diagram first. It then specifies "\n'
     '  "each use case in the required format."'),

    ('"login, title and registration, milestones, evaluation and reports. A supervisor "\n'
     '  "reaches login, the acknowledgement, the milestone review and the evaluation. A "\n'
     '  "lecturer reaches login and coursework entry. A coordinator and an admin reach "\n'
     '  "every use case, including the reports and the notifications. Pairing is not a use "\n'
     '  "case of its own: it is a step inside title and registration."',
     '"login, title and registration, milestones, evaluation and reports. A supervisor "\n'
     '  "reaches login, the acknowledgement, the milestone review and the evaluation. A "\n'
     '  "lecturer reaches login and coursework entry. A coordinator and an admin reach "\n'
     '  "every use case. Pairing is not a use case of its own. It is a step inside title "\n'
     '  "and registration."'),

    ('"This section specifies each use case in turn. Every specification carries its "\n'
     '  "history log, its actors, its normal flow, its alternative flows and its "\n'
     '  "exceptions. Each table ends with the requirements that the use case satisfies, "\n'
     '  "and the sequence diagram and activity diagram for the same use case follow "\n'
     '  "immediately beneath it."',
     '"This section specifies each use case in turn. Every specification carries its "\n'
     '  "history log and its actors. It also carries its normal flow, its alternative "\n'
     '  "flows and its exceptions. Each table ends with the requirements it satisfies. "\n'
     '  "Both diagrams for the use case follow beneath the table."'),

    # ---- 4.2.3 -----------------------------------------------------
    ('"The class diagram in Figure 4.2 shows the domain classes the system is built "\n'
     '  "from and the associations between them. Twelve classes carry the lifecycle: a "\n'
     '  "project holds milestones and members; a milestone holds submissions and its "\n'
     '  "review; a project holds a final grade and a set of mark submissions; and the "\n'
     '  "structure records behind them are the Lampiran B filings, the enrolments, the "\n'
     '  "supervisions and the panel seats."',
     '"The class diagram shows the domain classes and their associations. Twelve "\n'
     '  "classes carry the lifecycle. A project holds milestones and members. A milestone "\n'
     '  "holds submissions and its review. A project holds a final grade and a set of "\n'
     '  "mark submissions. Behind these sit four records. These are the Lampiran B "\n'
     '  "filings, the enrolments, the supervisions and the panel seats."'),

    # ---- 4.3 -------------------------------------------------------
    ('"The database is PostgreSQL. It holds one schema for the whole lifecycle, and "\n'
     '  "every rule the analysis describes is enforced here as well as in the "\n'
     '  "application. Where a rule can be expressed as a constraint it is expressed as "\n'
     '  "one, so a bug in the application cannot write a state the analysis forbids. The "\n'
     '  "data dictionary below is generated from the live schema rather than typed, so "\n'
     '  "the types and sizes are what the database holds."',
     '"The database is PostgreSQL. It holds one schema for the whole lifecycle. Every "\n'
     '  "rule the analysis describes is enforced here too. A rule that can be a "\n'
     '  "constraint is written as one. An application bug cannot then write a state the "\n'
     '  "analysis forbids. The data dictionary below is generated from the live schema. "\n'
     '  "Its types and sizes are therefore what the database holds."'),

    # ---- 4.4 -------------------------------------------------------
    ('"The interface is a single-page React client. It is organised by role, so a "\n'
     '  "user sees the screens their role allows and nothing else. Every screen is "\n'
     '  "built from the same components: a page header, a filter bar, a data table and "\n'
     '  "a form. Three screens are described here because they carry the lifecycle: the "\n'
     '  "login screen, Lampiran A and Lampiran B."',
     '"The interface is a single-page React client. It is organised by role. A user "\n'
     '  "therefore sees only the screens their role allows. Every screen uses the same "\n'
     '  "four components. These are a page header, a filter bar, a data table and a "\n'
     '  "form. Three screens are described here. They are login, Lampiran A and "\n'
     '  "Lampiran B."'),

    ('"The login screen asks for an email address and a password. It is the only "\n'
     '  "screen reachable without a session, and it is what decides which dashboard the "\n'
     '  "user lands on."',
     '"The login screen asks for an email address and a password. It is the only "\n'
     '  "screen reachable without a session. It also decides which dashboard the user "\n'
     '  "lands on."'),

    ('"The Lampiran A screen records the student\'s particulars, up to three proposed "\n'
     '  "titles and the supervisor they have approached. The session is a dropdown of "\n'
     '  "open terms, and the supervisor list offers only staff of the student\'s "\n'
     '  "programme who have capacity."',
     '"The Lampiran A screen records the student\'s particulars and up to three "\n'
     '  "proposed titles. It also records the supervisor they approached. The session "\n'
     '  "is a dropdown of open terms. The supervisor list offers only staff of the "\n'
     '  "student\'s programme. It also offers only those with capacity."'),

    ('"The Lampiran B screen records the project type, the field of study and the "\n'
     '  "software, hardware and technology the project requires. The supervisor signs "\n'
     '  "it here, which opens the proposal milestone."',
     '"The Lampiran B screen records the project type and the field of study. It also "\n'
     '  "records the software, hardware and technology required. The supervisor signs "\n'
     '  "it here. Signing opens the proposal milestone."'),

    # ---- 4.5 -------------------------------------------------------
    ('"This chapter presented the analysis and design of the PSM Assessment System. "\n'
     '  "It began with the system modules, the functional requirements and the "\n'
     '  "non-functional requirements. It then described the users\' requirements through "\n'
     '  "the use case diagram and six use case specifications. Each specification "\n'
     '  "carried its history log, its actors, its flows and its exceptions, and was "\n'
     '  "followed by its sequence diagram and activity diagram."',
     '"This chapter presented the analysis and design of the system. It began with "\n'
     '  "the system modules. It then gave the functional and non-functional "\n'
     '  "requirements. The users\' requirements followed. These were the use case "\n'
     '  "diagram and six specifications. Each specification carried its history log and "\n'
     '  "its actors. Each also carried its flows and exceptions, followed by both "\n'
     '  "diagrams."'),

    ('"The design was then presented. The class diagram described the domain classes "\n'
     '  "and their associations. The database design gave the core tables and the data "\n'
     '  "dictionary for the eight tables the lifecycle rests on. The interface design "\n'
     '  "described the three screens that carry registration and the Lampiran forms."',
     '"The design was then presented. The class diagram described the domain classes. "\n'
     '  "The database design gave the core tables. It also gave a data dictionary for "\n'
     '  "eight tables. The interface design described three screens. These carry "\n'
     '  "registration and the Lampiran forms."'),

    ('"The next chapter presents the implementation of the system and the testing "\n'
     '  "carried out on it, including User Acceptance Testing."',
     '"The next chapter presents the implementation. It also reports the testing "\n'
     '  "carried out, including User Acceptance Testing."'),
]

missed = 0
for old, new in REPL:
    if old in t:
        t = t.replace(old, new, 1)
    else:
        missed += 1
        print("NOT FOUND:", old[:70].replace("\n", " "))

io.open(P, "w", encoding="utf-8").write(t)
print(f"applied {len(REPL) - missed} of {len(REPL)}")
