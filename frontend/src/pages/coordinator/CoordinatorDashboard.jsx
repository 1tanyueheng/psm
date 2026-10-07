import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportApi, assignmentApi, projectApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, ProgressBar,
  EmptyState, Spinner, ErrorState, Button, DataTable, Td,
  SegmentedControl, FilterBar, FilterField, Select,
} from '../../components/ui'
import { formatPercent } from '../../lib/format'
import { PSM_PARTS, partLabel } from '../../lib/psmPart'
import { useSemesters } from '../../context/SemesterContext'

const LAST_PART_KEY = 'psm.dashboard.lastPart'

/**
 * Coordinator dashboard — Module 5's cohort view.
 *
 * The coordinator is accountable for the whole batch, so this page answers
 * "is the cohort on track, and where is it stuck" rather than tracking
 * individuals. Distribution charts and the bottleneck list are the priority.
 *
 * PSM 1 and PSM 2 run concurrently in one term, so the cohort view is
 * segmented by batch (acceptance criterion #1) and scoped to a term. Switching
 * batch does not refetch: `by_part` already carries each batch's cohort, marks
 * and at-risk figures for the term, so both halves stay comparable in a single
 * round trip — which is the point of showing them side by side.
 */
export default function CoordinatorDashboard() {
  const { selected, selectedId, options: semesterOptions, selectSemester } = useSemesters()

  // '' means both batches. Remembered per browser so a coordinator working
  // through one half of the cohort is not flipped back on every reload.
  const [part, setPart] = useState(() => readLastPart())
  const [overview, setOverview] = useState(null)
  const [distribution, setDistribution] = useState([])
  const [workload, setWorkload] = useState([])
  const [unpaired, setUnpaired] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  function changePart(next) {
    setPart(next)
    try {
      window.localStorage.setItem(LAST_PART_KEY, next)
    } catch {
      // The preference simply will not persist.
    }
  }

  // Refetch when the term changes — every figure here is scoped to it, so a
  // stale term would quietly show the previous cohort's numbers.
  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)

      try {
        // Four independent reads — run them together rather than in series.
        const scope = { semester_id: selectedId ?? undefined }
        const [overviewRes, distributionRes, workloadRes, unpairedRes] = await Promise.all([
          reportApi.overview(scope),
          reportApi.markDistribution(scope),
          reportApi.workload(scope),
          assignmentApi.unassigned({ ...scope, per_page: 1 }),
        ])
        if (cancelled) return

        setOverview(unwrap(overviewRes))
        // The API returns the distribution as { by_range: [...] } — the ranges
        // are what the bars render, not the raw `distribution` map.
        setDistribution(unwrap(distributionRes)?.by_range ?? [])
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
  }, [selectedId])

  const k = overview ?? {}

  // The server returns a row for both batches whether or not either has
  // students, so the segmented control always has two options to render.
  const byPart = k.by_part ?? {}
  const partTotals = Object.fromEntries(
    PSM_PARTS.map((value) => [value, byPart[value]?.cohort?.total_projects ?? 0])
  )
  const partCounts = {
    all: PSM_PARTS.reduce((sum, value) => sum + partTotals[value], 0),
    ...partTotals,
  }

  // Everything below describes the selected batch when one is chosen, and the
  // whole term otherwise.
  const scoped = part ? byPart[part] : null
  const cohort = (scoped ? scoped.cohort : k.cohort) ?? {}
  const stages = cohort.stages ?? {}
  const marks = (scoped ? scoped.marks : k.marks) ?? {}

  // The chart follows the batch too, so PSM 1 and PSM 2 marks are never mixed.
  const bands = scoped ? (scoped.marks?.by_range ?? []) : distribution
  const maxBand = useMemo(() => Math.max(1, ...bands.map((row) => row.count ?? 0)), [bands])

  // Module 5's "where is the cohort stuck" — drawn from the summary the
  // dashboard endpoint already returns, rather than a separate round-trip.
  const attention = [
    { label: 'Awaiting review', count: k.awaiting_review ?? 0, tone: 'warning' },
    { label: 'At risk', count: (scoped ? scoped.at_risk : k.at_risk) ?? 0, tone: 'danger' },
    { label: 'Revision required', count: stages.rejected?.count ?? 0, tone: 'warning' },
  ].filter((item) => item.count > 0)

  if (loading && !overview) return <Spinner label="Loading cohort analytics" />
  if (error && !overview) return <ErrorState message={error.message || error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Cohort overview"
        subtitle={selected?.name ?? 'Current cohort'}
        action={
          <div className="flex flex-wrap gap-2">
            <Link to="/assignments">
              <Button variant="secondary">Assign supervisors</Button>
            </Link>
            <Link to="/semesters">
              <Button variant="secondary">Manage semesters</Button>
            </Link>
            <Link to="/reports">
              <Button>Full analytics</Button>
            </Link>
          </div>
        }
      />

      <FilterBar>
        <FilterField label="Semester">
          <Select
            value={selectedId ?? ''}
            onChange={(event) => selectSemester(event.target.value)}
            className="min-w-[13rem]"
          >
            {semesterOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
                {option.active ? ' (active)' : ''}
              </option>
            ))}
          </Select>
        </FilterField>

        <FilterField label="Batch">
          <SegmentedControl
            value={part}
            onChange={changePart}
            name="dashboard_part"
            options={[
              { value: '', label: 'All batches', count: partCounts.all },
              ...PSM_PARTS.map((value) => ({
                value,
                label: partLabel(value, { short: true }),
                count: partTotals[value],
              })),
            ]}
          />
        </FilterField>
      </FilterBar>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Active projects"
          value={cohort.total_projects ?? 0}
          hint={part ? `In ${partLabel(part)}` : 'Both batches'}
        />
        <StatCard
          label="Unpaired students"
          value={unpaired?.total ?? 0}
          hint="No supervisor yet"
          tone={(unpaired?.total ?? 0) > 0 ? 'danger' : 'success'}
        />
        <StatCard
          label="Overdue milestones"
          value={stages.overdue?.count ?? 0}
          hint="Past due date, not approved"
          tone={(stages.overdue?.count ?? 0) > 0 ? 'warning' : 'success'}
        />
        <StatCard
          label="Marks released"
          value={marks.total ?? 0}
          hint="Released final marks"
          tone="success"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Mark distribution — module 5's headline chart. */}
        <Card>
          <CardHeader
            title="Mark distribution"
            subtitle={
              marks?.stats?.count
                ? `${marks.stats.count} marked project${marks.stats.count === 1 ? '' : 's'}${
                    part ? ` in ${partLabel(part)}` : ''
                  }`
                : 'All marked projects'
            }
          />
          {bands.length === 0 ? (
            <EmptyState
              title="No marks yet"
              message="The distribution appears once examiners and supervisors submit their forms."
            />
          ) : (
            <div className="space-y-3 pt-1">
              {bands.map((row) => {
                const share = ((row.count ?? 0) / maxBand) * 100
                return (
                  <div key={row.range ?? row.label} className="flex items-center gap-3">
                    <span className="w-14 shrink-0 text-sm font-semibold text-slate-700">
                      {row.range ?? row.label}
                    </span>
                    <div className="h-6 flex-1 overflow-hidden rounded bg-slate-100">
                      <div
                        className={`h-full rounded ${barTone(row.range ?? row.label)}`}
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

        {/* Where the cohort is collectively stuck — awaiting/at-risk/revisions. */}
        <Card>
          <CardHeader
            title="Where the cohort needs attention"
            subtitle="Items that require a coordinator decision"
          />
          {attention.length === 0 ? (
            <EmptyState title="All clear" message="Nothing needs attention across the cohort." />
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
              { key: 'name', label: 'Supervisor' },
              { key: 'supervising', label: 'Students' },
              { key: 'capacity', label: 'Capacity' },
              { key: 'utilisation', label: 'Utilisation' },
              { key: 'pending_reviews', label: 'Pending reviews' },
              { key: 'availability', label: 'Availability' },
            ]}
            rows={workload}
            render={(row) => {
              const used = row.supervising ?? 0
              const max = row.capacity ?? 0
              const pct = row.utilisation ?? 0

              // A part that is out of slots is a real constraint even when the
              // other part is empty, so the badge reads the per-part picture
              // rather than the single `overloaded` flag. A supervisor at 2/2
              // PSM 1 is not "Available" — they have no PSM 1 slots left.
              const byPart = row.capacity_by_part ?? {}
              const loadByPart = row.load_by_part ?? {}
              const parts = Object.keys(byPart).filter((p) => (byPart[p] ?? 0) > 0)
              const fullParts = parts.filter((p) => (loadByPart[p] ?? 0) >= (byPart[p] ?? 0))
              const openParts = parts.filter((p) => (loadByPart[p] ?? 0) < (byPart[p] ?? 0))

              const full = row.overloaded === true
                || row.accepting === false
                || (parts.length > 0 && openParts.length === 0)

              return (
                <tr key={row.id ?? row.name}>
                  <Td>
                    <div className="font-medium text-slate-800">{row.name}</div>
                    {row.project_count > 0 && (
                      <div className="text-xs text-slate-400">
                        {row.project_count} project{row.project_count === 1 ? '' : 's'}
                      </div>
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
                  <Td className="tabular-nums text-amber-700">{row.pending_reviews ?? 0}</Td>
                  <Td>
                    {fullParts.length > 0 && openParts.length > 0 ? (
                      <Badge tone="warning">
                        Full for {fullParts.join(', ')}
                      </Badge>
                    ) : full ? (
                      <Badge tone="danger">At capacity</Badge>
                    ) : (
                      <Badge tone="success">Available</Badge>
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

/** Mark range → bar colour. Ranges are descriptive, not evaluative. */
const RANGE_TONES = {
  '80+':   'bg-emerald-500',
  '70-79': 'bg-sky-500',
  '60-69': 'bg-amber-500',
  '50-59': 'bg-orange-500',
  '40-49': 'bg-rose-500',
  '0-39':  'bg-red-600',
}

function barTone(range) {
  return RANGE_TONES[range] ?? 'bg-slate-400'
}

function readLastPart() {
  try {
    const stored = window.localStorage.getItem(LAST_PART_KEY)
    // Guard against a stale or hand-edited value: only the three states the
    // control can actually be in are accepted.
    return stored === 'PSM1' || stored === 'PSM2' ? stored : ''
  } catch {
    return ''
  }
}