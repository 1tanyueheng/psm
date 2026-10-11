import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { assessmentWindowApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Input, ProgressBar,
} from '../../components/ui'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'
import { formatDateTime } from '../../lib/format'

/**
 * Coordinator — the assessment window board.
 *
 * One row per Lampiran. Each form is marked at a different time by different
 * people — the supervisor files Lampiran E when the project is finished, the
 * examiners file Lampiran I on seminar day, the PSM 2 progress reports are filed
 * mid-project — so each has its own open/close control and its own dates.
 *
 * This screen is the whole management surface for that. Opening a form also
 * allocates it to every student in the batch, so "marking is open" and "there is
 * something to mark" cannot disagree. Closing stops new marks but keeps what was
 * filed.
 *
 * The board is grouped by batch because that is how the forms are organised in
 * the process: PSM 1 runs Lampiran E and I, PSM 2 runs G, H1, H2 and J.
 */
export default function AssessmentWindowPage() {
  const { user } = useAuth()
  const { selectedId: termId, active } = useSemesters()
  const [searchParams] = useSearchParams()

  /**
   * A link from a project's page arrives scoped to that project's term, so the
   * coordinator lands on the batch they were just looking at rather than an
   * unscoped board.
   */
  const scopedSemesterId = searchParams.get('semester_id')

  const [windows, setWindows] = useState([])
  const [expandedId, setExpandedId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [editingDates, setEditingDates] = useState(null)

  const canManage = ['coordinator', 'admin'].includes(user?.role)

  /** The term the board is showing: the scoped one wins over the switcher. */
  const semesterId = scopedSemesterId ?? termId ?? active?.id ?? null

  const load = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true)
    setError(null)
    try {
      const list = (await assessmentWindowApi.list({
        semester_id: semesterId ?? undefined,
      })) ?? []
      setWindows(list)
    } catch (err) {
      setError(err)
    } finally {
      if (!quiet) setLoading(false)
    }
  }, [semesterId])

  useEffect(() => {
    load()
  }, [load])

  // The expanded row's detail is a separate fetch so opening one does not
  // refetch the whole board.
  useEffect(() => {
    if (expandedId == null) {
      setDetail(null)
      return undefined
    }

    let cancelled = false
    assessmentWindowApi
      .show(expandedId)
      .then((data) => {
        if (!cancelled) setDetail(data)
      })
      .catch((err) => {
        if (!cancelled) setError(err)
      })

    return () => {
      cancelled = true
    }
  }, [expandedId])

  async function act(id, fn) {
    setBusyId(id)
    setActionError(null)
    setNotice(null)
    try {
      const result = await fn()
      await load({ quiet: true })
      if (expandedId === id) {
        setDetail(await assessmentWindowApi.show(id))
      }
      if (result?.message) setNotice(result.message)
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="Loading assessment windows" />
  if (error) return <ErrorState error={error} />

  // Grouped by batch, and within a batch in the order the forms are filed, so
  // the board reads like the process rather than like a table.
  const grouped = PSM_PARTS
    .map((part) => ({
      part,
      rows: windows
        .filter((w) => w.psm_part === part)
        .sort((a, b) => String(a.form_code).localeCompare(String(b.form_code))),
    }))
    .filter((g) => g.rows.length > 0)

  const openCount = windows.filter((w) => w.accepts_marks).length

  return (
    <div className="space-y-6">
      <PageHeader
        title="Assessment"
        subtitle="Open each Lampiran when its marking period begins. Every form has its own dates."
        action={
          <div className="text-right text-sm text-slate-500">
            <div className="font-medium text-slate-700">
              {openCount} of {windows.length} open
            </div>
            <div>{active?.name ?? 'No active term'}</div>
          </div>
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

      {windows.length === 0 ? (
        <Card>
          <EmptyState
            title="No assessment windows for this term"
            message="Windows are created with the term, one per Lampiran. If this term predates that, a coordinator can create them on the Semesters screen."
          />
        </Card>
      ) : (
        grouped.map(({ part, rows }) => (
          <Card key={part} className="overflow-hidden p-0">
            <CardHeader
              title={
                <span className="flex items-center gap-2">
                  <Badge tone={PSM_PART_BADGE_TONES[part] ?? 'neutral'}>{partLabel(part)}</Badge>
                  <span className="text-sm font-normal text-slate-500">
                    {rows.length} Lampiran
                  </span>
                </span>
              }
              subtitle="Each form is opened and closed on its own"
            />

            <div className="divide-y divide-slate-100">
              {rows.map((w) => (
                <FormRow
                  key={w.id}
                  window={w}
                  canManage={canManage}
                  busy={busyId === w.id}
                  expanded={expandedId === w.id}
                  detail={expandedId === w.id ? detail : null}
                  editing={editingDates === w.id}
                  onToggleExpand={() => setExpandedId(expandedId === w.id ? null : w.id)}
                  onToggleDates={() => setEditingDates(editingDates === w.id ? null : w.id)}
                  onOpen={() => act(w.id, async () => {
                    const res = await assessmentWindowApi.open(w.id)
                    return { message: res?.message }
                  })}
                  onClose={() => act(w.id, async () => {
                    const res = await assessmentWindowApi.close(w.id)
                    return { message: res?.message }
                  })}
                  onSaveDates={(payload) => act(w.id, async () => {
                    const res = await assessmentWindowApi.update(w.id, payload)
                    setEditingDates(null)
                    return { message: res?.message }
                  })}
                />
              ))}
            </div>
          </Card>
        ))
      )}
    </div>
  )
}

/**
 * One Lampiran on the board.
 *
 * Collapsed it is a single line: the form, its state, and the two buttons that
 * matter. Expanded it adds the progress and the per-student roster, because the
 * coordinator only needs that when deciding whether a form can be closed.
 */
function FormRow({
  window: w,
  canManage,
  busy,
  expanded,
  detail,
  editing,
  onToggleExpand,
  onToggleDates,
  onOpen,
  onClose,
  onSaveDates,
}) {
  const tone = w.accepts_marks ? 'success' : w.is_open ? 'warning' : 'neutral'

  return (
    <div className={expanded ? 'bg-slate-50/50' : ''}>
      <div className="flex flex-wrap items-center gap-3 px-4 py-3">
        <button
          type="button"
          onClick={onToggleExpand}
          className="flex min-w-0 flex-1 items-center gap-3 text-left"
        >
          <span className="font-mono text-xs text-slate-400">{expanded ? '▾' : '▸'}</span>
          <span className="w-16 shrink-0 font-medium text-slate-800">
            Lampiran {w.form_code}
          </span>
          <Badge tone={tone}>{w.state_label}</Badge>
          <span className="truncate text-xs text-slate-500">
            {w.window_label}
            {w.opened_at && ` · opened ${formatDateTime(w.opened_at)}`}
          </span>
        </button>

        {canManage && (
          <div className="flex shrink-0 gap-2">
            <Button size="sm" variant="secondary" onClick={onToggleDates} disabled={busy}>
              {editing ? 'Cancel' : 'Dates'}
            </Button>
            {w.is_open ? (
              <Button size="sm" variant="secondary" onClick={onClose} disabled={busy}>
                {busy ? 'Closing…' : 'Close'}
              </Button>
            ) : (
              <Button size="sm" onClick={onOpen} disabled={busy}>
                {busy ? 'Opening…' : 'Open'}
              </Button>
            )}
          </div>
        )}
      </div>

      {editing && (
        <DateEditor window={w} busy={busy} onSave={onSaveDates} />
      )}

      {expanded && (
        <ExpandedDetail detail={detail} />
      )}
    </div>
  )
}

/**
 * The form's own published period.
 *
 * Separate from opening: the coordinator can publish "Lampiran I closes 20
 * December" in advance and open it on the day, which is how a real marking
 * period is arranged.
 */
function DateEditor({ window: w, busy, onSave }) {
  const toLocal = (iso) => (iso ? String(iso).slice(0, 16) : '')

  const [start, setStart] = useState(toLocal(w.scheduled_start_at))
  const [end, setEnd] = useState(toLocal(w.scheduled_end_at))
  const [error, setError] = useState(null)

  async function save() {
    if (start && end && new Date(end) <= new Date(start)) {
      setError('The closing time must be after the opening time.')
      return
    }

    setError(null)
    await onSave({
      scheduled_start_at: start || null,
      scheduled_end_at: end || null,
    })
  }

  return (
    <div className="border-t border-slate-100 bg-white px-4 py-4">
      <p className="mb-3 text-xs text-slate-500">
        The period this Lampiran accepts marks. Leave both blank for no fixed
        period — the open/close buttons still control it.
      </p>

      {error && (
        <p className="mb-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Accepts from" hint="Optional">
          <Input
            type="datetime-local"
            value={start}
            onChange={(e) => setStart(e.target.value)}
          />
        </Field>
        <Field
          label="Accepts until"
          hint="Optional — a form left open past this stops accepting marks"
        >
          <Input type="datetime-local" value={end} onChange={(e) => setEnd(e.target.value)} />
        </Field>
      </div>

      <div className="mt-3 flex justify-end">
        <Button size="sm" onClick={save} disabled={busy}>
          {busy ? 'Saving…' : 'Save dates'}
        </Button>
      </div>
    </div>
  )
}

/** Progress and the per-student roster for one Lampiran. */
function ExpandedDetail({ detail }) {
  if (!detail) {
    return (
      <div className="border-t border-slate-100 bg-white px-4 py-4">
        <p className="text-sm text-slate-500">Loading…</p>
      </div>
    )
  }

  const progress = detail.progress ?? {}

  return (
    <div className="space-y-4 border-t border-slate-100 bg-white px-4 py-4">
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Meta label="Students" value={progress.students ?? 0} />
        <Meta label="Forms filed" value={`${progress.filed ?? 0} / ${progress.forms ?? 0}`} />
        <Meta label="Outstanding" value={progress.outstanding ?? 0} />
        <Meta label="Complete" value={`${progress.percent ?? 0}%`} />
      </div>

      <ProgressBar value={progress.percent ?? 0} tone="brand" showLabel />

      <div>
        <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
          Who has filed
        </p>

        {(detail.roster ?? []).length === 0 ? (
          <p className="text-sm text-slate-500">
            No students in this batch yet. Opening the form will allocate it as
            soon as there are any.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100 rounded-md border border-slate-100">
            {(detail.roster ?? []).map((row) => (
              <li
                key={row.project_id}
                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
              >
                <div className="min-w-0">
                  <span className="text-sm text-slate-800">{row.name ?? '—'}</span>
                  <span className="ml-2 font-mono text-xs text-slate-400">{row.student}</span>
                </div>

                <div className="flex items-center gap-3">
                  <div className="flex flex-wrap gap-1">
                    {(row.forms ?? []).map((f, i) => (
                      <Badge
                        key={`${row.project_id}-${i}`}
                        tone={['submitted', 'released'].includes(f.status) ? 'success' : 'neutral'}
                      >
                        {f.form_code ?? '—'}
                      </Badge>
                    ))}
                  </div>
                  <span className="tabular-nums text-xs text-slate-500">
                    {row.filed} / {row.total}
                  </span>
                  <Link
                    to={`/projects/${row.project_id}`}
                    className="text-xs font-medium text-brand-700 hover:underline"
                  >
                    Open
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
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
