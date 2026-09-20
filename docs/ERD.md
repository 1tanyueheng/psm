# PSM Management System — Entity Relationship Model

37 tables. Grouped below by the module that owns them. Column lists are the
real schema; `→` marks a foreign key, `uq` a unique constraint, `idx` an index
worth knowing about.

---

## 1. Identity and access — Module 1

### users
| Column | Notes |
|---|---|
| `id` | PK |
| `name`, `email` | `email` is `uq` |
| `password` | hashed, hidden from serialisation |
| `role` | enum: student / supervisor / coordinator / examiner / admin |
| `status` | enum: active / inactive / suspended / pending |
| `staff_id`, `department`, `phone` | staff and contact fields |
| `must_change_password` | drives the `first.login` middleware |
| `failed_login_attempts`, `locked_until`, `last_login_at` | brute-force defence |
| `notification_preferences` | JSON, per-channel opt-outs |
| `deleted_at` | soft delete |

`password_reset_tokens`, `sessions`, and `personal_access_tokens` (Sanctum) are
framework tables carried alongside.

**Cardinality.** One user has at most one `student_profile` **or** one
`supervisor_profile` (examiners are supervisors with `can_examine = true` and
`max_supervisees = 0`, because they need a `staff_no` and expertise tags).
A coordinator may hold several `coordinator_scopes` — one per batch or program
they are responsible for.

---

## 2. Profiles and pairing — Module 2

```
users ──1:1──► student_profiles
users ──1:1──► supervisor_profiles
supervisor_profiles ──M:N──► expertise_areas        (pivot: supervisor_expertise)
supervisor_profiles ──1:N──► supervision_assignments ◄──1:N── student_profiles
users ──1:N──► coordinator_scopes
```

### student_profiles
`user_id → users` (uq), `student_id` (uq), `program`, `program_code`, `batch`,
`faculty`, `current_semester`, `phone_emergency`, `thesis_title`,
`thesis_abstract`, `max_supervisors` (default 2), `is_active_cohort`, soft delete.

### supervisor_profiles
`user_id → users` (uq), `staff_no` (uq), `academic_title`, `max_supervisees`,
`is_accepting_students`, `workload_release_percent`, `can_examine`,
`office_location`, `bio`, soft delete.

`currentLoad()` counts active supervisions; `hasCapacity()` is the single guard
every assignment path consults before pairing.

### supervision_assignments
`student_profile_id →`, `supervisor_profile_id →`, `psm_part` (PSM1/PSM2/BOTH),
`role` (primary/co/advisor), `responsibility_percent`, `is_active` (idx),
`assigned_by → users`, `assignment_note`, `effective_from`, `effective_until`,
`ended_at`, `end_reason`.

Composite index `(student_profile_id, supervisor_profile_id, psm_part, is_active)`
— this is the hot lookup for "who supervises this student".

Pairings are **ended, never deleted**: `end()` sets `is_active = false` and
stamps `ended_at`, so the coordinator can always answer "who supervised whom,
and when".

### examiner_assignments
`project_id →`, `examiner_id → users`, `psm_part`, `panel_role`
(chair/member/reserve), `is_active`, `assigned_by → users`, `notified_at`.
Unique `(project_id, examiner_id, psm_part)`.

---

## 3. Projects and delivery — Module 3

```
                          ┌── milestone_templates ──1:N──► milestone_template_items
                          │                                      │
projects ──1:N──► milestones ◄──────────────────────────────────────┘
   │                 │
   │                 ├──1:N──► submission_files
   │                 └──1:N──► submission_events
   └──1:N──► project_members ◄──M:N── student_profiles
```

### projects
`code` (uq, e.g. `PSM2-2026-CS-014`), `title`, `abstract`, `objectives`,
`scope`, `category` (system/research, idx), `psm_part` (idx),
`academic_session`, `batch` (idx), `program`, `status` (draft → submitted →
approved → in_progress → completed → archived), `created_by → users`,
`approved_by → users`, `submitted_at`, `approved_at`, `rejection_reason`,
`archived_at`, `leaderboard_opt_out`, `metadata` (JSON), soft delete.

Indexes on `(batch, status)` and `(psm_part, category)` — the two cohort
filters Module 5 uses constantly.

### project_members
`project_id →`, `student_profile_id →`, `is_leader`, `contribution_percent`.
Unique `(project_id, student_profile_id)`. Lets a group project exist without
changing the shape of the table.

### milestone_templates
`name`, `category`, `psm_part`, `version`, `is_active` (idx),
`default_duration_days` (140), `created_by → users`, `notes`.
Unique `(category, psm_part, version)`.

**Versioned, not mutated.** Next semester's wording change creates version 2
and leaves version 1 intact, so an in-flight cohort keeps the deadlines and
weights it was told about at registration.

