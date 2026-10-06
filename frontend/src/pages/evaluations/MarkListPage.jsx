import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { markApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input,
} from '../../components/ui'
import { formatMark, formatDate, CATEGORY_LABELS } from '../../lib/format'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'

/**
 * Mark list â€” read-only oversight (Module 4 tail end).
 *
 * This page used to be a release surface: coordinators came here to check
 * computed marks and publish them one at a time or in bulk. Marks now publish
 * themselves the moment a supervisor's form arrives, so there is nothing to
 * release and no button to offer.
 *
 * What a coordinator still needs from this screen is the *completeness*
 * picture â€” which students' forms are all in and which are not â€” because that
 * is what gates closing the semester. The lock column answers that.
 */
export default function MarkListPage() {
  const { selectedId, selectSemester, semesters } = useSemesters()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''
  const part = params.get('psm_part') ?? ''

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

  if (loading) return <Spinner label="Loading marks" />
  if (error) return <ErrorState error={error} />

  // How far the term's marking has got, computed server-side from the
  // submissions rather than from a flag a coordinator set.
  const marks = meta?.semester_marks

  return (
    <div className="space-y-6">
      <PageHeader
        title="Marks"
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
              <option value="released">Published</option>
              <option value="draft">Provisional</option>
            </Select>
            <Input
              type="search"
              placeholder="Search student, matric, projectâ€¦"
              value={search}
              onChange={(e) => setFilter('q', e.target.value)}
              className="w-64"
              aria-label="Search"
            />
          </div>
        }
      />

      {marks && (
        <Card>
          <p className="text-sm text-slate-600">
            <span className="font-semibold text-slate-800">{marks.complete}</span> of{' '}
            <span className="font-semibold text-slate-800">{marks.total}</span> mark
            submissions are complete
            {marks.outstanding > 0 && (
              <>
                {' â€” '}
                <span className="text-amber-700">
                  {marks.outstanding} still waiting on a form
                </span>
              </>
            )}
            . Marks are published to students automatically as soon as each form is
            filed; nothing here needs releasing.
          </p>
        </Card>
      )}

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No marks"
            message="Marks appear once assessors submit their forms and the final mark is computed."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
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
                      <Avatar name={mark.student?.name ?? 'â€”'} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm text-slate-700">
                          {mark.student?.name ?? 'â€”'}
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
                    {mark.project?.category_label ?? CATEGORY_LABELS[mark.project?.category] ?? 'â€”'}
                  </Td>
                  <Td className="text-center tabular-nums text-slate-600">
                    {mark.assessor_count ?? 0}
                  </Td>
                  <Td className="tabular-nums">
                    {/*
                      The internal aggregate, deliberately labelled as a
                      comparator rather than a mark. It is the weighted
                      subtotals rescaled onto 0-100 because the system holds
                      only part of the assessment; the number a student is
                      actually shown is the Lampiran total out of 65 or 95.
                      Showing this unlabelled is what made the student page look
                      like it disagreed with itself.
                    */}
                    <div className="font-semibold text-slate-800">
                      {mark.final_mark != null ? formatMark(mark.final_mark) : 'â€”'}
                    </div>
                    <div className="text-xs text-slate-400">internal scale</div>
                  </Td>
                  <Td>
                    <Badge tone={mark.is_released ? 'success' : 'warning'}>
                      {mark.is_released ? 'published' : 'provisional'}
                    </Badge>
                    {mark.released_at && (
                      <div className="mt-0.5 text-xs text-slate-400">
                        {formatDate(mark.released_at)}
                      </div>
                    )}
                  </Td>
                  <Td>
                    <Link to={`/projects/${mark.project_id}`}>
                      <Button size="sm" variant="ghost">
                        View
                      </Button>
                    </Link>
                  </Td>
                </tr>
              ))}
            </DataTable>
        </Card>
      )}
    </div>
  )
}
