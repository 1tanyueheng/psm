import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { evaluationApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, Avatar,
  EmptyState, Spinner, ErrorState, Button, ProgressBar,
} from '../../components/ui'
import { formatDate, formatMark, relativeDays, EVALUATION_STATUS, EXAMINER_PANEL_ROLES, statusMeta } from '../../lib/format'

/**
 * Examiner workspace.
 *
 * An examiner does not own a project — they are invited to mark one. So the
 * page is built around "what have I been asked to examine, and what is still
 * outstanding" rather than a roster of long-term relationships.
 */
export default function ExaminerDashboard() {
  const { user } = useAuth()
  const [forms, setForms] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const { items } = unwrapPaged(
          await evaluationApi.list({ mine: true, as: 'examiner', per_page: 100 })
        )
        if (!cancelled) setForms(items)
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

  const { drafts, submitted, stats } = useMemo(() => {
    const isDraft = (f) => f.status === 'draft'
    const draftRows = forms.filter(isDraft)
    const submittedRows = forms.filter((f) => !isDraft(f))

    return {
      drafts: draftRows,
      submitted: submittedRows,
      stats: {
        total: forms.length,
        outstanding: draftRows.length,
        done: submittedRows.length,
        conflicts: forms.filter((f) => f.has_conflict).length,
      },
    }
  }, [forms])

  if (loading) return <Spinner label="جارٍ تحميل تكليفات الامتحان" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title={`مرحباً، ${user?.name?.split(' ')[0] ?? 'ممتحن'}`}
        subtitle="المشاريع التي تم تكليفك بتقييمها"
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="الموكل إليك" value={stats.total} hint="نماذج التقييم" />
        <StatCard
          label="معلق"
          value={stats.outstanding}
          hint="مسودة غير مقدمة"
          tone={stats.outstanding > 0 ? 'warning' : 'default'}
        />
        <StatCard label="مقدمة" value={stats.done} hint="مقفلة ومحتسبة" tone="success" />
        <StatCard
          label="تعارض معلَن"
          value={stats.conflicts}
          hint="مستبعدة من التقييم"
          tone={stats.conflicts > 0 ? 'danger' : 'default'}
        />
      </div>

      {stats.outstanding > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <div className="flex flex-wrap items-center gap-3 p-1">
            <div className="flex-1">
              <p className="font-medium text-amber-900">
                {stats.outstanding} نموذج لا يزال في المسودة
              </p>
              <p className="text-sm text-amber-800">
                العلامات لا تظهر لأي أحد حتى تقدم. قدّم قبل موعد اللجنة.
              </p>
            </div>
          </div>
        </Card>
      )}

      <Card>
        <CardHeader
          title="التقييمات المعلقة"
          subtitle="نماذج مسودة بانتظار إكمالها"
        />
        {drafts.length === 0 ? (
          <EmptyState
            title="لا توجد تقييمات معلقة"
            message="تم تقديم جميع النماذج الموكل بها إليك. شكراً لك."
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {drafts.map((form) => (
              <FormRow key={form.id} form={form} />
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="التقييمات المقدمة" subtitle="سجل للقراءة فقط لما أودعته" />
        {submitted.length === 0 ? (
          <EmptyState title="لا توجد تقديمات بعد" message="تُحفظ النماذج المقدمة هنا." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {submitted.map((form) => (
              <FormRow key={form.id} form={form} readOnly />
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function FormRow({ form, readOnly = false }) {
  const meta = statusMeta(EVALUATION_STATUS, form.status)
  const project = form.project ?? {}
  const lead = project.students?.[0]

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start gap-4">
        <Avatar name={lead?.name ?? project.title ?? 'Project'} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Link
              to={`/evaluations/${form.id}`}
              className="font-medium text-slate-900 hover:text-brand-700"
            >
              {project.title ?? `تقييم #${form.id}`}
            </Link>
            <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? form.status}</Badge>
            {form.panel_role && (
              <Badge tone="neutral">{EXAMINER_PANEL_ROLES[form.panel_role] ?? form.panel_role}</Badge>
            )}
            {form.has_conflict && <Badge tone="danger">تم الإعلان عن تعارض</Badge>}
          </div>

          <p className="mt-1 text-sm text-slate-600">
            {lead?.name}
            {lead?.student_id && (
              <span className="ms-2 font-mono text-xs text-slate-400">{lead.student_id}</span>
            )}
          </p>

          {/* Only show progress on a draft — a submitted form's marks are frozen. */}
          {!readOnly && form.rubric_completion_percent != null && (
            <div className="mt-3 flex items-center gap-3">
              <div className="w-full max-w-xs">
                <ProgressBar
                  value={form.rubric_completion_percent}
                  tone={form.rubric_completion_percent >= 80 ? 'success' : 'brand'}
                />
              </div>
              <span className="w-10 shrink-0 text-xs tabular-nums text-slate-500">
                {form.rubric_completion_percent}%
              </span>
            </div>
          )}

          {readOnly && form.aggregate_mark != null && (
            <p className="mt-2 text-sm text-slate-700">
              علامتك: <span className="font-semibold tabular-nums">{formatMark(form.aggregate_mark)}</span>
            </p>
          )}
        </div>

        <div className="text-end text-sm">
          {form.submitted_at ? (
            <>
              <p className="text-slate-700">{formatDate(form.submitted_at)}</p>
              <p className="text-xs text-slate-400">{relativeDays(form.submitted_at)}</p>
            </>
          ) : form.due_at ? (
            <>
              <p className="text-slate-700">مستحق {formatDate(form.due_at)}</p>
              <p className="text-xs text-slate-400">{relativeDays(form.due_at)}</p>
            </>
          ) : null}
        </div>

        <Link to={`/evaluations/${form.id}`}>
          <Button size="sm" variant={readOnly ? 'secondary' : 'primary'}>
            {readOnly ? 'عرض' : 'تقييم'}
          </Button>
        </Link>
      </div>
    </li>
  )
}