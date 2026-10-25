import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { can } from '../../lib/permissions'
import { Button, Field, Input } from '../../components/ui'

/**
 * Module 1 — sign in.
 *
 * ## One email, two entrances
 *
 * There is **one** account per person and **one** password; `/login` and
 * `/lecturer/login` are two doors into the same house, not two sets of users.
 * The coursework entrance exists because a member of staff plays more than one
 * part: a supervisor signs in here to reach the section they have been appointed
 * to, and signs in on the main page to reach everything else they do. The
 * account, the role and the password are identical either way — only the screen
 * they are pointed at differs.
 *
 * That is also why nothing here checks the role *before* asking the API for a
 * token, and why the check afterwards is a courtesy rather than a rule. The
 * server decides who may key coursework from the role **and** the appointment;
 * this page can only save the person a redirect to a screen that would have
 * bounced them.
 *
 * Two other small details worth noting:
 *  - `next` is honoured, so a deep link interrupted by the session expiring
 *    returns the user where they were going rather than to a dashboard.
 *  - The demo accounts are listed, because this is an assessed project and a
 *    marker needs to get in without hunting through documentation.
 */
export default function LoginPage({ variant = 'default' }) {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState(null)
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const next = searchParams.get('next')

  /**
   * Which door the user came through.
   *
   * `default` is the main sign-in — students and all staff. `lecturer` is the
   * coursework entrance for the person who teaches a section. It changes the
   * copy, the demo chips and one check; it does **not** change the request.
   */
  const isLecturerEntrance = variant === 'lecturer'

  async function handleSubmit(event) {
    event.preventDefault()

    setSubmitting(true)
    setErrors(null)
    setMessage(null)

    const result = await login(email.trim(), password)

    setSubmitting(false)

    if (!result.ok) {
      setMessage(result.message)
      setErrors(result.errors ?? null)
      return
    }

    // A forced password change takes priority over any destination
    if (result.user?.must_change_password) {
      navigate('/change-password', { replace: true })
      return
    }

    /**
     * The one check the coursework entrance makes.
     *
     * A supervisor's account works here whatever they teach — but if they hold
     * no section, the marks screen has nothing to show them and the route guard
     * would bounce them straight back out with no explanation. So they are told
     * why, on the screen they are standing on, and given the main sign-in as a
     * way onwards.
     *
     * It stays a *courtesy*: the token has already been issued and the session
     * is live (deliberately — the same session serves the main entrance), and
     * the server is still the thing that refuses the marks. This branch only
     * decides whether to walk the person into a screen that would refuse them.
     */
    const entitled =
      can(result.user?.role, 'keyCoursework') ||
      Boolean(result.user?.extra_capabilities?.includes('keyCoursework'))

    if (isLecturerEntrance && !entitled) {
      setMessage(
        'Your sign-in worked, but this account is not appointed to a section, '
          + 'so there are no marks for it to key yet. An administrator appoints a '
          + 'section’s lecturer — use the main sign-in below in the meantime.',
      )
      return
    }

    navigate(next ? decodeURIComponent(next) : result.homeRoute || '/dashboard', { replace: true })
  }

  /** One-tap fill for the demo accounts. */
  function fillDemo(demoEmail) {
    setEmail(demoEmail)
    setPassword('password')
    setMessage(null)
    setErrors(null)
  }

  const mainAccounts = [
    { email: 'student@psm.test', label: 'Student' },
    { email: 'supervisor@psm.test', label: 'Supervisor' },
    { email: 'coordinator@psm.test', label: 'Coordinator' },
    // The seeded label, not "Examiner": being on a panel is a seating, and the
    // demo chip should name the account the way the rest of the app does.
    { email: 'examiner@psm.test', label: 'Supervisor (panel)' },
    { email: 'admin@psm.test', label: 'Admin' },
  ]

  /**
   * The coursework demo accounts.
   *
   * These four are the only people appointed to a section in the seeded data —
   * one each for S1 to S4 — so they are the accounts for which this entrance
   * leads somewhere. `lec-00N@psm.test` is also how the section list names them,
   * which makes the chip and the admin screen agree.
   */
  const lecturerAccounts = [
    { email: 'lec-001@psm.test', label: 'LEC-001 · S1' },
    { email: 'lec-002@psm.test', label: 'LEC-002 · S2' },
    { email: 'lec-003@psm.test', label: 'LEC-003 · S3' },
    { email: 'lec-004@psm.test', label: 'LEC-004 · S4' },
  ]

  const demoAccounts = isLecturerEntrance ? lecturerAccounts : mainAccounts

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <div className="flex-1 flex items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">
          {/* Brand */}
          <div className="text-center mb-8">
            <div className="w-11 h-11 rounded-xl bg-brand-600 text-white flex items-center justify-center text-sm font-medium mx-auto mb-3">
              PSM
            </div>
            <h1 className="text-lg font-medium text-slate-900">
              {isLecturerEntrance ? 'Coursework marks' : 'Sign in'}
            </h1>
            <p className="text-sm text-slate-500 mt-1">
              {isLecturerEntrance
                ? 'For the lecturer of a teaching section'
                : 'Final Year Project Management System'}
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

            {/*
              The same credentials, stated once for both entrances: someone
              arriving here with a supervisor address should not have to guess
              whether they need a different password.
            */}
            {isLecturerEntrance && (
              <p className="text-xs text-slate-500">
                Use your usual staff email and password — this is the same account
                you use on the main sign-in.
              </p>
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
            <p className="text-xs font-medium text-slate-700 mb-2">
              {isLecturerEntrance ? 'Demo lecturers' : 'Demo accounts'}
            </p>
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

          {/*
            The other door, named on both sides. Without this the second entrance
            is only reachable by typing its URL, which is exactly how it came to
            be asked about in the first place.
          */}
          <p className="text-center text-xs text-slate-500 mt-4">
            {isLecturerEntrance ? (
              <>
                Looking for the rest of the system?{' '}
                <Link to="/login" className="text-brand-600 hover:text-brand-700">
                  Main sign-in
                </Link>
              </>
            ) : (
              <>
                Teaching a section?{' '}
                <Link to="/lecturer/login" className="text-brand-600 hover:text-brand-700">
                  Coursework marks sign-in
                </Link>
              </>
            )}
          </p>
        </div>
      </div>
    </div>
  )
}
