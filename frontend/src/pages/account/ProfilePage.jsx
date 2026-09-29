import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { profileApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Field, Input, Textarea, FieldErrors,
} from '../../components/ui'
import { formatDate, relativeDays } from '../../lib/format'
import { roleLabel, roleTone } from '../../lib/permissions'

/**
 * Profile — the account holder's own details.
 *
 * Two distinct concerns live here: identity fields the user can edit, and
 * administrative fields (role, student ID, supervision capacity) that only a
 * coordinator or admin may change. The read-only ones are shown because a
 * student needs to be able to check what the system thinks their programme and
 * batch are, but they are never rendered as editable inputs.
 */
export default function ProfilePage() {
  const { user, refresh } = useAuth()

  const [profile, setProfile] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const [saved, setSaved] = useState(false)

  const [form, setForm] = useState({ name: '', email: '', phone: '', bio: '' })

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // profileApi.me() already unwraps the envelope and returns the
        // payload. Calling unwrap() again here would read `.data.data` off a
        // plain object, yield null, and crash on `data.name` below.
        const data = await profileApi.me()
        if (cancelled) return
        setProfile(data)
        setForm({
          name: data.name ?? '',
          email: data.email ?? '',
          phone: data.phone ?? '',
          bio: data.bio ?? '',
        })
      } catch (err) {
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [])

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setSaveError(null)
    setSaved(false)

    try {
      const payload = {
        name: form.name.trim(),
        phone: form.phone.trim() || null,
        bio: form.bio.trim() || null,
      }
      // Also already unwrapped by the API layer.
      const updated = await profileApi.update(payload)
      setProfile((prev) => ({ ...prev, ...(updated ?? payload) }))
      setEditing(false)
      setSaved(true)
      // Keep the shell's cached user in sync so the sidebar name updates.
      await refresh?.()
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setSaveError(err?.message ?? 'تعذّر حفظ ملفك الشخصي.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل ملفك الشخصي" />
  if (error) return <ErrorState error={error} />

  const p = profile ?? user ?? {}
  const role = p.role ?? user?.role

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="ملفي الشخصي"
        subtitle="بياناتك وإعدادات حسابك"
        actions={
          !editing ? (
            <Button onClick={() => setEditing(true)}>تعديل البيانات</Button>
          ) : null
        }
      />

      {saved && !editing && (
        <Card className="border-emerald-200 bg-emerald-50/50">
          <p className="text-sm text-emerald-900">تم تحديث ملفك الشخصي.</p>
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-5">
          <Avatar name={p.name} size="lg" />
          <div className="min-w-0 flex-1">
            <h2 className="text-lg font-semibold text-slate-900">{p.name}</h2>
            <p className="text-sm text-slate-500">{p.email}</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <Badge tone={roleTone(role)}>{roleLabel(role)}</Badge>
              {p.student_id && (
                <span className="font-mono text-xs text-slate-500">{p.student_id}</span>
              )}
              {p.staff_id && (
                <span className="font-mono text-xs text-slate-500">{p.staff_id}</span>
              )}
              {p.must_change_password && (
                <Badge tone="warning">يتطلب تغيير كلمة المرور</Badge>
              )}
            </div>
          </div>
        </div>
      </Card>

      {saveError && <ErrorState error={{ message: saveError }} />}

      <form onSubmit={save}>
        <Card>
          <CardHeader
            title="البيانات الشخصية"
            subtitle={editing ? undefined : 'للقراءة فقط — اختر «تعديل البيانات» لتغييرها'}
          />
          <div className="space-y-5">
            <Field label="الاسم الكامل" htmlFor="name" required error={errors?.name}>
              <Input
                id="name"
                value={form.name}
                onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                disabled={!editing}
                required
              />
            </Field>

            <Field
              label="البريد الإلكتروني"
              htmlFor="email"
              hint="تواصل مع المنسّق لتغيير البريد المسجّل على حسابك"
              error={errors?.email}
            >
              <Input id="email" type="email" value={form.email} disabled readOnly />
            </Field>

            <Field label="الهاتف" htmlFor="phone" error={errors?.phone}>
              <Input
                id="phone"
                value={form.phone}
                onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
                disabled={!editing}
                placeholder="+60 12-345 6789"
              />
            </Field>

            <Field
              label="نبذة مختصرة"
              htmlFor="bio"
              hint="ظاهرة للمشرفين والممتحنين في صفحات مشروعك"
              error={errors?.bio}
            >
              <Textarea
                id="bio"
                rows={4}
                value={form.bio}
                onChange={(e) => setForm((prev) => ({ ...prev, bio: e.target.value }))}
                disabled={!editing}
                placeholder="جملة أو جملتان عن اهتماماتك…"
              />
            </Field>

            <FieldErrors errors={errors} />
          </div>

          {editing && (
            <div className="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-5">
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setEditing(false)
                  setForm({
                    name: p.name ?? '',
                    email: p.email ?? '',
                    phone: p.phone ?? '',
                    bio: p.bio ?? '',
                  })
                  setErrors(null)
                }}
              >
                إلغاء
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}
              </Button>
            </div>
          )}
        </Card>
      </form>

      {/* بيانات التسجيل حسب الدور — للقراءة فقط بالتصميم. */}
      {role === 'student' && (
        <Card>
          <CardHeader
            title="البيانات الأكاديمية"
            subtitle="يحدّثها المنسّق — تواصل معه لتصحيح أي شيء هنا"
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <ReadOnly label="رقم الطالب" value={p.student_id} mono />
            <ReadOnly label="البرنامج" value={p.program ?? p.student_profile?.program} />
            <ReadOnly label="الدفعة" value={p.batch ?? p.student_profile?.batch} />
            <ReadOnly
              label="الفصل الدراسي"
              value={p.current_semester ?? p.student_profile?.current_semester}
            />
            <ReadOnly label="جزء المشروع" value={p.psm_part ?? p.student_profile?.psm_part} />
            <ReadOnly
              label="تاريخ الالتحاق"
              value={formatDate(p.created_at, { fallback: '—' })}
            />
          </div>
        </Card>
      )}

      {['supervisor', 'examiner'].includes(role) && (
        <Card>
          <CardHeader
            title="بيانات الإشراف"
            subtitle="يحدّثها المنسّق"
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <ReadOnly label="رقم الموظف" value={p.staff_id} mono />
            <ReadOnly
              label="الحد الأقصى للمشرفين عليهم"
              value={p.max_supervisees ?? p.supervisor_profile?.max_supervisees}
            />
            <ReadOnly
              label="العبء الحالي"
              value={p.current_load ?? p.supervisor_profile?.current_load}
            />
            <ReadOnly
              label="يقبل طلابًا جددًا"
              value={
                (p.is_accepting_students ?? p.supervisor_profile?.is_accepting_students)
                  ? 'نعم'
                  : 'لا'
              }
            />
            <ReadOnly label="يمكنه الامتحان" value={p.can_examine ? 'نعم' : 'لا'} />
            <ReadOnly
              label="الخبرات"
              value={(p.expertise ?? []).join(', ') || '—'}
            />
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="الأمان" />
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-slate-800">كلمة المرور</p>
              <p className="text-sm text-slate-500">
                {p.password_changed_at
                  ? `آخر تغيير قبل ${relativeDays(p.password_changed_at)}`
                  : 'غيّر كلمة المرور بانتظام'}
              </p>
            </div>
            <Link to="/change-password">
              <Button variant="secondary">تغيير كلمة المرور</Button>
            </Link>
          </div>

          {p.last_login_at && (
            <div className="border-t border-slate-100 pt-4">
              <p className="text-sm font-medium text-slate-800">آخر تسجيل دخول</p>
              <p className="text-sm text-slate-500">
                {formatDate(p.last_login_at, { fallback: 'غير معروف' })}
                {p.last_login_ip && ` من ${p.last_login_ip}`}
              </p>
            </div>
          )}

          <div className="border-t border-slate-100 pt-4">
            <p className="text-sm font-medium text-slate-800">تسجيل الخروج</p>
            <p className="text-sm text-slate-500">
              تسجيل الخروج يلغي الرمز لهذا المتصفح فقط. إذا سجّلت الدخول على جهاز
              مشترك أو عام، فسجّل الخروج منه كذلك.
            </p>
            <div className="mt-3">
              <SignOutNow />
            </div>
          </div>
        </div>
      </Card>

      {!p.name && (
        <Card>
          <EmptyState
            title="ملف شخصي غير مكتمل"
            message="لا يوجد اسم مسجّل على حسابك. أضف اسمًا ليتمكن المشرفون من التعرّف عليك."
          />
        </Card>
      )}
    </div>
  )
}

function ReadOnly({ label, value, mono = false }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-0.5 text-sm text-slate-700 ${mono ? 'font-mono' : ''}`}>
        {value ?? '—'}
      </p>
    </div>
  )
}

/**
 * Sign out from here.
 *
 * The API revokes only the token presented with the request, so this cannot
 * offer "sign out everywhere" — that would need a server route that revokes
 * every token for the user. Rather than fake it, this does the honest thing
 * and tells the user to sign out on other devices themselves.
 */
function SignOutNow() {
  const { logout } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function signOut() {
    setBusy(true)
    setError(null)
    try {
      await logout()
      // logout() clears the token; a full navigation guarantees every cached
      // screen is discarded rather than left in memory.
      window.location.href = '/login'
    } catch (err) {
      setError(err?.message ?? 'تعذّر تسجيل خروجك.')
      setBusy(false)
    }
  }

  return (
    <div className="space-y-2">
      {error && <p className="text-sm text-rose-700">{error}</p>}
      <Button variant="secondary" disabled={busy} onClick={signOut}>
        {busy ? 'جارٍ تسجيل الخروج…' : 'تسجيل الخروج من هذا المتصفح'}
      </Button>
    </div>
  )
}
