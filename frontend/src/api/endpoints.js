import api, { unwrap, unwrapPaged, saveDownload } from './client'

/**
 * The API surface, grouped to mirror the backend's module layout.
 *
 * Method names follow the backend routes (`routes/api.php`) so that a route
 * and its client are easy to match up. A few aliases exist where a screen has
 * a natural name for an operation that differs from the controller — for
 * example `projectApi.registerMeta` for `/projects/registration-meta`. Every
 * alias is annotated so the real route stays discoverable.
 *
 * Components never touch Axios directly, so a change to the transport stays in
 * this file plus `client.js`.
 */

// =====================================================================
// Module 1 — authentication
// =====================================================================
// Lives in `./auth` because the login/logout flow also drives AuthContext.

// =====================================================================
// Module 3 — academic semesters
// =====================================================================
/**
 * The term is the unit that owns both PSM batches, so this sits above every
 * project/mark/report call rather than inside one of them.
 *
 * Note the shape of the two gate routes. Registration and mark release are
 * POST actions rather than PATCH fields on purpose — on the server they carry
 * side effects (stamping `registration_opened_at` / `marks_released_at`, and
 * bulk-releasing the term's `final_grades`). Keeping them off the generic
 * update means a stray `{is_marks_released: true}` cannot publish a cohort.
 */
export const semesterApi = {
  /** Every term, oldest first. Pass `active: true` to narrow to live terms. */
  list: (params) => api.get('/semesters', { params }).then(unwrap),
  /**
   * The term the caller is working in — their own enrolment where they have
   * one, otherwise the faculty's active term. This is what every filter bar
   * should default to.
   */
  current: () => api.get('/semesters/current').then(unwrap),
  /** One term plus its per-batch cohort stats. */
  show: (id) => api.get(`/semesters/${id}`).then(unwrap),
  /** Create a term. Starts inactive; nothing is open until you open it. */
  create: (payload) => api.post('/semesters', payload).then(unwrap),
  /** Dates and metadata only — see the note above on why flags are excluded. */
  update: (id, payload) => api.patch(`/semesters/${id}`, payload).then(unwrap),

  /** Close the term: registration shuts and the term stops being active. */
  close: (id) => api.post(`/semesters/${id}/close`).then(unwrap),
  /**
   * Reopen a closed term — the counterpart to `close`.
   *
   * Restores the term as live and clears `closed_at`. **Registration stays
   * closed**: opening it is a separate decision, and the response says so, so
   * the screen can prompt rather than leave the coordinator guessing.
   */
  reopen: (id) => api.post(`/semesters/${id}/reopen`).then(unwrap),
  /**
   * `{ is_registration_open: boolean }` — gates Lampiran A for this term.
   *
   * The key deliberately matches the column rather than being abbreviated: the
   * controller validates this exact name and a mismatch is a 422, not a
   * silently ignored field.
   */
  setRegistration: (id, isOpen) =>
    api.post(`/semesters/${id}/registration`, { is_registration_open: Boolean(isOpen) }).then(unwrap),
  /**
   * Who the intake actually enrolled in this term.
   *
   * The admin-add-student form sets `academic_semester_id`, but nothing listed
   * the result back — a student created with the wrong term was invisible until
   * they could not register. Staff only: it returns student records.
   */
  students: (id) => api.get(`/semesters/${id}/students`).then(unwrap),
  // There is no `setMarkRelease`. A mark publishes itself the moment its
  // supervisor's form arrives, so a term has no release to set. How far a
  // term's marking has got comes back on `stats.marks` from `show`/`list`.
}

