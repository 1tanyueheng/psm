import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { milestoneApi, projectApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import {
  Card, PageHeader, Badge, EmptyState, Spinner, ErrorState, Button, Select,
} from '../../components/ui'
import { formatDate, relativeDays, isOverdue, MILESTONE_STATUS, statusMeta } from '../../lib/format'

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

  return (
    <div className="space-y-6">
      <PageHeader
        title="Milestones"
        subtitle={`${rows.length} milestone${rows.length === 1 ? '' : 's'}`}
      />

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
  const meta = statusMeta(MILESTONE_STATUS, milestone.status)
  const due = milestone.effective_due_at ?? milestone.due_at
  const late = milestone.status !== 'approved' && isOverdue(due)

  return (
    <li className="flex flex-wrap items-center gap-3 py-3">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to={`/milestones/${milestone.id}`}
            className="font-medium text-slate-800 hover:text-brand-700"
          >
            {milestone.name}
          </Link>
          <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? milestone.status}</Badge>
          {(milestone.revision_count ?? 0) > 0 && (
            <Badge tone="warning">rev {milestone.revision_count}</Badge>
          )}
        </div>
        <p className="mt-0.5 truncate text-sm text-slate-500">
          {milestone.project?.title}
          {milestone.milestone_code && (
            <span className="ml-2 font-mono text-xs text-slate-400">
              {milestone.milestone_code}
            </span>
          )}
        </p>
      </div>

      <div className="text-right text-sm">
        <p className={late ? 'font-medium text-rose-600' : 'text-slate-700'}>
          {formatDate(due, { fallback: 'no date' })}
        </p>
        <p className="text-xs text-slate-400">
          {milestone.approved_at
            ? `approved ${relativeDays(milestone.approved_at)}`
            : relativeDays(due)}
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
    if (isOverdue(due)) overdue.push(m)
    else if (m.status === 'submitted' || m.status === 'under_review') dueSoon.push(m)
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
