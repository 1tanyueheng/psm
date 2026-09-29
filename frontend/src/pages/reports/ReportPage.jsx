import { useEffect, useMemo, useState } from 'react'
import { reportApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, EmptyState, Spinner,
  ErrorState, Button, DataTable, Td, ProgressBar, Select,
} from '../../components/ui'
import { formatPercent, formatMark, gradeTone, CATEGORY_LABELS } from '../../lib/format'

/**
 * Reporting & analytics (Module 5).
 *
 * Everything here is aggregated, so the page is organised as a small dashboard
 * rather than a set of tables: cohort health at the top, then the two
 * breakdowns a coordinator actually acts on (by programme and by supervisor),
 * then milestone timeliness, which is the earliest warning signal available.
 *
 * Export buttons point at the API's CSV endpoints — the browser downloads
 * directly from the API rather than streaming through React, so a large export
 * does not have to fit in memory here.
 */
export default function ReportPage() {
  const [session, setSession] = useState('')
  const [sessions, setSessions] = useState([])
  const [overview, setOverview] = useState(null)
  const [programmes, setProgrammes] = useState([])
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
        const params = session ? { academic_session: session } : {}

        const [overviewRes, programmeRes, workloadRes, timelinessRes, distRes] =
          await Promise.allSettled([
            reportApi.overview(params),
            reportApi.byProgramme(params),
            reportApi.workload(params),
            reportApi.milestoneTimeliness(params),
            reportApi.gradeDistribution(params),
          ])

        if (overviewRes.status === 'rejected') throw overviewRes.reason
        if (cancelled) return

        const ov = unwrap(overviewRes.value) ?? {}
        setOverview(ov)
        setSessions(ov.available_sessions ?? [])
        setProgrammes(programmeRes.status === 'fulfilled' ? (unwrap(programmeRes.value) ?? []) : [])
        setWorkload(workloadRes.status === 'fulfilled' ? (unwrap(workloadRes.value) ?? []) : [])
        setTimeliness(timelinessRes.status === 'fulfilled' ? (unwrap(timelinessRes.value) ?? []) : [])
        setDistribution(distRes.status === 'fulfilled' ? (unwrap(distRes.value) ?? []) : [])
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
    const k = overview ?? {}
    return {
      projects: k.active_projects ?? 0,
      students: k.total_students ?? 0,
      onTrack: k.on_track_percent ?? 0,
      overdue: k.overdue_milestones ?? 0,
      completion: k.average_completion ?? 0,
      graded: k.graded_count ?? 0,
    }
  }, [overview])

  if (loading) return <Spinner label="Building the analytics view" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Reports & analytics"
        subtitle="Cohort progress, supervision load, and assessment outcomes"
        actions={
          <div className="flex flex-wrap gap-2">
            <Select
              value={session}
              onChange={(e) => setSession(e.target.value)}
              aria-label="Academic session"
            >
              <option value="">All sessions</option>
              {sessions.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </Select>
            <ExportButton label="Export grades" endpoint="grades" session={session} />
            <ExportButton label="Export projects" endpoint="projects" session={session} />
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Active projects" value={kpis.projects} />
        <StatCard label="Students" value={kpis.students} />
        <StatCard
          label="On track"
          value={formatPercent(kpis.onTrack, 0)}
          tone={kpis.onTrack >= 80 ? 'success' : kpis.onTrack >= 60 ? 'warning' : 'danger'}
        />
        <StatCard
          label="Overdue"
          value={kpis.overdue}
          tone={kpis.overdue > 0 ? 'danger' : 'success'}
        />
        <StatCard label="Avg. completion" value={formatPercent(kpis.completion, 0)} />
        <StatCard label="Graded" value={kpis.graded} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="By programme" subtitle="Progress and outcomes per programme" />
          {programmes.length === 0 ? (
            <EmptyState title="No data" message="No programmes to report on for this session." />
          ) : (
            <DataTable columns={['Programme', 'Students', 'Avg. progress', 'Avg. mark']}>
              {programmes.map((row) => (
                <tr key={row.program ?? row.name}>
                  <Td>
                    <div className="font-medium text-slate-800">{row.program ?? row.name}</div>
                    {row.batch && (
                      <div className="text-xs text-slate-400">batch {row.batch}</div>
                    )}
                  </Td>
                  <Td className="tabular-nums">{row.student_count ?? row.count ?? 0}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-20">
                        <ProgressBar
                          value={row.average_progress ?? 0}
                          tone={(row.average_progress ?? 0) >= 70 ? 'success' : 'brand'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">
                        {formatPercent(row.average_progress ?? 0, 0)}
                      </span>
                    </div>
                  </Td>
                  <Td className="tabular-nums">
                    {row.average_mark != null ? formatMark(row.average_mark) : '—'}
                  </Td>
                </tr>
              ))}
            </DataTable>
          )}
        </Card>

        <Card>
          <CardHeader title="Grade distribution" subtitle="Across all graded projects" />
          {distribution.length === 0 ? (
            <EmptyState title="No grades" message="Nothing has been graded yet." />
          ) : (
            <DistributionBars rows={distribution} />
          )}
        </Card>
      </div>

      <Card>
        <CardHeader
          title="Milestone timeliness"
          subtitle="Submitted on time versus late, per milestone"
        />
        {timeliness.length === 0 ? (
          <EmptyState title="No data" message="Timeliness appears once milestones are submitted." />
        ) : (
          <DataTable columns={['Milestone', 'Part', 'Submitted', 'On time', 'Late', 'Timeliness']}>
            {timeliness.map((row) => {
              const total = row.submitted_count ?? (row.on_time_count ?? 0) + (row.late_count ?? 0)
              const rate = total > 0 ? Math.round(((row.on_time_count ?? 0) / total) * 100) : 0

              return (
                <tr key={`${row.milestone_code}-${row.psm_part}`}>
                  <Td>
                    <div className="font-medium text-slate-800">{row.name}</div>
                    <div className="font-mono text-xs text-slate-400">{row.milestone_code}</div>
                  </Td>
                  <Td>
                    <Badge tone={row.psm_part === 'PSM2' ? 'brand' : 'neutral'}>
                      {row.psm_part}
                    </Badge>
                  </Td>
                  <Td className="tabular-nums">{total}</Td>
                  <Td className="tabular-nums text-emerald-700">{row.on_time_count ?? 0}</Td>
                  <Td className="tabular-nums text-rose-600">{row.late_count ?? 0}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-24">
                        <ProgressBar
                          value={rate}
                          tone={rate >= 80 ? 'success' : rate >= 60 ? 'warning' : 'danger'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">{rate}%</span>
                    </div>
                  </Td>
                </tr>
              )
            })}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="Supervision load" subtitle="Students per supervisor, with outcomes" />
        {workload.length === 0 ? (
          <EmptyState title="No data" message="No supervision activity to report." />
        ) : (
          <DataTable
            columns={['Supervisor', 'Students', 'Primary', 'Co-supervision', 'Completed', 'Avg. mark']}
          >
            {workload.map((row) => (
              <tr key={row.id ?? row.name}>
                <Td>
                  <div className="font-medium text-slate-800">{row.name}</div>
                  {row.staff_id && (
                    <div className="font-mono text-xs text-slate-400">{row.staff_id}</div>
                  )}
                </Td>
                <Td className="tabular-nums">{row.student_count ?? row.current_load ?? 0}</Td>
                <Td className="tabular-nums">{row.primary_count ?? '—'}</Td>
                <Td className="tabular-nums">{row.co_supervision_count ?? '—'}</Td>
                <Td className="tabular-nums text-emerald-700">{row.completed_count ?? 0}</Td>
                <Td className="tabular-nums">
                  {row.average_mark != null ? formatMark(row.average_mark) : '—'}
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="By category" subtitle="Project type mix and outcomes" />
        {(overview?.by_category ?? []).length === 0 ? (
          <EmptyState title="No data" message="No projects to break down." />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            {overview.by_category.map((row) => (
              <div
                key={row.category}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3"
              >
                <div>
                  <p className="font-medium text-slate-800">
                    {CATEGORY_LABELS[row.category] ?? row.category}
                  </p>
                  <p className="text-sm text-slate-500">
                    {row.count ?? 0} project{row.count === 1 ? '' : 's'}
                  </p>
                </div>
                <div className="text-right">
                  <p className="text-sm font-medium text-slate-700">
                    {formatPercent(row.average_progress ?? 0, 0)}
                  </p>
                  <p className="text-xs text-slate-400">avg. progress</p>
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
  const url = reportApi.exportUrl(endpoint, session ? { academic_session: session } : {})

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
            <span className="w-14 shrink-0 text-right text-sm tabular-nums text-slate-600">
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
