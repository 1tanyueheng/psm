import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { projectApi, milestoneApi, profileApi } from '../../api/endpoints'
import { unwrapPaged, unwrap } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, Avatar, ProgressBar,
  EmptyState, Spinner, ErrorState, Button, SemesterFilterBar,
} from '../../components/ui'
import { partLabel, normalisePart, PSM_PARTS } from '../../lib/psmPart'
import { formatDate, relativeDays, isOverdue } from '../../lib/format'

/**
 * Supervisor workspace.
 *
 * The supervisor's day is organised around two questions: "what needs marking
 * right now" and "which of my students is drifting". So the page leads with an
 * action queue rather than a wall of statistics.
 *
 * Because both batches now run in the same term, a supervisor routinely carries
 * PSM 1 and PSM 2 students at once. Those are governed by two independent
 * capacity ceilings, so the page reports them as two bars rather than one
 * total — a single aggregate would hide the case that matters most, namely
 * "PSM 1 is full but PSM 2 has room".
 */
export default function SupervisorDashboard() {
  const { user } = useAuth()
  const { selectedId, selectedSemester } = useSemesters()
  const [projects, setProjects] = useState([])
  const [pending, setPending] = useState([])
  const [capacity, setCapacity] = useState(null)
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
        const { items } = unwrapPaged(
          await projectApi.list({
            role: 'supervisor',
            per_page: 100,
            semester_id: selectedId ?? undefined,
          })
        )
        if (cancelled) return
        setProjects(items)

        // Capacity is a separate call because it lives on the profile, and it is
        // the only figure here that is not derivable from the project list.
        const workload = await profileApi.workload({ semester_id: selectedId ?? undefined })
        if (cancelled) return
        setCapacity(unwrap(workload)?.supervisor?.by_part ?? null)

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
  }, [selectedId])

  const stats = useMemo(() => {
    const total = projects.length
    const atRisk = projects.filter(
      (p) =>
        p.health === 'at_risk' ||
        p.health === 'critical' ||
        (p.next_milestone?.due_at && isOverdue(p.next_milestone.due_at))
    ).length
    const completed = projects.filter((p) => p.status === 'completed').length
    return { total, atRisk, completed, awaiting: pending.length }
  }, [projects, pending])

  // Group the roster by part. The order is fixed rather than derived from the
  // data, so a supervisor with no PSM 1 students still sees the PSM 1 heading
  // and the reason it is empty rather than a section silently vanishing.
  const byPart = useMemo(() => {
    const groups = Object.fromEntries(PSM_PARTS.map((p) => [p, []]))
    for (const project of projects) {
      groups[normalisePart(project.psm_part)] ??= []
      groups[normalisePart(project.psm_part)].push(project)
    }
    return groups
  }, [projects])

  const termLabel = selectedSemester?.short_name ?? selectedSemester?.name

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Welcome, ${user?.name?.split(' ')[0] ?? 'Supervisor'}`}
        subtitle={
          termLabel
            ? `${stats.total} student${stats.total === 1 ? '' : 's'} under supervision in ${termLabel}`
            : `${stats.total} student${stats.total === 1 ? '' : 's'} under supervision`
        }
        action={
          <Link to="/projects">
            <Button variant="secondary">All my projects</Button>
          </Link>
        }
      />

      <SemesterFilterBar />

      {loading ? (
        <Spinner label="Loading your supervision workspace" />
      ) : error ? (
        <ErrorState error={error} />
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Supervisees" value={stats.total} hint="In the selected term" />
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
            <StatCard label="Completed" value={stats.completed} hint="This term" tone="success" />
          </div>

          <PartCapacity capacity={capacity} termLabel={termLabel} />

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
                          {milestone.title}
                        </Link>
                        <Badge tone="info">Submitted</Badge>
                      </div>
                      <p className="mt-0.5 truncate text-sm text-slate-500">
                        {milestone.project?.title}
                      </p>
                    </div>
                    <div className="text-right text-sm">
                      <p className="text-slate-700">
                        {formatDate(milestone.submitted_at)}
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

          {/* Then the roster, split per batch so a supervisor can see at a
              glance which half of their term needs attention. */}
          {projects.length === 0 ? (
            <Card>
              <EmptyState
                title="No students assigned yet"
                message="Your coordinator assigns supervision pairs. Once assigned the projects will show up here."
              />
            </Card>
          ) : (
            PSM_PARTS.map((part) => (
              <PartRoster
                key={part}
                part={part}
                projects={byPart[part] ?? []}
                capacity={capacity?.[part]}
                termLabel={termLabel}
              />
            ))
          )}
        </>
      )}
    </div>
  )
}

/**
 * The two independent ceilings, side by side.
 *
 * Rendered as separate bars deliberately: "4/5 PSM 1, 5/5 PSM 2" and "9/10 PSM 2"
 * are indistinguishable in a single total, yet only the first says the
 * supervisor is full for new PSM 1 allocations.
 */
function PartCapacity({ capacity, termLabel }) {
  if (!capacity) return null

  const rows = PSM_PARTS.map((part) => {
    const c = capacity[part] ?? { capacity: 0, load: 0, remaining: 0 }
    const pct = c.capacity > 0 ? Math.min(100, Math.round((c.load / c.capacity) * 100)) : 0
    return { part, ...c, pct }
  })

  const full = rows.filter((r) => r.capacity > 0 && r.load >= r.capacity)

  return (
    <Card>
      <CardHeader
        title="My capacity"
        subtitle={
          termLabel
            ? `Students carried against each batch's ceiling in ${termLabel}`
            : "Students carried against each batch's ceiling"
        }
        action={
          full.length > 0 ? (
            <Badge tone="danger">Full for {full.map((r) => partLabel(r.part, { short: true })).join(', ')}</Badge>
          ) : (
            <Badge tone="success">Accepting students</Badge>
          )
        }
      />
      <div className="grid gap-4 sm:grid-cols-2">
        {rows.map((r) => (
          <div key={r.part}>
            <div className="mb-1 flex items-baseline justify-between gap-2">
              <span className="text-sm font-medium text-slate-700">{partLabel(r.part)}</span>
              <span
                className={`text-sm tabular-nums ${
                  r.capacity > 0 && r.load >= r.capacity ? 'font-semibold text-rose-600' : 'text-slate-500'
                }`}
              >
                {r.load} / {r.capacity}
              </span>
            </div>
            <ProgressBar
              value={r.pct}
              tone={r.capacity > 0 && r.load >= r.capacity ? 'danger' : r.pct >= 70 ? 'warning' : 'brand'}
            />
            <p className="mt-1 text-xs text-slate-400">
              {r.remaining} slot{r.remaining === 1 ? '' : 's'} available
            </p>
          </div>
        ))}
      </div>
    </Card>
  )
}

