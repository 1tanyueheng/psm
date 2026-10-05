import { useEffect, useState } from 'react'
import { reportApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, EmptyState, Spinner,
  ErrorState, Button, DataTable, Td, ProgressBar, Select,
} from '../../components/ui'
import { formatPercent, formatMark } from '../../lib/format'
import { useSemesters } from '../../context/SemesterContext'
import { PSM_PARTS, partLabel } from '../../lib/psmPart'

/**
 * Reporting & analytics (Module 5).
 *
 * Everything here is aggregated, so the page is organised as a small dashboard
 * rather than a set of tables: cohort health at the top, then the breakdowns a
 * coordinator actually acts on (where the cohort is stuck, and by batch),
 * then outcomes (mark distribution) and the two operational tables —
 * milestone completion and supervision load.
 *
 * Every figure below is read from the shape the API actually returns:
 * `/reports/dashboard` answers with `{ cohort, awaiting_review, at_risk,
 * marks, categories }`, `cohort-progress` with the bare `cohort` object, and
 * the workload and milestone endpoints with flat row arrays.
 *
 * Because both batches run in one term, every report is scoped by a selected
 * semester (defaulting to the active term) and a batch filter, mirroring the
 * three filters the API accepts: `semester_id`, `psm_part`, `batch`.
 */
