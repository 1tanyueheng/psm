import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { assessmentWindowApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Input, Textarea, FieldErrors, Select, DataTable, Td,
  ProgressBar,
} from '../../components/ui'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'
import { formatDateTime } from '../../lib/format'

/**
 * Coordinator â€” the assessment window.
 *
 * One event per (term, batch) that the coordinator opens when marking should
 * begin. Opening it is the whole job: it allocates every Lampiran the batch
 * needs â€” Lampiran E to each supervisor, I to both panel examiners for PSM 1 â€”
 * and only then does it start accepting marks. Assessors then pick a student
 * and file their form.
 *
 * Closing is not the same as releasing. It stops new marks; locking the
 * aggregate and releasing the mark stay on the per-student mark submission.
 */
export default function AssessmentWindowPage() {
  const { user } = useAuth()
  const { semesters, selectedId: termId, active } = useSemesters()
  const [searchParams] = useSearchParams()

  /**
   * A link from a project's page arrives scoped to that project's term and
   * batch, so the coordinator lands on the batch they were just looking at
   * rather than an unscoped list.
   *
   * The list is filtered by the scoped term and the matching batch's window is
   * selected. The create form is *not* opened automatically: a batch may only
   * have one window per term (a unique key enforces it), so opening the form for
   * a batch that already has one would only offer an action that cannot succeed.
   * The scoped values still pre-fill the form when the coordinator asks for a
   * new window.
   */
  const scopedSemesterId = searchParams.get('semester_id')
  const scopedPart = searchParams.get('psm_part')

  const [windows, setWindows] = useState([])
  const [detail, setDetail] = useState(null)
  /**
   * The window whose detail is being shown.
   *
   * A single source of truth on purpose. It used to be duplicated: a
   * `windowId` state for "which row is selected" *and* an inline fetch that set
   * `detail`. Selecting a row then called `act()`, which reloaded the list, and
   * `load` read the id frozen in its closure â€” the previous window â€” found it
   * still present, and re-selected it. Clicking a window appeared to do nothing.
   */
  const [needsDetail, setNeedsDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [notice, setNotice] = useState(null)

  const canManage = ['coordinator', 'admin'].includes(user?.role)

  /**
   * What `load()` should keep selected across a reload.
   *
   * A ref, not a dependency. Taking the selection as a dependency while `load`
   * also *set* it meant selecting a window could re-create `load`, re-run the
   * effect and fetch again. A ref breaks that: it is always current when `load`
   * reads it, and changing it cannot re-trigger the effect.
   */
  const selectedWindowId = useRef(null)

  /**
   * Read the window list.
   *
   * `quiet` skips the full-page skeleton for a refresh the user did not ask for
   * and does not need to see â€” after an open/close the button already shows a
   * busy state, and replacing the whole page with "Loading assessment windows"
   * loses their place.
   */
  const load = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true)
    setError(null)
    try {
      // The scoped term wins over the global one, so a link from a project's
      // page shows that project's batch even when the term switcher is elsewhere.
      const list = (await assessmentWindowApi.list({
        semester_id: scopedSemesterId ?? termId ?? undefined,
      })) ?? []
      setWindows(list)

      // Keep the current selection when it is still in the refreshed list;
      // otherwise fall back to the batch the caller linked in with, then to the
      // first row.
      const keep = list.find((w) => w.id === selectedWindowId.current)
      const scoped = scopedPart ? list.find((w) => w.psm_part === scopedPart) : null
      const next = keep ?? scoped ?? list[0] ?? null

      selectedWindowId.current = next?.id ?? null
      setNeedsDetail(next?.id ?? null)
    } catch (err) {
      setError(err)
    } finally {
      if (!quiet) setLoading(false)
    }
  }, [termId, scopedSemesterId, scopedPart])

  useEffect(() => {
    load()
  }, [load])

  // The detail fetch is a separate effect so `load` does not have to be async
  // about two different things â€” and so selecting a window does not refetch the
  // list it was selected from.
  useEffect(() => {
    if (needsDetail == null) {
      setDetail(null)
      return undefined
    }

    let cancelled = false
    assessmentWindowApi
      .show(needsDetail)
      .then((data) => {
        if (!cancelled) setDetail(data)
      })
      .catch((err) => {
        if (!cancelled) setError(err)
      })

    return () => {
      cancelled = true
    }
  }, [needsDetail])

  /** Select one window without reloading the list it came from. */
  function selectWindow(id) {
    selectedWindowId.current = id
    setNeedsDetail(id)
  }

  async function act(fn) {
    setBusy(true)
    setActionError(null)
    setNotice(null)
    try {
      const result = await fn()
      // The list, then the detail. The detail has to be refetched explicitly:
      // `load()` deliberately leaves the selected window alone, because bumping
      // `needsDetail` to the value it already holds would not re-run the effect
      // that fetches it.
      await load({ quiet: true })
      await refreshDetail()
      if (result?.message) setNotice(result.message)
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  /** Re-fetch the selected window so the panel reflects what just happened. */
  async function refreshDetail() {
    const id = selectedWindowId.current

    if (id == null) return

    try {
      setDetail(await assessmentWindowApi.show(id))
    } catch (err) {
      setError(err)
    }
  }

  if (loading) return <Spinner label="Loading assessment windows" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Assessment"
        subtitle="Open marking for a batch, then let the supervisor and panel file their Lampiran"
        action={
          canManage ? (
            <Button onClick={() => setShowCreate((v) => !v)}>
              {showCreate ? 'Cancel' : 'New window'}
            </Button>
          ) : undefined
        }
      />

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && (
        <Card className="border-rose-200 bg-rose-50/60">
          <p className="text-sm text-rose-900">{actionError}</p>
        </Card>
      )}

      {showCreate && (
        <CreateWindowForm
          semesters={semesters}
          activeSemester={active}
          defaultPart={scopedPart}
          onCreated={async (created) => {
            setShowCreate(false)
            // Select the new window through the same path a click uses, then
            // refresh the list â€” the ref keeps the selection across that reload.
            if (created?.id != null) selectWindow(created.id)
            await load()
            setNotice('Assessment window created. Open it when marking should begin.')
          }}
          onError={setActionError}
        />
      )}

      {windows.length === 0 && !showCreate ? (
        <Card>
          <EmptyState
            title="No assessment window"
            message="Create one for a batch, then open it. Opening allocates every Lampiran that batch needs."
          />
        </Card>
      ) : (
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-1">
            <CardHeader title="Windows" />
            <ul className="divide-y divide-slate-100">
              {windows.map((w) => (
                <li key={w.id}>
                  <button
                    type="button"
                    // Selecting is a local, synchronous act â€” no reload, so the
                    // choice cannot be overwritten by the list refetching itself.
                    onClick={() => selectWindow(w.id)}
                    className={`w-full py-3 pl-3 text-left transition ${
                      detail?.id === w.id
                        ? 'border-l-2 border-brand-600'
                        : 'border-l-2 border-transparent hover:bg-slate-50'
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      <Badge tone={PSM_PART_BADGE_TONES[w.psm_part] ?? 'neutral'}>
                        {partLabel(w.psm_part)}
                      </Badge>
                      <span className="truncate text-sm font-medium text-slate-800">{w.name}</span>
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {w.semester ?? w.academic_session} Â· {w.window_label}
                    </p>
                    <div className="mt-1">
                      <Badge tone={w.accepts_marks ? 'success' : w.is_open ? 'warning' : 'neutral'}>
                        {w.state_label}
                      </Badge>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          </Card>

          <div className="space-y-6 lg:col-span-2">
            {detail ? (
              <>
                <Card>
                  <CardHeader
                    title={detail.name}
                    subtitle={`${detail.semester ?? detail.academic_session} Â· ${detail.window_label}`}
                    action={
                      canManage ? (
                        <div className="flex gap-2">
                          <Button
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                              act(async () => {
                                const res = await assessmentWindowApi.open(detail.id)
                                return { message: res?.message }
                              })
                            }
                          >
                            {detail.is_open ? 'Re-open / top up forms' : 'Open for marking'}
                          </Button>
                          {detail.is_open && (
                            <Button
                              size="sm"
                              variant="secondary"
                              disabled={busy}
                              onClick={() =>
                                act(async () => {
                                  const res = await assessmentWindowApi.close(detail.id)
                                  return { message: res?.message }
                                })
                              }
                            >
                              Close
                            </Button>
                          )}
                        </div>
                      ) : undefined
                    }
                  />

                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Meta label="State" value={detail.state_label} />
                    <Meta label="Students" value={detail.progress?.students ?? 0} />
                    <Meta
                      label="Forms filed"
                      value={`${detail.progress?.filed ?? 0} / ${detail.progress?.forms ?? 0}`}
                    />
                    <Meta label="Outstanding" value={detail.progress?.outstanding ?? 0} />
                  </div>

                  <div className="mt-4">
                    <ProgressBar value={detail.progress?.percent ?? 0} tone="brand" showLabel />
                  </div>

                  <p className="mt-3 text-xs text-slate-500">
                    Opening allocates Lampiran E to each student's supervisor and Lampiran I to
                    both panel examiners. Re-opening only tops up students who were missed.
                  </p>
                </Card>

                <Card className="overflow-hidden p-0">
                  <CardHeader
                    title="Students"
                    subtitle={
                      <>
                        <Badge tone={PSM_PART_BADGE_TONES[detail.psm_part] ?? 'neutral'}>
                          {partLabel(detail.psm_part)}
                        </Badge>{' '}
                        only â€” of {detail.semester ?? detail.academic_session}. Who has been
                        marked, and who has not.
                      </>
                    }
                  />
                  <DataTable columns={['Student', 'Project', 'Lampiran', 'Filed', '']}>
                      {(detail.roster ?? []).map((row) => (
                        <tr key={row.project_id} className="hover:bg-slate-50/60">
                          <Td>
                            <div className="text-sm font-medium text-slate-800">{row.name ?? 'â€”'}</div>
                            <div className="font-mono text-xs text-slate-400">{row.student}</div>
                          </Td>
                          <Td className="font-mono text-xs text-slate-500">{row.code}</Td>
                          <Td>
                            <div className="flex flex-wrap gap-1">
                              {(row.forms ?? []).map((f, i) => (
                                <Badge
                                  key={`${row.project_id}-${i}`}
                                  tone={
                                    ['submitted', 'released'].includes(f.status)
                                      ? 'success'
                                      : 'neutral'
                                  }
                                >
                                  {f.form_code ?? 'â€”'}
                                </Badge>
                              ))}
                            </div>
                          </Td>
                          <Td className="tabular-nums text-slate-600">
                            {row.filed} / {row.total}
                          </Td>
                          <Td>
                            <Link
                              to={`/projects/${row.project_id}`}
                              className="text-sm font-medium text-brand-700 hover:underline"
                            >
                              Open
                            </Link>
                          </Td>
                        </tr>
                      ))}
                    </DataTable>
                </Card>
              </>
            ) : (
              <Card>
                <EmptyState
                  title="Select a window"
                  message="Choose an assessment window on the left to see its progress."
                />
              </Card>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function CreateWindowForm({ semesters, activeSemester, defaultPart, onCreated, onError }) {
  const [form, setForm] = useState({
    name: '',
    // Always the running term. The server refuses anything else â€” a window
    // opened against a closed or not-yet-started term lists whatever projects
    // that term happens to hold, which is how a window named "PSM 2" ended up
    // showing a PSM 1 cohort with nothing on screen to contradict it.
    academic_semester_id: activeSemester?.id ?? '',
    // Defaults to the batch the coordinator arrived from, and to PSM 1
    // otherwise â€” the batch whose marking opens first in a term.
    psm_part: defaultPart || 'PSM1',
    scheduled_start_at: '',
    scheduled_end_at: '',
    notes: '',
  })
  const [errors, setErrors] = useState(null)
  const [busy, setBusy] = useState(false)

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors(null)
    onError(null)
    try {
      const created = await assessmentWindowApi.create({
        ...form,
        academic_semester_id: Number(form.academic_semester_id),
        // Empty date inputs must not be sent as "" â€” the API expects null.
        scheduled_start_at: form.scheduled_start_at || null,
        scheduled_end_at: form.scheduled_end_at || null,
        notes: form.notes || null,
      })
      await onCreated(created)
    } catch (err) {
      setErrors(err?.errors ?? { name: err?.message })
    } finally {
      setBusy(false)
    }
  }

  if (!activeSemester) {
    return (
      <Card>
        <CardHeader title="New assessment window" />
        <p className="text-sm text-amber-800">
          No semester is active, so marking cannot be opened. Activate a semester
          on the Semesters screen first â€” a window has to belong to the term the
          students are actually enrolled in.
        </p>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader
        title="New assessment window"
        subtitle="One per batch per term â€” opening it allocates the forms"
      />
      <form onSubmit={submit} className="space-y-4">
        <FieldErrors errors={errors} />

        <Field label="Name" required>
          <Input
            value={form.name}
            onChange={set('name')}
            placeholder={`e.g. PSM 1 Assessment â€” ${activeSemester.name}`}
            required
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          {/*
            The term is shown, not chosen. It is always the active semester,
            because that is the only term the server will accept: a window on
            any other term would list that term's projects, which is how the
            wrong cohort ends up under the wrong batch heading.
          */}
          <Field
            label="Term"
            hint="Fixed to the active semester â€” marking can only be opened for the term students are enrolled in"
          >
            <Input value={activeSemester.name} readOnly disabled />
          </Field>

          <Field
            label="Batch"
            required
            hint="The batch being marked. The two run in the same term but have different forms, so each needs its own window."
          >
            <Select value={form.psm_part} onChange={set('psm_part')}>
              {PSM_PARTS.map((p) => (
                <option key={p} value={p}>
                  {partLabel(p)}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Opens at" hint="Optional â€” leave blank for no start time">
            <Input type="datetime-local" value={form.scheduled_start_at} onChange={set('scheduled_start_at')} />
          </Field>

          <Field
            label="Closes at"
            hint="Optional â€” a window left open past this stops accepting marks"
          >
            <Input type="datetime-local" value={form.scheduled_end_at} onChange={set('scheduled_end_at')} />
          </Field>
        </div>

        <Field label="Notes">
          <Textarea value={form.notes} onChange={set('notes')} rows={2} />
        </Field>

        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
          Opening this window will allocate forms to every live student in{' '}
          <b>{partLabel(form.psm_part)}</b> of <b>{activeSemester.name}</b>.
          Lampiran E goes to each student's supervisor and Lampiran I to both panel examiners.
          A term and batch with no students will allocate nothing.
        </p>

        <div className="flex justify-end gap-2">
          <Button type="submit" disabled={busy}>
            {busy ? 'Creatingâ€¦' : 'Create window'}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function Meta({ label, value }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-sm font-medium text-slate-700">{value}</p>
    </div>
  )
}
