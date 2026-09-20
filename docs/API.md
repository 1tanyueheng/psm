# PSM Management System — API Reference

Base URL `/api`. All responses use one envelope:

```json
{ "success": true, "message": "OK", "data": { } }
```

Errors use the same shape with `success: false` and an HTTP status that means
something:

| Status | When |
|---|---|
| 401 | Missing or invalid token |
| 403 | Authenticated, but the policy refused |
| 404 | Not found, or not visible to this user |
| 409 | Conflicting state (e.g. milestone cannot accept a submission) |
| 422 | Validation failed — `errors` carries field messages |
| 429 | Rate limited |

Authentication is `Authorization: Bearer <token>` from `/api/auth/login`.

---

## Module 8 — Public (no authentication)

These are registered **before** any auth middleware and rate-limited at
`60/min` per IP. They are the only endpoints an anonymous visitor can reach.

| Method | Path | Purpose |
|---|---|---|
| GET | `/public/leaderboard` | The currently published board |
| GET | `/public/leaderboard/status` | Is a board live? When was it published? |
| GET | `/public/leaderboard/archive` | Previously published boards |
| GET | `/public/leaderboard/{slug}` | A specific board by slug |
| GET | `/public/leaderboard/{slug}/entry/{rank}` | One entry |

Response for `/public/leaderboard`:

```json
{
  "success": true,
  "data": {
    "title": "PSM 2025/2026 — Pixel-It Awards",
    "slug": "psm-2026-pixel-it",
    "subtitle": "Top Final Year Projects",
    "batch": "2026",
    "session": "2025/2026",
    "published_at": "2026-07-15T09:00:00+08:00",
    "show_scores": false,
    "podium": [
      {
        "rank": 1,
        "rank_label": "1st",
        "medal": "gold",
        "title": "AI-Powered Student Performance Prediction System",
        "category": "System Development",
        "students": [{ "name": "Aisyah binti Rahman", "student_id": "S23001" }],
        "supervisors": [{ "name": "Dr. Ahmad Faizal bin Hassan" }],
        "score": null,
        "award": "Pixel-It Gold"
      }
    ],
    "runners_up": []
  }
}
```

`show_scores: false` is why `score` is null and `code` is omitted — the board
is configured to show names and abstracts but not raw marks. Every field here
is explicitly whitelisted by `LeaderboardService::publicEntry()`; no student
contact detail or user id is reachable on this route.

---

## Module 1 — Authentication

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | `{email, password}` → token. Throttled 10/min |
| POST | `/auth/forgot-password` | Throttled 5/min |
| POST | `/auth/reset-password` | Throttled 5/min |
| POST | `/auth/logout` | Revokes the current token |
| POST | `/auth/change-password` | Keeps the current session alive |
| GET | `/me` | Current user, role, and profile |

Login returns the role, which is what the SPA uses for its first redirect:

```json
{
  "data": {
    "token": "1|abc...",
    "user": { "id": 7, "name": "Aisyah binti Rahman", "role": "student" },
    "home_route": "/student/dashboard",
    "must_change_password": false
  }
}
```

---

## Module 6 — Notifications

| Method | Path | Purpose |
|---|---|---|
| GET | `/notifications` | Paginated, newest first |
| GET | `/notifications/summary` | Unread count, grouped |
| POST | `/notifications/read-all` | Mark everything read |
| POST | `/notifications/{id}/read` | Mark one read |
| DELETE | `/notifications/{id}` | Dismiss |
| GET | `/notifications/preferences` | Per-type, per-channel settings |
| PUT | `/notifications/preferences` | Update them |

---

## Module 2 — Profile and assignments

### Own profile (any role)

| Method | Path | Purpose |
|---|---|---|
| GET | `/profile` | Own profile with role-specific detail |
| PUT | `/profile` | Update |
| POST | `/profile/availability` | Supervisor: accepting students, capacity |
| PUT | `/profile/expertise` | Supervisor: expertise tags with proficiency |
| GET | `/profile/workload` | Supervisor: current load vs capacity |

### Administration

