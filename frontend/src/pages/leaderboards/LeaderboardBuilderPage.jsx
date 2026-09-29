import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { leaderboardApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, DataTable, Td, Field, Input,
} from '../../components/ui'
import { formatMark, medalFor } from '../../lib/format'

/**
 * Board builder — inspect and adjust a draft before it goes public.
 *
 * The point of a draft is that staff can see exactly who would be shown and
 * who would be left out, with reasons, before committing. So the excluded list
 * is given equal weight to the ranked list: an unexplained omission from an
 * award is the thing that generates complaints.
 */
export default function LeaderboardBuilderPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [board, setBoard] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [tab, setTab] = useState('ranked')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // leaderboardApi.get() already unwraps the envelope.
      const data = await leaderboardApi.get(id)
      setBoard(data)

      // Eligibility is only meaningful for a draft; a published board is frozen.
      // leaderboardApi.eligible() already unwraps, so no .then(unwrap) here.
      if (!data?.is_published) {
        const eligible = await leaderboardApi
          .eligible({ min_assessors: data?.min_assessors })
          .catch(() => null)
        setEligibility(eligible)
      } else {
        setEligibility(null)
      }
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  async function update(patch) {
    setSaving(true)
    setActionError(null)
    try {
      await leaderboardApi.update(id, patch)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر حفظ هذا التغيير.')
    } finally {
      setSaving(false)
    }
  }

  async function publish() {
    setSaving(true)
    setActionError(null)
    try {
      await leaderboardApi.publish(id)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر نشر هذا اللوح.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل اللوح" />
  if (error) return <ErrorState error={error} />
  if (!board) return <ErrorState error={{ message: 'اللوح غير موجود.' }} />

  const entries = board.entries ?? []
  const ranked = entries.filter((e) => e.rank != null).sort((a, b) => a.rank - b.rank)
  const excluded = eligibility?.excluded ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title={board.title ?? 'لوح الجوائز'}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            {board.is_published ? (
              <Badge tone="success">مباشر</Badge>
            ) : (
              <Badge tone="warning">مسودة</Badge>
            )}
            <span className="font-mono text-xs">{board.slug}</span>
            {board.academic_session && <Badge tone="neutral">{board.academic_session}</Badge>}
          </span>
        }
        back={{ to: '/leaderboards', label: 'كل الألواح' }}
        actions={
          <div className="flex gap-2">
            {board.is_published ? (
              <>
                <Button variant="secondary" onClick={() => window.open(`/leaderboard/${board.slug}`, '_blank')}>
                  عرض الصفحة العامة
                </Button>
                <Button
                  variant="secondary"
                  disabled={saving}
                  onClick={() =>
                    update({ is_published: false }).then(() => navigate('/leaderboards'))
                  }
                >
                  إزالة من النشر
                </Button>
              </>
            ) : (
              <Button disabled={saving || ranked.length === 0} onClick={publish}>
                {saving ? 'جارٍ التنفيذ…' : 'نشر اللوح'}
              </Button>
            )}
          </div>
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {!board.is_published && (
        <Card>
          <CardHeader title="خيارات العرض" subtitle="ينطبق على هذا اللوح فقط" />
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="عدد الفائزين المعروضين" htmlFor="top_n">
              <Input
                id="top_n"
                type="number"
                min={1}
                max={20}
                defaultValue={board.top_n ?? 3}
                onBlur={(e) => {
                  const value = Number(e.target.value)
                  if (value && value !== board.top_n) update({ top_n: value })
                }}
              />
            </Field>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={board.show_scores !== false}
                onChange={(e) => update({ show_scores: e.target.checked })}
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              />
              عرض الدرجات علنًا
            </label>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={board.show_student_names !== false}
                onChange={(e) => update({ show_student_names: e.target.checked })}
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              />
              عرض أسماء الطلاب
            </label>
          </div>
          <p className="mt-3 text-sm text-slate-500">
            إخفاء الأسماء أو الدرجات لا يزال يعرض عنوان المشروع والمرتبة. أرقام الطلاب لا تُنشر أبدًا.
          </p>
        </Card>
      )}

      <div className="flex gap-1 border-b border-slate-200">
        {[
          { key: 'ranked', label: 'المُرتَّبون', count: ranked.length },
          { key: 'excluded', label: 'المُستبعدون', count: excluded.length },
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

      {tab === 'ranked' ? (
        ranked.length === 0 ? (
          <Card>
            <EmptyState
              title="لا توجد مشاركات مرتبة"
              message="لا ترتيب لهذا اللوح بعد. أنشئ مسودة من صفحة الجوائز بعد الإفراج عن الدرجات."
            />
          </Card>
        ) : (
          <Card className="overflow-hidden p-0">
            <div className="overflow-x-auto">
              <DataTable
                columns={[
                  { key: 'rank', label: 'المرتبة' },
                  { key: 'project', label: 'المشروع' },
                  { key: 'students', label: 'الطلاب' },
                  { key: 'category', label: 'الفئة' },
                  { key: 'assessors', label: 'المُقيِّمون' },
                  { key: 'mark', label: 'الدرجة النهائية' },
                  { key: 'board', label: 'اللوح' },
                ]}
                render={(entry) => {
                  const medal = medalFor(entry.rank)
                  const inTop = entry.rank <= (board.top_n ?? 3)
                  return (
                    <>
                      <Td>
                        <span className="inline-flex items-center gap-1.5">
                          {medal && <span aria-hidden="true">{medal}</span>}
                          <span className="font-semibold tabular-nums text-slate-800">
                            {entry.rank}
                          </span>
                        </span>
                      </Td>
                      <Td>
                        <div className="font-medium text-slate-800">{entry.title}</div>
                        {entry.code && (
                          <div className="font-mono text-xs text-slate-400">{entry.code}</div>
                        )}
                      </Td>
                      <Td className="text-sm text-slate-600">
                        {(entry.students ?? [])
                          .map((s) => (typeof s === 'string' ? s : s.name))
                          .filter(Boolean)
                          .join(', ') || '—'}
                      </Td>
                      <Td className="text-sm capitalize text-slate-600">
                        {entry.category ?? '—'}
                      </Td>
                      <Td className="text-center tabular-nums text-slate-600">
                        {entry.assessor_count ?? '—'}
                      </Td>
                      <Td className="font-semibold tabular-nums">
                        {formatMark(entry.final_mark)}
                      </Td>
                      <Td>
                        {inTop ? (
                          <Badge tone="brand">معروض</Badge>
                        ) : (
                          <Badge tone="neutral">مخفي</Badge>
                        )}
                      </Td>
                    </>
                  )
                }}
                rows={ranked}
              />
            </div>
          </Card>
        )
      ) : excluded.length === 0 ? (
        <Card>
          <EmptyState
            title="لا شيء مستبعد"
            message="كل مشروع حصل على درجة مُفرَج عنها مؤهل لهذا اللوح."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={[
                { key: 'project', label: 'المشروع' },
                { key: 'student', label: 'الطالب' },
                { key: 'category', label: 'الفئة' },
                { key: 'reasons', label: 'سبب الاستبعاد' },
              ]}
              render={(row) => (
                <>
                  <Td>
                    <div className="font-medium text-slate-800">{row.title}</div>
                    {row.code && (
                      <div className="font-mono text-xs text-slate-400">{row.code}</div>
                    )}
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {row.student?.name ?? row.student_name ?? '—'}
                    {row.student?.student_id && (
                      <span className="ms-1.5 font-mono text-xs text-slate-400">
                        {row.student.student_id}
                      </span>
                    )}
                  </Td>
                  <Td className="text-sm capitalize text-slate-600">{row.category ?? '—'}</Td>
                  <Td>
                    <ul className="space-y-0.5">
                      {(row.reasons ?? []).map((reason) => (
                        <li key={reason} className="text-sm text-amber-700">
                          {reason}
                        </li>
                      ))}
                      {(row.reasons?.length ?? 0) === 0 && (
                        <li className="text-sm text-slate-400">لم يُسجَّل أي سبب</li>
                      )}
                    </ul>
                  </Td>
                </>
              )}
              rows={excluded}
            />
          </div>
        </Card>
      )}
    </div>
  )
}
