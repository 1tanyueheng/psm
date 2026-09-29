import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { projectApi, milestoneApi, evaluationApi, gradeApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, ProgressBar, EmptyState,
  Spinner, ErrorState, Button, DataTable, Td,
} from '../../components/ui'
import {
  formatDate, formatDateTime, formatMark, relativeDays, isOverdue,
  CATEGORY_LABELS, MILESTONE_STATUS, statusMeta,
} from '../../lib/format'
import { StatusBadge } from './ProjectListPage'

/**
 * Project detail — the single page from which a project is understood.
 *
 * Every role lands here for a different reason (student: what is next;
 * supervisor: what do I review; examiner: what am I marking), so the page
 * shows one canonical set of sections and lets the role determine which
 * actions are enabled rather than branching the layout.
 */
export default function ProjectDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()

  const [project, setProject] = useState(null)
  const [milestones, setMilestones] = useState([])
  const [evaluations, setEvaluations] = useState([])
  const [grade, setGrade] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [tab, setTab] = useState('milestones')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [projectRes, milestoneRes, evaluationRes, gradeRes] = await Promise.allSettled([
        projectApi.show(id),
        milestoneApi.list({ project_id: id, per_page: 100 }),
        evaluationApi.list({ project_id: id, per_page: 100 }),
        gradeApi.forProject(id),
      ])

      if (projectRes.status === 'rejected') throw projectRes.reason
      setProject(unwrap(projectRes.value))

      setMilestones(
        milestoneRes.status === 'fulfilled' ? unwrapPaged(milestoneRes.value).items : []
      )
      setEvaluations(
        evaluationRes.status === 'fulfilled' ? unwrapPaged(evaluationRes.value).items : []
      )
      // A grade may legitimately not exist yet, or be hidden from this role —
      // either way it is not an error worth failing the whole page over.
      setGrade(gradeRes.status === 'fulfilled' ? unwrap(gradeRes.value) : null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  if (loading) return <Spinner label="Loading project" />
  if (error) return <ErrorState error={error} />
  if (!project) return <ErrorState error={{ message: 'Project not found.' }} />

  const isOwner = project.students?.some((s) => s.user_id === user?.id)
  const approved = milestones.filter((m) => m.status === 'approved').length
  const overdue = milestones.filter(
    (m) => m.status !== 'approved' && isOverdue(m.effective_due_at ?? m.due_at)
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={project.title}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <StatusBadge status={project.status} />
            <Badge tone={project.psm_part === 'PSM2' ? 'brand' : 'neutral'}>
              {project.psm_part}
            </Badge>
            <Badge tone="neutral">{CATEGORY_LABELS[project.category] ?? project.category}</Badge>
            {project.code && <span className="font-mono text-xs">{project.code}</span>}
          </span>
        }
        actions={
          <div className="flex gap-2">
            {isOwner && project.status === 'draft' && (
              <Button onClick={() => navigate(`/projects/${id}/edit`)}>Edit</Button>
            )}
            {isOwner && ['draft', 'registered'].includes(project.status) && (
              <Button
                variant="secondary"
                onClick={async () => {
                  await projectApi.submit(id)
                  load()
                }}
              >
                Submit for approval
              </Button>
            )}
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Progress summary */}
        <Card className="lg:col-span-2">
          <CardHeader title="Progress" subtitle={`${approved} of ${milestones.length} milestones approved`} />
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <div className="flex-1">
                <ProgressBar
                  value={project.progress_percent ?? 0}
                  tone={overdue.length > 0 ? 'danger' : project.progress_percent >= 70 ? 'success' : 'brand'}
                />
              </div>
              <span className="w-12 shrink-0 text-right font-semibold tabular-nums text-slate-700">
                {project.progress_percent ?? 0}%
              </span>
            </div>
            {overdue.length > 0 && (
              <p className="text-sm text-rose-600">
                {overdue.length} milestone{overdue.length === 1 ? '' : 's'} overdue
              </p>
            )}
            <div className="grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-4">
              <Mini label="Started" value={formatDate(project.started_at, { fallback: '—' })} />
              <Mini label="Target" value={formatDate(project.target_end_at, { fallback: '—' })} />
              <Mini label="Session" value={project.academic_session ?? '—'} />
              <Mini label="Updated" value={relativeDays(project.updated_at)} />
            </div>
          </div>
        </Card>

        {/* People */}
        <Card>
          <CardHeader title="People" />
          <div className="space-y-4">
            <PersonGroup label="Students" people={project.students} showId />
            <PersonGroup label="Supervisors" people={project.supervisors} />
            <PersonGroup label="Examiners" people={project.examiners} />
          </div>
        </Card>
      </div>

      {project.abstract && (
        <Card>
          <CardHeader title="Abstract" />
          <p className="whitespace-pre-line text-sm leading-relaxed text-slate-700">
            {project.abstract}
          </p>
          {project.keywords?.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-1.5">
              {project.keywords.map((kw) => (
                <Badge key={kw} tone="neutral">
                  {kw}
                </Badge>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Tabs keep the page digestible — milestones are one of four concerns. */}
      <Card className="p-0">
        <div className="flex overflow-x-auto border-b border-slate-200 px-2">
          {[
            { key: 'milestones', label: 'Milestones', count: milestones.length },
            { key: 'evaluations', label: 'Assessments', count: evaluations.length },
            { key: 'grade', label: 'Grade' },
          ].map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setTab(t.key)}
              className={`shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition ${
                tab === t.key
                  ? 'border-brand-600 text-brand-700'
                  : 'border-transparent text-slate-500 hover:text-slate-700'
              }`}
              aria-current={tab === t.key ? 'page' : undefined}
            >
              {t.label}
              {t.count != null && (
                <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 text-xs tabular-nums text-slate-600">
                  {t.count}
                </span>
              )}
            </button>
          ))}
        </div>

        <div className="p-5">
          {tab === 'milestones' && <MilestoneTab milestones={milestones} />}
          {tab === 'evaluations' && <EvaluationTab evaluations={evaluations} />}
          {tab === 'grade' && <GradeTab grade={grade} />}
        </div>
      </Card>
    </div>
  )
}

function MilestoneTab({ milestones }) {
  if (milestones.length === 0) {
    return (
      <EmptyState
        title="No milestones yet"
        message="Milestones are generated automatically once the project is approved and a template is matched."
      />
    )
  }

  return (
    <ol className="relative space-y-1">
      {milestones.map((milestone, index) => {
        const meta = statusMeta(MILESTONE_STATUS, milestone.status)
        const due = milestone.effective_due_at ?? milestone.due_at
        const late = milestone.status !== 'approved' && isOverdue(due)
        const isLast = index === milestones.length - 1

        return (
          <li key={milestone.id} className="relative flex gap-4 pb-6">
            {!isLast && (
              <span
                className="absolute left-3.5 top-8 h-full w-px bg-slate-200"
                aria-hidden="true"
              />
            )}
            <span
              className={`relative z-10 mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                milestone.status === 'approved'
                  ? 'bg-emerald-100 text-emerald-700'
                  : late
                    ? 'bg-rose-100 text-rose-700'
                    : milestone.status === 'submitted'
                      ? 'bg-amber-100 text-amber-700'
                      : 'bg-slate-100 text-slate-500'
              }`}
            >
              {milestone.status === 'approved' ? '✓' : index + 1}
            </span>

            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <Link
                  to={`/milestones/${milestone.id}`}
                  className="font-medium text-slate-800 hover:text-brand-700"
                >
                  {milestone.name}
                </Link>
                <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? milestone.status}</Badge>
                {milestone.sequence > 0 && (
                  <span className="font-mono text-xs text-slate-400">
                    {milestone.milestone_code}
                  </span>
                )}
                {(milestone.revision_count ?? 0) > 0 && (
                  <Badge tone="warning">rev {milestone.revision_count}</Badge>
                )}
              </div>

              {milestone.deliverable_expectation && (
                <p className="mt-1 line-clamp-2 text-sm text-slate-600">
                  {milestone.deliverable_expectation}
                </p>
              )}

              <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                <span className={late ? 'font-medium text-rose-600' : ''}>
                  Due {formatDate(due, { fallback: 'no date' })}
                  {late && ' · overdue'}
                </span>
                {milestone.submitted_at && <span>Submitted {formatDate(milestone.submitted_at)}</span>}
                {milestone.approved_at && (
                  <span className="text-emerald-600">Approved {formatDate(milestone.approved_at)}</span>
                )}
                {milestone.weight > 0 && <span>Weight {milestone.weight}%</span>}
              </div>
            </div>

            <Link to={`/milestones/${milestone.id}`}>
              <Button size="sm" variant="secondary">
                Open
              </Button>
            </Link>
          </li>
        )
      })}
    </ol>
  )
}

