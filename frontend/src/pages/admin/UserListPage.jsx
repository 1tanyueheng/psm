import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { userApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input, Field,
} from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { roleLabel, roleTone } from '../../lib/permissions'

/**
 * The roles an admin can create, with what each one is for.
 *
 * There is no `examiner`: a panel is drawn from the people who supervise, so a
 * supervisor supervises their own students and may also be seated on someone
 * else's panel. Which *form* they fill is decided by the assessment, not by the
 * account.
 */
const ROLES = [
  {
    value: 'student',
    label: 'Student',
    hint: 'Registers a project — needs a matric number, a programme and a batch.',
  },
  {
    value: 'supervisor',
    label: 'Supervisor',
    hint: 'Supervises students, and may be seated on a panel. Needs a staff number.',
  },
  {
    value: 'coordinator',
    label: 'Coordinator',
    hint: 'Runs a batch: terms, allocation, assessment windows.',
  },
  {
    value: 'admin',
    label: 'Administrator',
    hint: 'Manages accounts and system settings.',
  },
]

function blankForm() {
  return {
    role: 'student',
    name: '', email: '', phone: '', department: '',
    student_id: '', program: '', program_code: '', batch: '', faculty: '',
    academic_semester_id: '',
    staff_no: '', academic_title: '', max_supervisees: '',
  }
}

/**
 * Send only the fields the chosen role actually uses.
 *
 * The API validates per role, so posting the whole form would fail a student for
 * a missing `staff_no` it never needed. Blank optional strings are dropped
 * rather than sent as `''`, so the server's `nullable` rules see "absent".
 */
function payloadFor(form) {
  const base = {
    role: form.role,
    name: form.name.trim(),
    email: form.email.trim(),
    phone: form.phone.trim() || undefined,
    department: form.department.trim() || undefined,
  }

  if (form.role === 'student') {
    return {
      ...base,
      student_id: form.student_id.trim(),
      program: form.program.trim(),
      batch: form.batch.trim(),
      program_code: form.program_code.trim() || undefined,
      faculty: form.faculty.trim() || undefined,
      /**
       * The term the student is enrolling into.
       *
       * This is the field the intake turns on. It is what Lampiran A is gated
       * against (`SemesterService::registrationGate`), what
       * `AssignmentService` capacity-checks a supervisor against, and what
       * makes a student appear in a term's cohort at all. The API defaults it
       * to the active term when omitted, which is why its absence was invisible
       * — an admin could not put the new intake into a term that was not the
       * active one, and could not see which term they had landed in either.
       */
      academic_semester_id: form.academic_semester_id === ''
        ? undefined
        : Number(form.academic_semester_id),
    }
  }

  if (form.role === 'supervisor') {
    return {
      ...base,
      staff_no: form.staff_no.trim(),
      academic_title: form.academic_title.trim() || undefined,
      max_supervisees: form.max_supervisees === '' ? undefined : Number(form.max_supervisees),
    }
  }

  return base
}

/**
 * Module 2 — user administration.
 *
 * The job of this screen is account lifecycle: an admin arrives here to add an
 * account, find one, unblock it, or issue a password reset.
 *
 * Two deliberate omissions:
 *
 *  - There is no delete button. `DELETE /users/{id}` soft-deletes, which in a
 *    system with an audit trail and archived projects is rarely the right
 *    answer. Deactivating is reversible; deleting hides a student's history.
 *  - The create form does **not** ask for a password. New accounts start on the
 *    system default (`psm.default_user_password`) and the holder resets it from
 *    the sign-in screen — the admin only ever holds an address, so there is
 *    nothing to choose and nothing to deliver.
 */