### milestone_template_items
`milestone_template_id →`, `code`, `title`, `description`,
`deliverable_expectation`, `sequence`, `offset_days`, `duration_days`
(default 14), `weight_percent`, `allowed_file_types` (JSON),
`requires_supervisor_approval`, `max_files`.
Unique `(template_id, code)`.

### milestones
`project_id →`, `milestone_template_item_id →` (nullable),
`code`, `title`, `description`, `sequence`, `weight_percent`,
`status` (pending/open/submitted/reviewed/approved/rejected/overdue),
`opens_at`, `due_at` (dates, not datetimes), `allow_late_submission`,
`late_window_days` (7), `extended_until`, `reviewed_by → users`,
`submitted_at`, `reviewed_at`, `approved_at`, `review_comment`,
`revision_count`, `deadline_overridden_by → users`,
`deadline_override_reason`, `allowed_file_types` (JSON), `max_files`,
`requires_supervisor_approval`, soft delete. Unique `(project_id, code)`.

Deadlines are **dates** because an academic deadline is "end of Friday"; the
cut-off hour is policy applied by `effectiveDueAt()`, not a per-row value.

### submission_files
`milestone_id →`, `uploaded_by → users`, `disk`, `path`, `original_name`,
`mime_type`, `size_bytes`, `checksum_sha256`, `revision_no`, `is_current`,
`superseded_at`, `superseded_by`.

Files are **never hard-deleted**: a new revision sets `is_current = false` on
the previous one, so Module 7 can prove what was submitted and when.

### submission_events
`milestone_id →`, `actor_id → users`, `event`, `from_status`, `to_status`,
`comment`, `payload` (JSON). Append-only.

---

## 4. Rubrics and assessment — Module 4

```
rubric_templates ──1:N──► rubric_components ──1:N──► rubric_criteria
       │                                                    │
       │                                                    │
       └──1:N──► evaluations ──1:N──► evaluation_scores ◄───┘
                      │
projects ──1:1──► grade_schemes
projects ──1:N──► final_grades ◄──1:N── student_profiles
```

### rubric_templates
`name`, `category`, `psm_part` (PSM1/PSM2/BOTH), `assessor_type` (idx),
`version`, `is_active` (idx), `is_published`, `total_marks` (100),
`pass_mark` (50), `description`, `grading_guide`, `created_by → users`.
Unique `(category, psm_part, assessor_type, version)`.

`resolveFor($category, $psmPart, $assessorType)` prefers a part-specific
published rubric and falls back to a BOTH-part one.

### rubric_components
`rubric_template_id →`, `code`, `title`, `description`, `weight_percent`,
`sequence`, `requires_comment_below`, `comment_threshold_percent` (50).
Unique `(template_id, code)`. **Weights must total 100** — enforced before a
rubric may be used to mark.

### rubric_criteria
`rubric_component_id →`, `code`, `title`, `description`, `guidance`,
`weight_percent`, `max_marks`, `sequence`, `is_required`.
Unique `(component_id, code)`. **Weights within a component total 100.**

### grade_schemes
`project_id →` (uq), `weights` (JSON, e.g. `{supervisor:60, examiner:40}`),
`aggregation` (mean/weighted_mean/max/min), `trim_extremes`, `pass_mark`,
`is_locked`. One per project, so a faculty default change cannot retroactively
invalidate a released grade.

### evaluations
`project_id →`, `assessor_id → users`, `rubric_template_id →` (restrict on
delete), `rubric_snapshot` (JSON), `assessor_type`, `psm_part`, `status`
(draft/submitted/moderated/released/recused), `raw_score`, `max_score`,
`score_percent`, `final_score`, `moderation_delta`, `comment`, `strengths`,
`improvements`, `is_late_assessment`, `submitted_at`, `released_at`,
`moderated_by → users`, `moderation_reason`, `moderated_at`, `coi_declaration`.
Unique `(project_id, assessor_id, rubric_template_id)`.

`rubric_snapshot` is the integrity mechanism: renaming or reweighting a
criterion later cannot change what a historical mark means.

### evaluation_scores
`evaluation_id →`, `rubric_component_id →` (nullable),
`rubric_criterion_id →` (nullable), `component_code`, `criterion_code`,
`criterion_title`, `max_marks`, `marks_awarded`, `weighted_contribution`
(decimal 8,4), `comment`, `is_flagged`.

Codes are denormalised alongside the FKs so a submitted form stays readable
even if the rubric is later retired. `is_flagged` marks a criterion scored at
or below 40% — the signal for "an explanatory comment is required".

### final_grades
`project_id →`, `student_profile_id →`, `psm_part`,
`supervisor_score`, `examiner_score`, `coordinator_score`,
`aggregate_percent`, `milestone_score`, `final_mark`, `grade_letter`,
`grade_point`, `is_pass`, `assessor_count`, `computation_breakdown` (JSON),
`status` (provisional/moderated/released/withheld), `computed_at`,
`released_at`, `released_by → users`, `is_publishable`.
Unique `(project_id, student_profile_id, psm_part)`.

