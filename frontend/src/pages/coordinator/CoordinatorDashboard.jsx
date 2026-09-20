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
        setDistribution(unwrap(distributionRes) ?? [])
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

  const bottlenecks = useMemo(
    () =>
      (overview?.milestone_bottlenecks ?? []).slice(0, 5).sort(
        (a, b) => (b.overdue_count ?? 0) - (a.overdue_count ?? 0)
      ),
    [overview]
  )

  if (loading) return <Spinner label="Loading cohort analytics" />
  if (error) return <ErrorState error={error} />

  const k = overview ?? {}

  return (
    <div className="space-y-6">
      <PageHeader
        title="Cohort overview"
        subtitle={k.academic_session ? `Session ${k.academic_session} · batch ${k.batch ?? '—'}` : 'Current cohort'}
        actions={
          <div className="flex gap-2">
            <Link to="/assignments">
              <Button variant="secondary">Assign supervisors</Button>
            </Link>
            <Link to="/reports">
              <Button>Full analytics</Button>
            </Link>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Active projects" value={k.active_projects ?? 0} hint="PSM1 + PSM2 in flight" />
        <StatCard
          label="Unpaired students"
          value={unpaired?.total ?? 0}
          hint="No supervisor yet"
          tone={(unpaired?.total ?? 0) > 0 ? 'danger' : 'success'}
        />
        <StatCard
          label="Overdue milestones"
          value={k.overdue_milestones ?? 0}
          hint="Past due date, not approved"
          tone={(k.overdue_milestones ?? 0) > 0 ? 'warning' : 'success'}
        />
        <StatCard
          label="Grades released"
          value={k.grades_released ?? 0}
          hint="Visible to students"
          tone="success"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Grade distribution — module 5's headline chart. */}
        <Card>
          <CardHeader
            title="Grade distribution"
            subtitle={k.graded_count ? `${k.graded_count} graded projects` : 'All graded projects'}
          />
          {distribution.length === 0 ? (
            <EmptyState
              title="No grades yet"
              message="The distribution appears once examiners and supervisors submit marks."
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
                    <span className="w-20 shrink-0 text-right text-sm tabular-nums text-slate-600">
                      {row.count ?? 0}
                      {row.percent != null && (
                        <span className="ml-1 text-xs text-slate-400">
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

        {/* Bottlenecks — where the cohort is collectively stuck. */}
        <Card>
          <CardHeader
            title="Where the cohort is stuck"
            subtitle="Milestones with the most overdue submissions"
          />
          {bottlenecks.length === 0 ? (
            <EmptyState title="No backlog" message="Nothing is overdue across the cohort." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {bottlenecks.map((row) => (
                <li key={row.milestone_code ?? row.name} className="flex items-center gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-800">{row.name}</p>
                    <p className="text-xs text-slate-500">
                      {row.milestone_code}
                      {row.psm_part ? ` · ${row.psm_part}` : ''}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-semibold text-rose-600 tabular-nums">
                      {row.overdue_count ?? 0}
                    </p>
                    <p className="text-xs text-slate-400">overdue</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* Supervisor workload — feeds the assignment decision. */}
      <Card>
        <CardHeader
          title="Supervisor workload"
          subtitle="Capacity utilisation across the cohort"
          action={
            <Link to="/assignments">
              <Button size="sm" variant="secondary">Manage assignments</Button>
            </Link>
          }
        />
        {workload.length === 0 ? (
          <EmptyState title="No supervisors loaded" message="Add supervisors to see workload." />
        ) : (
          <DataTable
            columns={[
              'Supervisor',
              'Expertise',
              'Load',
              'Capacity',
              'Utilisation',
              'Availability',
            ]}
          >
            {workload.map((row) => {
              const used = row.current_load ?? row.supervisee_count ?? 0
              const max = row.max_supervisees ?? 0
              const pct = max > 0 ? Math.round((used / max) * 100) : 0
              const full = row.has_capacity === false || (max > 0 && used >= max)

              return (
                <tr key={row.id ?? row.name}>
                  <Td>
                    <div className="font-medium text-slate-800">{row.name}</div>
                    {row.staff_id && (
                      <div className="font-mono text-xs text-slate-400">{row.staff_id}</div>
                    )}
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {(row.expertise ?? []).slice(0, 2).join(', ')}
                    {(row.expertise?.length ?? 0) > 2 && (
                      <span className="text-slate-400"> +{row.expertise.length - 2}</span>
                    )}
                  </Td>
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
                      <Badge tone="danger">At capacity</Badge>
                    ) : (
                      <Badge tone="success">Available</Badge>
                    )}
                  </Td>
                </tr>
              )
            })}
          </DataTable>
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