export default function UserListPage() {
  const { user: me } = useAuth()
  const { semesters, active, selectedId, selectSemester } = useSemesters()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)

  // The add-user panel.
  const [creating, setCreating] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(blankForm())
  const [formErrors, setFormErrors] = useState({})

  const role = params.get('role') ?? ''
  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next, { replace: true })
  }

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await userApi.list({
        role: role || undefined,
        status: status || undefined,
        q: search || undefined,
        per_page: 100,
      })
      const { items, meta: pageMeta } = unwrapPaged(res)
      setRows(items)
      setMeta(pageMeta)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [role, status, search])

  useEffect(() => {
    load()
  }, [load])

  /**
   * Run one account action, then refresh.
   *
   * Every action here changes server state that other people depend on
   * (a suspended supervisor stops being assignable), so the list is reloaded
   * rather than patched locally — the response is the source of truth.
   */
  async function act(key, fn, successMessage) {
    setBusy(key)
    setActionError(null)
    setNotice(null)
    try {
      await fn()
      setNotice(successMessage)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'That action failed.')
    } finally {
      setBusy(null)
    }
  }

  /**
   * Create an account, then refresh.
   *
   * Field-level 422s are mapped back onto the form rather than shown as a
   * banner, so the message lands beside the field that caused it — the server is
   * the authority on which role needs which field.
   */
  async function submitCreate() {
    setSaving(true)
    setFormErrors({})
    setActionError(null)
    setNotice(null)

    try {
      const created = await userApi.create(payloadFor(form))

      setCreating(false)
      setForm(blankForm())
      setNotice(
        `Account created for ${created?.email ?? form.email}. It starts on the system default `
        + 'password — tell the holder to reset it from the sign-in screen.',
      )
      await load()
    } catch (err) {
      if (err?.errors) setFormErrors(err.errors)
      else setActionError(err?.message ?? 'That account could not be created.')
    } finally {
      setSaving(false)
    }
  }

  const counts = useMemo(() => {
    const out = { total: meta?.total ?? rows.length, suspended: 0, pending: 0 }
    for (const row of rows) {
      if (row.is_active === false || row.status === 'suspended') out.suspended += 1
      if (row.status === 'pending' || row.must_change_password) out.pending += 1
    }
    return out
  }, [rows, meta])

  if (loading && rows.length === 0) return <Spinner label="Loading accounts" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="User accounts"
        subtitle={
          meta?.total != null ? `${meta.total} account${meta.total === 1 ? '' : 's'}` : undefined
        }
        action={
          // Only an admin may create accounts (`UserPolicy::create`), even though
          // coordinators hold `canManageUsers` for profile work.
          me?.role === 'admin' ? (
            <Button
              onClick={() => {
                setCreating((open) => !open)
                setFormErrors({})
              }}
            >
              {creating ? 'Close' : 'Add user'}
            </Button>
          ) : null
        }
      />

      {creating && (
        <CreateUserPanel
          form={form}
          setForm={setForm}
          errors={formErrors}
          saving={saving}
          semesters={semesters}
          defaultSemesterId={active?.id ?? selectedId ?? ''}
          onCancel={() => {
            setCreating(false)
            setForm(blankForm())
            setFormErrors({})
          }}
          onSubmit={submitCreate}
        />
      )}

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && <ErrorState error={{ message: actionError }} />}

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Accounts</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.total}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Suspended</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.suspended}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Awaiting sign-in</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.pending}
          </p>
        </Card>
      </div>

      <Card>
        <CardHeader title="Filter" />
        <div className="grid gap-3 sm:grid-cols-3">
          <Input
            type="search"
            placeholder="Name, email or staff number"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="Search accounts"
          />
          <Select
            value={role}
            onChange={(e) => setFilter('role', e.target.value)}
            aria-label="Filter by role"
          >
            <option value="">All roles</option>
            <option value="student">Students</option>
            <option value="supervisor">Supervisors</option>
            <option value="coordinator">Coordinators</option>
            <option value="admin">Administrators</option>
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Invited, not yet signed in</option>
          </Select>
        </div>
      </Card>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No accounts match"
            description="Adjust the filters, or check that the accounts have been imported for this session."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={['Name', 'Role', 'Identifier', 'Status', 'Last signed in', 'Actions']}
            >
              {rows.map((row) => {
                const suspended = row.is_active === false || row.status === 'suspended'
                const isSelf = row.id === me?.id

                return (
                  <tr key={row.id} className="hover:bg-slate-50/60">
                    <Td>
                      <div className="flex items-center gap-2">
                        <Avatar name={row.name} size="sm" />
                        <div className="min-w-0">
                          <div className="truncate text-sm font-medium text-slate-800">
                            {row.name}
                            {isSelf && (
                              <span className="ml-1.5 text-xs font-normal text-slate-400">
                                you
                              </span>
                            )}
                          </div>
                          <div className="truncate text-xs text-slate-500">{row.email}</div>
                        </div>
                      </div>
                    </Td>
                    <Td>
                      <Badge tone={roleTone(row.role)}>{roleLabel(row.role)}</Badge>
                    </Td>
                    <Td className="font-mono text-xs text-slate-500">
                      {row.student_id ?? row.staff_no ?? '—'}
                    </Td>
                    <Td>
                      <Badge tone={suspended ? 'danger' : 'success'}>
                        {suspended ? 'suspended' : (row.status ?? 'active')}
                      </Badge>
                      {row.must_change_password && (
                        <div className="mt-0.5 text-xs text-amber-600">
                          must change password
                        </div>
                      )}
                    </Td>
                    <Td className="text-sm text-slate-600">
                      {row.last_login_at ? (
                        <>
                          <div>{formatDate(row.last_login_at)}</div>
                          <div className="text-xs text-slate-400">
                            {formatDateTime(row.last_login_at)}
                          </div>
                        </>
                      ) : (
                        <span className="text-slate-400">never</span>
                      )}
                    </Td>
                    <Td>
                      <div className="flex flex-wrap gap-1.5">
                        {/*
                          Deactivating yourself would lock you out of the very
                          screen you would use to undo it. The API permits it;
                          this UI declines to offer it.
                        */}
                        <Button
                          size="sm"
                          variant="secondary"
                          disabled={isSelf || busy === `toggle-${row.id}`}
                          onClick={() =>
                            act(
                              `toggle-${row.id}`,
                              () =>
                                suspended ? userApi.reactivate(row.id) : userApi.deactivate(row.id),
                              suspended
                                ? `${row.name} can sign in again.`
                                : `${row.name} can no longer sign in.`,
                            )
                          }
                        >
                          {busy === `toggle-${row.id}`
                            ? 'Working…'
                            : suspended
                              ? 'Reactivate'
                              : 'Suspend'}
                        </Button>

                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={busy === `reset-${row.id}`}
                          onClick={() =>
                            act(
                              `reset-${row.id}`,
                              () => userApi.sendPasswordReset(row.id),
                              `Password reset for ${row.name} will receive an email.`,
                            )
                          }
                        >
                          {busy === `reset-${row.id}` ? 'Sending…' : 'Reset password'}
                        </Button>

                        {row.locked_at && (
                          <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy === `unlock-${row.id}`}
                            onClick={() =>
                              act(
                                `unlock-${row.id}`,
                                () => userApi.unlock(row.id),
                                `${row.name} is unlocked.`,
                              )
                            }
                          >
                            Unlock
                          </Button>
                        )}
                      </div>
                    </Td>
                  </tr>
                )
              })}
            </DataTable>
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="What these actions do" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            <span className="font-medium text-slate-800">Suspend</span> blocks sign-in but keeps
            the account, its projects and its audit history intact. It is reversible.
          </li>
          <li>
            <span className="font-medium text-slate-800">Reset password</span> emails a
            single-use link to the account holder. No administrator ever sees or sets a password,
            so the change stays attributable.
          </li>
          <li>
            <span className="font-medium text-slate-800">Unlock</span> clears a lockout from
            repeated failed sign-ins. It appears only for accounts that are actually locked.
          </li>
        </ul>
        <p className="mt-3 text-xs text-slate-500">
          Accounts are never deleted from this screen. A student&rsquo;s record is needed by the
          archive and the audit trail long after they graduate.
        </p>
      </Card>
    </div>
  )
}