// =====================================================================
// Module 2 — users, profiles, assignments
// =====================================================================
export const userApi = {
  list: (params) => api.get('/users', { params }).then(unwrapPaged),
  show: (id) => api.get(`/users/${id}`).then(unwrap),
  create: (payload) => api.post('/users', payload).then(unwrap),
  update: (id, payload) => api.patch(`/users/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/users/${id}`).then(unwrap),

  // Available to admin + coordinator — supplies supervisor/examiner pickers.
  options: (params) => api.get('/users/options', { params }).then(unwrap),

  deactivate: (id) => api.post(`/users/${id}/deactivate`).then(unwrap),
  reactivate: (id) => api.post(`/users/${id}/reactivate`).then(unwrap),
  unlock: (id) => api.post(`/users/${id}/unlock`).then(unwrap),
  sendPasswordReset: (id) => api.post(`/users/${id}/reset-password`).then(unwrap),
}

export const profileApi = {
  show: () => api.get('/profile').then(unwrap),
  update: (payload) => api.put('/profile', payload).then(unwrap),
  setAvailability: (payload) => api.post('/profile/availability', payload).then(unwrap),
  updateExpertise: (payload) => api.put('/profile/expertise', payload).then(unwrap),
  workload: (params) => api.get('/profile/workload', { params }).then(unwrap),

  // Alias — the current user's profile is the natural "me".
  me: () => api.get('/profile').then(unwrap),
}

export const assignmentApi = {
  supervisions: (params) => api.get('/assignments/supervisions', { params }).then(unwrapPaged),
  assignSupervisor: (payload) => api.post('/assignments/supervisions', payload).then(unwrap),
  endSupervision: (id) => api.delete(`/assignments/supervisions/${id}`).then(unwrap),

  setCapacity: (supervisorId, payload) =>
    api.post(`/assignments/supervisors/${supervisorId}/capacity`, payload).then(unwrap),
  suggestSupervisors: (studentId) =>
    api.get(`/assignments/suggest-supervisors/${studentId}`).then(unwrap),

  examiners: (params) => api.get('/assignments/examiners', { params }).then(unwrapPaged),
  assignExaminer: (payload) => api.post('/assignments/examiners', payload).then(unwrap),
  endExaminer: (id) => api.delete(`/assignments/examiners/${id}`).then(unwrap),

  /** Fixed examiner pairs — examiners grouped into standing panels. */
  examinerPairs: (params) => api.get('/assignments/examiner-pairs', { params }).then(unwrap),
  /**
   * Batch-assign students to examiner pairs, two examiners each. Give it an
   * explicit student list, the students awaiting a panel verdict, an explicit
   * project list, or nothing for the whole batch of the term.
   */
  autoAssignExaminerPairs: (payload) =>
    api.post('/assignments/examiner-pairs/auto-assign', payload).then(unwrap),

  unassignedStudents: (params) =>
    api.get('/assignments/students/unassigned', { params }).then(unwrap),
  expertiseAreas: () => api.get('/assignments/expertise-areas').then(unwrap),

  // --- Aliases used by the assignment screen -----------------------------
  /** List supervision pairs. */
  list: (params) => api.get('/assignments/supervisions', { params }).then(unwrapPaged),
  /** Unassigned students — paged, because the screen counts them. */
  unassigned: (params) =>
    api.get('/assignments/students/unassigned', { params }).then(unwrapPaged),

  /**
   * One student's panel: who is seated, and who may be.
   *
   * The candidate list already has the student's own supervisor removed — the
   * conflict-of-interest rule — and `supervisors` names them, so the screen can
   * say *why* a name is missing rather than leaving the coordinator to wonder.
   */
  panel: (studentId, params) =>
    api.get(`/assignments/students/${studentId}/panel`, { params }).then(unwrap),

  /**
   * Seat a pair on a student's panel.
   *
   * Exactly two ids, and the **first is the chair**. Re-pairing replaces the
   * previous panel rather than being refused.
   */
  assignPanel: (studentId, examinerIds, psmPart) =>
    api
      .post(`/assignments/students/${studentId}/panel`, {
        examiner_ids: examinerIds,
        psm_part: psmPart || undefined,
      })
      .then(unwrap),

  /**
   * The cohort view: every student beside every examiner who could examine them.
   *
   * Each row carries the people who **cannot** examine that student (their own
   * supervisors) as well as the people who can, so the screen can show the
   * exclusion rather than leaving a gap.
   */
  panelMatching: (params) => api.get('/assignments/panel-matching', { params }).then(unwrap),
  /**
   * The supervisor pool, in `supervisor_profiles` terms.
   *
   * Not `/users/options`: that is the generic picker vocabulary keyed on *user*
   * ids, and the two id spaces are disjoint — user 3 is supervisor_profiles 1,
   * while supervisor_profiles 3 is a different person. `assign` validates
   * `supervisor_profile_id` against `supervisor_profiles`, so this returns the
   * id the write actually needs.
   */
  supervisors: (params) =>
    api.get('/assignments/supervisors', { params }).then(unwrapPaged),
  /** Create a supervision pair. */
  assign: (payload) => api.post('/assignments/supervisions', payload).then(unwrap),
  /** End a supervision pair. */
  remove: (id) => api.delete(`/assignments/supervisions/${id}`).then(unwrap),
}

