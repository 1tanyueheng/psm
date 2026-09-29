import { useState } from 'react'
import { Link } from 'react-router-dom'
import { authApi } from '../../api/auth'
import { Button, Field, Input } from '../../components/ui'

/**
 * Module 1 — request a password reset.
 *
 * The success message is shown whether or not the address exists. Telling an
 * anonymous visitor "no such account" turns this form into a directory of who
 * works or studies here.
 */
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState(null)

  async function handleSubmit(event) {
    event.preventDefault()

    setSubmitting(true)
    setErrors(null)

    try {
      await authApi.forgotPassword(email.trim())
      setSent(true)
    } catch (err) {
      setErrors(err.errors)
      // Still show the confirmation: a validation error is different from a
      // "user not found", and only the former should be revealed.
      if (!err.errors) setSent(true)
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
          <h1 className="text-lg font-medium text-slate-900">Reset your password</h1>
        </div>

        {sent ? (
          <div className="bg-white border border-slate-200 rounded-xl p-5 text-center">
            <p className="text-sm text-slate-700">Check your email.</p>
            <p className="text-xs text-slate-500 mt-2">
              If an account exists for <strong>{email}</strong>, a reset link is on its way.
              The link expires in 60 minutes.
            </p>
            <Link
              to="/login"
              className="inline-block mt-4 text-xs font-medium text-brand-600 hover:underline"
            >
              Back to sign in
            </Link>
          </div>
        ) : (
          <form
            onSubmit={handleSubmit}
            className="bg-white border border-slate-200 rounded-xl p-5 space-y-4"
          >
            <p className="text-xs text-slate-500">
              Enter your email address and we will send you a link to choose a new password.
            </p>

            <Field label="Email" required errors={errors} field="email">
              <Input
                type="email"
                autoComplete="username"
                autoFocus
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@psm.test"
              />
            </Field>

            <Button type="submit" className="w-full" loading={submitting}>
              Send reset link
            </Button>

            <div className="text-center">
              <Link to="/login" className="text-xs text-slate-500 hover:text-brand-600">
                Back to sign in
              </Link>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
