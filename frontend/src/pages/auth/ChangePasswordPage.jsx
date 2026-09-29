import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { authApi } from '../../api/auth'
import { Button, Card, Field, Input, PageHeader } from '../../components/ui'

/**
 * Module 1 — change password.
 *
 * Reached two ways: voluntarily from the profile, or forcibly by the
 * `first.login` middleware when an administrator issued a temporary password.
 * The forced case is detected from the user record so the copy can explain
 * why they are here.
 */
export default function ChangePasswordPage() {
  const { user, homeRoute, logout } = useAuth()
  const navigate = useNavigate()

  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState(null)
  const [message, setMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const forced = Boolean(user?.must_change_password)

  async function handleSubmit(event) {
    event.preventDefault()

    setSubmitting(true)
    setErrors(null)
    setMessage(null)

    try {
      await authApi.changePassword({
        current_password: current,
        password,
        password_confirmation: confirmation,
      })

      navigate(forced ? homeRoute : '/profile', {
        replace: true,
        state: { message: 'Password updated.' },
      })
    } catch (err) {
      setErrors(err.errors)
      setMessage(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className={forced ? 'min-h-screen bg-slate-50 flex items-center justify-center px-4' : ''}>
      <div className={forced ? 'w-full max-w-sm' : 'max-w-lg'}>
        {forced ? (
          <div className="text-center mb-6">
            <h1 className="text-lg font-medium text-slate-900">تعيين كلمة مرور جديدة</h1>
            <p className="text-sm text-slate-500 mt-1">
              حسابك يستخدم كلمة مرور مؤقتة. اختر كلمة مرور جديدة للمتابعة.
            </p>
          </div>
        ) : (
          <PageHeader
            title="تغيير كلمة المرور"
            subtitle="ستبقى مسجلاً الدخول على هذا الجهاز."
          />
        )}

        <Card>
          <form onSubmit={handleSubmit} className="space-y-4">
            {message && (
              <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                <p className="text-xs text-rose-800">{message}</p>
              </div>
            )}

            <Field label="كلمة المرور الحالية" required errors={errors} field="current_password">
              <Input
                type="password"
                autoComplete="current-password"
                autoFocus
                required
                value={current}
                onChange={(e) => setCurrent(e.target.value)}
              />
            </Field>

            <Field
              label="كلمة المرور الجديدة"
              required
              errors={errors}
              field="password"
              hint="8 أحرف على الأقل. تجنّب إعادة استخدام كلمة مرور من موقع آخر."
            >
              <Input
                type="password"
                autoComplete="new-password"
                required
                minLength={8}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </Field>

            <Field
              label="تأكيد كلمة المرور الجديدة"
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

            <div className="flex items-center gap-3 pt-1">
              <Button type="submit" loading={submitting}>
                تحديث كلمة المرور
              </Button>

              {!forced && (
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => navigate(-1)}
                >
                  إلغاء
                </Button>
              )}

              {forced && (
                <Button type="button" variant="ghost" onClick={logout}>
                  تسجيل الخروج بدلاً من ذلك
                </Button>
              )}
            </div>
          </form>
        </Card>
      </div>
    </div>
  )
}