| Method | Path | Role |
|---|---|---|
| GET | `/users/options` | admin, coordinator |
| GET | `/users` | admin |
| POST | `/users` | admin |
| GET | `/users/{user}` | policy |
| PATCH | `/users/{user}` | policy |
| POST | `/users/{user}/reset-password` | policy |
| POST | `/users/{user}/deactivate` | admin |
| POST | `/users/{user}/reactivate` | admin |
| POST | `/users/{user}/unlock` | admin |
| DELETE | `/users/{user}` | admin |

### Assignments (admin, coordinator)

| Method | Path | Purpose |
|---|---|---|
| GET | `/assignments/supervisions` | All pairings, filterable |
| POST | `/assignments/supervisions` | Pair a student with a supervisor |
| DELETE | `/assignments/supervisions/{assignment}` | End a pairing |
| POST | `/assignments/supervisors/{supervisor}/capacity` | Override capacity |
| GET | `/assignments/suggest-supervisors/{student}` | Ranked shortlist |
| GET | `/assignments/examiners` | Examiner allocations |
| POST | `/assignments/examiners` | Allocate an examiner |
| DELETE | `/assignments/examiners/{assignment}` | Remove |
| GET | `/assignments/students/unassigned` | The coordinator's queue |

Pairing request:

```json
POST /api/assignments/supervisions
{
  "student_profile_id": 12,
  "supervisor_profile_id": 3,
  "psm_part": "BOTH",
  "role": "primary",
  "responsibility_percent": 100,
  "assignment_note": "Topic overlap in machine learning"
}
```

A `422` comes back if the supervisor is at capacity, already supervises this
student for this part, or is removing themselves from availability. All three
checks live in `AssignmentService`, not in the controller.

---

## Module 3 — Projects and milestones

| Method | Path | Role |
|---|---|---|
| GET | `/projects` | Scoped by `Project::visibleTo()` |
| GET | `/projects/options` | Dropdown data |
| GET | `/projects/summary` | Counts by status |
| POST | `/projects` | student |
| GET | `/projects/{project}` | policy |
| PATCH | `/projects/{project}` | policy |
| POST | `/projects/{project}/submit` | student |
| POST | `/projects/{project}/approve` | admin, coordinator |
| POST | `/projects/{project}/reject` | admin, coordinator |
| POST | `/projects/{project}/archive` | admin, coordinator |
| POST | `/projects/{project}/leaderboard-consent` | student |
| GET | `/projects/{project}/milestones` | policy |

### Milestones

| Method | Path | Role |
|---|---|---|
| GET | `/milestones/{milestone}` | policy |
| POST | `/milestones/{milestone}/submit` | student (multipart) |
| POST | `/milestones/{milestone}/comment` | policy |
| POST | `/milestones/{milestone}/approve` | admin, coordinator, supervisor |
| POST | `/milestones/{milestone}/request-revision` | admin, coordinator, supervisor |
| POST | `/milestones/{milestone}/deadline` | admin, coordinator |

### Submission files

| Method | Path | Purpose |
|---|---|---|
| GET | `/submissions/{file}/download` | policy-checked download |
| DELETE | `/submissions/{file}` | Remove a current, unreviewed file |

Submitting a milestone:

```
POST /api/milestones/42/submit
Content-Type: multipart/form-data

files[]=<binary>
files[]=<binary>
comment=Final report with appendices.
```

A `409` is returned when the milestone does not accept submissions — wrong
status, or past the deadline with no late window. That decision belongs to
`Milestone::acceptsSubmission()`; the client should render it, not second-guess
it.

Requesting a revision:

```json
POST /api/milestones/42/request-revision
{
  "comment": "The sampling strategy is not justified for the population size.",
  "allow_resubmission": true
}
```

The comment is required. This action notifies the student and increments
`revision_count`.

Changing a deadline:

```json
POST /api/milestones/42/deadline
{
  "due_at": "2026-05-20",
  "reason": "Faculty approved a two-week extension due to the lab closure."
}
```

Both fields are required. The reason is mandatory because this is the most
contested administrative action in the system, and it is written to the audit
log along with the old and new dates.