function EvaluationTab({ evaluations }) {
  if (evaluations.length === 0) {
    return (
      <EmptyState
        title="No assessments yet"
        message="Assessment forms appear when supervisors and examiners are asked to mark this project."
      />
    )
  }

  return (
    <DataTable columns={['Assessor', 'Type', 'Status', 'Mark', 'Submitted']}>
      {evaluations.map((ev) => (
        <tr key={ev.id} className="hover:bg-slate-50/60">
          <Td>
            <div className="text-sm font-medium text-slate-800">
              {ev.assessor?.name ?? '—'}
            </div>
            {ev.panel_role && (
              <div className="text-xs text-slate-400">{ev.panel_role}</div>
            )}
          </Td>
          <Td className="text-sm capitalize text-slate-600">{ev.assessor_type}</Td>
          <Td>
            <Badge tone={ev.status === 'submitted' || ev.status === 'released' ? 'success' : 'warning'}>
              {ev.status}
            </Badge>
          </Td>
          <Td className="font-semibold tabular-nums">
            {ev.aggregate_mark != null ? formatMark(ev.aggregate_mark) : '—'}
          </Td>
          <Td className="text-sm text-slate-500">
            {ev.submitted_at ? formatDateTime(ev.submitted_at) : '—'}
          </Td>
        </tr>
      ))}
    </DataTable>
  )
}

