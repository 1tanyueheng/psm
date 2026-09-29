import { useEffect, useState } from 'react'
import { rubricApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { can } from '../../lib/permissions'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Select,
} from '../../components/ui'
import { formatDate, formatMark, CATEGORY_LABELS } from '../../lib/format'

/**
 * Rubric template management (Module 4, authoring side).
 *
 * Rubrics are versioned and immutable once used. That is the whole point: a
 * mark awarded last semester must stay explainable by the criteria that were
 * in force at the time. So this page never edits a live template — it clones
 * one forward into a new version, which is why "New version" is the primary
 * action rather than "Edit".
 */
export default function RubricListPage() {
  const { user } = useAuth()
  const [templates, setTemplates] = useState([])
  const [selectedId, setSelectedId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [filter, setFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState(null)
  const [cloning, setCloning] = useState(false)

  const canManage = can(user?.role, 'manageTemplates')

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const { items } = unwrapPaged(
          await rubricApi.templates({ category: filter || undefined, per_page: 100 })
        )
        if (cancelled) return
        setTemplates(items)
        // Open the newest template by default so the page is never empty.
        if (items.length > 0) setSelectedId((prev) => prev ?? items[0].id)
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
  }, [filter])

  useEffect(() => {
    if (!selectedId) return undefined
    let cancelled = false

    async function loadDetail() {
      setDetailLoading(true)
      try {
        const data = unwrap(await rubricApi.template(selectedId))
        if (!cancelled) setDetail(data)
      } catch (err) {
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setDetailLoading(false)
      }
    }

    loadDetail()
    return () => {
      cancelled = true
    }
  }, [selectedId])

  async function createVersion() {
    if (!detail) return
    setCloning(true)
    try {
      // rubricApi.cloneTemplate() delegates to evaluationApi.cloneTemplate,
      // which already unwraps. Note rubricApi.template() is the exception: it
      // returns a synthetic envelope on purpose, so that one DOES need unwrap.
      const created = await rubricApi.cloneTemplate(detail.id, {
        version: (detail.version ?? 1) + 1,
      })
      setSelectedId(created?.id ?? selectedId)
    } catch (err) {
      setError(err)
    } finally {
      setCloning(false)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل نماذج سُلّم التقييم" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="قوالب سُلّم التقييم"
        subtitle="أنظمة تصحيح بنسخ — النسخ المنشورة لا تُعدَّل في مكانها أبدًا"
        actions={
          canManage ? (
            <Button onClick={createVersion} disabled={cloning || !detail}>
              {cloning ? 'جارٍ الإنشاء…' : 'نسخة جديدة من الحالية'}
            </Button>
          ) : null
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader title="القوالب" />
          <div className="mb-3">
            <Select
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              aria-label="تصفية حسب الفئة"
            >
              <option value="">كل الفئات</option>
              {Object.entries(CATEGORY_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>

          {templates.length === 0 ? (
            <EmptyState title="لا توجد قوالب" message="لا توجد قوالب سُلّم تقييم تطابق هذه التصفية." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {templates.map((template) => (
                <li key={template.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedId(template.id)}
                    className={`w-full px-1 py-3 text-start transition ${
                      selectedId === template.id
                        ? 'border-r-2 border-brand-600 pr-3'
                        : 'border-r-2 border-transparent pr-3 hover:bg-slate-50'
                    }`}
                    aria-current={selectedId === template.id ? 'true' : undefined}
                  >
                    <div className="flex items-center gap-2">
                      <span className="truncate font-medium text-slate-800">{template.name}</span>
                      {template.version && <Badge tone="neutral">v{template.version}</Badge>}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span className="capitalize">{template.assessor_type}</span>
                      <span>·</span>
                      <span>{CATEGORY_LABELS[template.category] ?? template.category}</span>
                      {template.psm_part && (
                        <>
                          <span>·</span>
                          <span>{template.psm_part}</span>
                        </>
                      )}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                      {template.is_published ? (
                        <Badge tone="success">منشور</Badge>
                      ) : (
                        <Badge tone="warning">مسودة</Badge>
                      )}
                      {template.usage_count > 0 && (
                        <span className="text-xs text-slate-400">
                          استُخدم {template.usage_count} مرة
                        </span>
                      )}
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <div className="lg:col-span-2">
          {detailLoading ? (
            <Spinner label="جارٍ تحميل القالب" />
          ) : !detail ? (
            <Card>
              <EmptyState title="اختر قالبًا" message="اختر سُلّمًا في القائمة اليسرى لفحصه." />
            </Card>
          ) : (
            <div className="space-y-6">
              <Card>
                <CardHeader
                  title={detail.name}
                  subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                      <Badge tone={detail.is_published ? 'success' : 'warning'}>
                        {detail.is_published ? 'منشور' : 'مسودة'}
                      </Badge>
                      <Badge tone="neutral">v{detail.version}</Badge>
                      <span className="capitalize">{detail.assessor_type}</span>
                      <span>·</span>
                      <span>{CATEGORY_LABELS[detail.category] ?? detail.category}</span>
                    </span>
                  }
                />
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <Meta label="إجمالي الدرجات" value={detail.total_marks ?? 100} />
                  <Meta label="درجة النجاح" value={detail.pass_mark ?? 50} />
                  <Meta
                    label="المكوّنات"
                    value={(detail.components ?? []).length}
                  />
                  <Meta label="أُنشئ" value={formatDate(detail.created_at)} />
                </div>
                {!detail.is_published && canManage && (
                  <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    هذه النسخة مسودة. انشرها قبل استخدامها في نماذج جديدة.
                  </p>
                )}
              </Card>

              {(detail.components ?? []).map((component) => (
                <Card key={component.code}>
                  <CardHeader
                    title={component.name}
                    action={
                      <Badge tone="brand">{component.weight ?? 0}%</Badge>
                    }
                  />
                  <ul className="divide-y divide-slate-100">
                    {(component.criteria ?? []).map((criterion) => (
                      <li key={criterion.code} className="py-3">
                        <div className="flex items-start justify-between gap-4">
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-slate-800">{criterion.name}</p>
                            {criterion.description && (
                              <p className="mt-0.5 text-sm text-slate-600">
                                {criterion.description}
                              </p>
                            )}
                          </div>
                          <div className="shrink-0 text-end text-xs text-slate-500">
                            <div className="font-semibold tabular-nums text-slate-700">
                              {formatMark(criterion.max_marks)} درجة
                            </div>
                            {criterion.weight != null && (
                              <div>{criterion.weight}% من المكوّن</div>
                            )}
                          </div>
                        </div>
                      </li>
                    ))}
                  </ul>
                </Card>
              ))}

              {/* Weight reconciliation — a mismatch would silently distort marks. */}
              <WeightCheck detail={detail} />
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * Show the arithmetic the server relies on. If component weights do not sum to
 * 100 the aggregate is rescaled, which is legal but worth surfacing so an
 * author can see the intent was preserved.
 */
function WeightCheck({ detail }) {
  const components = detail.components ?? []
  if (components.length === 0) return null

  const total = components.reduce((sum, c) => sum + (c.weight ?? 0), 0)
  const balanced = Math.abs(total - 100) < 0.01

  return (
    <Card className={balanced ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40'}>
      <div className="flex items-start gap-3">
        <span
          className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs text-white ${
            balanced ? 'bg-emerald-500' : 'bg-amber-500'
          }`}
        >
          {balanced ? '✓' : '!'}
        </span>
        <div>
          <p className={`font-medium ${balanced ? 'text-emerald-900' : 'text-amber-900'}`}>
            مجموع أوزان المكوّنات {formatMark(total)}%
          </p>
          <p className={`text-sm ${balanced ? 'text-emerald-800' : 'text-amber-800'}`}>
            {balanced
              ? 'الأوزان متوازنة — تُرتبط المجاميع المحسوبة مباشرة بمقياس 100 درجة.'
              : 'الأوزان لا تجمع إلى 100. تُعاد تسوية الدرجات النهائية نسبيًا، فيُحفظ الوزن النسبي لكن يختلف الحساب الخام عن المجموع الصارم.'}
          </p>
        </div>
      </div>
    </Card>
  )
}

function Meta({ label, value }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-sm font-medium text-slate-700">{value}</p>
    </div>
  )
}