// =====================================================================
// Module 2/3 — registration flow (Lampiran A & B)
// =====================================================================
// Lampiran A drives the supervisor<->student pairing and fixes the agreed
// title; Lampiran B registers that title as a project. There is no approval
// step between them — the title is judged afterwards, by the panel, at the
// project's proposal milestone (see `milestoneApi` below).
export const registrationApi = {
  /** Agreements visible to the signed-in user (scoped by role server-side). */
  agreements: (params) => api.get('/registrations/agreements', { params }).then(unwrap),
  agreement: (id) => api.get(`/registrations/agreements/${id}`).then(unwrap),

  /** Student submits Lampiran A (supervisor agreement). */
  submitAgreement: (payload) => api.post('/registrations/agreements', payload).then(unwrap),

  /** Supervisor acknowledges Part C and picks the agreed title. */
  acknowledge: (id, agreedTitle) =>
    api
      .post(`/registrations/agreements/${id}/acknowledge`, { agreed_title: agreedTitle })
      .then(unwrap),

  /** Student submits Lampiran B against an acknowledged agreement. */
  submitTitleProposal: (id, payload) =>
    api.post(`/registrations/agreements/${id}/title-proposal`, payload).then(unwrap),
}

// =====================================================================
// Module 3 — projects, milestones, submissions
// =====================================================================
export const projectApi = {
  list: (params) => api.get('/projects', { params }).then(unwrapPaged),
  show: (id) => api.get(`/projects/${id}`).then(unwrap),
  create: (payload) => api.post('/projects', payload).then(unwrap),
  update: (id, payload) => api.patch(`/projects/${id}`, payload).then(unwrap),

  options: () => api.get('/projects/options').then(unwrap),
  summary: () => api.get('/projects/summary').then(unwrap),

  submit: (id) => api.post(`/projects/${id}/submit`).then(unwrap),
  approve: (id) => api.post(`/projects/${id}/approve`).then(unwrap),
  reject: (id, reason) => api.post(`/projects/${id}/reject`, { reason }).then(unwrap),
  archive: (id, note) => api.post(`/projects/${id}/archive`, { note }).then(unwrap),

  /**
   * PSM 1 → PSM 2. PSM 1 and PSM 2 are one project across two continuous terms
   * on one title, so PSM 2 is reached by progressing the student rather than by
   * filing a second Lampiran A. Creates the PSM 2 project from this title and
   * archives the PSM 1 one. Refused until the PSM 1 marks are released.
   */
  progressToPsm2: (id) => api.post(`/projects/${id}/progress-to-psm2`).then(unwrap),

  milestones: (id) => api.get(`/projects/${id}/milestones`).then(unwrap),
  grades: (id) => api.get(`/projects/${id}/grades`).then(unwrap),

  /**
   * One student's mark broken down by form and component.
   *
   * Returns the Lampiran's own marks and the part's total (`out_of`), which is
   * what the student is shown — never the rescaled 0-100 aggregate. Withheld by
   * the server until the mark is readable, so calling it speculatively is safe:
   * an unpublished mark comes back as `{ released: false }`.
   */
  markBreakdown: (projectId, studentId) =>
    api.get(`/projects/${projectId}/students/${studentId}/mark-breakdown`).then(unwrap),
  updateGradeScheme: (id, payload) =>
    api.put(`/projects/${id}/grade-scheme`, payload).then(unwrap),

  /**
   * The batch PSM 1 → PSM 2 rollover.
   *
   * `rolloverCandidates` is the tick-list: every live PSM 1 project in a term,
   * each with the title that will carry over and whether the term is ready at
   * all. It deliberately includes students who cannot move, so a coordinator
   * looking for one sees them present and blocked rather than absent.
   *
   * `rollover` moves the ticked ones. Partial success is the expected outcome —
   * the response carries who moved and why each of the rest did not.
   */
  rolloverCandidates: (semesterId) =>
    api.get('/projects/rollover', { params: { semester_id: semesterId } }).then(unwrap),
  rollover: (projectIds) =>
    api.post('/projects/rollover', { project_ids: projectIds }).then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /**
   * Registration screen metadata — supervisors with room, PSM parts, and the
   * term registration is open for.
   *
   * Points at `/projects/registration-meta`, **not** `/projects/options`. The
   * two are unrelated: `options` returns a value/label list of *projects* for a
   * `<Select>`, while `registration-meta` is the payload the Lampiran A and
   * register-a-project forms read `supervisors` from. This alias used to call
   * `options`, so `data.supervisors` was always undefined and both forms
   * silently rendered an empty supervisor dropdown — with no error, because a
   * missing key reads as an empty list.
   */
  registerMeta: () => api.get('/projects/registration-meta').then(unwrap),
}

