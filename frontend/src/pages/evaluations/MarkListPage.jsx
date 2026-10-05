import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { markApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import { can } from '../../lib/permissions'
import {
  Card, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input,
} from '../../components/ui'
import { formatMark, formatDate, CATEGORY_LABELS } from '../../lib/format'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'

/**
 * Mark list — the release surface (Module 4 tail end).
 *
 * Coordinator and admin staff come here to do one job: check computed marks
 * and release them. The page therefore leads with the "pending release" count
 * and offers bulk release, since releasing one at a time across a cohort of
 * dozens is the kind of friction that stops a process being followed.
 */
export default function MarkListPage() {
  const { user } = useAuth()
  const { selectedId, selectSemester, semesters } = useSemesters()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState(null)

  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''
  const part = params.get('psm_part') ?? ''
  const canRelease = can(user?.role, 'releaseMarks')

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
      const listParams = {}
      if (selectedId) listParams.semester_id = selectedId
      if (part) listParams.psm_part = part
      const listRes = await markApi.list({
        ...listParams,
        status: status || undefined,
        q: search || undefined,
        per_page: 100,
      })

      const { items, meta: pageMeta } = unwrapPaged(listRes)
      setRows(items)
      setMeta(pageMeta)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [status, search, selectedId, part])

  useEffect(() => {
    load()
  }, [load])

  // A mark is either released or still provisional. The API stores nothing
  // else, so "not yet released" is the only pending state there is.
  const pending = useMemo(() => rows.filter((r) => r.status !== 'released'), [rows])

  async function release(mark) {
    setBusyId(mark.id)
    setActionError(null)
    try {
      await markApi.release(mark.id)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'Could not release that mark.')
    } finally {
      setBusyId(null)
    }
  }

  async function releaseAll() {
    setBusyId('all')
    setActionError(null)
    try {
      // Release pending marks one by one so a single failure does not block
      // the rest — the API has no bulk endpoint by design, since each release
      // writes its own audit entry.
      for (const mark of pending) {
        await markApi.release(mark.id)
      }
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'Some marks could not be released.')
      await load()
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="Loading marks" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Marks & Release"
        subtitle={meta ? `${meta.total} computed` : undefined}
        action={
          <div className="flex flex-wrap items-center gap-2">
            {semesters.length > 0 && (
              <Select
                value={selectedId ?? ''}
                onChange={(e) => selectSemester(e.target.value || null)}
                aria-label="Semester"
              >
                <option value="">All semesters</option>
                {semesters.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            )}
            <Select
              value={part}
              onChange={(e) => setFilter('psm_part', e.target.value)}
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
              value={status}
              onChange={(e) => setFilter('status', e.target.value)}
              aria-label="Status"
            >
              <option value="">All statuses</option>
              <option value="released">Released</option>
              <option value="draft">Draft</option>
            </Select>
            <Input
              type="search"
              placeholder="Search student, matric, project…"
              value={search}
              onChange={(e) => setFilter('q', e.target.value)}
              className="w-64"
              aria-label="Search"
            />
            {canRelease && pending.length > 0 ? (
              <Button onClick={releaseAll} disabled={busyId === 'all'}>
                {busyId === 'all' ? 'Releasing…' : `Release all ${pending.length}`}
              </Button>
            ) : null}
          </div>
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {canRelease && pending.length > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <p className="text-sm text-amber-900">
            <span className="font-semibold">{pending.length}</span>{' '}
            mark{pending.length === 1 ? '' : 's'} computed but not yet visible to students.
            Releasing publishes the mark and writes an audit entry per project.
          </p>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-4">
      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No marks"
            message="Marks appear once assessors submit their forms and the final mark is computed."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={[
                'Project',
                'Student',
                'Batch',
                'Category',
                'Assessors',
                'Final mark',
                'Status',
                '',
              ]}
            >
              {rows.map((mark) => (
                <tr key={mark.id} className="hover:bg-slate-50/60">
                  <Td>
                    <Link
                      to={`/projects/${mark.project_id}`}
                      className="font-medium text-slate-800 hover:text-brand-700"
                    >
                      {mark.project?.title ?? `#${mark.project_id}`}
                    </Link>
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <Avatar name={mark.student?.name ?? '—'} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm text-slate-700">
                          {mark.student?.name ?? '—'}
                        </div>
                        <div className="font-mono text-xs text-slate-400">
                          {mark.student?.student_id}
                        </div>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    <Badge tone={PSM_PART_BADGE_TONES[mark.psm_part] ?? 'neutral'}>
                      {partLabel(mark.psm_part)}
                    </Badge>
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {mark.project?.category_label ?? CATEGORY_LABELS[mark.project?.category] ?? '—'}
                  </Td>
                  <Td className="text-center tabular-nums text-slate-600">
                    {mark.assessor_count ?? 0}
                    {mark.status !== 'released' && mark.is_publishable === false && (
                      <span className="ml-1 text-xs text-amber-600">insufficient</span>
                    )}
                  </Td>
                  <Td className="font-semibold tabular-nums">
                    {mark.final_mark != null ? formatMark(mark.final_mark) : '—'}
                  </Td>
                  <Td>
                    <Badge
                      tone={mark.status === 'released' ? 'success' : 'warning'}
                    >
                      {mark.status === 'released' ? 'released' : 'awaiting release'}
                    </Badge>
                    {mark.released_at && (
                      <div className="mt-0.5 text-xs text-slate-400">
                        {formatDate(mark.released_at)}
                      </div>
                    )}
                  </Td>
                  <Td>
                    {canRelease && mark.status !== 'released' ? (
                      <Button
                        size="sm"
                        disabled={busyId === mark.id || busyId === 'all'}
                        onClick={() => release(mark)}
                      >
                        {busyId === mark.id ? 'Releasing…' : 'Release'}
                      </Button>
                    ) : (
                      <Link to={`/projects/${mark.project_id}`}>
                        <Button size="sm" variant="ghost">
                          View
                        </Button>
                      </Link>
                    )}
                  </Td>
                </tr>
              ))}
            </DataTable>
          </div>
        </Card>
      )}
    </div>
  </div>
)
}
