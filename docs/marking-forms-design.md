# Marking Forms — System Design (Lampiran E, G, H, I, J)

Status: **draft for review** · Institution: UTHM FSKTM · Last updated: 2026-10-02

R1 is **out of scope** until its usage is confirmed.

---

## 1. The five forms at a glance

| Form | Name | Filled by | PSM | Form max | % label |
|------|------|-----------|-----|----------|---------|
| **E** | Penilaian PSM 1 bagi Penyelia | Supervisor | PSM1 | 35 | 35% |
| **I** | Penilaian Seminar 1 bagi Penilai | Examiner | PSM1 | 30 | 30% |
| **G** | Penilaian PSM 2 bagi Penyelia | Supervisor | PSM2 | 50 | 50% |
| **H** | Penilaian Laporan Kemajuan bagi Penyelia | Supervisor | PSM2 | 25 (raw) | 5% |
| **J** | Penilaian Seminar Akhir bagi Penilai | Examiner | PSM2 | 40 | 40% |

Two assessor roles: **supervisor** (Penyelia) and **examiner** (Penilai). Each form exists in two
category variants — **Pembangunan (system)** and **Kajian (research)** — selected by the project's
category.

---

## 2. Scoring model (identical across all forms)

- Every item is scored on a **0–5 scale**:
  `0 = Tiada Data` · `1 = Sangat Kurang Memuaskan` · `2 = Kurang Memuaskan` ·
  `3 = Sederhana` · `4 = Baik` · `5 = Sangat Baik`
- Item contribution = `(score / 5) × item_weight`
- Component subtotal = Σ item contributions (component max = Σ item weights)
- Form grand total = Σ component subtotals

---

## 3. Per-form structure

### Lampiran E — PSM 1 Supervisor (max 35)

| Component | Max | Items (weight) |
|-----------|-----|----------------|
| A · Logbook | 5 | Implementation Planning 1.25 · Interaction with Supervisor 1.25 · Weekly Activities 1.25 · Progress Report Discussion 1.25 |
| B · Report Writing | 20 | Chapter 1 · 2 · 3 · 4 (5.00 each) |
| C(i) · Prototype *(Development)* | 10 | Analysis & Specifications 3.30 · UI / Storyboard 3.30 · Prototype 3.40 |
| C(ii) · Research Framework *(Research)* | 10 | Tools / Data / Case Studies 3.30 · Technique / Method / Algorithm 3.30 · Measurement Technique 3.40 |

### Lampiran I — PSM 1 Examiner (max 30)

| Component | Max | Items (weight) |
|-----------|-----|----------------|
| A · Proceeding Paper | 10 | Abstract 1.45 · Introduction 1.45 · Literature Review 1.45 · Methodology 1.40 · Analysis & Design 1.45 · Conclusion & Reference 1.40 · Report Formatting 1.40 |
| B(i) · Prototype Presentation *(Development)* | 15 | Analysis & Specifications 4.95 · UI / Storyboard 4.95 · Prototype 5.10 |
| B(ii) · Research Framework Presentation *(Research)* | 15 | Tools / Data / Case Studies 4.95 · Technique / Method / Algorithm 4.95 · Measurement Technique 5.10 |
| C · Presentation | 5 | Appearance 1.65 · Q&A Session 1.65 · Presentation Organisation 1.70 |

### Lampiran G — PSM 2 Supervisor (max 50)

| Component | Max | Items (weight) |
|-----------|-----|----------------|
| A · Logbook | 5 | Implementation Planning 1.25 · Interaction with Supervisor 1.25 · Weekly Activities 1.25 · Progress Report Discussion 1.25 |
| B · PSM 2 Report | 20 | Abstract 1.15 · Introduction 2.20 · Literature Review 3.30 · Methodology 2.20 · Analysis & Design 3.30 · Results / Findings 4.50 · Conclusion 1.15 · Report Formatting 2.20 |
| C(i) · Product *(Development)* | 25 | System Analysis 4.19 · System Design 4.19 · Translation of Development 4.19 · Implementation 4.19 · Testing & Validation 4.12 · Commercial Value 4.12 |
| C(ii) · Product *(Research)* | 25 | Analysis 4.19 · Design / Algorithm 4.19 · Translation of Methodology 4.19 · Implementation / Simulation 4.19 · Testing & Validation 4.12 · Commercial Value 4.12 |

### Lampiran H — PSM 2 Progress Report, Supervisor (raw max 25 · labelled 5%)

| Component | Max | Items (weight) |
|-----------|-----|----------------|
| Progress Report (1 or 2) | 25 | Milestone Achievement 1.65 · Progressed as Planned 1.65 · Increased Knowledge & Skills 1.70 |

> The form has a **Laporan Kemajuan 1 / 2** selector, so it is filled twice per student.

### Lampiran J — PSM 2 Examiner (max 40)

