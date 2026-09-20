import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { evaluationApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, ProgressBar,
} from '../../components/ui'
import { formatDate, formatMark, relativeDays, EVALUATION_STATUS, statusMeta } from '../../lib/format'

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

  const { open, filed } = useMemo(() => {
    const isOpen = (r) => ['draft', 'in_progress'].includes(r.status)
    return {
      open: rows.filter(isOpen),
      filed: rows.filter((r) => !isOpen(r)),
    }
  }, [rows])

  if (loading) return <Spinner label="Loading assessments" />
  if (error) return <ErrorState error={error} />

  const canSeeAll = ['coordinator', 'admin'].includes(user?.role)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Assessments"
        subtitle={meta ? `${meta.total} form${meta.total === 1 ? '' : 's'}` : undefined}
      />

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
            title="Nothing outstanding"
            message="You have no draft assessment forms waiting to be completed."
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
                  <Td className="text-sm capitalize text-slate-600">{row.assessor_type}</Td>
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
