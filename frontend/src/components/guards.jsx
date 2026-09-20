import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Spinner } from './ui'

/**
 * Route guards (Module 1).
 *
 * These shape the user experience — keeping someone out of a screen that
 * would only 403 on load. They are not the security boundary: the API
 * enforces everything independently, so bypassing these in devtools grants
 * nothing but an empty page.
 */

/**
 * Requires an authenticated session. Remembers where the user was heading
 * so the login handler can return them there.
 */
export function RequireAuth({ children }) {
  const { isAuthenticated, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <Spinner label="Restoring your session…" />
      </div>
    )
  }

  if (!isAuthenticated) {
    const next = encodeURIComponent(location.pathname + location.search)
    return <Navigate to={`/login?next=${next}`} replace />
  }

  return children
}

/**
 * Requires the user to hold one of the given roles.
 * Redirects to their own dashboard rather than a bare 403, since landing
 * somewhere useful is better than landing on an error.
 */
export function RequireRole({ roles, children }) {
  const { role, homeRoute, loading } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <Spinner />
      </div>
    )
  }

  if (!roles.includes(role)) {
    return <Navigate to={homeRoute} replace />
  }

  return children
}

/**
 * Sends an already-authenticated user away from /login.
 * Without this, signing in and pressing Back lands on the login form again.
 */
export function RedirectIfAuthenticated({ children }) {
  const { isAuthenticated, loading, homeRoute } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <Spinner />
      </div>
    )
  }

  if (isAuthenticated) {
    return <Navigate to={homeRoute} replace />
  }

  return children
}

/**
 * Requires a capability rather than a role list — for screens that several
 * roles share, where listing them twice invites them to drift apart.
 */
export function RequireCapability({ capability, children }) {
  const { can, homeRoute, loading } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <Spinner />
      </div>
    )
  }

  if (!can(capability)) {
    return <Navigate to={homeRoute} replace />
  }

  return children
}
