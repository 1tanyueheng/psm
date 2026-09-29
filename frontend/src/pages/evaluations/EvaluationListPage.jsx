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

  if (loading) return <Spinner label="جارٍ تحميل التقييمات" />
  if (error) return <ErrorState error={error} />

  const canSeeAll = ['coordinator', 'admin'].includes(user?.role)

  return (
    <div className="space-y-6">
      <PageHeader
        title="التقييمات"
        subtitle={meta ? `${meta.total} ${meta.total === 1 ? 'نموذج' : 'نماذج'}` : undefined}
      />

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {canSeeAll && (
            <Select
              value={scope}
              onChange={(e) => setFilter('scope', e.target.value)}
              aria-label="النطاق"
            >
              <option value="mine">نماذجي</option>
              <option value="all">كل النماذج</option>
            </Select>
          )}
          <Select
            value={assessorType}
            onChange={(e) => setFilter('assessor_type', e.target.value)}
            aria-label="تصفية حسب نوع المقيم"
          >
            <option value="">كل أنواع المقيمين</option>
            <option value="supervisor">مشرف</option>
            <option value="examiner">ممتحن</option>
            <option value="coordinator">منسق</option>
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="تصفية حسب الحالة"
          >
            <option value="">كل الحالات</option>
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
              className="self-center text-start text-sm font-medium text-brand-700 hover:underline"
            >
              مسح التصفية
            </button>
          )}
        </div>
      </Card>

      <Card>
        <CardHeader
          title="معلّقة"
          subtitle="نماذج مسودة ما زالت بحاجة إلى درجات"
          action={open.length > 0 ? <Badge tone="warning">{open.length}</Badge> : <Badge tone="success">مكتملة</Badge>}
        />
        {open.length === 0 ? (
          <EmptyState
            title="لا يوجد شيء معلّق"
            message="لا توجد نماذج تقييم مسودة بانتظار إكمالها."
          />
        ) : (
          <ul className="divide-y divide-slate-100">
            {open.map((row) => (
              <EvaluationRow key={row.id} row={row} actionLabel="متابعة التقييم" />
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="مُسلَّمة" subtitle="النماذج المقدمة — للقراءة فقط" />
        {filed.length === 0 ? (
          <EmptyState title="لم يُسلَّم شيء بعد" message="ستُعرض النماذج المقدمة هنا." />
        ) : (
          <DataTable
            columns={[
              { key: 'project', label: 'المشروع' },
              { key: 'assessor', label: 'المقيم' },
              { key: 'type', label: 'النوع' },
              { key: 'status', label: 'الحالة' },
              { key: 'mark', label: 'الدرجة' },
              { key: 'submitted', label: 'أُرسل' },
              { key: 'action', label: '' },
            ]}
            rows={filed}
            render={(row) => {
              const m = statusMeta(EVALUATION_STATUS, row.status)
              return [
                <Td key="project">
                  <div className="text-sm font-medium text-slate-800">
                    {row.project?.title ?? `#${row.project_id}`}
                  </div>
                  <div className="text-xs text-slate-400">
                    {row.project?.students?.[0]?.name}
                  </div>
                </Td>,
                <Td key="assessor" className="text-sm text-slate-600">{row.assessor?.name ?? '—'}</Td>,
                <Td key="type" className="text-sm capitalize text-slate-600">{row.assessor_type}</Td>,
                <Td key="status">
                  <Badge tone={m?.tone ?? 'neutral'}>{m?.label ?? row.status}</Badge>
                </Td>,
                <Td key="mark" className="font-semibold tabular-nums">
                  {row.aggregate_mark != null ? formatMark(row.aggregate_mark) : '—'}
                </Td>,
                <Td key="submitted" className="text-sm text-slate-500">
                  {row.submitted_at ? formatDate(row.submitted_at) : '—'}
                </Td>,
                <Td key="action">
                  <Link to={`/evaluations/${row.id}`}>
                    <Button size="sm" variant="secondary">عرض</Button>
                  </Link>
                </Td>,
              ]
            }}
          />
        )}
      </Card>
    </div>
  )
}

function EvaluationRow({ row, actionLabel }) {
  return (
    <li className="flex flex-wrap items-center gap-3 py-3">
      <Avatar name={row.project?.students?.[0]?.name ?? 'المشروع'} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to={`/evaluations/${row.id}`}
            className="truncate font-medium text-slate-800 hover:text-brand-700"
          >
            {row.project?.title ?? `تقييم #${row.id}`}
          </Link>
          <Badge tone="neutral">{row.assessor_type}</Badge>
          {row.has_conflict && <Badge tone="danger">تعارض</Badge>}
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
      <div className="text-end text-sm">
        {row.due_at && !row.submitted_at && (
          <>
            <p className="text-slate-700">الموعد {formatDate(row.due_at)}</p>
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