export const milestoneApi = {
  list: (params) => api.get('/milestones', { params }).then(unwrapPaged),
  show: (id) => api.get(`/milestones/${id}`).then(unwrap),

  /**
   * Submit files for a milestone.
   *
   * Accepts either a pre-built FormData (the form builds one so it can append
   * several files and a note) or `{ files, note }`.
   */
  submit: (id, payload) => {
    const form = payload instanceof FormData ? payload : new FormData()

    if (!(payload instanceof FormData)) {
      // Laravel expects files[] for an array field.
      Array.from(payload?.files ?? []).forEach((file) => form.append('files[]', file))
      // The controller validates `note`; `comment` is accepted here only
      // because older callers used that name, and it used to be sent verbatim
      // and silently ignored by the validator.
      const note = payload?.note ?? payload?.comment
      if (note) form.append('note', note)
    }

    // Let the browser set the multipart boundary and Content-Length.
    return api.post(`/milestones/${id}/submit`, form).then(unwrap)
  },

  comment: (id, comment) => api.post(`/milestones/${id}/comment`, { comment }).then(unwrap),
  approve: (id, comment) => api.post(`/milestones/${id}/approve`, { comment }).then(unwrap),
  requestRevision: (id, comment) =>
    api.post(`/milestones/${id}/request-revision`, { comment }).then(unwrap),
  changeDeadline: (id, dueAt, reason) =>
    api.post(`/milestones/${id}/deadline`, { due_at: dueAt, reason }).then(unwrap),

  // --- The proposal milestone: the title decision ------------------------
  // The proposal is the one milestone whose verdict is the panel's, and it is
  // what settles the title and opens the rest of the chain. `approve` and
  // `requestRevision` are refused for it server-side, so these are the only
  // routes that decide it.

  /**
   * Record the panel's verdict. `decision` is `approved`, `conditional_approve`
   * or `rejected`; a reason is required for the latter two.
   */
  titleDecision: (id, payload) =>
    api.post(`/milestones/${id}/title-decision`, payload).then(unwrap),

  /**
   * Lampiran C — the corrections a conditional approval required. Filed by the
   * student; accepting it approves the milestone and fixes the corrected title.
   */
  fileLampiranC: (id, payload) =>
    api.post(`/milestones/${id}/lampiran-c`, payload).then(unwrap),

  /**
   * Change the title after the panel refused it. The new title is written to the
   * project, and the milestone reopens for a fresh decision.
   */
  changeTitle: (id, title) =>
    api.post(`/milestones/${id}/change-title`, { title }).then(unwrap),

  /**
   * The proposals this panel member has to rule on.
   *
   * A panel decides the title at the project's proposal milestone, so a seated
   * examiner needs to find those students. Settled proposals are included too,
   * so the list is a record rather than a to-do that empties silently.
   */
  panelProposals: () => api.get('/panel/proposals').then(unwrap),

  downloadUrl: (fileId) => `/api/submissions/${fileId}/download`,
  /**
   * Authenticated file download.
   *
   * Fetched through the axios instance rather than opened with `window.open`,
   * for the same reason as the CSV exports: the route sits behind Sanctum and
   * a plain navigation carries no Authorization header.
   *
   * `fallbackName` should be the file's original name. The server sends
   * Content-Disposition (CORS exposes it), so this is only used if that header
   * is ever unavailable — but the previous default of `submission-<id>` would
   * then save the file with no extension at all.
   */
  download: (fileId, fallbackName) =>
    api
      .get(`/submissions/${fileId}/download`, { responseType: 'blob' })
      .then((res) => saveDownload(res, fallbackName || `submission-${fileId}`)),

  /**
   * Withdraw a submission file. The server keeps the row and the bytes — this
   * only clears `is_current`, so the file leaves the reviewer's view while the
   * audit trail keeps it.
   */
  deleteFile: (fileId) => api.delete(`/submissions/${fileId}`).then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /** Read a single milestone. */
  get: (id) => api.get(`/milestones/${id}`).then(unwrap),
  /**
   * Record a review decision. The two outcomes are separate routes on the
   * server, so this dispatches rather than inventing a combined endpoint.
   */
  review: (id, { decision, comment }) =>
    decision === 'approved'
      ? api.post(`/milestones/${id}/approve`, { comment }).then(unwrap)
      : api.post(`/milestones/${id}/request-revision`, { comment }).then(unwrap),
}

