import { useEffect, useMemo, useState } from 'react'
import { reportApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, EmptyState, Spinner,
  ErrorState, Button, DataTable, Td, ProgressBar, Select,
} from '../../components/ui'
import { formatMark, gradeTone, CATEGORY_LABELS } from '../../lib/format'

/**
 * Reporting & analytics (Module 5).
 *
 * Everything here is aggregated, so the page is organised as a small dashboard
 * rather than a set of tables: cohort health at the top, then the breakdowns
 * a coordinator actually acts on (milestone stages, grade distribution,
 * milestone timeliness and supervision load), then the project mix.
 *
 * Export buttons point at the API's CSV endpoints — the browser downloads
 * directly from the API rather than streaming through React, so a large export
 * does not have to fit in memory here.
 */
export default function ReportPage() {
  const [session, setSession] = useState('')
  const [overview, setOverview] = useState(null)
  const [workload, setWorkload] = useState([])
  const [timeliness, setTimeliness] = useState([])
  const [distribution, setDistribution] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const params = session ? { batch: session } : {}

        const [overviewRes, workloadRes, timelinessRes, distRes] = await Promise.allSettled([
          reportApi.overview(params),
          reportApi.workload(params),
          reportApi.milestoneTimeliness(params),
          reportApi.gradeDistribution(params),
        ])

        if (overviewRes.status === 'rejected') throw overviewRes.reason
        if (cancelled) return

        const ov = unwrap(overviewRes.value) ?? {}
        setOverview(ov)
        setWorkload(workloadRes.status === 'fulfilled' ? (unwrap(workloadRes.value) ?? []) : [])
        setTimeliness(timelinessRes.status === 'fulfilled' ? (unwrap(timelinessRes.value) ?? []) : [])
        setDistribution(
          distRes.status === 'fulfilled' ? unwrap(distRes.value)?.by_band ?? [] : []
        )
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
  }, [session])

  const kpis = useMemo(() => {
    const cohort = overview?.cohort ?? {}
    const stages = cohort.stages ?? {}
    return {
      projects: cohort.total_projects ?? 0,
      students: cohort.total_students ?? 0,
      awaiting: overview?.awaiting_review ?? 0,
      overdue: stages.overdue?.count ?? 0,
      atRisk: overview?.at_risk ?? 0,
      graded: overview?.grades?.stats?.count ?? 0,
    }
  }, [overview])

  const batches = useMemo(() => {
    const seen = new Set((overview?.cohort?.by_batch ?? []).map((b) => b.batch).filter(Boolean))
    return [...seen]
  }, [overview])

  if (loading) return <Spinner label="جارٍ إنشاء عرض التحليلات" />
  if (error) return <ErrorState message={error.message || error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="التقارير والتحليلات"
        subtitle="تقدّم الفوج، وعبء الإشراف، ونتائج التقييم"
        actions={
          <div className="flex flex-wrap gap-2">
            <Select
              value={session}
              onChange={(e) => setSession(e.target.value)}
              aria-label="الدفعة"
            >
              <option value="">كل الدفعات</option>
              {batches.map((b) => (
                <option key={b} value={b}>
                  {b}
                </option>
              ))}
            </Select>
            <ExportButton label="تصدير الدرجات" endpoint="grades" session={session} />
            <ExportButton label="تصدير المشاريع" endpoint="projects" session={session} />
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <StatCard label="مشاريع نشطة" value={kpis.projects} />
        <StatCard label="الطلاب" value={kpis.students} />
        <StatCard label="بانتظار المراجعة" value={kpis.awaiting} tone={kpis.awaiting > 0 ? 'warning' : 'default'} />
        <StatCard label="متأخرة" value={kpis.overdue} tone={kpis.overdue > 0 ? 'danger' : 'success'} />
        <StatCard label="معرَّضة للخطر" value={kpis.atRisk} tone={kpis.atRisk > 0 ? 'danger' : 'default'} />
        <StatCard label="مُقيَّمة" value={kpis.graded} tone="success" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="مراحل المعالم" subtitle="أين يقف الفوج عبر سلسلة التسليم" />
          <ul className="divide-y divide-slate-100">
            {Object.entries(overview?.cohort?.stages ?? {}).length === 0 ? (
              <li className="py-3 text-sm text-slate-500">لا توجد بيانات</li>
            ) : (
              Object.entries(overview.cohort.stages).map(([status, row]) => (
                <li key={status} className="flex items-center justify-between py-2.5">
                  <span className="flex items-center gap-2 text-sm text-slate-700">
                    <Badge tone={STAGE_TONE[row.tone] ?? 'neutral'}>{row.label}</Badge>
                  </span>
                  <span className="font-semibold tabular-nums text-slate-800">{row.count}</span>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card>
          <CardHeader title="توزيع الدرجات" subtitle="عبر كل المشاريع المقيَّمة" />
          {distribution.length === 0 ? (
            <EmptyState title="لا توجد درجات" message="لم يُقيَّم أي شيء بعد." />
          ) : (
            <DistributionBars rows={distribution} />
          )}
        </Card>
      </div>

      <Card>
        <CardHeader
          title="الالتزام بالمواعيد"
          subtitle="الإنجاز لكل خطوة معلم عبر الفوج"
        />
        {timeliness.length === 0 ? (
          <EmptyState title="لا توجد بيانات" message="يظهر الالتزام بالمواعيد بعد إنشاء المعالم." />
        ) : (
          <DataTable
            columns={[
              { key: 'milestone', label: 'المعلم' },
              { key: 'sequence', label: 'الخطوة' },
              { key: 'total', label: 'الإجمالي' },
              { key: 'approved', label: 'معتمد' },
              { key: 'submitted', label: 'تم تسليمه' },
              { key: 'problem', label: 'متأخر/مُعدَّل' },
              { key: 'completion', label: 'الإنجاز' },
            ]}
            render={(row) => {
              const pct = row.completion_percent ?? 0
              return (
                <>
                  <Td>
                    <div className="font-medium text-slate-800">{row.title}</div>
                    <div className="font-mono text-xs text-slate-400">{row.code}</div>
                  </Td>
                  <Td className="tabular-nums">{row.sequence}</Td>
                  <Td className="tabular-nums">{row.total ?? 0}</Td>
                  <Td className="tabular-nums text-emerald-700">{row.approved ?? 0}</Td>
                  <Td className="tabular-nums">{row.submitted ?? 0}</Td>
                  <Td className="tabular-nums text-rose-600">{row.problem ?? 0}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-24">
                        <ProgressBar
                          value={pct}
                          tone={pct >= 80 ? 'success' : pct >= 50 ? 'warning' : 'danger'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">{Math.round(pct)}%</span>
                    </div>
                  </Td>
                </>
              )
            }}
            rows={timeliness}
          />
        )}
      </Card>

      <Card>
        <CardHeader title="عبء الإشراف" subtitle="الطلاب لكل مشرف، مع النتائج" />
        {workload.length === 0 ? (
          <EmptyState title="لا توجد بيانات" message="لا يوجد نشاط إشراف ليُبلَّغ عنه." />
        ) : (
          <DataTable
            columns={[
              { key: 'name', label: 'المشرف' },
              { key: 'students', label: 'الطلاب' },
              { key: 'capacity', label: 'السعة' },
              { key: 'pending', label: 'مراجعات معلّقة' },
              { key: 'mark', label: 'متوسط الدرجة' },
              { key: 'utilisation', label: 'الاستغلال' },
            ]}
            render={(row) => {
              const pct = row.utilisation ?? 0
              const full = row.overloaded === true || row.accepting === false
              return (
                <>
                  <Td>
                    <div className="font-medium text-slate-800">{row.name}</div>
                    {row.staff_id && (
                      <div className="font-mono text-xs text-slate-400">{row.staff_id}</div>
                    )}
                  </Td>
                  <Td className="tabular-nums">{row.supervising ?? 0}</Td>
                  <Td className="tabular-nums">{row.capacity ?? '—'}</Td>
                  <Td className="tabular-nums">{row.pending_reviews ?? 0}</Td>
                  <Td className="tabular-nums">{row.avg_mark != null ? formatMark(row.avg_mark) : '—'}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-20">
                        <ProgressBar
                          value={pct}
                          tone={pct >= 100 ? 'danger' : pct >= 80 ? 'warning' : 'brand'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">{pct}%</span>
                      {full && <Badge tone="danger">ممتلئ</Badge>}
                    </div>
                  </Td>
                </>
              )
            }}
            rows={workload}
          />
        )}
      </Card>

      <Card>
        <CardHeader title="حسب الفئة" subtitle="مزيج أنواع المشاريع" />
        {(overview?.categories ?? []).length === 0 ? (
          <EmptyState title="لا توجد بيانات" message="لا توجد مشاريع لتحليلها." />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            {overview.categories.map((row) => (
              <div
                key={row.category}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3"
              >
                <div>
                  <p className="font-medium text-slate-800">
                    {CATEGORY_LABELS[row.category] ?? row.category}
                  </p>
                  <p className="text-sm text-slate-500">
                    {row.total ?? 0} مشروع{row.total === 1 ? '' : 'ات'}
                  </p>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}

/**
 * Downloads go straight from the API to the browser. Fetching through axios
 * and re-blobbing would double the memory cost for a large cohort export.
 */
function ExportButton({ label, endpoint, session }) {
  const url = reportApi.exportUrl(endpoint, session ? { batch: session } : {})

  return (
    <Button
      variant="secondary"
      onClick={() => {
        window.open(url, '_blank')
      }}
    >
      {label}
    </Button>
  )
}

function DistributionBars({ rows }) {
  const max = Math.max(1, ...rows.map((r) => r.count ?? 0))

  return (
    <div className="space-y-3">
      {rows.map((row) => {
        const grade = row.grade ?? row.label
        return (
          <div key={grade} className="flex items-center gap-3">
            <span className="w-10 shrink-0 text-sm font-semibold text-slate-700">{grade}</span>
            <div className="h-6 flex-1 overflow-hidden rounded bg-slate-100">
              <div
                className={`h-full rounded ${toneFor(grade)}`}
                style={{ width: `${((row.count ?? 0) / max) * 100}%` }}
              />
            </div>
            <span className="w-14 shrink-0 text-end text-sm tabular-nums text-slate-600">
              {row.count ?? 0}
            </span>
          </div>
        )
      })}
    </div>
  )
}

function toneFor(grade) {
  const map = {
    'A+': 'bg-emerald-500',
    A: 'bg-emerald-500',
    'A-': 'bg-emerald-400',
    'B+': 'bg-brand-500',
    B: 'bg-brand-500',
    'B-': 'bg-brand-400',
    'C+': 'bg-amber-500',
    C: 'bg-amber-500',
    D: 'bg-orange-500',
    F: 'bg-rose-500',
  }
  return map[grade] ?? gradeTone(grade) ?? 'bg-slate-400'
}

/** Milestone stage tone names → Badge vocabulary. */
const STAGE_TONE = {
  slate: 'neutral',
  blue: 'info',
  amber: 'warning',
  violet: 'brand',
  emerald: 'success',
  rose: 'danger',
  red: 'danger',
}