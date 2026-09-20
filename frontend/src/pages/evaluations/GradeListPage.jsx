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
      setDistribution(distRes.status === 'fulfilled' ? (unwrap(distRes.value) ?? []) : [])
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
      setActionError(err?.message ?? 'Could not release that grade.')
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
      setActionError(err?.message ?? 'Some grades could not be released.')
      await load()
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="Loading grades" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Grades"
        subtitle={meta ? `${meta.total} computed` : undefined}
        actions={
          canRelease && pending.length > 0 ? (
            <Button onClick={releaseAll} disabled={busyId === 'all'}>
              {busyId === 'all' ? 'Releasing…' : `Release all ${pending.length}`}
            </Button>
          ) : null
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {canRelease && pending.length > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <p className="text-sm text-amber-900">
            <span className="font-semibold">{pending.length}</span>{' '}
            grade{pending.length === 1 ? '' : 's'} computed but not yet visible to students.
            Releasing publishes the mark and writes an audit entry per project.
          </p>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-4">
        <Card>
          <CardHeader title="Distribution" />
          {distribution.length === 0 ? (
            <p className="text-sm text-slate-500">No data</p>
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
                placeholder="Search by student or project"
                defaultValue={search}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
                }}
                aria-label="Search grades"
              />
              <Select
                value={status}
                onChange={(e) => setFilter('status', e.target.value)}
                aria-label="Filter by status"
              >
                <option value="">All statuses</option>
                <option value="computed">Computed, not released</option>
                <option value="released">Released</option>
                <option value="pending">Pending assessment</option>
                <option value="moderated">Moderated</option>
              </Select>
            </div>
          </Card>
        </div>
      </div>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No grades"
            message="Grades appear once assessors submit their marks and the final mark is computed."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={[
                'Project',
                'Student',
                'Category',
                'Assessors',
                'Final mark',
                'Grade',
                'Status',
                '',
              ]}
            >
              {rows.map((grade) => (
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
                      <span className="ml-1 text-xs text-amber-600">insufficient</span>
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
                      {grade.status === 'computed' ? 'awaiting release' : grade.status}
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
                        {busyId === grade.id ? 'Releasing…' : 'Release'}
                      </Button>
                    ) : (
                      <Link to={`/projects/${grade.project_id}`}>
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