// =====================================================================
// Module 4 — evaluations and marks
// =====================================================================
export const evaluationApi = {
  list: (params) => api.get('/evaluations', { params }).then(unwrapPaged),
  show: (id) => api.get(`/evaluations/${id}`).then(unwrap),
  create: (payload) => api.post('/evaluations', payload).then(unwrap),
  /** Lampiran H — the supervisor's PSM 2 progress report (1 or 2). */
  createProgressReport: (payload) =>
    api.post('/evaluations/progress-report', payload).then(unwrap),
  saveMarks: (id, payload) => api.put(`/evaluations/${id}/marks`, payload).then(unwrap),
  submit: (id) => api.post(`/evaluations/${id}/submit`).then(unwrap),
  declareConflict: (id, note) =>
    api.post(`/evaluations/${id}/declare-conflict`, { note }).then(unwrap),

  rubrics: () => api.get('/rubrics').then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /** Read a single evaluation form. */
  get: (id) => api.get(`/evaluations/${id}`).then(unwrap),

  // Rubric template authoring. The server exposes a single flat `/rubrics`
  // endpoint that returns the templates the caller may see, so these helpers
  // fetch it and select client-side rather than pretending dedicated routes
  // exist for each template.
  templates: (params = {}) =>
    api.get('/rubrics', { params }).then((response) => ({
      items: pickRubricList(response),
      meta: response?.data?.meta ?? null,
    })),
  template: (id) =>
    api.get('/rubrics').then((response) => {
      const items = pickRubricList(response)
      return items.find((t) => String(t.id) === String(id)) ?? null
    }),
  /** Clone a template forward into a new version. */
  cloneTemplate: (id, payload) =>
    api.post('/rubrics', { source_template_id: id, ...payload }).then(unwrap),
}

