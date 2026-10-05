import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { projectApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, PageHeader, Badge, Avatar, ProgressBar, EmptyState,
  Spinner, ErrorState, Button, Select, Input, DataTable, Td,
  FilterBar, FilterField, SegmentedControl,
} from '../../components/ui'
import { formatDate, isOverdue, CATEGORY_LABELS, PROJECT_STATUS, statusMeta } from '../../lib/format'
import { PSM_PART_BADGE_TONES, PSM_PARTS, partLabel, normalisePart } from '../../lib/psmPart'

/**
 * Project list.
 *
 * Deliberately filter-driven rather than paginated-by-default, because every
 * role arrives here with a specific slice in mind: a supervisor wants their
 * own roster, a coordinator wants unpaired projects, an examiner wants the ones
 * they assess. The API scopes results to the caller, so the filters refine
 * rather than broaden.
 *
 * The term comes from SemesterContext rather than the URL, so it stays in step
 * with every other screen — a report and a project list showing different terms
 * would be worse than a link that does not encode one. Batch, status, category
 * and search stay in the URL because they are specific to this screen.
 */
export default function ProjectListPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const { selected, selectedId, options: semesterOptions, selectSemester } = useSemesters()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const search = params.get('q') ?? ''
  const status = params.get('status') ?? ''
  const category = params.get('category') ?? ''
  const psmPart = normalisePart(params.get('psm_part')) ?? ''
  const page = Number(params.get('page') ?? 1)

  const setFilter = useCallback(
    (key, value) => {
      const next = new URLSearchParams(params)
      if (value) next.set(key, value)
      else next.delete(key)
      // Any filter change resets pagination — page 4 of a new result set is
      // almost always empty, which reads as a bug to the user.
      if (key !== 'page') next.delete('page')
      setParams(next, { replace: true })
    },
    [params, setParams]
  )

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const res = await projectApi.list({
          // The API's parameter is `search`, not `q` — sending `q` was silently
          // ignored, so typing in the search box appeared to do nothing.
          search: search || undefined,
          status: status || undefined,
          category: category || undefined,
          // An empty part is left out rather than sent blank: the API reads a
          // missing `psm_part` as *both* batches, which is what "All batches"
          // should mean.
          psm_part: psmPart || undefined,
          semester_id: selectedId ?? undefined,
          page,
          per_page: 15,
        })
        // projectApi.list already normalises the envelope to { items, meta },
        // so it is used as-is. Re-reading `res.data` here yielded undefined and
        // the list silently rendered as empty.
        const { items, meta: pageMeta } = res
        if (cancelled) return
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
  }, [search, status, category, psmPart, selectedId, page])

  const canRegister = user?.role === 'student'

  return (
    <div className="space-y-6">
      <PageHeader
        title="Projects"
        subtitle={
          [
            meta ? `${meta.total} project${meta.total === 1 ? '' : 's'}` : null,
            selected?.name,
          ]
            .filter(Boolean)
            .join(' · ') || undefined
        }
        action={
          canRegister ? (
            <Link to="/registrations/new">
              <Button>Register a project</Button>
            </Link>
          ) : null
        }
      />

      <FilterBar>
        <FilterField label="Semester">
          <Select
            value={selectedId ?? ''}
            onChange={(event) => selectSemester(event.target.value)}
            className="min-w-[12rem]"
            aria-label="Filter by semester"
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
            value={psmPart}
            onChange={(value) => setFilter('psm_part', value)}
            name="project_part"
            options={[
              { value: '', label: 'All batches' },
              ...PSM_PARTS.map((value) => ({ value, label: partLabel(value, { short: true }) })),
            ]}
          />
        </FilterField>

        <FilterField label="Category" className="min-w-[10rem]">
          <Select
            value={category}
            onChange={(e) => setFilter('category', e.target.value)}
            aria-label="Filter by category"
          >
            <option value="">All categories</option>
            {Object.entries(CATEGORY_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </FilterField>

        <FilterField label="Status">
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            {Object.entries(PROJECT_STATUS).map(([value, meta2]) => (
              <option key={value} value={value}>
                {meta2.label}
              </option>
            ))}
          </Select>
        </FilterField>

        <FilterField label="Search" className="min-w-[14rem] flex-1">
          <Input
            type="search"
            placeholder="Title, code, or student"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="Search projects"
          />
        </FilterField>
      </FilterBar>

      {(search || status || category || psmPart) && (
        <div className="flex items-center gap-2 text-sm text-slate-500">
          <span>Filters active</span>
          <button
            type="button"
            onClick={() => setParams(new URLSearchParams(), { replace: true })}
            className="font-medium text-brand-700 hover:underline"
          >
            Clear all
          </button>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading projects" />
      ) : error ? (
        <ErrorState error={error} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No projects found"
            message={
              search || status || category || psmPart
                ? 'Try widening the filters.'
                : canRegister
                  ? 'Register your PSM project to get started.'
                  : 'No projects are visible to your role yet.'
            }
            action={
              canRegister && !search && !status ? (
                <Link to="/registrations/new">
                  <Button>Register a project</Button>
                </Link>
              ) : null
            }
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={['Project', 'Student', 'Part', 'Semester', 'Category', 'Progress', 'Next milestone', 'Status']}
            >
              {rows.map((project) => (
                <tr key={project.id} className="hover:bg-slate-50/60">
                  <Td>
                    <Link
                      to={`/projects/${project.id}`}
                      className="font-medium text-slate-800 hover:text-brand-700"
                    >
                      {project.title}
                    </Link>
                    {project.code && (
                      <div className="font-mono text-xs text-slate-400">{project.code}</div>
                    )}
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <Avatar name={project.students?.[0]?.name ?? '—'} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm text-slate-700">
                          {project.students?.[0]?.name ?? '—'}
                        </div>
                        <div className="font-mono text-xs text-slate-400">
                          {project.students?.[0]?.student_id}
                        </div>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    <span
                      className={`inline-block rounded border px-1.5 py-0.5 text-[11px] font-medium ${
                        PSM_PART_BADGE_TONES[normalisePart(project.psm_part)] ??
                        PSM_PART_BADGE_TONES.BOTH
                      }`}
                    >
                      {partLabel(project.psm_part, { short: true })}
                    </span>
                  </Td>
                  <Td className="text-xs text-slate-500">
                    {project.academic_semester?.name ?? project.academic_session ?? '—'}
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {CATEGORY_LABELS[project.category] ?? project.category}
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-20">
                        <ProgressBar
                          value={project.milestone_progress ?? 0}
                          tone={(project.milestone_progress ?? 0) >= 70 ? 'success' : 'brand'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">
                        {project.milestone_progress ?? 0}%
                      </span>
                    </div>
                  </Td>
                  <Td>
                    {project.next_milestone ? (
                      <>
                        <div className="text-sm text-slate-700">{project.next_milestone.title}</div>
                        <div
                          className={`text-xs ${
                            isOverdue(project.next_milestone.due_at)
                              ? 'text-rose-600'
                              : 'text-slate-400'
                          }`}
                        >
                          {formatDate(project.next_milestone.due_at, { fallback: 'no date' })}
                        </div>
                      </>
                    ) : (
                      <span className="text-xs text-slate-400">—</span>
                    )}
                  </Td>
                  <Td>
                    <StatusBadge status={project.status} />
                  </Td>
                </tr>
              ))}
            </DataTable>
          </div>
        </Card>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-slate-500">
            Showing {meta.from}–{meta.to} of {meta.total}
          </p>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => setFilter('page', String(meta.current_page - 1))}
            >
              Previous
            </Button>
            <Button
              variant="secondary"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setFilter('page', String(meta.current_page + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

export function StatusBadge({ status }) {
  const meta = statusMeta(PROJECT_STATUS, status)
  return <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? status}</Badge>
}
