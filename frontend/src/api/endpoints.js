import api, { unwrap, unwrapPaged } from './client'

/**
 * The API surface, grouped to mirror the backend's module layout.
 *
 * Method names follow the backend routes (`routes/api.php`) so that a route
 * and its client are easy to match up. A few aliases exist where a screen has
 * a natural name for an operation that differs from the controller — for
 * example `projectApi.registerMeta` for `/projects/options`. Every alias is
 * annotated so the real route stays discoverable.
 *
 * Components never touch Axios directly, so a change to the transport stays in
 * this file plus `client.js`.
 */

// =====================================================================
// Module 1 — authentication
// =====================================================================
// Lives in `./auth` because the login/logout flow also drives AuthContext.

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
  workload: () => api.get('/profile/workload').then(unwrap),

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
   * Supervisors with capacity, for the capacity panel.
   *
   * There is no `/assignments/supervisors` route — that path only exists as
   * `/assignments/supervisors/{id}/capacity` for setting a limit. The list of
   * staff to choose from comes from `/users/options`, which returns
   * {value, label, meta:{staff_no, capacity, load, available}} entries and is
   * readable by admin and coordinator alike.
   */
  supervisors: (params) =>
    api.get('/users/options', { params: { role: 'supervisor', ...params } }).then(unwrapPaged),
  /** Create a supervision pair. */
  assign: (payload) => api.post('/assignments/supervisions', payload).then(unwrap),
  /** End a supervision pair. */
  remove: (id) => api.delete(`/assignments/supervisions/${id}`).then(unwrap),
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
  setLeaderboardConsent: (id, optOut) =>
    api.post(`/projects/${id}/leaderboard-consent`, { opt_out: optOut }).then(unwrap),

  milestones: (id) => api.get(`/projects/${id}/milestones`).then(unwrap),
  grades: (id) => api.get(`/projects/${id}/grades`).then(unwrap),
  releaseAllGrades: (id) => api.post(`/projects/${id}/grades/release-all`).then(unwrap),
  updateGradeScheme: (id, payload) =>
    api.put(`/projects/${id}/grade-scheme`, payload).then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /** Registration screen metadata — categories, sessions, supervisor list. */
  registerMeta: () => api.get('/projects/options').then(unwrap),
}

