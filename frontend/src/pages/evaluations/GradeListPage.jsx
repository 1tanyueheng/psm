import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { gradeApi, reportApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { can } from '../../lib/permissions'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input,
} from '../../components/ui'
import { formatMark, formatDate, CATEGORY_LABELS, gradeTone } from '../../lib/format'

/**
 * Grade list — the moderation and release surface (Module 4 tail end).
 *
 * Coordinator and admin staff come here to do one job: check computed grades
 * and release them. The page therefore leads with the "pending release" count
 * and offers bulk release, since releasing one at a time across a cohort of
 * dozens is the kind of friction that stops a process being followed.
 */
export default function GradeListPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [distribution, setDistribution] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState(null)

  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''
  const canRelease = can(user?.role, 'releaseGrades')

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [listRes, distRes] = await Promise.allSettled([
        gradeApi.list({
          status: status || undefined,
          q: search || undefined,
          per_page: 100,
        }),
        reportApi.gradeDistribution(),
      ])

      if (listRes.status === 'rejected') throw listRes.reason
      const { items, meta: pageMeta } = unwrapPaged(listRes.value)
      setRows(items)
      setMeta(pageMeta)
      setDistribution(
        distRes.status === 'fulfilled' ? unwrap(distRes.value)?.by_band ?? [] : []
      )
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [status, search])

  useEffect(() => {
    load()
  }, [load])

  const pending = useMemo(() => rows.filter((r) => r.status === 'computed'), [rows])

  async function release(grade) {
    setBusyId(grade.id)
    setActionError(null)
    try {
      await gradeApi.release(grade.id)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر الإفراج عن هذه الدرجة.')
    } finally {
      setBusyId(null)
    }
  }

  async function releaseAll() {
    setBusyId('all')
    setActionError(null)
    try {
      // Release pending grades one by one so a single failure does not block
      // the rest — the API has no bulk endpoint by design, since each release
      // writes its own audit entry.
      for (const grade of pending) {
        await gradeApi.release(grade.id)
      }
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر الإفراج عن بعض الدرجات.')
      await load()
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل الدرجات" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="الدرجات"
        subtitle={meta ? `${meta.total} محسوبة` : undefined}
        actions={
          canRelease && pending.length > 0 ? (
            <Button onClick={releaseAll} disabled={busyId === 'all'}>
              {busyId === 'all' ? 'جارٍ الإفراج…' : `الإفراج عن الكل (${pending.length})`}
            </Button>
          ) : null
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {canRelease && pending.length > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <p className="text-sm text-amber-900">
            <span className="font-semibold">{pending.length}</span>{' '}
            {pending.length === 1 ? 'درجة محسوبة' : 'درجات محسوبة'} لكنها غير ظاهرة للطلاب بعد.
            الإفراج ينشر الدرجة ويكتب سجل تدقيق لكل مشروع.
          </p>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-4">
        <Card>
          <CardHeader title="التوزيع" />
          {distribution.length === 0 ? (
            <p className="text-sm text-slate-500">لا توجد بيانات</p>
          ) : (
            <ul className="space-y-2">
              {distribution.map((row) => (
                <li key={row.grade ?? row.label} className="flex items-center justify-between">
                  <span className={`rounded px-1.5 text-sm font-semibold ${gradeTone(row.grade)}`}>
                    {row.grade ?? row.label}
                  </span>
                  <span className="text-sm tabular-nums text-slate-600">{row.count ?? 0}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <div className="lg:col-span-3">
          <Card>
            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                type="search"
                placeholder="ابحث باسم طالب أو مشروع"
                defaultValue={search}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
                }}
                aria-label="البحث في الدرجات"
              />
              <Select
                value={status}
                onChange={(e) => setFilter('status', e.target.value)}
                aria-label="تصفية حسب الحالة"
              >
                <option value="">كل الحالات</option>
                <option value="computed">محسوبة، غير مُفرج عنها</option>
                <option value="released">مُفرج عنها</option>
                <option value="pending">تقييم معلّق</option>
                <option value="moderated">تمت المراجعة</option>
              </Select>
            </div>
          </Card>
        </div>
      </div>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="لا توجد درجات"
            message="تظهر الدرجات بعد أن يسلم المقيمون درجاتهم ويُحسب التقدير النهائي."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <DataTable
            columns={[
              { key: 'project', label: 'المشروع' },
              { key: 'student', label: 'الطالب' },
              { key: 'category', label: 'الفئة' },
              { key: 'assessors', label: 'المقيمون' },
              { key: 'mark', label: 'الدرجة النهائية' },
              { key: 'grade', label: 'التقدير' },
              { key: 'status', label: 'الحالة' },
              { key: 'actions', label: '' },
            ]}
            render={(grade) => (
                <tr key={grade.id} className="hover:bg-slate-50/60">
                  <Td>
                    <Link
                      to={`/projects/${grade.project_id}`}
                      className="font-medium text-slate-800 hover:text-brand-700"
                    >
                      {grade.project?.title ?? `#${grade.project_id}`}
                    </Link>
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <Avatar name={grade.project?.students?.[0]?.name ?? '—'} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm text-slate-700">
                          {grade.project?.students?.[0]?.name ?? '—'}
                        </div>
                        <div className="font-mono text-xs text-slate-400">
                          {grade.project?.students?.[0]?.student_id}
                        </div>
                      </div>
                    </div>
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {CATEGORY_LABELS[grade.project?.category] ?? '—'}
                  </Td>
                  <Td className="text-center tabular-nums text-slate-600">
                    {grade.assessor_count ?? 0}
                    {grade.min_assessors != null && grade.assessor_count < grade.min_assessors && (
                      <span className="ms-1 text-xs text-amber-600">غير كافٍ</span>
                    )}
                  </Td>
                  <Td className="font-semibold tabular-nums">
                    {grade.final_mark != null ? formatMark(grade.final_mark) : '—'}
                  </Td>
                  <Td>
                    <GradePill letter={grade.grade_letter} point={grade.grade_point} />
                  </Td>
                  <Td>
                    <Badge
                      tone={
                        grade.status === 'released'
                          ? 'success'
                          : grade.status === 'computed'
                            ? 'warning'
                            : 'neutral'
                      }
                    >
                      {grade.status === 'computed' ? 'بانتظار الإفراج' : grade.status}
                    </Badge>
                    {grade.released_at && (
                      <div className="mt-0.5 text-xs text-slate-400">
                        {formatDate(grade.released_at)}
                      </div>
                    )}
                  </Td>
                  <Td>
                    {canRelease && grade.status === 'computed' ? (
                      <Button
                        size="sm"
                        disabled={busyId === grade.id || busyId === 'all'}
                        onClick={() => release(grade)}
                      >
                        {busyId === grade.id ? 'جارٍ الإفراج…' : 'إفراج'}
                      </Button>
                    ) : (
                      <Link to={`/projects/${grade.project_id}`}>
                        <Button size="sm" variant="ghost">
                          عرض
                        </Button>
                      </Link>
                    )}
                  </Td>
                </tr>
              )
            }
          />
        </Card>
      )}
    </div>
  )
}

function GradePill({ letter, point }) {
  if (!letter) return <span className="text-slate-400">—</span>
  return (
    <span className="inline-flex items-baseline gap-1.5">
      <span className={`rounded px-1.5 py-0.5 text-sm font-semibold ${gradeTone(letter)}`}>
        {letter}
      </span>
      {point != null && (
        <span className="text-xs tabular-nums text-slate-400">{point.toFixed(2)}</span>
      )}
    </span>
  )
}
