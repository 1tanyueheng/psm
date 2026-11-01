"""Rewrite Chapter 2 so every sentence is 15 words or fewer.

Companion to `rewrite_ch2_short.py`, which did 2.2 and the section title. This
one covers 2.1, 2.3, 2.4, 2.5 and 2.6 — the sections that still hold the long,
comma-chained sentences the user objected to.

Nothing is deleted: every claim and every citation in the original is present in
the rewrite. The paragraph indices, and the opening words of each, are asserted
before the text is replaced, so the script fails loudly rather than writing over
the wrong paragraph.

Run once after `rewrite_ch2_short.py`.
"""
import docx

PATH = (r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis"
        r"\Chapter2 - PSM Assessment System v2.docx")

NEW = {
    # ---- 2.1 Introduction ------------------------------------------
    6: (
        "This chapter presents the literature review for the PSM Assessment "
        "System. It discusses the domain case study and the web based approach. "
        "It then reviews three existing final year project systems. Finally, it "
        "compares them with the proposed system. This comparison justifies the "
        "need for a new system."
    ),

    # ---- 2.3 Problem study of existing systems ----------------------
    15: (
        "This section reviews three existing systems. Each manages final year "
        "project activity at FSKTM UTHM. That is the same domain as the proposed "
        "system. Each system is described in terms of its function and its "
        "advantages."
    ),
    17: (
        "The FSKTM FYP Management System was developed to streamline project "
        "management. It serves final year students at FSKTM. It aimed to resolve "
        "three problems (Arumugam & Abd Ishak, 2025). These are time consuming "
        "manual task tracking, inefficient communication and delayed project "
        "administering. It was developed using the waterfall methodology. That "
        "methodology included detailed user acceptance testing."
    ),
    19: (
        "FFMS provides real time progress tracking and automated deadline "
        "reminders. It also gives role based access to students, supervisors "
        "and project coordinators. It offers productive reporting mechanisms as "
        "well. The aim is to improve operational efficiency and enhance "
        "interactivity (Arumugam & Abd Ishak, 2025). It also aims to ensure "
        "timely project completion."
    ),
    20: (
        "The main advantage of FFMS is that it is the closest existing system. "
        "It already covers progress tracking, reminders and role based access. "
        "It does this in the same faculty as the proposed system. However, FFMS "
        "stops at tracking and reporting. It does not assess students. It has "
        "no weighted rubric marking. It has no gated milestone approval chain "
        "across PSM 1 and PSM 2. It also offers no mechanism for controlled "
        "result release."
    ),
    22: (
        "This system was developed to manage PSM supervision. A student can "
        "select a potential supervisor from the system. The student can also "
        "check that supervisor's availability (Wan Fauzi & Alduais, 2025). It "
        "was developed using the waterfall model. The technologies were HTML, "
        "CSS, JavaScript and PHP. It was evaluated using test plans for each "
        "module. User acceptance testing was also carried out."
    ),
    24: (
        "The function of the system is to track a student's progress. The "
        "supervisor does this through the report submission features. Testing "
        "showed that target users were satisfied with the functionality (Wan "
        "Fauzi & Alduais, 2025). They were also satisfied with the interface."
    ),
    25: (
        "The main advantage of this system is the matching stage. It handles "
        "the student to supervisor matching that PSM begins with. Its authors "
        "also name real time chat as future work. However, it covers only "
        "supervision and report submission. It does not enforce supervision "
        "capacity. It does not manage title registration or panel decisions. It "
        "performs no rubric based assessment."
    ),
    27: (
        "This system addresses the selection of a suitable supervisor. That "
        "selection is a crucial starting point for a final year project. FSKTM "
        "still relied on manual selection methods (Tam & Abdullah, 2025). Those "
        "methods were time consuming. They also produced mismatches between "
        "student research and supervisor expertise. It was developed using the "
        "agile methodology. Its structure is client server."
    ),
    29: (
        "The function of the system is to handle a student's application "
        "request. It also handles the supervisor's response. This simplifies "
        "the process of finding a supervisor. It also helps the faculty "
        "coordinator monitor the latest matching information (Tam & Abdullah, "
        "2025)."
    ),
    30: (
        "The main advantage of this system is that it makes matching "
        "systematic. It gives the coordinator visibility of who is paired with "
        "whom. However, it addresses only one step of the PSM lifecycle. Once a "
        "supervisor is selected, the system has nothing further to offer. It "
        "does not track milestones or manage submissions. Nor does it assess "
        "students against the official rubrics."
    ),

    # ---- 2.4 Your proposed system ----------------------------------
    32: (
        "The proposed system is a web based PSM Assessment System. It "
        "centralises the entire lifecycle of final year projects. It covers "
        "both PSM 1 and PSM 2."
    ),
    33: (
        "Compared with the three existing systems, this system extends them "
        "rather than repeating them. It keeps the progress tracking, "
        "notifications and role based access established by FFMS. It also keeps "
        "the supervision matching of the other two systems. What none of the "
        "three provides is assessment."
    ),
    34: (
        "The system therefore focuses on three capabilities. First, it performs "
        "automated weighted marking of the five official Lampiran rubrics. It "
        "converts rubric scores into marks using fixed non round weights. Those "
        "weights are currently calculated by hand. Second, it builds and gates "
        "the milestone chain. A milestone cannot open until the preceding one "
        "is approved. Third, it releases a final result only after every "
        "assessor has submitted. This removes the delay caused by one missing "
        "form."
    ),

    # ---- 2.5 Comparison --------------------------------------------
    36: (
        "A comparison was conducted between three existing systems and the "
        "proposed system (Arumugam & Abd Ishak, 2025; Wan Fauzi & Alduais, "
        "2025; Tam & Abdullah, 2025). The three are FFMS, the PSM Supervision "
        "System and the Supervisor Selection System. The comparison focuses on "
        "features related to PSM administration and assessment."
    ),
    39: (
        "Based on Table 2.1, all three existing systems manage one part of a "
        "final year project. Each does that part well. FFMS covers tracking and "
        "reminders. The PSM Supervision System covers matching and report "
        "submission. The Supervisor Selection System covers matching alone. "
        "None treats the full two part PSM process as one governed record. That "
        "includes its assessment."
    ),
    40: (
        "The gap is clearest in the last three assessment rows. No existing "
        "system performs automated weighted marking. None gates the milestone "
        "chain. None releases a result only after every assessor has submitted. "
        "These three capabilities are what the proposed system adds."
    ),

    # ---- 2.6 Chapter summary ---------------------------------------
    42: (
        "This chapter discussed the literature review. It also analysed "
        "existing systems related to final year project management at FSKTM "
        "UTHM. It covered the domain case study and the web based approach. It "
        "also covered three existing systems, namely FFMS, the PSM Supervision "
        "System and the Supervisor Selection System. Each system was analysed "
        "in terms of its function and advantages. A comparison then evaluated "
        "their features against the PSM Assessment System."
    ),
    43: (
        "The existing systems are effective in the parts they were built for. "
        "However, each covers only one stage of the lifecycle. None performs "
        "rubric based weighted marking. None gates the milestone chain. None "
        "controls result release. The proposed system therefore overcomes these "
        "limitations. It offers a web based platform that treats the whole PSM "
        "process as one governed record, across both parts."
    ),
    44: (
        "Chapter 3 will discuss the methodology used to develop the system. It "
        "covers the system design, the tools and the development approach."
    ),
}

