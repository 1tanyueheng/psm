import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { archiveApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import {
  Card, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Input, Select, DataTable, Td,
} from '../../components/ui'
import { formatMark, gradeTone, CATEGORY_LABELS } from '../../lib/format'

/**
 * Project archive (Module 7).
 *
 * The archive is a record, not a workspace, so the emphasis is on finding
 * things: full-text search across title/abstract/keywords, plus filters for
 * the dimensions a reader browses by (session, category, part). Only released
 * projects are archived, so a result here is always final and quotable.
 */
export default function ArchivePage() {
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [sessions, setSessions] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const search = params.get('q') ?? ''
  const session = params.get('academic_session') ?? ''
  const category = params.get('category') ?? ''
  const psmPart = params.get('psm_part') ?? ''
  const page = Number(params.get('page') ?? 1)

  const setFilter = useCallback(
    (key, value) => {
      const next = new URLSearchParams(params)
      if (value) next.set(key, value)
      else next.delete(key)
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
        const res = await archiveApi.list({
          q: search || undefined,
          academic_session: session || undefined,
          category: category || undefined,
          psm_part: psmPart || undefined,
          page,
          per_page: 15,
        })
        if (cancelled) return
        const { items, meta: pageMeta } = unwrapPaged(res)
        setRows(items)
        setMeta(pageMeta)
        // Session list rides along on the first response so the filter can be
        // populated without a second round trip.
        if (items.length > 0 && sessions.length === 0) {
          const unique = [...new Set(items.map((r) => r.academic_session).filter(Boolean))]
          setSessions(unique.sort().reverse())
        }
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
  }, [search, session, category, psmPart, page, sessions.length])

  const hasFilters = Boolean(search || session || category || psmPart)

  return (
    <div className="space-y-6">
      <PageHeader
        title="أرشيف المشاريع"
        subtitle={
          meta
            ? `${meta.total} ${meta.total === 1 ? 'مشروع مؤرشف' : 'مشاريع مؤرشفة'} — قابل للبحث والاستشهاد`
            : undefined
        }
      />

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Input
            type="search"
            placeholder="ابحث في العنوان أو الملخص أو الكلمات المفتاحية أو الطالب"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="البحث في الأرشيف"
          />
          <Select
            value={session}
            onChange={(e) => setFilter('academic_session', e.target.value)}
            aria-label="تصفية حسب الدورة"
          >
            <option value="">كل الدورات</option>
            {sessions.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </Select>
          <Select
            value={category}
            onChange={(e) => setFilter('category', e.target.value)}
            aria-label="تصفية حسب الفئة"
          >
            <option value="">كل الفئات</option>
            {Object.entries(CATEGORY_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
          <Select
            value={psmPart}
            onChange={(e) => setFilter('psm_part', e.target.value)}
            aria-label="تصفية حسب جزء PSM"
          >
            <option value="">كل الأجزاء</option>
            <option value="PSM1">PSM1</option>
            <option value="PSM2">PSM2</option>
          </Select>
        </div>
        {hasFilters && (
          <div className="mt-3 flex items-center gap-2 text-sm text-slate-500">
            <span>التصفية نشطة</span>
            <button
              type="button"
              onClick={() => setParams(new URLSearchParams(), { replace: true })}
              className="font-medium text-brand-700 hover:underline"
            >
              مسح الكل
            </button>
          </div>
        )}
      </Card>

      {loading ? (
        <Spinner label="جارٍ البحث في الأرشيف" />
      ) : error ? (
        <ErrorState error={error} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="لا شيء وُجد"
            message={
              hasFilters
                ? 'لا يوجد مشروع مؤرشف يطابق هذه التصفية.'
                : 'الأرشيف فارغ. تُضاف المشاريع بعد الإفراج عن الدرجات.'
            }
          />
        </Card>
      ) : (
        <>
          <Card className="overflow-hidden p-0">
            <div className="overflow-x-auto">
              <DataTable
                columns={[
                  { key: 'project', label: 'المشروع' },
                  { key: 'students', label: 'الطلاب' },
                  { key: 'session', label: 'الدورة' },
                  { key: 'category', label: 'الفئة' },
                  { key: 'supervisors', label: 'المشرفون' },
                  { key: 'grade', label: 'التقدير' },
                ]}
                rows={rows}
                render={(row) => [
                  <Td key="project" className="max-w-md">
                    <Link
                      to={`/archive/${row.id}`}
                      className="font-medium text-slate-800 hover:text-brand-700"
                    >
                      {row.title}
                    </Link>
                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                      {row.code && (
                        <span className="font-mono text-xs text-slate-400">{row.code}</span>
                      )}
                      {row.psm_part && (
                        <Badge tone={row.psm_part === 'PSM2' ? 'brand' : 'neutral'}>
                          {row.psm_part}
                        </Badge>
                      )}
                      {row.is_public && <Badge tone="success">عام</Badge>}
                    </div>
                    {row.keywords?.length > 0 && (
                      <div className="mt-1 flex flex-wrap gap-1">
                        {row.keywords.slice(0, 4).map((kw) => (
                          <span
                            key={kw}
                            className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                          >
                            {kw}
                          </span>
                        ))}
                      </div>
                    )}
                  </Td>,
                  <Td key="students">
                    <ul className="space-y-0.5">
                      {(row.students ?? []).slice(0, 2).map((s) => (
                        <li key={s.student_id ?? s.name} className="text-sm text-slate-700">
                          {s.name}
                          {s.student_id && (
                            <span className="ms-1.5 font-mono text-xs text-slate-400">
                              {s.student_id}
                            </span>
                          )}
                        </li>
                      ))}
                      {(row.students?.length ?? 0) > 2 && (
                        <li className="text-xs text-slate-400">
                          +{row.students.length - 2} إضافي
                        </li>
                      )}
                    </ul>
                  </Td>,
                  <Td key="session" className="text-sm text-slate-600">{row.academic_session ?? '—'}</Td>,
                  <Td key="category" className="text-sm text-slate-600">
                    {CATEGORY_LABELS[row.category] ?? row.category ?? '—'}
                  </Td>,
                  <Td key="supervisors" className="text-sm text-slate-600">
                    {(row.supervisor_names ?? row.supervisors ?? [])
                      .map((s) => (typeof s === 'string' ? s : s.name))
                      .filter(Boolean)
                      .join(', ') || '—'}
                  </Td>,
                  <Td key="grade">
                    {row.grade_letter ? (
                      <span
                        className={`rounded px-1.5 py-0.5 text-sm font-semibold ${gradeTone(row.grade_letter)}`}
                      >
                        {row.grade_letter}
                      </span>
                    ) : (
                      <span className="text-sm text-slate-400">—</span>
                    )}
                    {row.final_mark != null && (
                      <div className="mt-0.5 text-xs tabular-nums text-slate-400">
                        {formatMark(row.final_mark)}
                      </div>
                    )}
                  </Td>,
                ]}
              />
            </div>
          </Card>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-slate-500">
                عرض {meta.from}–{meta.to} من أصل {meta.total}
              </p>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setFilter('page', String(meta.current_page - 1))}
                >
                  السابق
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setFilter('page', String(meta.current_page + 1))}
                >
                  التالي
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
