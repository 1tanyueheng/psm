import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { projectApi, milestoneApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, Avatar, ProgressBar,
  EmptyState, Spinner, ErrorState, Button,
} from '../../components/ui'
import { formatDate, relativeDays, isOverdue } from '../../lib/format'

/**
 * Supervisor workspace.
 *
 * The supervisor's day is organised around two questions: "what needs marking
 * right now" and "which of my students is drifting". So the page leads with an
 * action queue rather than a wall of statistics.
 */
export default function SupervisorDashboard() {
  const { user } = useAuth()
  const [projects, setProjects] = useState([])
  const [pending, setPending] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // Projects where this user is the supervisor. The API scopes this
        // automatically from the bearer token, so no id needs passing.
        const { items } = unwrapPaged(await projectApi.list({ role: 'supervisor', per_page: 100 }))
        if (cancelled) return
        setProjects(items)

        // Pull every submitted-but-unmarked step across those projects in one
        // pass so the queue is authoritative rather than derived per project.
        const queues = await Promise.all(
          items.map(async (project) => {
            const { items: milestones } = unwrapPaged(
              await milestoneApi.list({ project_id: project.id, status: 'submitted', per_page: 100 })
            )
            return milestones.map((milestone) => ({ ...milestone, project }))
          })
        )
        if (cancelled) return
        setPending(queues.flat())
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

  const stats = useMemo(() => {
    const total = projects.length
    const atRisk = projects.filter(
      (p) =>
        p.health === 'at_risk' ||
        p.health === 'critical' ||
        (p.next_milestone_due_at && isOverdue(p.next_milestone_due_at))
    ).length
    const completed = projects.filter((p) => p.status === 'completed').length
    return { total, atRisk, completed, awaiting: pending.length }
  }, [projects, pending])

  if (loading) return <Spinner label="Loading your supervision workspace" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Welcome, ${user?.name?.split(' ')[0] ?? 'Supervisor'}`}
        subtitle={`${stats.total} student${stats.total === 1 ? '' : 's'} under supervision`}
        actions={
          <Link to="/projects">
            <Button variant="secondary">All my projects</Button>
          </Link>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Supervisees" value={stats.total} hint="Primary and co-supervision" />
        <StatCard
          label="Awaiting review"
          value={stats.awaiting}
          hint="Submitted milestones"
          tone={stats.awaiting > 0 ? 'warning' : 'default'}
        />
        <StatCard
          label="Needs attention"
          value={stats.atRisk}
          hint="Overdue or at risk"
          tone={stats.atRisk > 0 ? 'danger' : 'default'}
        />
        <StatCard label="Completed" value={stats.completed} hint="This session" tone="success" />
      </div>

      {/* Action queue first — this is why the supervisor opened the page. */}
      <Card>
        <CardHeader
          title="Review queue"
          subtitle="Submissions waiting on your feedback"
          action={
            stats.awaiting > 0 ? (
              <Badge tone="warning">{stats.awaiting} pending</Badge>
            ) : (
              <Badge tone="success">Clear</Badge>
            )
          }
        />
        {pending.length === 0 ? (
          <EmptyState
            title="Nothing to review"
            message="When a student submits a milestone it will appear here with their files attached."
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {pending.map((milestone) => (
              <li key={milestone.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <Link
                      to={`/milestones/${milestone.id}`}
                      className="truncate font-medium text-slate-900 hover:text-brand-700"
                    >
                      {milestone.name}
                    </Link>
                    <Badge tone="info">Submitted</Badge>
                  </div>
                  <p className="mt-0.5 truncate text-sm text-slate-500">
                    {milestone.project?.title}
                  </p>
                </div>
                <div className="text-right text-sm">
                  <p className="text-slate-700">
                    {formatDate(milestone.submitted_at, { fallback: '—' })}
                  </p>
                  <p className="text-xs text-slate-400">
                    {relativeDays(milestone.submitted_at)}
                  </p>
                </div>
                <Link to={`/milestones/${milestone.id}`}>
                  <Button size="sm">Open</Button>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* Then the roster, ordered so the drifting students float to the top. */}
      <Card>
        <CardHeader title="My supervisees" subtitle="Sorted by how much attention they need" />
        {projects.length === 0 ? (
          <EmptyState
            title="No students assigned yet"
            message="Your coordinator assigns supervision pairs. Once assigned the projects will show up here."
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {sortByRisk(projects).map((project) => (
              <SuperviseeRow key={project.id} project={project} />
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function SuperviseeRow({ project }) {
  const lead = project.students?.[0]
  const overdue = project.next_milestone_due_at && isOverdue(project.next_milestone_due_at)

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start gap-4">
        <Avatar name={lead?.name ?? project.title} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Link
              to={`/projects/${project.id}`}
              className="font-medium text-slate-900 hover:text-brand-700"
            >
              {lead?.name ?? 'Unassigned'}
            </Link>
            <span className="font-mono text-xs text-slate-400">{lead?.student_id}</span>
            <Badge tone={project.psm_part === 'PSM2' ? 'brand' : 'neutral'}>
              {project.psm_part}
            </Badge>
            {overdue && <Badge tone="danger">Overdue</Badge>}
          </div>
          <p className="mt-1 line-clamp-1 text-sm text-slate-600">{project.title}</p>

          <div className="mt-3 flex items-center gap-3">
            <div className="w-full max-w-xs">
              <ProgressBar
                value={project.progress_percent ?? 0}
                tone={overdue ? 'danger' : project.progress_percent >= 70 ? 'success' : 'brand'}
              />
            </div>
            <span className="w-10 shrink-0 text-xs tabular-nums text-slate-500">
              {project.progress_percent ?? 0}%
            </span>
          </div>
        </div>

        <div className="text-right text-sm">
          {project.next_milestone_name ? (
            <>
              <p className="text-slate-700">{project.next_milestone_name}</p>
              <p className={`text-xs ${overdue ? 'text-rose-600' : 'text-slate-400'}`}>
                due {formatDate(project.next_milestone_due_at, { fallback: 'no date' })}
              </p>
            </>
          ) : (
            <span className="text-xs text-slate-400">No open milestones</span>
          )}
        </div>
      </div>
    </li>
  )
}

/** Overdue first, then lowest progress, then alphabetical by title. */
function sortByRisk(projects) {
  const rank = (p) => {
    if (p.next_milestone_due_at && isOverdue(p.next_milestone_due_at)) return 0
    if (p.health === 'at_risk' || p.health === 'critical') return 1
    return 2
  }
  return [...projects].sort((a, b) => {
    const byRisk = rank(a) - rank(b)
    if (byRisk !== 0) return byRisk
    const byProgress = (a.progress_percent ?? 0) - (b.progress_percent ?? 0)
    if (byProgress !== 0) return byProgress
    return (a.title ?? '').localeCompare(b.title ?? '')
  })
}