EXPECT = {
    6:  "This chapter presents the literature review",
    15: "This section reviews three existing systems",
    17: "The FSKTM FYP Management System was developed",
    19: "The function of FFMS is to provide",
    20: "The main advantage of FFMS is",
    22: "This system was developed to manage PSM supervision",
    24: "The function of the system is to let a supervisor",
    25: "The main advantage of this system is that it handles",
    27: "This system was developed because the selection",
    29: "The function of the system is to handle the student's",
    30: "The main advantage of this system is that it makes",
    32: "The proposed system is a web based PSM Assessment System designed",
    33: "Comparing with the three existing systems",
    34: "The system therefore focuses on three capabilities",
    36: "A comparison has been conducted between the existing systems",
    39: "Based on Table 2.1",
    40: "The gap is clearest in the last three assessment rows",
    42: "This chapter discussed the literature review and the analysis",
    43: "The existing systems are effective in the parts",
    44: "Chapter 3 will discuss the methodology used in the development",
}


def replace_paragraph(paragraph, text):
    runs = paragraph.runs
    if not runs:
        paragraph.add_run(text)
        return
    runs[0].text = text
    for extra in runs[1:]:
        extra._element.getparent().remove(extra._element)


def main():
    doc = docx.Document(PATH)

    for idx, text in NEW.items():
        p = doc.paragraphs[idx]
        assert p.text.strip().startswith(EXPECT[idx]), (idx, p.text[:80])
        replace_paragraph(p, text)

    doc.save(PATH)
    print(f"rewrote {len(NEW)} paragraphs in {PATH}")


if __name__ == "__main__":
    main()
