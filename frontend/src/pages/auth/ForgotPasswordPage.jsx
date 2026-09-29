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
          <h1 className="text-lg font-medium text-slate-900">إعادة تعيين كلمة المرور</h1>
        </div>

        {sent ? (
          <div className="bg-white border border-slate-200 rounded-xl p-5 text-center">
            <p className="text-sm text-slate-700">تحقق من بريدك الإلكتروني.</p>
            <p className="text-xs text-slate-500 mt-2">
              إذا وُجد حساب مربوط بـ <strong>{email}</strong>، فسيصلك رابط إعادة التعيين قريباً.
              يصلح الرابط لمدة 60 دقيقة فقط.
            </p>
            <Link
              to="/login"
              className="inline-block mt-4 text-xs font-medium text-brand-600 hover:underline"
            >
              العودة إلى تسجيل الدخول
            </Link>
          </div>
        ) : (
          <form
            onSubmit={handleSubmit}
            className="bg-white border border-slate-200 rounded-xl p-5 space-y-4"
          >
            <p className="text-xs text-slate-500">
              أدخل بريدك الإلكتروني وسنرسل لك رابطاً لاختيار كلمة مرور جديدة.
            </p>

            <Field label="البريد الإلكتروني" required errors={errors} field="email">
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
              إرسال رابط إعادة التعيين
            </Button>

            <div className="text-center">
              <Link to="/login" className="text-xs text-slate-500 hover:text-brand-600">
                العودة إلى تسجيل الدخول
              </Link>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}