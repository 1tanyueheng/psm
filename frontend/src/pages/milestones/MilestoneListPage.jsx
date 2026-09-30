import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { milestoneApi, projectApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import {
  Card, PageHeader, Badge, EmptyState, Spinner, ErrorState, Button, Select, ProgressBar,
} from '../../components/ui'
import {
  formatDate, relativeDays, isOverdue, formatPercent, MILESTONE_STATUS, statusMeta,
} from '../../lib/format'

/**
 * Milestone worklist — "what needs doing across everything I can see".
 *
 * Students see their own timeline flattened; supervisors and coordinators see
 * a triage queue. The grouping-by-urgency layout serves both: it is ordered by
 * what is late, then what is due, then what is done.
 */
export default function MilestoneListPage() {
  const [params, setParams] = useSearchParams()
  const [rows, setRows] = useState([])
  const [projects, setProjects] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const status = params.get('status') ?? ''
  const projectId = params.get('project_id') ?? ''

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
        const [milestoneRes, projectRes] = await Promise.all([
          milestoneApi.list({
            status: status || undefined,
            project_id: projectId || undefined,
            per_page: 100,
          }),
          projectApi.list({ per_page: 100 }),
        ])
        if (cancelled) return
        setRows(unwrapPaged(milestoneRes).items)
        setProjects(unwrapPaged(projectRes).items)
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
  }, [status, projectId])

  if (loading) return <Spinner label="Loading milestones" />
  if (error) return <ErrorState error={error} />

  const groups = groupByUrgency(rows)
  const overall = overallProgress(rows, projectId, status)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Milestones"
        subtitle={`${rows.length} milestone${rows.length === 1 ? '' : 's'}`}
      />

      {overall && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-slate-800">
                Overall progress
                {overall.title && (
                  <span className="ml-2 font-normal text-slate-500">{overall.title}</span>
                )}
              </p>
              <p className="text-xs text-slate-500">
                {overall.approved} of {overall.total} chapters approved
              </p>
            </div>
            <p className="text-2xl font-medium tabular-nums text-slate-800">
              {formatPercent(overall.percent)}
            </p>
          </div>
          <div className="mt-3">
            <ProgressBar value={overall.percent} tone={overall.percent >= 100 ? 'success' : 'brand'} />
          </div>
        </Card>
      )}

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Select
            value={projectId}
            onChange={(e) => setFilter('project_id', e.target.value)}
            aria-label="Filter by project"
          >
            <option value="">All my projects</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            {Object.entries(MILESTONE_STATUS).map(([value, meta]) => (
              <option key={value} value={value}>
                {meta.label}
              </option>
            ))}
          </Select>
          {(status || projectId) && (
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

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No milestones"
            message={
              status || projectId
                ? 'Nothing matches those filters.'
                : 'Milestones appear here once your project is approved.'
            }
          />
        </Card>
      ) : (
        <div className="space-y-6">
          {groups.map((group) => (
            <Card key={group.key}>
              <div className="mb-3 flex items-center gap-2">
                <h2 className="font-semibold text-slate-800">{group.title}</h2>
                <Badge tone={group.tone}>{group.items.length}</Badge>
                {group.hint && (
                  <span className="text-sm text-slate-500">{group.hint}</span>
                )}
              </div>

              <ul className="divide-y divide-slate-100">
                {group.items.map((milestone) => (
                  <MilestoneRow key={milestone.id} milestone={milestone} />
                ))}
              </ul>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function MilestoneRow({ milestone }) {
  const due = milestone.effective_due_at ?? milestone.due_at
  const late = milestone.status !== 'approved' && isOverdue(due, milestone.status)

  return (
    <li className="flex flex-wrap items-center gap-3 py-3">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to={`/milestones/${milestone.id}`}
            className="font-medium text-slate-800 hover:text-brand-700"
          >
            {milestone.title}
          </Link>
          <Badge tone={milestone.status_tone ?? 'neutral'}>
            {milestone.status_label ?? statusMeta(MILESTONE_STATUS, milestone.status).label}
          </Badge>
          {(milestone.revision_count ?? 0) > 0 && (
            <Badge tone="warning">rev {milestone.revision_count}</Badge>
          )}
        </div>
        <p className="mt-0.5 truncate text-sm text-slate-500">
          {milestone.project?.title ?? '—'}
          {milestone.code && (
            <span className="ml-2 font-mono text-xs text-slate-400">{milestone.code}</span>
          )}
        </p>
        {/* Per-chapter progress: how complete this chapter is, and what it is
            worth toward the project total. */}
        <div className="mt-2 max-w-xs">
          <ProgressBar
            value={milestone.completion_percent ?? 0}
            tone={progressTone(milestone.status)}
            showLabel
          />
        </div>
      </div>

      <div className="text-right text-sm">
        <p className={late ? 'font-medium text-rose-600' : 'text-slate-700'}>
          {formatDate(due)}
        </p>
        <p className="text-xs text-slate-400">
          {milestone.approved_at
            ? `approved ${relativeDays(milestone.approved_at)}`
            : relativeDays(due)}
        </p>
      </div>

      <div className="text-right text-xs text-slate-500">
        <p>worth {formatPercent(milestone.weight_percent ?? 0, 0)}</p>
        <p className="text-slate-400">
          +{formatPercent(milestone.progress_contribution ?? 0, 1)} earned
        </p>
      </div>

      <Link to={`/milestones/${milestone.id}`}>
        <Button size="sm" variant="secondary">
          Open
        </Button>
      </Link>
    </li>
  )
}

/** Bar colour that matches how far the chapter has actually got. */
function progressTone(status) {
  if (status === 'approved') return 'success'
  if (status === 'reviewed' || status === 'submitted') return 'warning'
  if (status === 'rejected' || status === 'overdue') return 'danger'
  return 'neutral'
}

/**
 * Overall progress for a single project, derived from the chapters in hand.
 *
 * The server sends each chapter's `progress_contribution`, which by
 * definition sums to the project's own progress figure, so adding them up
 * here reproduces the headline number rather than approximating it. Only
 * shown when a single project is selected — summing across projects would
 * produce a meaningless figure — and only while no status filter is narrowing
 * the rows, since a filtered sum counts the chapters that happen to match
 * rather than the project as a whole.
 */
function overallProgress(rows, projectId, statusFilter) {
  if (!projectId || statusFilter || rows.length === 0) return null

  const percent = rows.reduce((sum, m) => sum + (m.progress_contribution ?? 0), 0)

  return {
    title: rows[0]?.project?.title ?? '',
    percent: Math.round(percent * 100) / 100,
    approved: rows.filter((m) => m.status === 'approved').length,
    total: rows.length,
  }
}

/**
 * Order the worklist by what actually needs attention. Anything not yet
 * approved is either late, imminent, or simply open — approved work is filed
 * away at the bottom rather than competing for the eye.
 */
function groupByUrgency(rows) {
  const overdue = []
  const dueSoon = []
  const open = []
  const done = []

  for (const m of rows) {
    if (m.status === 'approved') {
      done.push(m)
      continue
    }
    const due = m.effective_due_at ?? m.due_at
    if (isOverdue(due, m.status)) overdue.push(m)
    // `reviewed` is the real backend status; there is no `under_review`.
    else if (m.status === 'submitted' || m.status === 'reviewed') dueSoon.push(m)
    else open.push(m)
  }

  const byDue = (a, b) =>
    new Date(a.effective_due_at ?? a.due_at ?? 0) - new Date(b.effective_due_at ?? b.due_at ?? 0)
  overdue.sort(byDue)
  dueSoon.sort(byDue)
  open.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))
  done.sort((a, b) => new Date(b.approved_at ?? 0) - new Date(a.approved_at ?? 0))

  return [
    { key: 'overdue', title: 'Overdue', tone: 'danger', items: overdue, hint: 'past the due date' },
    { key: 'review', title: 'In review', tone: 'warning', items: dueSoon, hint: 'submitted, awaiting a decision' },
    { key: 'open', title: 'In progress', tone: 'neutral', items: open },
    { key: 'done', title: 'Approved', tone: 'success', items: done },
  ].filter((g) => g.items.length > 0)
}
