import api, { unwrap, unwrapPaged, saveDownload } from './client'

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
  update: (payload) => api.patch('/profile', payload).then(unwrap),
  changePassword: (payload) => api.post('/profile/password', payload).then(unwrap),
}

export const assignmentApi = {
  list: (params) => api.get('/assignments', { params }).then(unwrapPaged),
  show: (id) => api.get(`/assignments/${id}`).then(unwrap),
  create: (payload) => api.post('/assignments', payload).then(unwrap),
  update: (id, payload) => api.patch(`/assignments/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/assignments/${id}`).then(unwrap),
  unassigned: (params) => api.get('/assignments/unassigned', { params }).then(unwrapPaged),
  swap: (id, payload) => api.post(`/assignments/${id}/swap`, payload).then(unwrap),
}

// =====================================================================
// Module 3 — projects, milestones, registrations, supervision
// =====================================================================
export const projectApi = {
  list: (params) => api.get('/projects', { params }).then(unwrapPaged),
  show: (id) => api.get(`/projects/${id}`).then(unwrap),
  create: (payload) => api.post('/projects', payload).then(unwrap),
  update: (id, payload) => api.patch(`/projects/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/projects/${id}`).then(unwrap),
  archive: (id, note) => api.post(`/projects/${id}/archive`, { note }).then(unwrap),
  // Registration-time options (supervisor picker, coordinator meta, etc.)
  registerMeta: (params) => api.get('/projects/options', { params }).then(unwrap),
}

export const milestoneApi = {
  list: (projectId, params) => api.get(`/projects/${projectId}/milestones`, { params }).then(unwrapPaged),
  show: (id) => api.get(`/milestones/${id}`).then(unwrap),
  submit: (id, payload) => api.post(`/milestones/${id}/submit`, payload).then(unwrap),
  grade: (id, payload) => api.post(`/milestones/${id}/grade`, payload).then(unwrap),
  options: (projectId) => api.get(`/projects/${projectId}/milestones/options`).then(unwrap),
}

export const registrationApi = {
  list: (params) => api.get('/registrations', { params }).then(unwrapPaged),
  show: (id) => api.get(`/registrations/${id}`).then(unwrap),
  create: (payload) => api.post('/registrations', payload).then(unwrap),
  approve: (id) => api.post(`/registrations/${id}/approve`).then(unwrap),
  reject: (id, payload) => api.post(`/registrations/${id}/reject`, payload).then(unwrap),
  duplicateA: (id) => api.post(`/registrations/${id}/duplicate-a`).then(unwrap),
  options: (params) => api.get('/registrations/options', { params }).then(unwrap),
}

export const supervisionApi = {
  list: (params) => api.get('/supervision', { params }).then(unwrapPaged),
  show: (id) => api.get(`/supervision/${id}`).then(unwrap),
  create: (payload) => api.post('/supervision', payload).then(unwrap),
  destroy: (id) => api.delete(`/supervision/${id}`).then(unwrap),
  options: (params) => api.get('/supervision/options', { params }).then(unwrap),
}

// =====================================================================
// Module 4 — marking forms (rubrics) and evaluations
// =====================================================================
export const rubricApi = {
  list: (params) => api.get('/rubrics', { params }).then(unwrapPaged),
  show: (id) => api.get(`/rubrics/${id}`).then(unwrap),
  create: (payload) => api.post('/rubrics', payload).then(unwrap),
  update: (id, payload) => api.patch(`/rubrics/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/rubrics/${id}`).then(unwrap),
  eligibleFor: (projectId, role) => api.get(`/rubrics/eligible/${projectId}/${role}`).then(unwrap),
}

export const evaluationApi = {
  list: (params) => api.get('/evaluations', { params }).then(unwrapPaged),
  show: (id) => api.get(`/evaluations/${id}`).then(unwrap),
  create: (payload) => api.post('/evaluations', payload).then(unwrap),
  update: (id, payload) => api.patch(`/evaluations/${id}`, payload).then(unwrap),
  destroy: (id) => api.delete(`/evaluations/${id}`).then(unwrap),
  submit: (id) => api.post(`/evaluations/${id}/submit`).then(unwrap),
  release: (id) => api.post(`/evaluations/${id}/release`).then(unwrap),
  draft: (params) => api.get('/evaluations/draft', { params }).then(unwrapPaged),
}

// =====================================================================
// Module 5 — reports and grades
// =====================================================================
export const reportApi = {
  overview: () => api.get('/reports/overview').then(unwrap),
  gradeDistribution: () => api.get('/reports/grade-distribution').then(unwrap),
  workload: () => api.get('/reports/workload').then(unwrap),
  systemStats: () => api.get('/reports/system-stats').then(unwrap),

  download: (params = {}) =>
    api
      .get('/reports/export.csv', { params, responseType: 'blob' })
      .then((res) => saveDownload(res, 'psm-report-export.csv')),
}

export const gradeApi = {
  list: (params) => api.get('/grades', { params }).then(unwrapPaged),
  show: (id) => api.get(`/grades/${id}`).then(unwrap),
  release: (id) => api.post(`/grades/${id}/release`).then(unwrap),
  bulkRelease: (payload) => api.post('/grades/bulk-release', payload).then(unwrap),
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

export const auditApi = {
  mine: (params) => api.get('/audit-logs/me', { params }).then(unwrapPaged),
  list: (params) => api.get('/audit-logs', { params }).then(unwrapPaged),
  filters: () => api.get('/audit-logs/filters').then(unwrap),
  forSubject: (type, id) => api.get(`/audit-logs/for/${type}/${id}`).then(unwrapPaged),
}