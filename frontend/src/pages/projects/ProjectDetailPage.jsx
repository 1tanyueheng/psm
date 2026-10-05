import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { projectApi, milestoneApi, evaluationApi, markApi, markSubmissionApi } from '../../api/endpoints'
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
import MarkBreakdown, { MarkTotal } from '../../components/MarkBreakdown'

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
  const [mark, setMark] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [tab, setTab] = useState('milestones')
  const [progressing, setProgressing] = useState(false)
  const [progressError, setProgressError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [projectRes, milestoneRes, evaluationRes, markRes] = await Promise.allSettled([
        projectApi.show(id),
        milestoneApi.list({ project_id: id, per_page: 100 }),
        evaluationApi.list({ project_id: id, per_page: 100 }),
        markApi.forProject(id),
      ])

      if (projectRes.status === 'rejected') throw projectRes.reason
      setProject(unwrap(projectRes.value))

      setMilestones(
        milestoneRes.status === 'fulfilled' ? unwrapPaged(milestoneRes.value).items : []
      )
      setEvaluations(
        evaluationRes.status === 'fulfilled' ? unwrapPaged(evaluationRes.value).items : []
      )
      // A mark may legitimately not exist yet, or be hidden from this role —
      // either way it is not an error worth failing the whole page over.
      setMark(markRes.status === 'fulfilled' ? unwrap(markRes.value) : null)
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
  const isCoordinator = user?.role === 'coordinator' || user?.role === 'admin'

  // PSM 2 continues this project in the next term, so the action is offered on
  // a live PSM 1 project only.
  const canProgress = isCoordinator && project.psm_part === 'PSM1' && !project.archived_at

  /**
   * Marking is opened per (term, batch), not per project: one window allocates
   * every Lampiran that batch needs. So this is a single action for the whole
   * cohort rather than one per student, and the link carries this project's term
   * and part so the coordinator lands on the right batch instead of an unscoped
   * list.
   */
  const canOpenAssessment = isCoordinator && !project.archived_at
  const assessmentHref =
    `/assessment?semester_id=${project.academic_semester_id ?? ''}`
    + `&psm_part=${project.psm_part ?? ''}`

  const approved = milestones.filter((m) => m.status === 'approved').length
  const overdue = milestones.filter(
    (m) => m.status !== 'approved' && isOverdue(m.effective_due_at ?? m.due_at, m.status)
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
        action={
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

        {/* Quick actions */}
        <Card>
          <CardHeader
            title="Quick actions"
            subtitle="Assessment is opened for a whole batch, not one student at a time"
          />
          <div className="space-y-2">
            {canOpenAssessment && (
              <Link to={assessmentHref}>
                <Button variant="outline" className="w-full justify-start">
                  Open assessment window
                </Button>
              </Link>
            )}
            {canProgress && (
              <Button
                variant="outline"
                className="w-full justify-start"
                disabled={progressing}
                onClick={async () => {
                  const go = window.confirm(
                    'Progress this student to PSM 2?\n\n'
                    + 'A PSM 2 project is created from this title in the next term, the supervisor '
                    + 'and the examiner panel carry over, and this PSM 1 project is archived.\n\n'
                    + 'The PSM 1 marks must already be released.',
                  )
                  if (!go) return

                  setProgressing(true)
                  setProgressError(null)
                  try {
                    const created = unwrap(await projectApi.progressToPsm2(id))
                    navigate(`/projects/${created?.project_id ?? ''}`)
                  } catch (err) {
                    setProgressError(err?.message ?? 'Could not progress the student.')
                  } finally {
                    setProgressing(false)
                  }
                }}
              >
                {progressing ? 'Progressing…' : 'Progress to PSM 2'}
              </Button>
            )}

            {progressError && (
              <p className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">{progressError}</p>
            )}
          </div>
        </Card>
      </div>

      {/* People */}
      <Card>
        <CardHeader title="People" />
        <div className="space-y-4">
          <PersonGroup label="Students" people={project.students} showId />
          <PersonGroup label="Supervisors" people={project.supervisors} />
          <PersonGroup label="Examiners" people={project.examiners} />
        </div>
      </Card>

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
            { key: 'mark', label: 'Mark' },
            { key: 'marks', label: 'Mark Submission', count: project?.students?.length },
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
          {tab === 'mark' && <MarkTab project={project} mark={mark} />}
          {tab === 'marks' && <MarkSubmissionTab project={project} />}
        </div>
      </Card>
    </div>
  )
}

function MilestoneTab({ milestones }) {
  if (milestones.length === 0) {
    return (
      <EmptyState
        title="No milestones"
        message="Create milestones to track progress against the timeline."
      />
    )
  }

  return (
    <DataTable
      columns={[
        { key: 'title', label: 'Milestone' },
        { key: 'status', label: 'Status' },
        { key: 'due_at', label: 'Due' },
        { key: 'approved_at', label: 'Approved' },
        { key: 'actions', label: '' },
      ]}
      rows={milestones}
      render={(row) => (
        <tr key={row.id} className="hover:bg-slate-50/60">
          <Td>
            {/*
              The whole row is not the link — a milestone row carries badges and
              an "Overdue" flag, and making all of it clickable makes selecting
              text impossible. The title is the target, with a chevron so it
              reads as one.
            */}
            <Link
              to={`/milestones/${row.id}`}
              className="font-medium text-slate-800 hover:text-brand-700 hover:underline"
            >
              {row.title}
            </Link>
            {row.description && (
              <p className="text-sm text-slate-500 truncate max-w-md">{row.description}</p>
            )}
          </Td>
          <Td>
            <Badge tone={statusMeta(MILESTONE_STATUS, row.status).tone}>
              {statusMeta(MILESTONE_STATUS, row.status).label}
            </Badge>
          </Td>
          <Td className="whitespace-nowrap">
            {formatDate(row.effective_due_at ?? row.due_at, { fallback: '—' })}
            {row.status !== 'approved' && isOverdue(row.effective_due_at ?? row.due_at, row.status) && (
              <span className="ml-2 text-xs text-rose-600 font-medium">Overdue</span>
            )}
          </Td>
          <Td className="whitespace-nowrap">
            {row.approved_at ? formatDateTime(row.approved_at) : '—'}
          </Td>
          <Td>
            <Link to={`/milestones/${row.id}`}>
              <Button size="sm" variant="ghost">Open</Button>
            </Link>
          </Td>
        </tr>
      )}
    />
  )
}

function EvaluationTab({ evaluations }) {
  if (evaluations.length === 0) {
    return (
      <EmptyState
        title="No assessments"
        message="No evaluation forms have been created for this project yet."
      />
    )
  }

  return (
    <DataTable columns={['Assessor', 'Type', 'Status', 'Mark', 'Submitted']}>
      {evaluations.map((ev) => (
        <tr key={ev.id}>
          <Td>{ev.assessor?.name ?? '—'}</Td>
          <Td>
            <Badge tone={ev.assessor_type === 'supervisor' ? 'blue' : 'green'}>
              {ev.assessor_type}
            </Badge>
          </Td>
          <Td>
            <Badge tone={
              ev.status === 'submitted' ? 'green' :
              ev.status === 'draft' ? 'amber' : 'neutral'
            }>
              {ev.status}
            </Badge>
          </Td>
          <Td className="font-semibold tabular-nums">
            {/* The mark in the form's own units — see the shared
                MarkBreakdown for the same figure per component. */}
            {ev.raw_score !== null && ev.max_score != null
              ? `${formatMark(ev.raw_score)} / ${formatMark(ev.max_score)}`
              : '—'}
          </Td>
          <Td>{ev.submitted_at ? formatDateTime(ev.submitted_at) : '—'}</Td>
        </tr>
      ))}
    </DataTable>
  )
}

function MarkTab({ project, mark }) {
  const { user } = useAuth()
  const [breakdown, setBreakdown] = useState(null)
  const [breakdownLoading, setBreakdownLoading] = useState(false)

  // Whose mark to show: a student sees their own, staff see the project's.
  // Projects are single-member, so the first roster entry is the one.
  const roster = project?.students ?? []
  const own = roster.find((s) => s.user_id === user?.id)
  const studentId = (own ?? roster[0])?.id ?? null

  // `mark` is the whole project-grades payload, not one grade: rows live under
  // `final_grades`. Guarding on `mark.final_mark` read a key that never exists
  // on this shape, so the tab reported "no mark yet" even when one was out.
  const grades = mark?.final_grades ?? []
  const grade = grades[0] ?? null

  useEffect(() => {
    if (!project || studentId == null) return undefined

    let cancelled = false
    setBreakdownLoading(true)

    projectApi
      .markBreakdown(project.id, studentId)
      .then((data) => {
        if (!cancelled) setBreakdown(data)
      })
      .catch(() => {
        // The breakdown explains the mark; failing to load it must not blank
        // the tab.
        if (!cancelled) setBreakdown(null)
      })
      .finally(() => {
        if (!cancelled) setBreakdownLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [project, studentId])

  // A grade row can exist before anything has been marked, and the server
  // withholds the total entirely from a student until there is something true
  // to show — so trust the breakdown's own `released` flag when we have it.
  const hasNumber = breakdown?.released
    ? breakdown.total_marks != null
    : grade != null && grade.final_mark != null

  if (!hasNumber) {
    return (
      <EmptyState
        title="No mark yet"
        message="The mark appears automatically as each assessor submits, starting with
                 the supervisor. Nothing has to be released."
      />
    )
  }

  return (
    <Card>
      <CardHeader
        title="Mark by Lampiran"
        subtitle="The mark as recorded on each form, in the form's own units"
        action={<MarkTotal breakdown={breakdown} />}
      />

      {breakdownLoading ? (
        <Spinner label="Loading the breakdown" />
      ) : (
        <MarkBreakdown
          breakdown={breakdown}
          emptyMessage="The breakdown appears once the assessors' forms are in."
        />
      )}
    </Card>
  )
}

function MarkSubmissionTab({ project }) {
  const { user } = useAuth()
  const isCoordinator = user?.role === 'coordinator' || user?.role === 'admin'

  if (!isCoordinator) {
    return (
      <div className="p-4 text-center text-slate-500">
        <p>Mark submissions are managed by the coordinator.</p>
      </div>
    )
  }

  if (!project?.students?.length) {
    return (
      <div className="p-4 text-center text-slate-500">
        <p>No students enrolled in this project.</p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">
        Open a mark submission for each student to allocate forms and track readiness.
      </p>
      <div className="space-y-2">
        {project.students.map((student) => (
          <Link
            key={student.id}
            to={`/coordinator/projects/${project.id}/students/${student.id}/mark-submission`}
            className="block"
          >
            <Card variant="bordered" className="p-4 hover:bg-slate-50 transition-colors">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <Avatar name={student.user?.name ?? '?'} size="md" />
                  <div>
                    <p className="font-medium text-slate-800">{student.user?.name}</p>
                    <p className="text-sm text-slate-500">
                      {student.student_id ?? student.user?.email}
                    </p>
                  </div>
                </div>
                <Button variant="secondary" size="sm">Open Submission</Button>
              </div>
            </Card>
          </Link>
        ))}
      </div>
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
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}