function PartRoster({ part, projects, capacity, termLabel }) {
  return (
    <Card>
      <CardHeader
        title={`${partLabel(part)} supervisees`}
        subtitle={
          projects.length === 0
            ? `No ${part} students assigned${termLabel ? ` in ${termLabel}` : ''}`
            : `${projects.length} student${projects.length === 1 ? '' : 's'}${capacity ? ` · ${capacity.load} of ${capacity.capacity} slots` : ''}`
        }
        action={
          capacity && capacity.capacity > 0 && capacity.load >= capacity.capacity ? (
            <Badge tone="danger">At capacity</Badge>
          ) : null
        }
      />
      {projects.length === 0 ? (
        <EmptyState
          title={`No ${part} students`}
          message={`Nothing assigned for ${partLabel(part)}${termLabel ? ` in ${termLabel}` : ''}.`}
        />
      ) : (
        <ul className="divide-y divide-slate-100">
          {sortByRisk(projects).map((project) => (
            <SuperviseeRow key={project.id} project={project} />
          ))}
        </ul>
      )}
    </Card>
  )
}

function SuperviseeRow({ project }) {
  const lead = project.students?.[0]
  const dueAt = project.next_milestone?.due_at
  const overdue = dueAt && isOverdue(dueAt)
  const progress = project.milestone_progress ?? 0

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
            {overdue && <Badge tone="danger">Overdue</Badge>}
          </div>
          <p className="mt-1 line-clamp-1 text-sm text-slate-600">{project.title}</p>

          <div className="mt-3 flex items-center gap-3">
            <div className="w-full max-w-xs">
              <ProgressBar
                value={progress}
                tone={overdue ? 'danger' : progress >= 70 ? 'success' : 'brand'}
              />
            </div>
            <span className="w-10 shrink-0 text-xs tabular-nums text-slate-500">
              {progress}%
            </span>
          </div>
        </div>

        <div className="text-right text-sm">
          {project.next_milestone ? (
            <>
              <p className="text-slate-700">{project.next_milestone.title}</p>
              <p className={`text-xs ${overdue ? 'text-rose-600' : 'text-slate-400'}`}>
                due {formatDate(dueAt, { fallback: 'no date' })}
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
    const due = p.next_milestone?.due_at
    if (due && isOverdue(due)) return 0
    if (p.health === 'at_risk' || p.health === 'critical') return 1
    return 2
  }
  return [...projects].sort((a, b) => {
    const byRisk = rank(a) - rank(b)
    if (byRisk !== 0) return byRisk
    const byProgress = (a.milestone_progress ?? 0) - (b.milestone_progress ?? 0)
    if (byProgress !== 0) return byProgress
    return (a.title ?? '').localeCompare(b.title ?? '')
  })
}
