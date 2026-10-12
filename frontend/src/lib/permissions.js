/**
 * Client-side authorisation vocabulary (Module 1).
 *
 * This mirrors the backend's Role enum. It exists to shape the UI — hiding a
 * menu item a user cannot use. It is NOT a security boundary: the API enforces
 * every rule independently through middleware and policies. Anything here that
 * disagrees with the server loses, which is why the maps are kept small and
 * explicit rather than clever.
 */

/**
 * Seniority ordering, used for "at least this role" checks.
 *
 * `lecturer` sits at 0 — deliberately *below* `student`, not beside the staff
 * roles. The ordering is a privilege hierarchy, and a lecturer's reach really
 * is narrower than a student's: they mark four coursework numbers for one
 * section and can read nothing else, while a student can read their own
 * project, milestones, marks and archive.
 *
 * The number matters because `isAtLeast` reads a missing key as 0, so an
 * unlisted role would already compare as the lowest rank. Giving the lecturer
 * an explicit 0 makes that explicit rather than an accident of omission, and
 * means any future `isAtLeast(role, 'student')` gate correctly excludes them.
 *
 * **A lecturer must never be given a staff rank.** The backend is emphatic that
 * they are not staff: `Role::worksOnPsm()` is false for them, `canAssess()` is
 * false, and `canManageUsers()` is false. A rank of 2 or above would let a
 * lecturer through every "at least a supervisor" check on the client.
 */
const SENIORITY = {
  lecturer: 0,
  student: 1,
  supervisor: 2,
  coordinator: 3,
  admin: 4,
}

/**
 * Where each role lands after signing in.
 *
 * These must agree with the backend's `Role::homeRoute()`, which is what the
 * login response's `home_route` is built from. The client copy is used on every
 * *later* navigation — `RequireRole` and `RequireCapability` redirect here when
 * they refuse — so a role missing from this map does not merely land on the
 * wrong page, it bounces the user to `/login` mid-session.
 */
const HOME_ROUTES = {
  student: '/student/dashboard',
  supervisor: '/supervisor/dashboard',
  lecturer: '/dashboard/lecturer',
  coordinator: '/coordinator/dashboard',
  admin: '/admin/dashboard',
}

/**
 * Capability → roles that hold it. Mirrors Role.php on the server.
 *
 * There is no `examiner` role: a panel is drawn from the people who supervise,
 * so `supervisor` covers both. Which *form* someone fills is decided by the
 * assessor type on the evaluation, not by their account.
 *
 * **Every capability is an allow-list, and `lecturer` appears in none of them.**
 * That is the point rather than an oversight: `can()` returns false for a name
 * that is not listed, so a lecturer is denied each of these by default. Adding
 * them to `accessArchive`, say, would grant PSM surfaces that `Role.php`
 * explicitly says they must not have — `worksOnPsm()` is false for a lecturer,
 * so screens that offer "your students" or "your projects" should treat them as
 * an outsider. See `worksOnPsm` below, which is the positive statement of the
 * same rule.
 */
const CAPABILITIES = {
  assess: ['supervisor', 'coordinator'],
  viewCohortAnalytics: ['coordinator', 'admin'],
  manageUsers: ['admin'],
  accessArchive: ['student', 'supervisor', 'coordinator', 'admin'],
  assignSupervisors: ['coordinator', 'admin'],
  /**
   * Reading a cohort's marks, and the coordinator's control over marking.
   *
   * Gates `/marks` and `/assessment`. It replaced a `releaseMarks` capability
   * that only existed to drive the Release buttons — when those went, so did
   * the capability, and anything still testing for it silently denied access
   * rather than failing loudly. `can()` returns false for an unknown name, and
   * `RequireCapability` redirects on false, so a deleted capability is an
   * invisible lockout.
   */
  reviewMarks: ['coordinator', 'admin'],
  /** Create terms, open/close registration. */
  manageSemesters: ['coordinator', 'admin'],
  manageMilestones: ['supervisor', 'coordinator', 'admin'],
  manageTemplates: ['admin'],
  viewAuditLog: ['coordinator', 'admin'],
  /** Open/lock/unlock mark submissions (Module 4). */
  manageMarkSubmissions: ['coordinator', 'admin'],
  /**
   * Coursework marking — the lecturer's own screen, and nothing more.
   *
   * Held by admin and coordinator as well as the lecturer because
   * `LecturerMarkPolicy` lets staff correct a mark; `saveMarks` resolves the
   * section from the student's own placement for them, and from the session for
   * a lecturer, so both audiences reach the same endpoints.
   */
  keyCoursework: ['lecturer', 'coordinator', 'admin'],
  /** The admin's control over who owns a section and who sits in it. */
  manageSections: ['admin'],
  /**
   * Placing a student into a section. Admin *or* coordinator, which is wider
   * than `manageSections` above because appointing a section's lecturer is a
   * faculty decision while moving a student between sections is administrative.
   * The server splits them the same way (`assignStudents` vs `manage`).
   */
  assignStudentsToSections: ['coordinator', 'admin'],
}