---

## Module 4 — Evaluations and grades

| Method | Path | Role |
|---|---|---|
| GET | `/evaluations` | Scoped to the assessor |
| POST | `/evaluations` | admin, coordinator — allocate a form |
| GET | `/evaluations/{evaluation}` | policy |
| PUT | `/evaluations/{evaluation}/marks` | policy |
| POST | `/evaluations/{evaluation}/submit` | policy |
| POST | `/evaluations/{evaluation}/moderate` | admin, coordinator |
| POST | `/evaluations/{evaluation}/declare-conflict` | policy |
| GET | `/rubrics` | Any assessor |

### Grades

| Method | Path | Role |
|---|---|---|
| GET | `/grades` | admin, coordinator |
| POST | `/grades/{grade}/recompute` | admin, coordinator |
| POST | `/grades/{grade}/release` | admin, coordinator |
| GET | `/projects/{project}/grades` | policy |
| POST | `/projects/{project}/grades/release-all` | admin, coordinator |
| PUT | `/projects/{project}/grade-scheme` | admin, coordinator |

Saving marks:

```json
PUT /api/evaluations/9/marks
{
  "marks": [
    { "criterion_code": "planning", "marks": 8.5, "comment": null },
    { "criterion_code": "meetings", "marks": 7.5, "comment": "Missed two scheduled meetings." }
  ]
}
```

Each mark is validated against that criterion's `max_marks` from the frozen
rubric snapshot. A mark at or below 40% of the maximum is flagged
automatically — the signal shown to the assessor that an explanation is
expected.

Submitting returns the recalculation consequence, so the assessor sees what
their marks produced before they leave the page:

```json
{
  "data": {
    "score_percent": 78.5,
    "raw_score": 78.5,
    "status": "submitted",
    "project_impact": { "students_affected": 1, "computed": true }
  }
}
```

Moderating:

```json
POST /api/evaluations/9/moderate
{
  "new_percent": 74.0,
  "reason": "Examiner's report mark was inconsistent with the panel's consensus on the defence."
}
```

The original assessor's `raw_score` survives, and `moderation_delta` records
the movement.

---

## Module 5 — Reporting (admin, coordinator)

| Method | Path | Purpose |
|---|---|---|
| GET | `/reports/dashboard` | Headline counts and trends |
| GET | `/reports/cohort-progress` | Milestone completion across a cohort |
| GET | `/reports/at-risk` | Students with overdue or rejected milestones |
| GET | `/reports/supervisor-workload` | Load vs capacity per supervisor |
| GET | `/reports/examiner-workload` | Allocations per examiner |
| GET | `/reports/grade-distribution` | Bands, with median and std deviation |
| GET | `/reports/milestone-breakdown` | Status counts per milestone |
| GET | `/reports/export/grades.csv` | CSV export |
| GET | `/reports/export/projects.csv` | CSV export |

Common filters: `?batch=2026&psm_part=PSM2&program=CS230&session=2025/2026`.

---

## Module 7 — Archive and audit

| Method | Path | Role |
|---|---|---|
| GET | `/archive` | policy |
| GET | `/archive/filters` | Available sessions, batches, categories |
| GET | `/archive/export.csv` | policy |
| GET | `/archive/{archived}` | policy |
| POST | `/archive/{archived}/restore` | admin |
| GET | `/audit-logs/me` | Any — own history |
| GET | `/audit-logs` | admin, coordinator |
| GET | `/audit-logs/filters` | admin, coordinator |
| GET | `/audit-logs/for/{type}/{id}` | admin, coordinator |

Search:

```
GET /api/archive?term=machine+learning&session=2024/2025&category=research
```

Search matches against the denormalised `keywords` and `supervisor_names`
columns, which exist precisely so the archive stays searchable after the live
users and rubrics are gone.

---

## Module 8 — Leaderboard management (admin, coordinator)