/** Normalise `/rubrics` responses, which may be a bare array or paginated. */
function pickRubricList(response) {
  const body = response?.data ?? {}
  if (Array.isArray(body)) return body
  if (Array.isArray(body.data)) return body.data
  return []
}

/**
 * Rubric templates, exposed under their own name for screens that treat
 * authoring as a separate concern from marking.
 */
export const rubricApi = {
  list: (params = {}) => evaluationApi.templates(params),
  show: (id) => evaluationApi.template(id),
  templates: (params = {}) => evaluationApi.templates(params),
  template: (id) => evaluationApi.template(id),
  cloneTemplate: (id, payload) => evaluationApi.cloneTemplate(id, payload),
  publish: (id) => api.post(`/rubrics/${id}/publish`).then(unwrap),
}

export const markApi = {
  list: (params) => api.get('/grades', { params }).then(unwrapPaged),
  recompute: (id) => api.post(`/grades/${id}/recompute`).then(unwrap),
  // No `release`: a mark publishes itself when its supervisor form arrives.
  // `recompute` survives as the repair path for marks whose forms predate the
  // automatic release.

  // --- Aliases -----------------------------------------------------------
  /** Marks for one project. Scoped to the project, not the mark list. */
  forProject: (projectId) => api.get(`/projects/${projectId}/grades`).then(unwrap),
}

/**
 * The coordinator's assessment window.
 *
 * Opening one allocates every Lampiran the batch needs and starts accepting
 * marks; closing stops new marks but keeps what was filed. Assessors read
 * `current()` to know whether they may file.
 */
export const assessmentWindowApi = {
  list: (params) => api.get('/assessment-windows', { params }).then(unwrap),
  current: () => api.get('/assessment-windows/current').then(unwrap),
  show: (id) => api.get(`/assessment-windows/${id}`).then(unwrap),
  create: (payload) => api.post('/assessment-windows', payload).then(unwrap),
  open: (id) => api.post(`/assessment-windows/${id}/open`).then(unwrap),
  close: (id) => api.post(`/assessment-windows/${id}/close`).then(unwrap),
}

export const markSubmissionApi = {
  /** Open (or fetch existing) a mark submission for one student on one project. */
  open: (projectId, studentId) =>
    api.post(`/projects/${projectId}/students/${studentId}/mark-submission/open`).then(unwrap),

  /** Get the current mark submission with readiness checklist and forms. */
  show: (projectId, studentId) =>
    api.get(`/projects/${projectId}/students/${studentId}/mark-submission`).then(unwrap),

  /** Lock the submission — attests all forms are in, freezes the aggregate. */
  lock: (projectId, studentId) =>
    api.post(`/projects/${projectId}/students/${studentId}/mark-submission/lock`).then(unwrap),

  /** Unlock a locked submission — requires a reason. */
  unlock: (projectId, studentId, reason) =>
    api.post(`/projects/${projectId}/students/${studentId}/mark-submission/unlock`, { reason }).then(unwrap),
}

