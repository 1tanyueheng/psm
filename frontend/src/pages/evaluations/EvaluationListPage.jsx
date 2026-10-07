import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { assessmentWindowApi, evaluationApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, ProgressBar,
} from '../../components/ui'
import { formatDate, formatMark, formLabel, relativeDays, EVALUATION_STATUS, statusMeta } from '../../lib/format'
import { partLabel } from '../../lib/psmPart'

/**
 * Assessment list — every evaluation form this user can see.
 *
 * The grouping is by "needs my action" vs "already filed", because the only
 * thing anyone does from this page is open a draft and finish it, or look up
 * what they submitted.
 */
export default function EvaluationListPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  // The coordinator's assessment window for each batch this assessor works in.
  // Read, not inferred: whether marking is open is the coordinator's decision.
  const [windows, setWindows] = useState([])

  const status = params.get('status') ?? ''
  const assessorType = params.get('assessor_type') ?? ''
  const scope = params.get('scope') ?? 'mine'

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const res = await evaluationApi.list({
          mine: scope === 'mine' ? true : undefined,
          status: status || undefined,
          assessor_type: assessorType || undefined,
          per_page: 100,
        })
        if (cancelled) return
        const { items, meta: pageMeta } = unwrapPaged(res)
        setRows(items)
        setMeta(pageMeta)
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
  }, [status, assessorType, scope])

  useEffect(() => {
    let cancelled = false
    assessmentWindowApi
      .current()
      .then((data) => {
        if (!cancelled) setWindows(data ?? [])
      })
      .catch(() => {
        // A missing window is not an error — it just means marking is ungated.
        if (!cancelled) setWindows([])
      })
    return () => {
      cancelled = true
    }
  }, [])

  const { open, filed } = useMemo(() => {
    const isOpen = (r) => ['draft', 'in_progress'].includes(r.status)
    return {
      open: rows.filter(isOpen),
      filed: rows.filter((r) => !isOpen(r)),
    }
  }, [rows])

  /**
   * Is any batch this assessor works in actually open for marking?
   *
   * Read from the windows, not inferred from the form list: the server hides
   * unfiled drafts while marking is shut, so an empty list and a closed window
   * look identical from here. This is what tells them apart, and it is why the
   * page can say "not opened yet" rather than "nothing outstanding" — the
   * latter reads as "you are done" when the truth is "you may not start".
   */
  const markingOpen = windows.some((w) => w.accepts_marks)

  /**
   * Did the server actually gate this list?
   *
   * `marking_gated` is the server's own answer — it is the same predicate that
   * decided which rows to send — so the UI never has to re-derive "am I exempt"
   * from the role and risk disagreeing with the filter. Falls back to the role
   * check for an older payload.
   */
  const gated = meta?.marking_gated ?? !['coordinator', 'admin'].includes(user?.role)

  if (loading) return <Spinner label="Loading assessments" />
  if (error) return <ErrorState error={error} />

  const canSeeAll = ['coordinator', 'admin'].includes(user?.role)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Assessments"
        subtitle="Pick the student you want to mark, then file their Lampiran"
      />

      {windows.map((w) => (
        <Card
          key={w.id}
          className={w.accepts_marks ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/60'}
        >
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className={`font-medium ${w.accepts_marks ? 'text-emerald-900' : 'text-amber-900'}`}>
                {w.accepts_marks
                  ? `Marking is open for ${partLabel(w.psm_part)}`
                  : `Marking is closed for ${partLabel(w.psm_part)}`}
              </p>
              <p className={`text-sm ${w.accepts_marks ? 'text-emerald-800' : 'text-amber-800'}`}>
                {w.name} · {w.window_label}
              </p>
            </div>
            <Badge tone={w.accepts_marks ? 'success' : 'warning'}>{w.state_label}</Badge>
          </div>
        </Card>
      ))}

      {/*
        The locked notice. Shown in place of the form list so the assessor is
        told *why* there is nothing to mark, rather than being handed an empty
        queue that reads as completed work.
      */}
      {!markingOpen && gated && (
        <Card className="border-amber-200 bg-amber-50/60">
          <EmptyState
            title="Marking has not been opened yet"
            message="Your coordinator opens marking for each batch. Once they do, your forms will appear here — nothing is missing from your side."
          />
        </Card>
      )}

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {canSeeAll && (
            <Select
              value={scope}
              onChange={(e) => setFilter('scope', e.target.value)}
              aria-label="Scope"
            >
              <option value="mine">My forms</option>
              <option value="all">All forms</option>
            </Select>
          )}
          <Select
            value={assessorType}
            onChange={(e) => setFilter('assessor_type', e.target.value)}
            aria-label="Filter by assessor type"
          >
            <option value="">All assessor types</option>
            <option value="supervisor">Supervisor</option>
            <option value="examiner">Examiner</option>
            <option value="coordinator">Coordinator</option>
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            {Object.entries(EVALUATION_STATUS).map(([value, m]) => (
              <option key={value} value={value}>
                {m.label}
              </option>
            ))}
          </Select>
          {(status || assessorType) && (
            <button
              type="button"
              onClick={() => setParams(new URLSearchParams(), { replace: true })}
              className="self-center text-left text-sm font-medium text-brand-700 hover:underline"
            >
              Clear filters
            </button>
          )}
        </div>
      </Card>

      <Card>
        <CardHeader
          title="Outstanding"
          subtitle="Draft forms that still need marks"
          action={open.length > 0 ? <Badge tone="warning">{open.length}</Badge> : <Badge tone="success">Clear</Badge>}
        />
        {open.length === 0 ? (
          <EmptyState
            title={markingOpen || !gated ? 'Nothing outstanding' : 'Not open for marking'}
            message={
              markingOpen || !gated
                ? 'You have no draft assessment forms waiting to be completed.'
                : 'Draft forms appear here once your coordinator opens marking for the batch.'
            }
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {open.map((row) => (
              <EvaluationRow key={row.id} row={row} actionLabel="Continue marking" />
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="Filed" subtitle="Submitted forms — read-only" />
        {filed.length === 0 ? (
          <EmptyState title="Nothing filed yet" message="Submitted forms will be listed here." />
        ) : (
          <DataTable columns={['Project', 'Assessor', 'Type', 'Status', 'Mark', 'Submitted', '']}>
            {filed.map((row) => {
              const m = statusMeta(EVALUATION_STATUS, row.status)
              return (
                <tr key={row.id} className="hover:bg-slate-50/60">
                  <Td>
                    <div className="text-sm font-medium text-slate-800">
                      {row.project?.title ?? `#${row.project_id}`}
                    </div>
                    <div className="text-xs text-slate-400">
                      {row.project?.students?.[0]?.name}
                    </div>
                  </Td>
                  <Td className="text-sm text-slate-600">{row.assessor?.name ?? '—'}</Td>
                  <Td>
                    <div className="text-sm capitalize text-slate-600">{row.assessor_type}</div>
                    {formLabel(row) && (
                      <div className="text-xs text-slate-400">{formLabel(row)}</div>
                    )}
                  </Td>
                  <Td>
                    <Badge tone={m?.tone ?? 'neutral'}>{m?.label ?? row.status}</Badge>
                  </Td>
                  <Td className="font-semibold tabular-nums">
                    {row.aggregate_mark != null ? formatMark(row.aggregate_mark) : '—'}
                  </Td>
                  <Td className="text-sm text-slate-500">
                    {row.submitted_at ? formatDate(row.submitted_at) : '—'}
                  </Td>
                  <Td>
                    <Link to={`/evaluations/${row.id}`}>
                      <Button size="sm" variant="secondary">View</Button>
                    </Link>
                  </Td>
                </tr>
              )
            })}
          </DataTable>
        )}
      </Card>
    </div>
  )
}

function EvaluationRow({ row, actionLabel }) {
  return (
    <li className="flex flex-wrap items-center gap-3 py-3">
      <Avatar name={row.project?.students?.[0]?.name ?? 'Project'} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to={`/evaluations/${row.id}`}
            className="truncate font-medium text-slate-800 hover:text-brand-700"
          >
            {row.project?.title ?? `Evaluation #${row.id}`}
          </Link>
          <Badge tone="neutral">{row.assessor_type}</Badge>
          {formLabel(row) && <Badge tone="brand">{formLabel(row)}</Badge>}
          {row.has_conflict && <Badge tone="danger">Conflict</Badge>}
        </div>
        {row.rubric_completion_percent != null && (
          <div className="mt-2 flex items-center gap-3">
            <div className="w-full max-w-xs">
              <ProgressBar
                value={row.rubric_completion_percent}
                tone={row.rubric_completion_percent >= 80 ? 'success' : 'brand'}
              />
            </div>
            <span className="w-10 shrink-0 text-xs tabular-nums text-slate-500">
              {row.rubric_completion_percent}%
            </span>
          </div>
        )}
      </div>
      <div className="text-right text-sm">
        {row.due_at && !row.submitted_at && (
          <>
            <p className="text-slate-700">due {formatDate(row.due_at)}</p>
            <p className="text-xs text-slate-400">{relativeDays(row.due_at)}</p>
          </>
        )}
      </div>
      <Link to={`/evaluations/${row.id}`}>
        <Button size="sm">{actionLabel}</Button>
      </Link>
    </li>
  )
}