**The frozen artefact.** Module 8 ranks on this value, never on a live
recomputation, so a published ranking cannot drift.
`computation_breakdown` stores the full per-assessor-type arithmetic, so any
result can be explained to an examiner or appealed by a student.

---

## 5. Notification — Module 6

### notifications
Standard Laravel `DatabaseNotification` shape (`id` uuid, `type`,
`notifiable_type`/`notifiable_id` morph, `data` JSON, `read_at`).

### reminder_dispatches
`milestone_id →`, `user_id →`, `days_before` (7, 3, 1), `notification_type`,
`channel` (default `mail`), `sent_at`. Unique
`(milestone_id, user_id, days_before, notification_type)`.

That unique constraint is what makes the reminder scan **idempotent**: the
hourly job can run as often as the scheduler likes, and a retry after a partial
failure is safe.

---

## 6. Oversight — Module 7

### audit_logs
`user_id → users` (nullable), `actor_name`, `actor_role` (snapshots — survive
user deletion), `action` (idx), `category` (idx), `severity` (info/warning/
critical, idx), `auditable_type`/`auditable_id` (morph),
`description`, `before` (JSON), `after` (JSON), `changes` (JSON, field-level
diff), `ip_address`, `user_agent`, `request_method`, `request_url`,
`session_id`, `is_suspicious` (idx), `created_at` (idx). **No `updated_at`.**

Append-only by construction: nothing may update or delete a row; the only
permitted removal is the retention prune by `audit:prune`.

### archived_projects
`project_id → users` (nullable), `code` (idx), `title`, `abstract`, `category`,
`psm_part`, `academic_session` (idx), `batch` (idx), `program`,
`students` (JSON), `supervisors` (JSON), `examiners` (JSON), `final_mark`,
`grade_letter`, `grade_point`, `milestone_summary` (JSON),
`grade_breakdown` (JSON), `documents` (JSON), `keywords`,
`supervisor_names` (flattened for LIKE search), `is_public` (idx),
`archived_at` (idx), `archived_by → users`, `archive_note`.

Fully **denormalised on purpose**: this row must stay readable for years even
after users are deleted, rubrics retired, and grades recomputed. Nothing here
may reference a live row as its only source.

---

## 7. Recognition — Module 8

### leaderboards
`title`, `slug` (uq, 96), `subtitle`, `description`, `psm_part`, `batch`,
`academic_session`, `top_n` (3), `min_assessors` (2),
`ranking_basis` (final_mark/aggregate_percent/milestone_score),
`tie_breaker` (assessor_count/supervisor_score/submission_time),
`status` (draft/published/unpublished), `published_at`, `unpublished_at`,
`auto_publish_at`, `created_by → users`, `published_by → users`,
`show_abstract`, `show_scores`, `show_student_names`, `show_program`, `theme`.

### leaderboard_entries
`leaderboard_id →`, `project_id →` (nullable), `final_grade_id →` (nullable),
`rank`, `is_winner`, `is_top_n`, `project_title`, `project_abstract`,
`project_code`, `category`, `program`, `students` (JSON), `supervisors` (JSON),
`display_score`, `score_label`, `assessor_count`, `award_title`, `citation`,
`poster_path`, `is_hidden`. Unique `(leaderboard_id, rank)`.

`students` / `supervisors` are **frozen rosters** holding only
`{name, student_id}` and `{name}`. The public page reads these columns and
nothing else — no email, phone, or user id reaches it.

### leaderboard_settings
Singleton: `default_top_n` (3), `default_min_assessors` (2),
`default_ranking_basis`, `module_enabled`, `require_approval`,
`honour_opt_out`, `updated_by → users`. Read through
`LeaderboardSetting::current()`, which creates the row on first use.

---

## 8. Framework support

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.
`personal_access_tokens`, `password_reset_tokens`, `sessions`.

---

## 9. Invariants worth preserving

1. **`rubric_components` weights total 100 per template.**
   **`rubric_criteria` weights total 100 per component.**
   Violate either and every mark computed against the rubric is quietly wrong.
   Verified before a rubric may be published; also checkable by
   `tools/audit_rubric_weights.php`.
2. **`milestone_template_items` weights total 100 per template.** Otherwise
   milestone progress percentages do not mean anything.
3. **A milestone's status changes only through
   `MilestoneService::transitionTo()`** — validated against
   `MilestoneStatus::allowedTransitions()`, recorded in both `submission_events`
   and `audit_logs`.
4. **`evaluations.rubric_snapshot` is written once, at form creation**, and
   never updated.
5. **A `final_grade` is released once.** `releaseGrade()` decides
   `is_publishable` at that moment rather than at render time.
6. **A `leaderboard` is rebuilt only while unpublished.** A live public page
   never changes underneath a visitor.
7. **`audit_logs` and `submission_events` are append-only.**
8. **Supervision capacity is checked through
   `SupervisorProfile::hasCapacity()`** — one guard, one place.
