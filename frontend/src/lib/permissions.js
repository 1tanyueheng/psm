/**
 * Client-side authorisation vocabulary (Module 1).
 *
 * This mirrors the backend's Role enum. It exists to shape the UI — hiding a
 * menu item a user cannot use. It is NOT a security boundary: the API enforces
 * every rule independently through middleware and policies. Anything here that
 * disagrees with the server loses, which is why the maps are kept small and
 * explicit rather than clever.
 */

/** Seniority ordering, used for "at least this role" checks. */
const SENIORITY = {
  student: 1,
  examiner: 2,
  supervisor: 3,
  coordinator: 4,
  admin: 5,
}

/** Where each role lands after signing in. */
const HOME_ROUTES = {
  student: '/student/dashboard',
  supervisor: '/supervisor/dashboard',
  coordinator: '/coordinator/dashboard',
  examiner: '/examiner/dashboard',
  admin: '/admin/dashboard',
}

/** Capability → roles that hold it. Mirrors Role.php on the server. */
const CAPABILITIES = {
  assess: ['supervisor', 'examiner', 'coordinator'],
  viewCohortAnalytics: ['coordinator', 'admin'],
  manageUsers: ['admin'],
  accessArchive: ['student', 'supervisor', 'coordinator', 'examiner', 'admin'],
  manageLeaderboard: ['coordinator', 'admin'],
  assignSupervisors: ['coordinator', 'admin'],
  releaseGrades: ['coordinator', 'admin'],
  moderateMarks: ['coordinator', 'admin'],
  manageMilestones: ['supervisor', 'coordinator', 'admin'],
  manageTemplates: ['admin'],
  viewAuditLog: ['coordinator', 'admin'],
}

export function homeRouteFor(role) {
  return HOME_ROUTES[role] ?? '/login'
}

export function isAtLeast(role, minimum) {
  if (!role || !minimum) return false

  return (SENIORITY[role] ?? 0) >= (SENIORITY[minimum] ?? 0)
}

export function can(role, capability) {
  if (!role) return false

  return (CAPABILITIES[capability] ?? []).includes(role)
}

/** Human label for a role, used in headers and badges. */
export const ROLE_LABELS = {
  student: 'Student',
  supervisor: 'Supervisor',
  coordinator: 'Coordinator',
  examiner: 'Examiner',
  admin: 'Administrator',
}

/** Tailwind classes per role, so a role is recognisable at a glance. */
export const ROLE_TONES = {
  student: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  supervisor: 'bg-amber-50 text-amber-700 border-amber-200',
  coordinator: 'bg-violet-50 text-violet-700 border-violet-200',
  examiner: 'bg-sky-50 text-sky-700 border-sky-200',
  admin: 'bg-rose-50 text-rose-700 border-rose-200',
}

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? role ?? '—'
}

export function roleTone(role) {
  return ROLE_TONES[role] ?? 'bg-slate-50 text-slate-700 border-slate-200'
}