export default function ReportPage() {
  const { selectedId, selectSemester, semesters: contextSemesters } = useSemesters()
  const [batch, setBatch] = useState('')
  const [batches, setBatches] = useState([])
  const [part, setPart] = useState('')
  const [summary, setSummary] = useState(null)
  const [cohort, setCohort] = useState(null)
  const [stages, setStages] = useState([])
  const [ranges, setRanges] = useState([])
  const [breakdown, setBreakdown] = useState([])
  const [workload, setWorkload] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [exportError, setExportError] = useState(null)

  // Load unfiltered batches.
  useEffect(() => {
    let cancelled = false
    reportApi
      .cohortProgress()
      .then((res) => {
        if (cancelled) return
        setBatches(unwrap(res)?.by_batch?.map((row) => row.batch).filter(Boolean) ?? [])
      })
      .catch(() => {
        // The filter simply stays empty; the page below still loads.
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const params = {}
        if (selectedId) params.semester_id = selectedId
        if (part) params.psm_part = part
        if (batch) params.batch = batch

        const [summaryRes, cohortRes, workloadRes, breakdownRes, distRes] =
          await Promise.allSettled([
            reportApi.overview(params),
            reportApi.cohortProgress(params),
            reportApi.supervisorWorkload(params),
            reportApi.milestoneBreakdown(params),
            reportApi.markDistribution(params),
          ])

        if (summaryRes.status === 'rejected') throw summaryRes.reason
        if (cancelled) return

        const overview = unwrap(summaryRes.value) ?? {}
        const cohortData = unwrap(cohortRes.value) ?? overview.cohort ?? {}

        setSummary(overview)
        setCohort(cohortData)
        setStages(
          Object.entries(cohortData.stages ?? {})
            .map(([status, stage]) => ({ ...stage, status }))
            .filter((stage) => stage.count > 0)
        )
        setRanges(distRes.status === 'fulfilled' ? unwrap(distRes.value)?.by_range ?? [] : [])
        setBreakdown(
          breakdownRes.status === 'fulfilled' ? unwrap(breakdownRes.value) ?? [] : []
        )
        setWorkload(workloadRes.status === 'fulfilled' ? unwrap(workloadRes.value) ?? [] : [])
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
  }, [selectedId, part, batch])

  const k = summary ?? {}
  const marks = k.marks ?? {}

  const kpis = {
    projects: cohort?.total_projects ?? 0,
    students: cohort?.total_students ?? 0,
    awaiting: k.awaiting_review ?? 0,
    atRisk: k.at_risk ?? 0,
    mean: marks.stats?.mean ?? null,
  }

  if (loading) return <Spinner label="Building the analytics view" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Reports & analytics"
        subtitle="Cohort progress, supervision load, and assessment outcomes"
        action={
          <div className="flex flex-wrap items-center gap-2">
            {contextSemesters.length > 0 && (
              <Select
                value={selectedId ?? ''}
                onChange={(e) => selectSemester(e.target.value || null)}
                aria-label="Semester"
              >
                <option value="">All semesters</option>
                {contextSemesters.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            )}
            <Select
              value={part}
              onChange={(e) => setPart(e.target.value)}
              aria-label="Batch type"
            >
              <option value="">All batches</option>
              {PSM_PARTS.map((p) => (
                <option key={p} value={p}>
                  {partLabel(p)}
                </option>
              ))}
            </Select>
            <Select
              value={batch}
              onChange={(e) => setBatch(e.target.value)}
              aria-label="Cohort year"
            >
              <option value="">All cohorts</option>
              {batches.map((b) => (
                <option key={b} value={b}>
                  Batch {b}
                </option>
              ))}
            </Select>
            <ExportButton
              label="Export marks"
              kind="marks"
              batch={batch}
              onError={setExportError}
            />
            <ExportButton
              label="Export projects"
              kind="projects"
              batch={batch}
              onError={setExportError}
            />
          </div>
        }
      />

      {exportError && <ErrorState error={{ message: exportError }} />}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Projects" value={kpis.projects} hint="In the selected cohort" />
        <StatCard label="Students" value={kpis.students} />
        <StatCard
          label="Awaiting review"
          value={kpis.awaiting}
          hint="Milestones submitted"
          tone={kpis.awaiting > 0 ? 'warning' : 'success'}
        />
        <StatCard
          label="At risk"
          value={kpis.atRisk}
          hint="Overdue or in revision"
          tone={kpis.atRisk > 0 ? 'danger' : 'success'}
        />
        <StatCard
          label="Mean mark"
          value={kpis.mean != null ? formatMark(kpis.mean) : '—'}
          hint={`${marks.total ?? 0} released`}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Where the cohort is stuck"
            subtitle="Milestones sitting at each stage"
          />
          {stages.length === 0 ? (
            <EmptyState
              title="No data"
              message="The stage breakdown appears once the cohort has milestones."
            />
          ) : (
            <ul className="space-y-2">
              {stages.map((stage) => (
                <li
                  key={stage.status}
                  className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2"
                >
                  <Badge tone={STAGE_TONES[stage.status] ?? 'neutral'}>{stage.label}</Badge>
                  <span className="text-sm font-semibold tabular-nums text-slate-700">
                    {stage.count}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <CardHeader title="Mark distribution" subtitle="Released marks by range" />
          {ranges.length === 0 ? (
            <EmptyState title="No marks" message="Nothing has been marked yet." />
          ) : (
            <DistributionBars rows={ranges} />
          )}
        </Card>
      </div>

      <Card>
        <CardHeader title="By batch" subtitle="Project completion per batch" />
        {(cohort?.by_batch ?? []).length === 0 ? (
          <EmptyState
            title="No data"
            message="No batches to report on for this selection."
          />
        ) : (
          <DataTable columns={['Batch', 'Projects', 'Completed', 'Completion']}>
            {cohort.by_batch.map((row) => (
              <tr key={row.batch}>
                <Td className="font-medium text-slate-800">Batch {row.batch}</Td>
                <Td className="tabular-nums">{row.projects ?? 0}</Td>
                <Td className="tabular-nums text-emerald-700">{row.completed ?? 0}</Td>
                <Td>
                  <div className="flex items-center gap-2">
                    <div className="w-24">
                      <ProgressBar
                        value={row.completion_percent ?? 0}
                        tone={(row.completion_percent ?? 0) >= 70 ? 'success' : 'brand'}
                      />
                    </div>
                    <span className="text-xs tabular-nums text-slate-500">
                      {formatPercent(row.completion_percent ?? 0, 0)}
                    </span>
                  </div>
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="Milestone completion" subtitle="Which stage is the bottleneck" />
        {breakdown.length === 0 ? (
          <EmptyState
            title="No data"
            message="Milestone data appears once projects have milestones."
          />
        ) : (
          <DataTable
            columns={['Milestone', 'Weight', 'Total', 'Approved', 'Submitted', 'Needs work', 'Completion']}
          >
            {breakdown.map((row) => (
              <tr key={row.code}>
                <Td>
                  <div className="font-medium text-slate-800">{row.title}</div>
                  <div className="font-mono text-xs text-slate-400">{row.code}</div>
                </Td>
                <Td className="tabular-nums text-slate-600">{formatPercent(row.weight ?? 0, 0)}</Td>
                <Td className="tabular-nums">{row.total ?? 0}</Td>
                <Td className="tabular-nums text-emerald-700">{row.approved ?? 0}</Td>
                <Td className="tabular-nums text-amber-700">{row.submitted ?? 0}</Td>
                <Td className="tabular-nums text-rose-600">{row.problem ?? 0}</Td>
                <Td>
                  <div className="flex items-center gap-2">
                    <div className="w-24">
                      <ProgressBar
                        value={row.completion_percent ?? 0}
                        tone={(row.completion_percent ?? 0) >= 80 ? 'success' : 'warning'}
                      />
                    </div>
                    <span className="text-xs tabular-nums text-slate-500">
                      {formatPercent(row.completion_percent ?? 0, 0)}
                    </span>
                  </div>
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader
          title="Supervision load"
          subtitle="Students per supervisor, with outcomes"
        />
        {workload.length === 0 ? (
          <EmptyState
            title="No data"
            message="No supervision activity to report."
          />
        ) : (
          <DataTable
            columns={[
              'Supervisor',
              'Students',
              'Capacity',
              'Utilisation',
              'Pending reviews',
              'Avg. mark',
              'On time',
            ]}
          >
            {workload.map((row) => (
              <tr key={row.id ?? row.name}>
                <Td className="font-medium text-slate-800">{row.name}</Td>
                <Td className="tabular-nums">{row.supervising ?? 0}</Td>
                <Td className="tabular-nums">{row.capacity || '—'}</Td>
                <Td>
                  <div className="flex items-center gap-2">
                    <div className="w-20">
                      <ProgressBar
                        value={row.utilisation ?? 0}
                        tone={
                          (row.utilisation ?? 0) >= 100
                            ? 'danger'
                            : (row.utilisation ?? 0) >= 80
                              ? 'warning'
                              : 'brand'
                        }
                      />
                    </div>
                    <span className="text-xs tabular-nums text-slate-500">
                      {formatPercent(row.utilisation ?? 0, 0)}
                    </span>
                  </div>
                </Td>
                <Td className="tabular-nums text-amber-700">{row.pending_reviews ?? 0}</Td>
                <Td className="tabular-nums">
                  {row.avg_mark != null ? formatMark(row.avg_mark) : '—'}
                </Td>
                <Td className="tabular-nums">
                  {row.on_time_rate != null ? formatPercent(row.on_time_rate, 0) : '—'}
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="By category" subtitle="Project type mix" />
        {(k.categories ?? []).length === 0 ? (
          <EmptyState title="No data" message="No projects to break down." />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            {k.categories.map((row) => (
              <div
                key={row.category}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3"
              >
                <p className="font-medium text-slate-800">{row.category}</p>
                <p className="text-sm text-slate-500">
                  {row.total ?? 0} project{row.total === 1 ? '' : 's'}
                </p>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}

/**
 * Downloads go through the API client, not window.open: the export routes are
 * behind Sanctum, so a plain navigation would be answered with a 401 instead
 * of the file.
 */
function ExportButton({ label, kind, batch, onError }) {
  const [busy, setBusy] = useState(false)

  return (
    <Button
      variant="secondary"
      loading={busy}
      onClick={async () => {
        setBusy(true)
        onError(null)
        try {
          await reportApi.download(kind, batch ? { batch } : {})
        } catch (err) {
          onError(err?.message ?? 'The export could not be generated.')
        } finally {
          setBusy(false)
        }
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
        const range = row.range ?? row.label
        return (
          <div key={range} className="flex items-center gap-3">
            <span className="w-14 shrink-0 text-sm font-semibold text-slate-700">{range}</span>
            <div className="h-6 flex-1 overflow-hidden rounded bg-slate-100">
              <div
                className={`h-full rounded ${RANGE_TONES[range] ?? 'bg-slate-400'}`}
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

/** Mark range → bar colour. Ranges are descriptive, not evaluative. */
const RANGE_TONES = {
  '80+':   'bg-emerald-500',
  '70-79': 'bg-sky-500',
  '60-69': 'bg-amber-500',
  '50-59': 'bg-orange-500',
  '40-49': 'bg-rose-500',
  '0-39':  'bg-red-600',
}

/**
 * Milestone status → Badge tone.
 *
 * The API reports a colour name per status ('emerald', 'amber', 'rose'), which
 * is not the Badge vocabulary, so it is mapped here rather than passed through.
 */
const STAGE_TONES = {
  pending: 'neutral',
  open: 'info',
  submitted: 'warning',
  reviewed: 'brand',
  approved: 'success',
  rejected: 'danger',
  overdue: 'danger',
}
