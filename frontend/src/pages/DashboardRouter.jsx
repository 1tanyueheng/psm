import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Spinner } from '../components/ui'

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

  return <Navigate to={homeRoute} replace />
}