// =====================================================================
// Module 5 — reports
// =====================================================================
export const reportApi = {
  dashboard: (params) => api.get('/reports/dashboard', { params }).then(unwrap),
  cohortProgress: (params) => api.get('/reports/cohort-progress', { params }).then(unwrap),
  atRisk: (params) => api.get('/reports/at-risk', { params }).then(unwrap),
  supervisorWorkload: (params) =>
    api.get('/reports/supervisor-workload', { params }).then(unwrap),
  examinerWorkload: (params) =>
    api.get('/reports/examiner-workload', { params }).then(unwrap),
  markDistribution: (params) =>
    api.get('/reports/mark-distribution', { params }).then(unwrap),
  milestoneBreakdown: (params) =>
    api.get('/reports/milestone-breakdown', { params }).then(unwrap),

  /**
   * CSV download.
   *
   * Fetched with the bearer token and saved client-side, so the file arrives
   * with the server's name and a 401 surfaces as an error rather than a
   * download of JSON.
   */
  download: (kind, params = {}) =>
    api
      .get(`/reports/export/${kind}.csv`, { params, responseType: 'blob' })
      .then((res) => saveDownload(res, `psm-${kind}-export.csv`)),

  // --- Aliases, mapped onto the real report routes -----------------------
  /** Cohort headline figures for the dashboard. */
  overview: (params) => api.get('/reports/dashboard', { params }).then(unwrap),
  /** Supervision load table. */
  workload: (params) => api.get('/reports/supervisor-workload', { params }).then(unwrap),
  /** Per-programme rollup, derived from cohort progress. */
  byProgramme: (params) =>
    api.get('/reports/cohort-progress', { params }).then(unwrap),
  /** On-time vs late, per milestone. */
  milestoneTimeliness: (params) =>
    api.get('/reports/milestone-breakdown', { params }).then(unwrap),
  /** Platform-level counts for the admin dashboard. */
  systemStats: (params) => api.get('/reports/dashboard', { params }).then(unwrap),
}

// =====================================================================
// Module 6 — notifications
// =====================================================================
export const notificationApi = {
  list: (params) => api.get('/notifications', { params }).then(unwrapPaged),
  summary: () => api.get('/notifications/summary').then(unwrap),
  markRead: (id) => api.post(`/notifications/${id}/read`).then(unwrap),
  markAllRead: () => api.post('/notifications/read-all').then(unwrap),
  dismiss: (id) => api.delete(`/notifications/${id}`).then(unwrap),
  preferences: () => api.get('/notifications/preferences').then(unwrap),
  updatePreferences: (payload) =>
    api.put('/notifications/preferences', payload).then(unwrap),
}

// =====================================================================
// Module 7 — archive and audit
// =====================================================================
export const archiveApi = {
  list: (params) => api.get('/archive', { params }).then(unwrapPaged),
  show: (id) => api.get(`/archive/${id}`).then(unwrap),
  filters: () => api.get('/archive/filters').then(unwrap),
  restore: (id) => api.post(`/archive/${id}/restore`).then(unwrap),

  exportUrl: (params = {}) => {
    const query = new URLSearchParams(params).toString()
    return `/api/archive/export.csv${query ? `?${query}` : ''}`
  },

  /** Authenticated CSV download — see reportApi.download for why. */
  download: (params = {}) =>
    api
      .get('/archive/export.csv', { params, responseType: 'blob' })
      .then((res) => saveDownload(res, 'psm-archive-export.csv')),

  // --- Aliases -----------------------------------------------------------
  /** Read one archived record. */
  get: (id) => api.get(`/archive/${id}`).then(unwrap),
  downloadUrl: (fileId) => `/api/submissions/${fileId}/download`,
  /**
   * A document preserved with an archived project is downloaded through the
   * normal submission-file route, since the archive stores a reference to the
   * original file rather than a second copy. Named `downloadFile` so it does
   * not collide with the CSV `download` above.
   */
  downloadFile: (fileId) =>
    api
      .get(`/submissions/${fileId}/download`, { responseType: 'blob' })
      .then((res) => saveDownload(res, `submission-${fileId}`)),
}

export const auditApi = {
  mine: (params) => api.get('/audit-logs/me', { params }).then(unwrapPaged),
  list: (params) => api.get('/audit-logs', { params }).then(unwrapPaged),
  filters: () => api.get('/audit-logs/filters').then(unwrap),
  forSubject: (type, id) => api.get(`/audit-logs/for/${type}/${id}`).then(unwrapPaged),
}
