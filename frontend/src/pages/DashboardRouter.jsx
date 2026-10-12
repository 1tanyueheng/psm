import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { homeRouteFor } from '../lib/permissions'
import { EmptyState, Spinner } from '../components/ui'

/**
 * Role-agnostic dashboard entry point.
 *
 * `/` and `/dashboard` both land here so a bookmark like `/dashboard` works
 * for every role. Keeping this as one component rather than five redirects
 * means there is exactly one place that decides where a role belongs.
 */
export default function DashboardRouter() {
  const { role, homeRoute, loading } = useAuth()

  if (loading) {
    return (
      <div className="py-20">
        <Spinner label="Loading your dashboard…" />
      </div>
    )
  }

  if (!role) {
    return <Navigate to="/login" replace />
  }

  /**
   * Stop rather than redirect when this is already the role's own home.
   *
   * A role the build does not recognise falls back to `/dashboard` — which is
   * this component. Navigating to ourselves would replace the history entry
   * forever and the screen would never paint, so an account with an unknown
   * role gets a sentence instead of a spinner that never resolves. That is
   * strictly better than the previous behaviour, where the fallback was
   * `/login` and `RedirectIfAuthenticated` bounced the user back here.
   */
  const target = homeRoute || homeRouteFor(role)

  if (target === '/dashboard') {
    return (
      <EmptyState
        title="No dashboard for this account"
        message={`Your account has the role "${role}", which this version of the system `
          + 'does not have a dashboard for. Ask an administrator to check your account.'}
      />
    )
  }

  return <Navigate to={target} replace />
}