| Method | Path | Role |
|---|---|---|
| GET | `/leaderboards` | admin, coordinator |
| GET | `/leaderboards/eligible` | Who would be ranked, and who is excluded |
| GET | `/leaderboards/settings` | Module settings |
| PUT | `/leaderboards/settings` | admin |
| GET | `/leaderboards/preview/{slug}` | Preview a draft as the public would see it |
| POST | `/leaderboards` | Create |
| GET | `/leaderboards/{leaderboard}` | Detail with entries |
| PATCH | `/leaderboards/{leaderboard}` | Update |
| DELETE | `/leaderboards/{leaderboard}` | Delete |
| POST | `/leaderboards/{leaderboard}/build` | Rank into a draft snapshot |
| POST | `/leaderboards/{leaderboard}/publish` | Flip it live |
| POST | `/leaderboards/{leaderboard}/unpublish` | Take it down |
| PATCH | `/leaderboards/{leaderboard}/entries/{entry}` | Hide an entry, set an award |

Creating a board:

```json
POST /api/leaderboards
{
  "title": "PSM 2025/2026 — Pixel-It Awards",
  "subtitle": "Top Final Year Projects",
  "psm_part": "PSM2",
  "batch": "2026",
  "academic_session": "2025/2026",
  "top_n": 3,
  "min_assessors": 2,
  "ranking_basis": "final_mark",
  "tie_breaker": "assessor_count",
  "show_abstract": true,
  "show_scores": false,
  "show_student_names": true,
  "show_program": true,
  "theme": "academic"
}
```

`show_scores: false` is the recommended default for a public page: it lets the
faculty celebrate the winners without publishing a raw mark that a student
might have to explain to a future employer.

Building and publishing:

```
POST /api/leaderboards/1/build      → ranks eligible grades into a draft
POST /api/leaderboards/1/publish    → makes it live
```

`build` returns `409` if the board is already published. This is deliberate:
a live public page must never change underneath a visitor, so you unpublish,
rebuild, and republish.

`/leaderboards/eligible` is the endpoint to reach for when a board is
unexpectedly empty. It reports each candidate together with **every** reason it
was excluded, so a single project can appear with more than one problem:

```json
{
  "success": true,
  "data": {
    "eligible_count": 6,
    "eligible": [
      {
        "grade_id": 14,
        "project_code": "PSM2-2026-CS-014",
        "title": "AI-Powered Student Performance Prediction System",
        "student": "Aisyah binti Rahman",
        "final_mark": 86.4,
        "assessor_count": 2,
        "reasons": []
      }
    ],
    "excluded_count": 3,
    "excluded": [
      {
        "grade_id": 31,
        "project_code": "PSM2-2026-CS-022",
        "final_mark": 79.1,
        "assessor_count": 2,
        "reasons": ["Student opted out"]
      },
      {
        "grade_id": 44,
        "project_code": "PSM2-2026-CS-031",
        "final_mark": 74.5,
        "assessor_count": 1,
        "reasons": [
          "Grade is not marked publishable",
          "Only 1 assessor(s), 2 required"
        ]
      }
    ]
  }
}
```

`reasons` is empty for eligible rows and populated for excluded ones, so the
same shape serves both lists. Accepts `?min_assessors=` to rehearse a stricter
threshold before changing the board's settings.

---

## Rate limits

| Scope | Limit |
|---|---|
| Public leaderboard | 60/min per IP |
| `POST /auth/login` | 10/min |
| `POST /auth/forgot-password` | 5/min |
| `POST /auth/reset-password` | 5/min |
| Everything else | Authenticated default |

Exceeding a limit returns `429` with a `Retry-After` header.

---

## Pagination

Index endpoints accept `?page=` and `?per_page=` (default 20, max 100). The
paginated envelope carries `meta` at the top level rather than `links`:

```json
{
  "success": true,
  "data": [ ],
  "meta": {
    "current_page": 1,
    "last_page": 7,
    "per_page": 20,
    "total": 137,
    "from": 1,
    "to": 20
  }
}
```

Non-paginated endpoints return `{success, message?, data}` only. Endpoints
returning a JSON Resource add `success` to the resource's own payload instead
of wrapping it, so `data` is always present but `meta` only appears on
paginated lists.
