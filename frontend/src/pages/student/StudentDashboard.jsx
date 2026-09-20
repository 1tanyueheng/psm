import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { projectApi } from '../../api/endpoints'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  PageHeader,
  ProgressBar,
  Spinner,
  StatCard,
} from '../../components/ui'
import {
  formatDate,
  formatMark,
  isOverdue,
  relativeDays,
  statusMeta,
  MILESTONE_STATUS,
  PROJECT_STATUS,
} from '../../lib/format'

/**
 * Module 3 — the student's own view.
 *
 * Answers three questions in order: where is my project, what do I owe next,
 * and what has been said about my work. Everything below the fold is detail.
 */
export default function StudentDashboard() {
  const { user } = useAuth()

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [milestones, setMilestones] = useState([])
  const [selected, setSelected] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)

      try {
        const list = await projectApi.list({ per_page: 10 })

        if (cancelled) return

        // Prefer the in-flight PSM2 project; fall back to whatever exists
        const project =
          list.items.find((p) => p.psm_part === 'PSM2' && p.status !== 'archived') ??
          list.items[0] ??
          null

        setSelected(project)
        setMilestones([])

        if (project) {
          const detail = await projectApi.milestones(project.id)
          if (!cancelled) setMilestones(detail?.milestones ?? detail ?? [])
        }
      } catch (err) {
        if (!cancelled) setError(err.message)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()

    return () => {
      cancelled = true
    }
  }, [])

  if (loading) return <Spinner label="Loading your project…" />

  if (error) {
    return (
      <>
        <PageHeader title="My Project" />
        <ErrorState message={error} onRetry={() => window.location.reload()} />
      </>
    )
  }

  // --- No project yet: the registration call to action -------------------
  if (!selected) {
    return (
      <>
        <PageHeader
          title={`Welcome, ${user?.name?.split(' ')[0] ?? 'there'}`}
          subtitle="You do not have a PSM project registered yet."
        />
        <Card>
          <EmptyState
            title="Register your project"
            description="Submit your proposed title, abstract and category. Your coordinator will assign a supervisor once it is approved."
            action={
              <Link to="/projects/new">
                <Button>Register a project</Button>
              </Link>
            }
          />
        </Card>
      </>
    )
  }

  // --- Derived state -----------------------------------------------------
  const current = milestones.find((m) =>
    ['open', 'rejected', 'overdue', 'pending'].includes(m.status),
  )

  const approved = milestones.filter((m) => m.status === 'approved').length
  const submitted = milestones.filter((m) =>
    ['submitted', 'reviewed'].includes(m.status),
  ).length

  const progress =
    selected.milestone_progress ?? selected.milestone_progress_percent ?? 0

  const projectStatus = statusMeta(PROJECT_STATUS, selected.status)

  return (
    <>
      <PageHeader
        title={selected.title}
        subtitle={`${selected.code} · ${selected.academic_session ?? ''}`}
        action={
          <div className="flex items-center gap-2">
            <Badge tone={projectStatus.tone}>{projectStatus.label}</Badge>
            <Link to={`/projects/${selected.id}`}>
              <Button variant="secondary" size="sm">
                Project detail
              </Button>
            </Link>
          </div>
        }
      />

      {/* --- Headline numbers --- */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <StatCard
          label="Overall progress"
          value={`${Math.round(Number(progress) || 0)}%`}
          hint={`${approved} of ${milestones.length} milestones approved`}
          tone="info"
        />
        <StatCard label="Approved" value={approved} tone="good" />
        <StatCard label="Awaiting review" value={submitted} tone="warn" />
        <StatCard
          label="Current milestone"
          value={current ? current.sequence : '—'}
          hint={current?.title ?? 'All milestones complete'}
        />
      </div>

      <div className="grid lg:grid-cols-3 gap-5">
        {/* --- Milestone timeline --- */}
        <div className="lg:col-span-2">
          <Card>
            <CardHeader
              title="Milestones"
              subtitle="Your submission chain for this project"
            />

            {milestones.length === 0 ? (
              <EmptyState
                title="No milestones yet"
                description="Milestones are created once your project registration is approved."
              />
            ) : (
              <ol className="space-y-2">
                {milestones.map((milestone) => {
                  const meta = statusMeta(MILESTONE_STATUS, milestone.status)
                  const late = isOverdue(milestone.due_at, milestone.status)

                  return (
                    <li key={milestone.id}>
                      <Link
                        to={`/milestones/${milestone.id}`}
                        className="flex items-start gap-3 p-3 rounded-lg border border-slate-200
                                   hover:border-brand-300 hover:bg-brand-50/40 transition-colors"
                      >
                        {/* Sequence marker */}
                        <div
                          className={`w-6 h-6 rounded-full flex items-center justify-center
                            text-[11px] font-medium shrink-0 mt-0.5 ${
                              milestone.status === 'approved'
                                ? 'bg-emerald-100 text-emerald-700'
                                : milestone.status === 'rejected' || late
                                  ? 'bg-rose-100 text-rose-700'
                                  : 'bg-slate-100 text-slate-600'
                            }`}
                        >
                          {milestone.sequence}
                        </div>

                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm font-medium text-slate-900">
                              {milestone.title}
                            </p>
                            <Badge tone={meta.tone}>{meta.label}</Badge>
                            {milestone.revision_count > 0 && (
                              <Badge tone="bg-amber-50 text-amber-700 border-amber-200">
                                Revision {milestone.revision_count}
                              </Badge>
                            )}
                          </div>

                          <p className="text-xs text-slate-500 mt-1">
                            Due {formatDate(milestone.due_at)}
                            {milestone.due_at && ` · ${relativeDays(milestone.due_at)}`}
                            {milestone.weight_percent
                              ? ` · ${Number(milestone.weight_percent)}% of progress`
                              : ''}
                          </p>

                          {milestone.review_comment && (
                            <p className="text-xs text-slate-600 mt-1.5 line-clamp-2">
                              <span className="text-slate-400">Supervisor: </span>
                              {milestone.review_comment}
                            </p>
                          )}
                        </div>

                        <span className="text-slate-300 text-sm shrink-0" aria-hidden="true">
                          ›
                        </span>
                      </Link>
                    </li>
                  )
                })}
              </ol>
            )}
          </Card>
        </div>

        {/* --- Side column --- */}
        <div className="space-y-5">
          {/* Next action */}
          <Card>
            <CardHeader title="What to do next" />

            {current ? (
              <div className="space-y-3">
                <div>
                  <p className="text-sm font-medium text-slate-900">{current.title}</p>
                  <p className="text-xs text-slate-500 mt-0.5">
                    Due {formatDate(current.due_at)} · {relativeDays(current.due_at)}
                  </p>
                </div>

                <ProgressBar value={progress} showLabel tone="bg-brand-500" />

                {['open', 'rejected', 'overdue'].includes(current.status) && (
                  <Link to={`/milestones/${current.id}`} className="block">
                    <Button className="w-full" size="sm">
                      {current.status === 'rejected' ? 'Resubmit work' : 'Submit work'}
                    </Button>
                  </Link>
                )}

                {current.status === 'pending' && (
                  <p className="text-xs text-slate-500 bg-slate-50 rounded-lg p-3">
                    This milestone opens once the previous one is approved.
                  </p>
                )}
              </div>
            ) : (
              <p className="text-sm text-slate-600">
                Every milestone is approved. Your supervisor will confirm the
                examination arrangements.
              </p>
            )}
          </Card>

          {/* Supervisors */}
          {selected.supervisors?.length > 0 && (
            <Card>
              <CardHeader title="Your supervisors" />
              <ul className="space-y-2">
                {selected.supervisors.map((supervisor) => (
                  <li
                    key={supervisor.id ?? supervisor.name}
                    className="flex items-center justify-between gap-2"
                  >
                    <div className="min-w-0">
                      <p className="text-sm text-slate-800 truncate">{supervisor.name}</p>
                      {supervisor.staff_no && (
                        <p className="text-xs text-slate-500">{supervisor.staff_no}</p>
                      )}
                    </div>
                    {supervisor.pivot?.role && (
                      <Badge
                        tone={
                          supervisor.pivot.role === 'primary'
                            ? 'bg-brand-50 text-brand-700 border-brand-200'
                            : 'bg-slate-100 text-slate-600 border-slate-200'
                        }
                      >
                        {supervisor.pivot.role}
                      </Badge>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {/* Result, once released */}
          {selected.primary_grade?.status === 'released' && (
            <Card>
              <CardHeader title="Result" subtitle="Released by the coordinator" />
              <div className="flex items-baseline gap-3">
                <p className="text-3xl font-medium text-slate-900">
                  {formatMark(selected.primary_grade.final_mark)}
                </p>
                <Badge
                  tone={
                    selected.primary_grade.is_pass
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-rose-50 text-rose-700 border-rose-200'
                  }
                >
                  {selected.primary_grade.grade_letter}
                </Badge>
              </div>
              <p className="text-xs text-slate-500 mt-2">
                {selected.primary_grade.grade_label}
              </p>
            </Card>
          )}
        </div>
      </div>
    </>
  )
}
