import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { Button, Field, Input } from '../../components/ui'

/**
 * Module 1 — sign in.
 *
 * Two small details worth noting:
 *  - `next` is honoured, so a deep link interrupted by the session expiring
 *    returns the user where they were going rather than to a dashboard.
 *  - The demo accounts are listed, because this is an assessed project and a
 *    marker needs to get in without hunting through documentation.
 */
export default function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState(null)
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const next = searchParams.get('next')

  async function handleSubmit(event) {
    event.preventDefault()

    setSubmitting(true)
    setErrors(null)
    setMessage(null)

    const result = await login(email.trim(), password)

    setSubmitting(false)

    if (!result.ok) {
      setMessage(result.message)
      return
    }

    // A forced password change takes priority over any destination
    if (result.user?.must_change_password) {
      navigate('/change-password', { replace: true })
      return
    }

    navigate(next ? decodeURIComponent(next) : result.homeRoute || '/dashboard', { replace: true })
  }

  /** One-tap fill for the demo accounts. */
  function fillDemo(demoEmail) {
    setEmail(demoEmail)
    setPassword('password')
    setMessage(null)
  }

  const demoAccounts = [
    { email: 'student@psm.test', label: 'Student' },
    { email: 'supervisor@psm.test', label: 'Supervisor' },
    { email: 'coordinator@psm.test', label: 'Coordinator' },
    { email: 'examiner@psm.test', label: 'Examiner' },
    { email: 'admin@psm.test', label: 'Admin' },
  ]

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <div className="flex-1 flex items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">
          {/* Brand */}
          <div className="text-center mb-8">
            <div className="w-11 h-11 rounded-xl bg-brand-600 text-white flex items-center justify-center text-sm font-medium mx-auto mb-3">
              PSM
            </div>
            <h1 className="text-lg font-medium text-slate-900">Sign in</h1>
            <p className="text-sm text-slate-500 mt-1">
              Final Year Project Management System
            </p>
          </div>

          <form
            onSubmit={handleSubmit}
            className="bg-white border border-slate-200 rounded-xl p-5 space-y-4"
          >
            {message && (
              <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                <p className="text-xs text-rose-800">{message}</p>
              </div>
            )}

            <Field label="Email" required errors={errors} field="email">
              <Input
                type="email"
                name="email"
                autoComplete="username"
                autoFocus
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@psm.test"
              />
            </Field>

            <Field label="Password" required errors={errors} field="password">
              <Input
                type="password"
                name="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
              />
            </Field>

            <Button type="submit" className="w-full" loading={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>

            <div className="text-center">
              <Link
                to="/forgot-password"
                className="text-xs text-slate-500 hover:text-brand-600"
              >
                Forgot your password?
              </Link>
            </div>
          </form>

          {/* Demo accounts — this is an assessed project, so a marker needs a
              way in without reading the repository. */}
          <div className="mt-5 bg-white border border-slate-200 rounded-xl p-4">
            <p className="text-xs font-medium text-slate-700 mb-2">Demo accounts</p>
            <p className="text-[11px] text-slate-500 mb-3">
              Password for all accounts: <code className="text-slate-700">password</code>
            </p>
            <div className="flex flex-wrap gap-1.5">
              {demoAccounts.map((account) => (
                <button
                  key={account.email}
                  type="button"
                  onClick={() => fillDemo(account.email)}
                  className="px-2 py-1 rounded-md border border-slate-200 text-[11px]
                             text-slate-600 hover:bg-slate-50 hover:border-slate-300"
                >
                  {account.label}
                </button>
              ))}
            </div>
          </div>

          <p className="text-center text-xs text-slate-400 mt-5">
            <Link to="/leaderboard" className="hover:text-brand-600">
              View the public showcase →
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}
