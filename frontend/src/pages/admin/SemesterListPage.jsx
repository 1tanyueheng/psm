import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { semesterApi } from '../../api/endpoints'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  DataTable,
  EmptyState,
  ErrorState,
  Field,
  Input,
  PageHeader,
  Select,
  Spinner,
  Td,
} from '../../components/ui'
import { useSemesters } from '../../context/SemesterContext'
import { formatDate } from '../../lib/format'

/**
 * Semester management (Module 3).
 *
 * The term is what owns both PSM batches, so this is the screen that decides
 * whether either is open: registration gates Lampiran A, and mark release
 * gates every mark in the term. Both are deliberate, reversible actions with
 * their own endpoints rather than fields on a form, because both publish
 * something to students.
 *
 * The per-batch columns come straight from `stats.by_part`, which the server
 * computes — the coordinator should not have to add PSM1 and PSM2 totals in
 * their head to know whether the term is balanced.
 */
export default function SemesterListPage() {
  const { semesters, loading, error, refresh, selectSemester, selectedId } = useSemesters()

  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState(blankForm())
  const [formErrors, setFormErrors] = useState({})
  const [saving, setSaving] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [notice, setNotice] = useState(null)
  const [actionError, setActionError] = useState(null)

  useEffect(() => {
    if (!notice) return
    const timer = setTimeout(() => setNotice(null), 4000)
    return () => clearTimeout(timer)
  }, [notice])

  async function run(semesterId, action, successMessage) {
    setBusyId(semesterId)
    setActionError(null)

    try {
      await action()
      await refresh()
      setNotice({ tone: 'success', message: successMessage })
    } catch (err) {
      setActionError(err?.message ?? 'The action could not be completed.')
    } finally {
      setBusyId(null)
    }
  }

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setFormErrors({})

    try {
      await semesterApi.create({
        name: form.name || null,
        academic_session: form.academic_session.trim(),
        semester_number: Number(form.semester_number),
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        // A new term starts active unless told otherwise — creating the
        // semester you are about to teach is the normal case.
        is_active: form.is_active === 'yes',
      })
      await refresh()
      setForm(blankForm())
      setCreating(false)
      setNotice({ tone: 'success', message: 'Semester created.' })
    } catch (err) {
      setFormErrors(err?.errors ?? {})
      setActionError(err?.message ?? 'The semester could not be created.')
    } finally {
      setSaving(false)
    }
  }

  if (loading && semesters.length === 0) return <Spinner label="Loading semesters" />
  if (error && semesters.length === 0) {
    return <ErrorState message={error?.message ?? error} />
  }

  const openTerm = semesters.find((s) => s.is_active)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Semesters"
        subtitle="Each semester runs PSM 1 and PSM 2 concurrently, from the same pool of staff."
        action={
          <Button onClick={() => setCreating((value) => !value)}>
            {creating ? 'Cancel' : 'New semester'}
          </Button>
        }
      />

      {notice && (
        <div
          role="status"
          className={`rounded-lg border px-3 py-2 text-sm ${
            notice.tone === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
              : 'border-rose-200 bg-rose-50 text-rose-800'
          }`}
        >
          {notice.message}
        </div>
      )}

      {actionError && <ErrorState message={actionError} />}

      {!openTerm && semesters.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          No semester is active. Students cannot register and the cohort screens
          have no term to scope to until one is.
        </div>
      )}

      {creating && (
        <Card>
          <CardHeader title="New semester" subtitle="PSM 1 and PSM 2 share this term." />
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Session"
              required
              field="academic_session"
              errors={formErrors}
              hint="e.g. 2026/2027"
            >
              <Input
                name="academic_session"
                value={form.academic_session}
                onChange={(e) => setForm({ ...form, academic_session: e.target.value })}
                placeholder="2026/2027"
                required
              />
            </Field>

            <Field label="Semester" required field="semester_number" errors={formErrors}>
              <Select
                name="semester_number"
                value={form.semester_number}
                onChange={(e) => setForm({ ...form, semester_number: e.target.value })}
              >
                <option value="1">Semester I</option>
                <option value="2">Semester II</option>
              </Select>
            </Field>

            <Field
              label="Display name"
              field="name"
              errors={formErrors}
              hint="Left blank, it is generated as “Session Semester N”."
            >
              <Input
                name="name"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="2026/2027 Semester I"
              />
            </Field>

            <Field label="Activate now" field="is_active" errors={formErrors}>
              <Select
                name="is_active"
                value={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.value })}
              >
                <option value="yes">Yes — this is the current semester</option>
                <option value="no">No — create it for later</option>
              </Select>
            </Field>

            <Field label="Starts" field="starts_at" errors={formErrors}>
              <Input
                type="date"
                name="starts_at"
                value={form.starts_at}
                onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
              />
            </Field>

            <Field label="Ends" field="ends_at" errors={formErrors}>
              <Input
                type="date"
                name="ends_at"
                value={form.ends_at}
                onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
              />
            </Field>

            {/* Form-level errors. `FieldErrors` only renders for a named field,
                so anything without one — a cross-field rule such as
                `ends_at` before `starts_at` — is surfaced here instead of
                being dropped. */}
            {formErrors && Object.keys(formErrors).length > 0 && (
              <div className="sm:col-span-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                <p className="text-sm font-medium text-rose-800">
                  The semester was not created
                </p>
                <ul className="mt-1 space-y-0.5">
                  {Object.entries(formErrors).map(([key, messages]) => (
                    <li key={key} className="text-xs text-rose-700">
                      {(Array.isArray(messages) ? messages : [messages]).join(' ')}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="sm:col-span-2">
              <Button type="submit" loading={saving}>
                Create semester
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Card>
        <CardHeader
          title="All semesters"
          subtitle={
            openTerm
              ? `${openTerm.name} is active. Registration and mark release are set per term.`
              : 'Registration and mark release are set per term.'
          }
        />

        {semesters.length === 0 ? (
          <EmptyState
            title="No semesters yet"
            message="Create a term to open registration for Lampiran A."
            action={
              <Button onClick={() => setCreating(true)}>Create the first semester</Button>
            }
          />
        ) : (
          <DataTable
            columns={[
              { key: 'name', label: 'Semester' },
              { key: 'dates', label: 'Dates' },
              { key: 'psm1', label: 'PSM 1' },
              { key: 'psm2', label: 'PSM 2' },
              { key: 'registration', label: 'Registration' },
              { key: 'marks', label: 'Marks' },
              { key: 'actions', label: 'Actions' },
            ]}
            rows={semesters}
            render={(row) => {
              const busy = busyId === row.id
              const selected = String(row.id) === String(selectedId)

              return (
                <tr key={row.id} className={selected ? 'bg-brand-50/40' : undefined}>
                  <Td>
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-800">{row.name}</span>
                      {row.is_active && <Badge tone="success">Active</Badge>}
                      {row.is_closed && <Badge tone="neutral">Closed</Badge>}
                      {selected && <Badge tone="brand">Viewing</Badge>}
                    </div>
                    <div className="text-xs text-slate-400">
                      {row.academic_session} · Semester {row.semester_number}
                    </div>
                  </Td>

                  <Td className="text-xs text-slate-500">
                    {row.starts_at || row.ends_at ? (
                      <>
                        {formatDate(row.starts_at)}
                        <br />
                        {formatDate(row.ends_at)}
                      </>
                    ) : (
                      '—'
                    )}
                  </Td>

                  <PartCell stats={row.stats?.by_part?.PSM1} />
                  <PartCell stats={row.stats?.by_part?.PSM2} />

                  <Td>
                    {row.is_closed ? (
                      <Badge tone="neutral">Term closed</Badge>
                    ) : row.registration_open ? (
                      <Badge tone="success">Open</Badge>
                    ) : (
                      <Badge tone="warning">Closed</Badge>
                    )}
                    <div className="mt-1">
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy || row.is_closed}
                        onClick={() =>
                          run(
                            row.id,
                            () => semesterApi.setRegistration(row.id, !row.registration_open),
                            row.registration_open
                              ? `Registration closed for ${row.name}.`
                              : `Registration open for ${row.name}.`
                          )
                        }
                      >
                        {row.registration_open ? 'Close' : 'Open'}
                      </Button>
                    </div>
                  </Td>

                  <Td>
                    {row.is_marks_released ? (
                      <Badge tone="success">Released</Badge>
                    ) : (
                      <Badge tone="warning">Withheld</Badge>
                    )}
                    <div className="mt-1">
                      <Button
                        size="sm"
                        variant="secondary"
                        loading={busy}
                        onClick={() =>
                          run(
                            row.id,
                            () => semesterApi.setMarkRelease(row.id, !row.is_marks_released),
                            row.is_marks_released
                              ? `Marks withheld for ${row.name}.`
                              : `Marks released for ${row.name}.`
                          )
                        }
                      >
                        {row.is_marks_released ? 'Withhold' : 'Release'}
                      </Button>
                    </div>
                  </Td>

                  <Td>
                    <div className="flex flex-col gap-1">
                      {!selected && (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => selectSemester(row.id)}
                        >
                          View
                        </Button>
                      )}
                      <Link to="/projects" onClick={() => selectSemester(row.id)}>
                        <Button size="sm" variant="ghost">Projects</Button>
                      </Link>
                      {row.is_closed ? (
                        // Closing used to be a one-way door: the button simply
                        // disappeared once a term was closed, with nothing to
                        // undo it. Reopening restores the term as live but
                        // leaves registration closed, so the message says so —
                        // the Open button is the next column along.
                        <Button
                          size="sm"
                          variant="ghost"
                          loading={busy}
                          onClick={() =>
                            run(
                              row.id,
                              () => semesterApi.reopen(row.id),
                              `${row.name} reopened. Registration is still closed — open it when you are ready.`
                            )
                          }
                        >
                          Reopen term
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="ghost"
                          loading={busy}
                          onClick={() =>
                            run(
                              row.id,
                              () => semesterApi.close(row.id),
                              `${row.name} closed.`
                            )
                          }
                        >
                          Close term
                        </Button>
                      )}
                    </div>
                  </Td>
                </tr>
              )
            }}
          />
        )}
      </Card>
    </div>
  )
}

/** One batch column: live projects, and how many are missing a supervisor. */
function PartCell({ stats }) {
  if (!stats) return <Td className="text-xs text-slate-400">—</Td>

  const unpaired = Math.max(0, (stats.total ?? 0) - (stats.with_supervisor ?? 0))

  return (
    <Td className="tabular-nums">
      <div className="text-slate-800">{stats.total ?? 0}</div>
      <div className="text-xs text-slate-400">
        {stats.students ?? 0} student{stats.students === 1 ? '' : 's'}
      </div>
      {unpaired > 0 && (
        <div className="text-xs text-amber-600">{unpaired} without supervisor</div>
      )}
    </Td>
  )
}

function blankForm() {
  return {
    academic_session: '',
    semester_number: '1',
    name: '',
    starts_at: '',
    ends_at: '',
    is_active: 'yes',
  }
}