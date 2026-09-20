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
      else setSaveError(err?.message ?? 'Could not save your profile.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="Loading your profile" />
  if (error) return <ErrorState error={error} />

  const p = profile ?? user ?? {}
  const role = p.role ?? user?.role

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="My profile"
        subtitle="Your details and account settings"
        actions={
          !editing ? (
            <Button onClick={() => setEditing(true)}>Edit details</Button>
          ) : null
        }
      />

      {saved && !editing && (
        <Card className="border-emerald-200 bg-emerald-50/50">
          <p className="text-sm text-emerald-900">Your profile has been updated.</p>
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
                <Badge tone="warning">password change required</Badge>
              )}
            </div>
          </div>
        </div>
      </Card>

      {saveError && <ErrorState error={{ message: saveError }} />}

      <form onSubmit={save}>
        <Card>
          <CardHeader
            title="Personal details"
            subtitle={editing ? undefined : 'Read-only — choose Edit details to change them'}
          />
          <div className="space-y-5">
            <Field label="Full name" htmlFor="name" required error={errors?.name}>
              <Input
                id="name"
                value={form.name}
                onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                disabled={!editing}
                required
              />
            </Field>

            <Field
              label="Email address"
              htmlFor="email"
              hint="Contact a coordinator to change the address on your account"
              error={errors?.email}
            >
              <Input id="email" type="email" value={form.email} disabled readOnly />
            </Field>

            <Field label="Phone" htmlFor="phone" error={errors?.phone}>
              <Input
                id="phone"
                value={form.phone}
                onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
                disabled={!editing}
                placeholder="+60 12-345 6789"
              />
            </Field>

            <Field
              label="Short bio"
              htmlFor="bio"
              hint="Visible to supervisors and examiners on your project pages"
              error={errors?.bio}
            >
              <Textarea
                id="bio"
                rows={4}
                value={form.bio}
                onChange={(e) => setForm((prev) => ({ ...prev, bio: e.target.value }))}
                disabled={!editing}
                placeholder="A sentence or two about your interests…"
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
                Cancel
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? 'Saving…' : 'Save changes'}
              </Button>
            </div>
          )}
        </Card>
      </form>

      {/* Role-specific enrolment data — read-only by design. */}
      {role === 'student' && (
        <Card>
          <CardHeader
            title="Academic details"
            subtitle="Maintained by the coordinator — contact them to correct anything here"
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <ReadOnly label="Student ID" value={p.student_id} mono />
            <ReadOnly label="Programme" value={p.program ?? p.student_profile?.program} />
            <ReadOnly label="Batch" value={p.batch ?? p.student_profile?.batch} />
            <ReadOnly
              label="Semester"
              value={p.current_semester ?? p.student_profile?.current_semester}
            />
            <ReadOnly label="PSM part" value={p.psm_part ?? p.student_profile?.psm_part} />
            <ReadOnly
              label="Enrolled"
              value={formatDate(p.created_at, { fallback: '—' })}
            />
          </div>
        </Card>
      )}

      {['supervisor', 'examiner'].includes(role) && (
        <Card>
          <CardHeader
            title="Supervision details"
            subtitle="Maintained by the coordinator"
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <ReadOnly label="Staff ID" value={p.staff_id} mono />
            <ReadOnly
              label="Max supervisees"
              value={p.max_supervisees ?? p.supervisor_profile?.max_supervisees}
            />
            <ReadOnly
              label="Current load"
              value={p.current_load ?? p.supervisor_profile?.current_load}
            />
            <ReadOnly
              label="Accepting students"
              value={
                (p.is_accepting_students ?? p.supervisor_profile?.is_accepting_students)
                  ? 'Yes'
                  : 'No'
              }
            />
            <ReadOnly label="Can examine" value={p.can_examine ? 'Yes' : 'No'} />
            <ReadOnly
              label="Expertise"
              value={(p.expertise ?? []).join(', ') || '—'}
            />
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="Security" />
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-slate-800">Password</p>
              <p className="text-sm text-slate-500">
                {p.password_changed_at
                  ? `Last changed ${relativeDays(p.password_changed_at)}`
                  : 'Change your password regularly'}
              </p>
            </div>
            <Link to="/change-password">
              <Button variant="secondary">Change password</Button>
            </Link>
          </div>

          {p.last_login_at && (
            <div className="border-t border-slate-100 pt-4">
              <p className="text-sm font-medium text-slate-800">Last sign-in</p>
              <p className="text-sm text-slate-500">
                {formatDate(p.last_login_at, { fallback: 'Unknown' })}
                {p.last_login_ip && ` from ${p.last_login_ip}`}
              </p>
            </div>
          )}

          <div className="border-t border-slate-100 pt-4">
            <p className="text-sm font-medium text-slate-800">Sign out</p>
            <p className="text-sm text-slate-500">
              Signing out revokes the token for this browser only. If you have signed in on
              a shared or public computer, sign out there too.
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
            title="Profile incomplete"
            message="Your account has no name recorded. Please add one so supervisors can identify you."
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
      setError(err?.message ?? 'Could not sign you out.')
      setBusy(false)
    }
  }

  return (
    <div className="space-y-2">
      {error && <p className="text-sm text-rose-700">{error}</p>}
      <Button variant="secondary" disabled={busy} onClick={signOut}>
        {busy ? 'Signing out…' : 'Sign out of this browser'}
      </Button>
    </div>
  )
}