/**
 * Where a role lands after signing in.
 *
 * The server's `home_route` is authoritative and is what login uses. This map is
 * the fallback for the *later* navigations — `RequireRole` and
 * `RequireCapability` redirect here when they refuse — and for a session
 * restored from a stored token, where no login response is available.
 *
 * **An unrecognised role must not return `/login`.** It did, and that is a
 * redirect loop rather than a safe default: a signed-in user with a role this
 * build does not know about fails a guard, is sent to `/login`, and
 * `RedirectIfAuthenticated` immediately sends them back to `homeRoute` — which
 * is `/login`. The page never renders. `/dashboard` is the honest fallback: it
 * is role-agnostic, dispatches on the role the server reports, and shows a
 * "no dashboard for this account" state rather than spinning.
 */
export function homeRouteFor(role) {
  return HOME_ROUTES[role] ?? '/dashboard'
}

export function isAtLeast(role, minimum) {
  if (!role || !minimum) return false

  return (SENIORITY[role] ?? 0) >= (SENIORITY[minimum] ?? 0)
}

export function can(role, capability) {
  if (!role) return false

  return (CAPABILITIES[capability] ?? []).includes(role)
}

/**
 * Does this role work on PSM itself?
 *
 * Mirrors `Role::worksOnPsm()`. A lecturer does not: they mark coursework for
 * one section of a service course that happens to run alongside PSM 1, and they
 * have no project, milestones, supervision, panel or submission of their own.
 *
 * Screens that would otherwise offer "your students" or "your projects" should
 * ask this before rendering that section, so a lecturer sees an honest empty
 * page rather than a PSM surface that will only ever be empty for them. The
 * capability map above already excludes them from every PSM capability, so this
 * is the positive spelling of the same rule — reach for it where the question
 * really is "are they on PSM?" rather than "may they do this one thing?".
 *
 * The check is written as an explicit list rather than as `!== 'lecturer'` so an
 * unknown role is treated as *not* working on PSM. That direction is safe: a
 * stranger is shown fewer PSM surfaces rather than more, and the API
 * authorises independently.
 */
export function worksOnPsm(role) {
  return ['student', 'supervisor', 'coordinator', 'admin'].includes(role)
}

/** Human label for a role, used in headers and badges. */
export const ROLE_LABELS = {
  student: 'Student',
  supervisor: 'Supervisor',
  lecturer: 'Lecturer',
  coordinator: 'Coordinator',
  admin: 'Administrator',
}

/** Tailwind classes per role, so a role is recognisable at a glance. */
export const ROLE_TONES = {
  student: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  supervisor: 'bg-amber-50 text-amber-700 border-amber-200',
  // Sky rather than a green/amber/violet/rose slot, so the outsider role does
  // not read as a shade of one of the PSM roles in a user list.
  lecturer: 'bg-sky-50 text-sky-700 border-sky-200',
  coordinator: 'bg-violet-50 text-violet-700 border-violet-200',
  admin: 'bg-rose-50 text-rose-700 border-rose-200',
}

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? role ?? '—'
}

export function roleTone(role) {
  return ROLE_TONES[role] ?? 'bg-slate-50 text-slate-700 border-slate-200'
}