/**
 * The add-user form.
 *
 * Role first: the section below it swaps, so the admin is never asked for a
 * staff number while creating a student. Switching roles resets the role-specific
 * fields, so a value typed for one role cannot be submitted for another.
 */
function CreateUserPanel({
  form, setForm, errors, saving, semesters, defaultSemesterId, onCancel, onSubmit,
}) {
  const set = (key) => (event) => setForm((prev) => ({ ...prev, [key]: event.target.value }))

  function changeRole(event) {
    const role = event.target.value
    setForm((prev) => ({
      ...blankForm(),
      // Keep what the role switch cannot invalidate.
      role,
      name: prev.name,
      email: prev.email,
      phone: prev.phone,
      department: prev.department,
      // The chosen term survives a role change: an admin flipping to student
      // to check a field should not silently lose the term they picked.
      academic_semester_id: prev.academic_semester_id || defaultSemesterId,
    }))
  }

  const role = ROLES.find((r) => r.value === form.role)

  // Seed the term on first render. Doing it here rather than in the parent's
  // state keeps `blankForm()` free of a value the parent may not have yet —
  // the term list is still loading when the panel first mounts.
  const semesterValue = form.academic_semester_id || defaultSemesterId || ''

  return (
    <Card>
      <CardHeader
        title="Add a user"
        subtitle="The account starts on the system default password; the holder resets it from the sign-in screen"
      />
      <div className="space-y-5">
        <Field label="Role" htmlFor="new_role" required errors={errors} field="role">
          <Select id="new_role" value={form.role} onChange={changeRole}>
            {ROLES.map((r) => (
              <option key={r.value} value={r.value}>{r.label}</option>
            ))}
          </Select>
          {role?.hint && <p className="mt-1 text-xs text-slate-400">{role.hint}</p>}
        </Field>

        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="Full name" htmlFor="new_name" required errors={errors} field="name">
            <Input id="new_name" value={form.name} onChange={set('name')} maxLength={255} />
          </Field>
          <Field
            label="Email"
            htmlFor="new_email"
            required
            errors={errors}
            field="email"
            hint="Their institutional address — the password reset goes here"
          >
            <Input id="new_email" type="email" value={form.email} onChange={set('email')} maxLength={255} />
          </Field>
          <Field label="Phone" htmlFor="new_phone" errors={errors} field="phone">
            <Input id="new_phone" value={form.phone} onChange={set('phone')} maxLength={32} />
          </Field>
          <Field label="Department" htmlFor="new_department" errors={errors} field="department">
            <Input id="new_department" value={form.department} onChange={set('department')} maxLength={255} />
          </Field>
        </div>

        {form.role === 'student' && (
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Matric number" htmlFor="new_student_id" required errors={errors} field="student_id">
              <Input id="new_student_id" value={form.student_id} onChange={set('student_id')} maxLength={32} />
            </Field>
            <Field label="Programme" htmlFor="new_program" required errors={errors} field="program">
              <Input id="new_program" value={form.program} onChange={set('program')} maxLength={128} />
            </Field>
            <Field
              label="Batch"
              htmlFor="new_batch"
              required
              errors={errors}
              field="batch"
              hint="The cohort year, e.g. 2026"
            >
              <Input id="new_batch" value={form.batch} onChange={set('batch')} maxLength={32} />
            </Field>

            {/*
              The enrolling term. Not decoration: it gates Lampiran A, it is what
              the supervisor capacity check counts against, and it is how the
              student appears in a term's cohort. Defaults to the active term,
              and is overridable for a late or re-enrolling student.
            */}
            <Field
              label="Enrolling semester"
              htmlFor="new_semester"
              errors={errors}
              field="academic_semester_id"
              hint={
                semesters?.length
                  ? 'The term this intake belongs to. Defaults to the active one.'
                  : 'No semesters exist yet — create one before adding students.'
              }
            >
              <Select
                id="new_semester"
                value={semesterValue}
                onChange={set('academic_semester_id')}
                disabled={!semesters?.length}
              >
                {!semesters?.length && <option value="">No semesters yet</option>}
                {(semesters ?? []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                    {s.is_active ? ' — active' : ''}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Programme code" htmlFor="new_program_code" errors={errors} field="program_code">
              <Input
                id="new_program_code"
                value={form.program_code}
                onChange={set('program_code')}
                maxLength={32}
                placeholder="CS240"
              />
            </Field>
            <Field label="Faculty" htmlFor="new_faculty" errors={errors} field="faculty">
              <Input id="new_faculty" value={form.faculty} onChange={set('faculty')} maxLength={255} />
            </Field>
          </div>
        )}

        {form.role === 'supervisor' && (
          <div className="grid gap-5 sm:grid-cols-2">
            <Field
              label="Staff number"
              htmlFor="new_staff_no"
              required
              errors={errors}
              field="staff_no"
              hint="Recorded once and used everywhere"
            >
              <Input id="new_staff_no" value={form.staff_no} onChange={set('staff_no')} maxLength={32} />
            </Field>
            <Field label="Academic title" htmlFor="new_academic_title" errors={errors} field="academic_title">
              <Input
                id="new_academic_title"
                value={form.academic_title}
                onChange={set('academic_title')}
                maxLength={64}
                placeholder="Dr."
              />
            </Field>
            <Field
              label="Supervision capacity"
              htmlFor="new_max_supervisees"
              errors={errors}
              field="max_supervisees"
              hint="Students they may supervise. Defaults to the faculty limit."
            >
              <Input
                id="new_max_supervisees"
                type="number"
                min="0"
                max="50"
                value={form.max_supervisees}
                onChange={set('max_supervisees')}
              />
            </Field>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onCancel} disabled={saving}>
            Cancel
          </Button>
          <Button
            onClick={onSubmit}
            disabled={saving || !form.name.trim() || !form.email.trim()}
          >
            {saving ? 'Creating…' : 'Create account'}
          </Button>
        </div>
      </div>
    </Card>
  )
}