| Component | Max | Items (weight) |
|-----------|-----|----------------|
| A · Proceeding Paper | 10 | Abstract 1.45 · Intro & Literature Review 1.45 · Methodology 1.45 · Findings / Discussion 1.45 · Conclusion & Suggestion 1.40 · Reference 1.40 · Report Formatting 1.40 |
| B(i) · Final Product *(Development)* | 25 | System Analysis 4.19 · System Design 4.19 · Translation of Development 4.19 · Implementation 4.19 · Testing & Validation 4.12 · Commercial Value 4.12 |
| B(ii) · Final Product *(Research)* | 25 | Analysis 4.19 · Design / Algorithm 4.19 · Translation of Methodology 4.19 · Implementation / Simulation 4.19 · Testing & Validation 4.12 · Significance 4.12 |
| C · Presentation | 5 | Appearance 1.25 · Field Knowledge 1.25 · Presentation Organisation 1.25 · Q&A Session 1.25 |

---

## 4. Mapping to the existing database

Your schema already models this. **No new mark tables are needed** — only seed data plus one
discriminator column.

| Form concept | Existing table |
|--------------|----------------|
| One form (per category) | `rubric_templates` (unique on `category, psm_part, assessor_type, version`) |
| Component A / B / C | `rubric_components` (`weight_percent`) |
| One assessment item | `rubric_criteria` (`weight_percent`, `max_marks`) |
| One assessor's completed form | `evaluations` (+ `rubric_snapshot`) |
| One item's mark | `evaluation_scores` (`marks_awarded`, `max_marks`) |
| How assessors combine | `grade_schemes` (`weights`, `aggregation`) |
| The computed result | `final_grades` (`supervisor_score`, `examiner_score`, `aggregate_percent`, `final_mark`) |

### 4.1 Ten rubric templates (5 forms × 2 categories)

| Template | category | psm_part | assessor_type |
|----------|----------|----------|---------------|
| E | system / research | PSM1 | supervisor |
| I | system / research | PSM1 | examiner |
| G | system / research | PSM2 | supervisor |
| H | system / research | PSM2 | supervisor *(needs discriminator — see §6.3)* |
| J | system / research | PSM2 | examiner |

### 4.2 Normalising weights

Rubric weights are **percentages** (`total_marks` + `weight_percent`), while the forms use raw
weights. Convert as:

- component `weight_percent` = component max ÷ form total × 100
- criterion `weight_percent` = item weight ÷ component max × 100
- criterion `max_marks` = the item's raw weight

**Example — Lampiran E (system), total 35:**
- A Logbook: `5 / 35 = 14.29%` · B Report: `20 / 35 = 57.14%` · C(i): `10 / 35 = 28.57%`
- Within A: each item `1.25 / 5 = 25%`
- Within B: each chapter `5 / 20 = 25%`
- Within C(i): `3.30/10 = 33%`, `3.30/10 = 33%`, `3.40/10 = 34%`

---

## 5. Assessor → form assignment

| PSM | Supervisor fills | Examiner fills |
|-----|------------------|----------------|
| PSM1 | **E** | **I** |
| PSM2 | **G** + **H** (×2) | **J** |

Coordinator moderates and releases. A supervisor may not examine their own student
(`AssignmentService::assignExaminer` already blocks this).

---

## 6. Open questions — please confirm before I build

1. **Weights do not sum to 100.**
   PSM1 = E(35) + I(30) = **65**. PSM2 = G(50) + H(5) + J(40) = **95**.
   How is the final mark produced — normalise to 100, or is a component missing?

2. **Lampiran H scaling.** Raw max is 25 but it is labelled **5%**. Is it scaled
   `(raw / 25) × 5`, and is it submitted **twice** (Laporan Kemajuan 1 and 2, each 5%)?

3. **G vs H collide.** Both are `(PSM2, supervisor)`, which breaks the rubric template's unique
   key. Proposal: add a `form_code` column (`E/I/G/H/J`) to `rubric_templates` and include it in
   the unique key.

4. **Multiple examiners.** If two examiners each fill I/J, how do their marks combine — simple
   mean, or weighted? (`grade_schemes.aggregation` supports mean / weighted_mean / max / min.)

5. **Category variant.** C(i)/B(i) vs C(ii)/B(ii) is chosen automatically from the project's
   category (system → (i), research → (ii)). Confirm.

6. **Supervisor vs examiner split.** What are the official weights — PSM1 `supervisor 35 : examiner 30`
   and PSM2 `supervisor 55 : examiner 40` (H counted inside supervisor)?

---

## 7. Implementation plan (once confirmed)

1. Add `form_code` to `rubric_templates` (resolves §6.3).
2. Seed the 10 rubric templates with components, criteria and normalised weights.
3. Reuse the rubric-driven `EvaluationController` + `EvaluationFormPage` to render each form —
   the 0–5 radio scale maps directly onto criterion marking.
4. Configure `grade_schemes` per PSM part with the confirmed weights/aggregation.
5. Add the form list + submission flow for supervisors/examiners.

---

## Appendix — source files

Converted HTML forms live in `form required/`:

- `lampiran A-B/` — Lampiran A, B (registration)
- `penilaian psm 1 ,2 (e,g,h)/` — Lampiran E, G, H
- `penilai 1-2 (I,J)/` — Lampiran I, J
- `supervision chapter 1-4 (R1)/` — Lampiran R1 *(deferred)*
- `jkpsm submission report/` — Lampiran K (final report submission)
