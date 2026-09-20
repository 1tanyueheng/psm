import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button } from '../components/ui'

/**
 * 404.
 *
 * A wrong URL is usually one of three things: a stale bookmark, a typo, or a
 * hand-typed email link. So rather than only offering "go home", this page
 * points at the most likely intended destination for the signed-in role, and
 * at the public results page for anyone who is not signed in.
 */
export default function NotFoundPage() {
  const { isAuthenticated, homeRoute } = useAuth()
  const location = useLocation()

  return (
    <div className="flex min-h-[70vh] items-center justify-center px-4">
      <div className="max-w-md text-center">
        <p className="text-sm font-medium uppercase tracking-widest text-brand-700">
          Error 404
        </p>
        <h1 className="mt-3 text-3xl font-semibold text-slate-900">Page not found</h1>
        <p className="mt-3 text-slate-600">
          We could not find anything at that address. The link may be out of date, or the
          item may have been archived.
        </p>

        {location.pathname && location.pathname !== '/' && (
          <p className="mt-3 break-all rounded-md bg-slate-100 px-3 py-2 font-mono text-xs text-slate-500">
            {location.pathname}
          </p>
        )}

        <div className="mt-7 flex flex-wrap justify-center gap-2">
          {isAuthenticated ? (
            <>
              <Link to={homeRoute ?? '/dashboard'}>
                <Button>Go to my dashboard</Button>
              </Link>
              <Link to="/projects">
                <Button variant="secondary">Browse projects</Button>
              </Link>
            </>
          ) : (
            <>
              <Link to="/login">
                <Button>Sign in</Button>
              </Link>
              <Link to="/leaderboard">
                <Button variant="secondary">View award results</Button>
              </Link>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