function GradeTab({ grade }) {
  if (!grade || !grade.status) {
    return (
      <EmptyState
        title="No grade yet"
        message="A grade is computed once enough assessors have submitted marks."
      />
    )
  }

  if (grade.status !== 'released') {
    return (
      <EmptyState
        title="Grade not released"
        message="Your grade has been computed but is being moderated. It will appear here once released by the coordinator."
      />
    )
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-6">
        <div>
          <p className="text-sm text-slate-500">Final mark</p>
          <p className="text-3xl font-semibold tabular-nums text-slate-900">
            {formatMark(grade.final_mark)}
          </p>
        </div>
        <div>
          <p className="text-sm text-slate-500">Grade</p>
          <p className="text-3xl font-semibold text-slate-900">{grade.grade_letter ?? '—'}</p>
        </div>
        {grade.grade_point != null && (
          <div>
            <p className="text-sm text-slate-500">Grade point</p>
            <p className="text-3xl font-semibold tabular-nums text-slate-900">
              {grade.grade_point.toFixed(2)}
            </p>
          </div>
        )}
      </div>

      {grade.breakdown && Object.keys(grade.breakdown).length > 0 && (
        <div className="border-t border-slate-100 pt-5">
          <p className="mb-3 text-sm font-medium text-slate-700">Breakdown</p>
          <div className="space-y-2">
            {Object.entries(grade.breakdown).map(([key, row]) => (
              <div key={key} className="flex items-center gap-3">
                <span className="w-40 shrink-0 text-sm capitalize text-slate-600">
                  {key.replace(/_/g, ' ')}
                </span>
                <div className="flex-1">
                  <ProgressBar
                    value={row.mark ?? row.score ?? 0}
                    max={100}
                    tone="brand"
                  />
                </div>
                <span className="w-24 shrink-0 text-right text-sm tabular-nums text-slate-600">
                  {formatMark(row.mark ?? row.score)}{' '}
                  <span className="text-xs text-slate-400">
                    ×{row.weight ?? 0}%
                  </span>
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function Mini({ label, value }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-sm font-medium text-slate-700">{value}</p>
    </div>
  )
}

function PersonGroup({ label, people, showId = false }) {
  const list = people ?? []
  return (
    <div>
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      {list.length === 0 ? (
        <p className="text-sm text-slate-400">Not assigned</p>
      ) : (
        <ul className="space-y-2">
          {list.map((person) => (
            <li key={`${label}-${person.id ?? person.user_id ?? person.name}`} className="flex items-center gap-2.5">
              <Avatar name={person.name ?? '?'} size="sm" />
              <div className="min-w-0">
                <p className="truncate text-sm text-slate-700">{person.name}</p>
                {showId && person.student_id && (
                  <p className="font-mono text-xs text-slate-400">{person.student_id}</p>
                )}
                {person.role && person.role !== 'primary' && (
                  <p className="text-xs text-slate-400">{person.role}</p>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