export const milestoneApi = {
  list: (params) => api.get('/milestones', { params }).then(unwrapPaged),
  show: (id) => api.get(`/milestones/${id}`).then(unwrap),

  /**
   * Submit files for a milestone.
   *
   * Accepts either a pre-built FormData (the form builds one so it can append
   * several files and a note) or `{ files, comment }`.
   */
  submit: (id, payload) => {
    const form = payload instanceof FormData ? payload : new FormData()

    if (!(payload instanceof FormData)) {
      // Laravel expects files[] for an array field.
      Array.from(payload?.files ?? []).forEach((file) => form.append('files[]', file))
      if (payload?.comment) form.append('comment', payload.comment)
      if (payload?.note) form.append('comment', payload.note)
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

  downloadUrl: (fileId) => `/api/submissions/${fileId}/download`,
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
// Module 4 — evaluations and grades
// =====================================================================
export const evaluationApi = {
  list: (params) => api.get('/evaluations', { params }).then(unwrapPaged),
  show: (id) => api.get(`/evaluations/${id}`).then(unwrap),
  create: (payload) => api.post('/evaluations', payload).then(unwrap),
  saveMarks: (id, payload) => api.put(`/evaluations/${id}/marks`, payload).then(unwrap),
  submit: (id) => api.post(`/evaluations/${id}/submit`).then(unwrap),
  moderate: (id, newPercent, reason) =>
    api.post(`/evaluations/${id}/moderate`, { new_percent: newPercent, reason }).then(unwrap),
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
    api.get('/rubrics', { params }).then((response) => {
      const items = pickRubricList(response)
      const category = params.category
      const filtered = category ? items.filter((t) => t.category === category) : items
      return { data: { data: filtered, meta: response?.data?.meta ?? null } }
    }),
  template: (id) =>
    api.get('/rubrics').then((response) => {
      const items = pickRubricList(response)
      return { data: { data: items.find((t) => String(t.id) === String(id)) ?? null } }
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

export const gradeApi = {
  list: (params) => api.get('/grades', { params }).then(unwrapPaged),
  recompute: (id) => api.post(`/grades/${id}/recompute`).then(unwrap),
  release: (id) => api.post(`/grades/${id}/release`).then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /** Grades for one project. Scoped to the project, not the grade list. */
  forProject: (projectId) => api.get(`/projects/${projectId}/grades`).then(unwrap),
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
  gradeDistribution: (params) =>
    api.get('/reports/grade-distribution', { params }).then(unwrap),
  milestoneBreakdown: (params) =>
    api.get('/reports/milestone-breakdown', { params }).then(unwrap),

  /**
   * CSV download URL.
   *
   * Points at the API directly so the browser streams the file rather than
   * axios buffering a large export in memory and re-blobbing it.
   */
  exportUrl: (kind, params = {}) => {
    const query = new URLSearchParams(params).toString()
    return `/api/reports/export/${kind}.csv${query ? `?${query}` : ''}`
  },

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

  // --- Aliases -----------------------------------------------------------
  /** Read one archived record. */
  get: (id) => api.get(`/archive/${id}`).then(unwrap),
  /**
   * A document preserved with an archived project is downloaded through the
   * normal submission-file route, since the archive stores a reference to the
   * original file rather than a second copy.
   */
  downloadUrl: (fileId) => `/api/submissions/${fileId}/download`,
}

export const auditApi = {
  mine: (params) => api.get('/audit-logs/me', { params }).then(unwrapPaged),
  list: (params) => api.get('/audit-logs', { params }).then(unwrapPaged),
  filters: () => api.get('/audit-logs/filters').then(unwrap),
  forSubject: (type, id) => api.get(`/audit-logs/for/${type}/${id}`).then(unwrapPaged),
}

// =====================================================================
// Module 8 — leaderboard management (staff side)
// =====================================================================
export const leaderboardApi = {
  list: (params) => api.get('/leaderboards', { params }).then(unwrapPaged),
  show: (id) => api.get(`/leaderboards/${id}`).then(unwrap),
  create: (payload) => api.post('/leaderboards', payload).then(unwrap),
  update: (id, payload) => api.patch(`/leaderboards/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/leaderboards/${id}`).then(unwrap),

  eligible: (params) => api.get('/leaderboards/eligible', { params }).then(unwrap),
  settings: () => api.get('/leaderboards/settings').then(unwrap),
  updateSettings: (payload) => api.put('/leaderboards/settings', payload).then(unwrap),
  preview: (slug) => api.get(`/leaderboards/preview/${slug}`).then(unwrap),

  /**
   * Build a board. With an id, rebuild that board into a fresh draft; without
   * one, create a new draft from the current rankings.
   */
  build: (idOrPayload) => {
    if (idOrPayload && typeof idOrPayload === 'object') {
      return api.post('/leaderboards', idOrPayload).then(unwrap)
    }
    if (idOrPayload) {
      return api.post(`/leaderboards/${idOrPayload}/build`).then(unwrap)
    }
    // No target given: create a draft, then populate it from current marks.
    return api
      .post('/leaderboards', {})
      .then((response) => {
        const created = response?.data?.data
        return created?.id ? api.post(`/leaderboards/${created.id}/build`) : response
      })
      .then(unwrap)
  },

  publish: (id) => api.post(`/leaderboards/${id}/publish`).then(unwrap),
  unpublish: (id, reason) =>
    api.post(`/leaderboards/${id}/unpublish`, { reason }).then(unwrap),
  updateEntry: (id, entryId, payload) =>
    api.patch(`/leaderboards/${id}/entries/${entryId}`, payload).then(unwrap),

  // --- Aliases -----------------------------------------------------------
  /** Read one board, with entries. */
  get: (id) => api.get(`/leaderboards/${id}`).then(unwrap),
}

// =====================================================================
// Module 8 — PUBLIC. No authentication required.
// =====================================================================
export const publicApi = {
  current: () => api.get('/public/leaderboard', { skipAuthRedirect: true }).then(unwrap),
  status: () => api.get('/public/leaderboard/status', { skipAuthRedirect: true }).then(unwrap),
  archive: () => api.get('/public/leaderboard/archive', { skipAuthRedirect: true }).then(unwrap),
  bySlug: (slug) =>
    api.get(`/public/leaderboard/${slug}`, { skipAuthRedirect: true }).then(unwrap),
  entry: (slug, rank) =>
    api.get(`/public/leaderboard/${slug}/entry/${rank}`, { skipAuthRedirect: true }).then(unwrap),

  // --- Aliases used by the public page ----------------------------------
  /**
   * The live board. With a slug, that board; without one, whichever board the
   * API reports as current.
   */
  leaderboard: (slug) =>
    slug
      ? api.get(`/public/leaderboard/${slug}`, { skipAuthRedirect: true }).then(unwrap)
      : api.get('/public/leaderboard', { skipAuthRedirect: true }).then(unwrap),
  /** Past sessions, for the "other sessions" navigation. */
  leaderboards: () =>
    api.get('/public/leaderboard/archive', { skipAuthRedirect: true }).then(unwrap),
}
