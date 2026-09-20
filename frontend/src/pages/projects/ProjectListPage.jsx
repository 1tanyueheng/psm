import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { projectApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import {
  Card, PageHeader, Badge, Avatar, ProgressBar, EmptyState,
  Spinner, ErrorState, Button, Select, Input, DataTable, Td,
} from '../../components/ui'
import { formatDate, isOverdue, CATEGORY_LABELS, PROJECT_STATUS, statusMeta } from '../../lib/format'

/**
 * Project list.
 *
 * Deliberately filter-driven rather than paginated-by-default, because every
 * role arrives here with a specific slice in mind: a supervisor wants their
 * own roster, a coordinator wants unpaired projects, an examiner wants the ones
 * they assess. The API scopes results to the caller, so the filters refine
 * rather than broaden.
 */
export default function ProjectListPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const search = params.get('q') ?? ''
  const status = params.get('status') ?? ''
  const category = params.get('category') ?? ''
  const psmPart = params.get('psm_part') ?? ''
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
          q: search || undefined,
          status: status || undefined,
          category: category || undefined,
          psm_part: psmPart || undefined,
          page,
          per_page: 15,
        })
        const { items, meta: pageMeta } = unwrapPagedLocal(res)
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
  }, [search, status, category, psmPart, page])

  const canRegister = user?.role === 'student'

  return (
    <div className="space-y-6">
      <PageHeader
        title="Projects"
        subtitle={meta ? `${meta.total} project${meta.total === 1 ? '' : 's'}` : undefined}
        actions={
          canRegister ? (
            <Link to="/projects/register">
              <Button>Register a project</Button>
            </Link>
          ) : null
        }
      />

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Input
            type="search"
            placeholder="Search title or student"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="Search projects"
          />
          <Select
            value={psmPart}
            onChange={(e) => setFilter('psm_part', e.target.value)}
            aria-label="Filter by PSM part"
          >
            <option value="">All parts</option>
            <option value="PSM1">PSM1</option>
            <option value="PSM2">PSM2</option>
          </Select>
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
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            {Object.entries(PROJECT_STATUS).map(([value, meta]) => (
              <option key={value} value={value}>
                {meta.label}
              </option>
            ))}
          </Select>
        </div>
        {(search || status || category || psmPart) && (
          <div className="mt-3 flex items-center gap-2 text-sm text-slate-500">
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
      </Card>

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
                <Link to="/projects/register">
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
              columns={['Project', 'Student', 'Part', 'Category', 'Progress', 'Next milestone', 'Status']}
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
                    <Badge tone={project.psm_part === 'PSM2' ? 'brand' : 'neutral'}>
                      {project.psm_part}
                    </Badge>
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {CATEGORY_LABELS[project.category] ?? project.category}
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <div className="w-20">
                        <ProgressBar
                          value={project.progress_percent ?? 0}
                          tone={project.progress_percent >= 70 ? 'success' : 'brand'}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-slate-500">
                        {project.progress_percent ?? 0}%
                      </span>
                    </div>
                  </Td>
                  <Td>
                    {project.next_milestone_name ? (
                      <>
                        <div className="text-sm text-slate-700">{project.next_milestone_name}</div>
                        <div
                          className={`text-xs ${
                            isOverdue(project.next_milestone_due_at)
                              ? 'text-rose-600'
                              : 'text-slate-400'
                          }`}
                        >
                          {formatDate(project.next_milestone_due_at, { fallback: 'no date' })}
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

/** Paged responses carry `meta` alongside `data` — see `ApiController::paginated()`. */
function unwrapPagedLocal(response) {
  const body = response?.data ?? {}
  return {
    items: body.data ?? [],
    meta: body.meta ?? null,
  }
}

export function StatusBadge({ status }) {
  const meta = statusMeta(PROJECT_STATUS, status)
  return <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? status}</Badge>
}
