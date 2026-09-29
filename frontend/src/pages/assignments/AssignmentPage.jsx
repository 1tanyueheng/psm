import { useCallback, useEffect, useMemo, useState } from 'react'
import { assignmentApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, Input, DataTable, Td, ProgressBar,
} from '../../components/ui'
import { formatDate } from '../../lib/format'

/**
 * Supervisor assignment (Module 2).
 *
 * The coordinator's job here is a matching problem with a hard constraint:
 * every student needs a supervisor, and no supervisor may exceed their
 * declared capacity. So the page shows the unassigned queue and the available
 * supervisors side by side and refuses to offer a full supervisor at all,
 * rather than letting the coordinator pick one and hit a server error.
 */
export default function AssignmentPage() {
  const [unassigned, setUnassigned] = useState([])
  const [supervisors, setSupervisors] = useState([])
  const [pairs, setPairs] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [search, setSearch] = useState('')
  const [tab, setTab] = useState('assign')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [unassignedRes, supervisorRes, pairRes] = await Promise.all([
        assignmentApi.unassigned({ per_page: 100 }),
        assignmentApi.supervisors({ per_page: 100 }),
        assignmentApi.list({ per_page: 100 }),
      ])
      setUnassigned(unwrapPaged(unassignedRes).items)
      setSupervisors(unwrapPaged(supervisorRes).items)
      setPairs(unwrapPaged(pairRes).items)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const available = useMemo(
    () => supervisors.filter((s) => s.has_capacity !== false),
    [supervisors]
  )

  const filteredUnassigned = useMemo(() => {
    if (!search.trim()) return unassigned
    const needle = search.trim().toLowerCase()
    return unassigned.filter(
      (s) =>
        s.name?.toLowerCase().includes(needle) ||
        s.student_id?.toLowerCase().includes(needle) ||
        s.program?.toLowerCase().includes(needle)
    )
  }, [unassigned, search])

  async function assign(student, supervisorId, role = 'primary') {
    setBusyId(student.id)
    setActionError(null)
    try {
      await assignmentApi.assign({
        student_id: student.id ?? student.student_id,
        supervisor_id: supervisorId,
        role,
      })
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر إنشاء هذا الإسناد.')
    } finally {
      setBusyId(null)
    }
  }

  async function remove(pair) {
    setBusyId(pair.id)
    setActionError(null)
    try {
      await assignmentApi.remove(pair.id)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر إزالة هذا الإسناد.')
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل الإسنادات" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="إسناد المشرفين"
        subtitle={`${unassigned.length} ${unassigned.length === 1 ? 'طالب بانتظار مشرف' : 'طلاب بانتظار مشرف'}`}
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {unassigned.length > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <p className="text-sm text-amber-900">
            {unassigned.length} {unassigned.length === 1 ? 'طالب ليس له مشرف' : 'طلاب ليس لهم مشرف'}.
            لا تُنشأ المراحل حتى يُسنَد مشرف أساسي.
          </p>
        </Card>
      )}

      <div className="flex gap-1 border-b border-slate-200">
        {[
          { key: 'assign', label: 'الإسناد', count: unassigned.length },
          { key: 'pairs', label: 'الإسنادات الحالية', count: pairs.length },
        ].map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition ${
              tab === t.key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
            aria-current={tab === t.key ? 'page' : undefined}
          >
            {t.label}
            <span className="ms-1.5 rounded-full bg-slate-100 px-1.5 text-xs tabular-nums text-slate-600">
              {t.count}
            </span>
          </button>
        ))}
      </div>

      {tab === 'assign' ? (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="lg:col-span-2">
            <Card>
              <CardHeader
                title="الطلاب غير المُسنَدين"
                subtitle="اختر مشرفًا من اللوحة على اليمين"
              />
              {unassigned.length > 0 && (
                <div className="mb-4">
                  <Input
                    type="search"
                    placeholder="تصفية بالاسم أو الرقم أو البرنامج"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="تصفية الطلاب"
                  />
                </div>
              )}

              {filteredUnassigned.length === 0 ? (
                <EmptyState
                  title={unassigned.length === 0 ? 'تم إسناد الجميع' : 'لا توجد نتائج'}
                  message={
                    unassigned.length === 0
                      ? 'كل طالب في هذه الدفعة لديه مشرف.'
                      : 'لا يوجد طالب يطابق هذه التصفية.'
                  }
                />
              ) : (
                <ul className="divide-y divide-slate-100">
                  {filteredUnassigned.map((student) => (
                    <li key={student.id} className="py-4">
                      <div className="flex flex-wrap items-start gap-4">
                        <Avatar name={student.name ?? '?'} />
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium text-slate-800">{student.name}</p>
                            <span className="font-mono text-xs text-slate-400">
                              {student.student_id}
                            </span>
                          </div>
                          <p className="mt-0.5 text-sm text-slate-500">
                            {student.program}
                            {student.batch && ` · دفعة ${student.batch}`}
                          </p>
                          {student.project_title && (
                            <p className="mt-1 line-clamp-1 text-sm text-slate-600">
                              {student.project_title}
                            </p>
                          )}
                        </div>

                        <div className="w-full sm:w-64">
                          <label className="sr-only" htmlFor={`sup-${student.id}`}>
                            إسناد مشرف لـ {student.name}
                          </label>
                          <Select
                            id={`sup-${student.id}`}
                            disabled={busyId === student.id || available.length === 0}
                            defaultValue=""
                            onChange={(e) => {
                              if (e.target.value) assign(student, e.target.value)
                            }}
                          >
                            <option value="">
                              {available.length === 0 ? 'لا توجد سعة متاحة' : 'اختر مشرفًا…'}
                            </option>
                            {available.map((s) => (
                              <option key={s.id} value={s.id}>
                                {s.name}
                                {s.current_load != null && s.max_supervisees
                                  ? ` (${s.current_load}/${s.max_supervisees})`
                                  : ''}
                              </option>
                            ))}
                          </Select>
                          {busyId === student.id && (
                            <p className="mt-1 text-xs text-slate-500">جارٍ الإسناد…</p>
                          )}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          {/* Capacity panel — the constraint the coordinator is working within. */}
          <div className="lg:col-span-1">
            <Card>
              <CardHeader title="سعة المشرفين" subtitle="يُعرض فقط المشرفون الذين لديهم متّسع" />
              {supervisors.length === 0 ? (
                <EmptyState title="لا يوجد مشرفون" message="لا يوجد مشرفون يقبلون طلابًا في الوقت الحالي." />
              ) : (
                <ul className="space-y-4">
                  {sortByAvailable(supervisors).map((s) => {
                    const used = s.current_load ?? 0
                    const max = s.max_supervisees ?? 0
                    const pct = max > 0 ? Math.round((used / max) * 100) : 0
                    const full = s.has_capacity === false || (max > 0 && used >= max)

                    return (
                      <li key={s.id}>
                        <div className="flex items-center gap-3">
                          <Avatar name={s.name} size="sm" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-800">{s.name}</p>
                            <p className="text-xs text-slate-400">
                              {s.expertise?.slice(0, 2).join(', ') || 'لا توجد خبرات مدرجة'}
                            </p>
                          </div>
                          <span className="shrink-0 text-xs tabular-nums text-slate-500">
                            {used}/{max || '∞'}
                          </span>
                        </div>
                        <div className="mt-2">
                          <ProgressBar
                            value={pct}
                            tone={full ? 'danger' : pct >= 80 ? 'warning' : 'success'}
                          />
                        </div>
                        {full && (
                          <p className="mt-1 text-xs text-rose-600">بلغ السعة القصوى</p>
                        )}
                      </li>
                    )
                  })}
                </ul>
              )}
            </Card>
          </div>
        </div>
      ) : (
        <Card className="overflow-hidden p-0">
          {pairs.length === 0 ? (
            <EmptyState title="لا توجد إسنادات بعد" message="ستُعرض الإسنادات التي تنشئها هنا." />
          ) : (
            <div className="overflow-x-auto">
              <DataTable
                columns={[
                  { key: 'student', label: 'الطالب' },
                  { key: 'programme', label: 'البرنامج' },
                  { key: 'supervisor', label: 'المشرف' },
                  { key: 'role', label: 'الدور' },
                  { key: 'assigned', label: 'تاريخ الإسناد' },
                  { key: 'action', label: '' },
                ]}
                rows={pairs}
                render={(pair) => [
                  <Td key="student">
                    <div className="flex items-center gap-2">
                      <Avatar name={pair.student?.name ?? '?'} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm text-slate-700">
                          {pair.student?.name ?? '—'}
                        </div>
                        <div className="font-mono text-xs text-slate-400">
                          {pair.student?.student_id}
                        </div>
                      </div>
                    </div>
                  </Td>,
                  <Td key="programme" className="text-sm text-slate-600">{pair.student?.program ?? '—'}</Td>,
                  <Td key="supervisor">
                    <div className="flex items-center gap-2">
                      <Avatar name={pair.supervisor?.name ?? '?'} size="sm" />
                      <span className="text-sm text-slate-700">
                        {pair.supervisor?.name ?? '—'}
                      </span>
                    </div>
                  </Td>,
                  <Td key="role">
                    <Badge tone={pair.role === 'primary' ? 'brand' : 'neutral'}>
                      {pair.role}
                    </Badge>
                  </Td>,
                  <Td key="assigned" className="text-sm text-slate-500">
                    {formatDate(pair.assigned_at, { fallback: '—' })}
                  </Td>,
                  <Td key="action">
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={busyId === pair.id}
                      onClick={() => remove(pair)}
                    >
                      إزالة
                    </Button>
                  </Td>,
                ]}
              />
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

/** Most headroom first, so the coordinator's eye lands on the best option. */
function sortByAvailable(list) {
  return [...list].sort((a, b) => {
    // Treat an unbounded capacity as the most room, not the least.
    const room = (s) =>
      s.max_supervisees ? (s.max_supervisees ?? 0) - (s.current_load ?? 0) : Infinity
    const roomA = room(a)
    const roomB = room(b)
    if (roomA !== roomB) return roomB - roomA
    return (a.name ?? '').localeCompare(b.name ?? '')
  })
}
