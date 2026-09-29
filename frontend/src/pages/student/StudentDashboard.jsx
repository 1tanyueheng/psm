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

const SUPERVISION_ROLE = {
  primary: 'أساسي',
  co: 'مشارك',
}

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

  if (loading) return <Spinner label="جارٍ تحميل مشروعك…" />

  if (error) {
    return (
      <>
        <PageHeader title="مشروعي" />
        <ErrorState message={error} onRetry={() => window.location.reload()} />
      </>
    )
  }

  // --- No project yet: the registration call to action -------------------
  if (!selected) {
    return (
      <>
        <PageHeader
          title={`مرحباً، ${user?.name?.split(' ')[0] ?? 'بك'}`}
          subtitle="لا يوجد لديك مشروع PSM مسجّل بعد."
        />
        <Card>
          <EmptyState
            title="سجّل مشروعك"
            description="قدّم عنوانك المقترح والملخص والفئة. سيقوم المنسق بتعيين مشرف بعد الموافقة."
            action={
              <Link to="/projects/new">
                <Button>تسجيل مشروع</Button>
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
                تفاصيل المشروع
              </Button>
            </Link>
          </div>
        }
      />

      {/* --- Headline numbers --- */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <StatCard
          label="التقدم الإجمالي"
          value={`${Math.round(Number(progress) || 0)}%`}
          hint={`${approved} من ${milestones.length} مراحل معتمدة`}
          tone="info"
        />
        <StatCard label="معتمد" value={approved} tone="good" />
        <StatCard label="بانتظار المراجعة" value={submitted} tone="warn" />
        <StatCard
          label="المرحلة الحالية"
          value={current ? current.sequence : '—'}
          hint={current?.title ?? 'اكتملت جميع المراحل'}
        />
      </div>

      <div className="grid lg:grid-cols-3 gap-5">
        {/* --- Milestone timeline --- */}
        <div className="lg:col-span-2">
          <Card>
            <CardHeader
              title="المراحل"
              subtitle="سلسلة تقديمك لهذا المشروع"
            />

            {milestones.length === 0 ? (
              <EmptyState
                title="لا توجد مراحل بعد"
                description="تُنشأ المراحل بعد الموافقة على تسجيل مشروعك."
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
                                المراجعة {milestone.revision_count}
                              </Badge>
                            )}
                          </div>

                          <p className="text-xs text-slate-500 mt-1">
                            مستحق {formatDate(milestone.due_at)}
                            {milestone.due_at && ` · ${relativeDays(milestone.due_at)}`}
                            {milestone.weight_percent
                              ? ` · ${Number(milestone.weight_percent)}% من التقدم`
                              : ''}
                          </p>

                          {milestone.review_comment && (
                            <p className="text-xs text-slate-600 mt-1.5 line-clamp-2">
                              <span className="text-slate-400">المشرف: </span>
                              {milestone.review_comment}
                            </p>
                          )}
                        </div>

                        <span className="text-slate-300 text-sm shrink-0" aria-hidden="true">
                          ‹
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
            <CardHeader title="ما عليك فعله بعد ذلك" />

            {current ? (
              <div className="space-y-3">
                <div>
                  <p className="text-sm font-medium text-slate-900">{current.title}</p>
                  <p className="text-xs text-slate-500 mt-0.5">
                    مستحق {formatDate(current.due_at)} · {relativeDays(current.due_at)}
                  </p>
                </div>

                <ProgressBar value={progress} showLabel tone="bg-brand-500" />

                {['open', 'rejected', 'overdue'].includes(current.status) && (
                  <Link to={`/milestones/${current.id}`} className="block">
                    <Button className="w-full" size="sm">
                      {current.status === 'rejected' ? 'إعادة تقديم العمل' : 'تقديم العمل'}
                    </Button>
                  </Link>
                )}

                {current.status === 'pending' && (
                  <p className="text-xs text-slate-500 bg-slate-50 rounded-lg p-3">
                    تُفتح هذه المرحلة بعد اعتماد المرحلة السابقة.
                  </p>
                )}
              </div>
            ) : (
              <p className="text-sm text-slate-600">
                تم اعتماد جميع المراحل. سيقوم مشرفك بتأكيد ترتيبات الامتحان.
              </p>
            )}
          </Card>

          {/* Supervisors */}
          {selected.supervisors?.length > 0 && (
            <Card>
              <CardHeader title="المشرفون عليك" />
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
                        {SUPERVISION_ROLE[supervisor.pivot.role] ?? supervisor.pivot.role}
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
              <CardHeader title="النتيجة" subtitle="صادق عليها المنسق" />
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