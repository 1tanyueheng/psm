import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportApi, assignmentApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, ProgressBar,
  EmptyState, Spinner, ErrorState, Button, DataTable, Td,
} from '../../components/ui'
import { formatPercent, gradeTone } from '../../lib/format'

/**
 * Coordinator dashboard — Module 5's cohort view.
 *
 * The coordinator is accountable for the whole batch, so this page answers
 * "is the cohort on track, and where is it stuck" rather than tracking
 * individuals. Distribution charts and the bottleneck list are the priority.
 */
export default function CoordinatorDashboard() {
  const [overview, setOverview] = useState(null)
  const [distribution, setDistribution] = useState([])
  const [workload, setWorkload] = useState([])
  const [unpaired, setUnpaired] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // Four independent reads — run them together rather than in series.
        const [overviewRes, distributionRes, workloadRes, unpairedRes] = await Promise.all([
          reportApi.overview(),
          reportApi.gradeDistribution(),
          reportApi.workload(),
          assignmentApi.unassigned({ per_page: 1 }),
        ])
        if (cancelled) return

        setOverview(unwrap(overviewRes))
        // The API returns the distribution as { by_band: [...] } — the bands
        // are what the bars render, not the raw `distribution` map.
        setDistribution(unwrap(distributionRes)?.by_band ?? [])
        setWorkload(unwrap(workloadRes) ?? [])
        setUnpaired(unwrapPaged(unpairedRes).meta)
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

  const maxBand = useMemo(
    () => Math.max(1, ...distribution.map((row) => row.count ?? 0)),
    [distribution]
  )

  const k = overview ?? {}
  const cohort = k.cohort ?? {}
  const stages = cohort.stages ?? {}
  const byBand = (() => {
    const first = cohort.by_batch?.[0]
    return first?.batch ? `الدفعة ${first.batch}` : null
  })()

  // Module 5's "where is the cohort stuck" — drawn from the summary the
  // dashboard endpoint already returns, rather than a separate round-trip.
  const attention = [
    { label: 'بانتظار المراجعة', count: k.awaiting_review ?? 0, tone: 'warning' },
    { label: 'في خطر', count: k.at_risk ?? 0, tone: 'danger' },
    { label: 'مطلوب تعديل', count: stages.rejected?.count ?? 0, tone: 'warning' },
  ].filter((item) => item.count > 0)

  if (loading) return <Spinner label="جارٍ تحميل تحليلات الدفعة" />
  if (error) return <ErrorState message={error.message || error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="نظرة عامة على الدفعة"
        subtitle={byBand ?? 'الدفعة الحالية'}
        actions={
          <div className="flex gap-2">
            <Link to="/assignments">
              <Button variant="secondary">تعيين المشرفين</Button>
            </Link>
            <Link to="/reports">
              <Button>التحليلات الكاملة</Button>
            </Link>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="مشاريع نشطة" value={cohort.total_projects ?? 0} hint="الدفعة الحالية قيد التنفيذ" />
        <StatCard
          label="طلاب بلا مشرف"
          value={unpaired?.total ?? 0}
          hint="لا يوجد مشرف بعد"
          tone={(unpaired?.total ?? 0) > 0 ? 'danger' : 'success'}
        />
        <StatCard
          label="مراحل متأخرة"
          value={stages.overdue?.count ?? 0}
          hint="تجاوزت موعدها ولم تُعتمد"
          tone={(stages.overdue?.count ?? 0) > 0 ? 'warning' : 'success'}
        />
        <StatCard
          label="الدرجات المنشورة"
          value={k.grades?.total ?? 0}
          hint="الدرجات النهائية المنشورة"
          tone="success"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Grade distribution — module 5's headline chart. */}
        <Card>
          <CardHeader
            title="توزيع الدرجات"
            subtitle={k.grades?.stats?.count ? `${k.grades.stats.count} مشاريع مصنّفة` : 'كل المشاريع المصنّفة'}
          />
          {distribution.length === 0 ? (
            <EmptyState
              title="لا توجد درجات بعد"
              message="يظهر التوزيع بعد أن يقدّم الممتحنون والمشرفون علاماتهم."
            />
          ) : (
            <div className="space-y-3 pt-1">
              {distribution.map((row) => {
                const share = ((row.count ?? 0) / maxBand) * 100
                return (
                  <div key={row.grade ?? row.label} className="flex items-center gap-3">
                    <span className="w-12 shrink-0 text-sm font-semibold text-slate-700">
                      {row.grade ?? row.label}
                    </span>
                    <div className="h-6 flex-1 overflow-hidden rounded bg-slate-100">
                      <div
                        className={`h-full rounded ${barTone(row.grade ?? row.label)}`}
                        style={{ width: `${share}%` }}
                      />
                    </div>
                    <span className="w-20 shrink-0 text-end text-sm tabular-nums text-slate-600">
                      {row.count ?? 0}
                      {row.percent != null && (
                        <span className="ms-1 text-xs text-slate-400">
                          {formatPercent(row.percent, 0)}
                        </span>
                      )}
                    </span>
                  </div>
                )
              })}
            </div>
          )}
        </Card>

        {/* Where the cohort is collectively stuck — awaiting/at-risk/revisions. */}
        <Card>
          <CardHeader
            title="أين تحتاج الدفعة إلى اهتمام"
            subtitle="بنود تتطلب قراراً من المنسق"
          />
          {attention.length === 0 ? (
            <EmptyState title="كل شيء على ما يرام" message="لا شيء يحتاج إلى اهتمام عبر الدفعة." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {attention.map((item) => (
                <li key={item.label} className="flex items-center justify-between py-3">
                  <p className="text-sm font-medium text-slate-800">{item.label}</p>
                  <Badge tone={item.tone}>{item.count}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* Supervisor workload — feeds the assignment decision. */}
      <Card>
        <CardHeader
          title="عبء عمل المشرفين"
          subtitle="استخدام السعة عبر الدفعة"
          action={
            <Link to="/assignments">
              <Button size="sm" variant="secondary">إدارة التعيينات</Button>
            </Link>
          }
        />
        {workload.length === 0 ? (
          <EmptyState title="لا يوجد مشرفون" message="أضف مشرفين لرؤية العبء." />
        ) : (
          <DataTable
            columns={[
              { key: 'name', label: 'المشرف' },
              { key: 'expertise', label: 'الخبرة' },
              { key: 'load', label: 'العدد' },
              { key: 'capacity', label: 'السعة' },
              { key: 'utilisation', label: 'الاستخدام' },
              { key: 'availability', label: 'التوفر' },
            ]}
            render={(row) => {
              const used = row.supervising ?? 0
              const max = row.capacity ?? 0
              const pct = row.utilisation ?? 0
              const full = row.overloaded === true || row.accepting === false

              return (
                <tr key={row.id ?? row.name}>
                  <Td>
                    <div className="font-medium text-slate-800">{row.name}</div>
                    {row.staff_id && (
                      <div className="font-mono text-xs text-slate-400">{row.staff_id}</div>
                    )}
                  </Td>
                  <Td className="text-sm text-slate-600">{row.expertise ?? '—'}</Td>
                  <Td className="tabular-nums">{used}</Td>
                  <Td className="tabular-nums">{max || '—'}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-24">
                        <ProgressBar
                          value={pct}
                          tone={pct >= 100 ? 'danger' : pct >= 80 ? 'warning' : 'brand'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">{pct}%</span>
                    </div>
                  </Td>
                  <Td>
                    {full ? (
                      <Badge tone="danger">بالسعة القصوى</Badge>
                    ) : (
                      <Badge tone="success">متاح</Badge>
                    )}
                  </Td>
                </tr>
              )
            }}
          />
        )}
      </Card>
    </div>
  )
}

/** Conventional Malaysian grade bands → colour. */
function barTone(grade) {
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