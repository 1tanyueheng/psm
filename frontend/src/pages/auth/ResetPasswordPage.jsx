import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { authApi } from '../../api/auth'
import { Button, Field, Input } from '../../components/ui'

/**
 * Module 1 — set a new password from an emailed token.
 *
 * The token and email arrive as query parameters, which is what the backend's
 * reset link produces. Both are submitted back with the new password for
 * verification.
 */
export default function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()

  const token = searchParams.get('token') ?? ''
  const emailFromLink = searchParams.get('email') ?? ''

  const [email, setEmail] = useState(emailFromLink)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState(null)
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  // A missing token means the user navigated here directly rather than
  // following a link. Telling them beats a confusing validation failure.
  const missingToken = token === ''

  async function handleSubmit(event) {
    event.preventDefault()

    setSubmitting(true)
    setErrors(null)
    setMessage(null)

    try {
      await authApi.resetPassword({
        token,
        email: email.trim(),
        password,
        password_confirmation: confirmation,
      })

      // Send them to sign in rather than straight in: the reset proves
      // ownership of the mailbox, not of the account, and a fresh login makes
      // that explicit.
      navigate('/login', {
        replace: true,
        state: { message: 'Password updated. Please sign in.' },
      })
    } catch (err) {
      setErrors(err.errors)
      setMessage(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="w-11 h-11 rounded-xl bg-brand-600 text-white flex items-center justify-center text-sm font-medium mx-auto mb-3">
            PSM
          </div>
          <h1 className="text-lg font-medium text-slate-900">Choose a new password</h1>
        </div>

        {missingToken ? (
          <div className="bg-white border border-slate-200 rounded-xl p-5 text-center">
            <p className="text-sm text-slate-700">This reset link is incomplete.</p>
            <p className="text-xs text-slate-500 mt-2">
              Please open the link from your email again, or request a new one.
            </p>
            <Link
              to="/forgot-password"
              className="inline-block mt-4 text-xs font-medium text-brand-600 hover:underline"
            >
              Request a new link
            </Link>
          </div>
        ) : (
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
                autoComplete="username"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </Field>

            <Field
              label="New password"
              required
              errors={errors}
              field="password"
              hint="At least 8 characters."
            >
              <Input
                type="password"
                autoComplete="new-password"
                autoFocus
                required
                minLength={8}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </Field>

            <Field
              label="Confirm new password"
              required
              errors={errors}
              field="password_confirmation"
            >
              <Input
                type="password"
                autoComplete="new-password"
                required
                minLength={8}
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
              />
            </Field>

            <Button type="submit" className="w-full" loading={submitting}>
              Update password
            </Button>
          </form>
        )}
      </div>
    </div>
  )
}
