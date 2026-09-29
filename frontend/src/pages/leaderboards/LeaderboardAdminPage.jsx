import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { leaderboardApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, DataTable, Td,
} from '../../components/ui'
import { formatDate } from '../../lib/format'

/**
 * Pixel-It award administration (Module 8, staff side).
 *
 * Publishing is deliberately two-phase. `build` computes a board from current
 * marks into a draft snapshot; `publish` flips that snapshot live. The public
 * page therefore always reads a frozen ranking rather than recomputing on each
 * request, which means a board cannot change underneath a visitor mid-ceremony
 * — and a half-graded cohort never produces a half-built public page.
 */
export default function LeaderboardAdminPage() {
  const [boards, setBoards] = useState([])
  const [settings, setSettings] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [boardRes, settingRes, eligibleRes] = await Promise.allSettled([
        leaderboardApi.list({ per_page: 50 }),
        leaderboardApi.settings(),
        leaderboardApi.eligible({ per_page: 1 }),
      ])

      if (boardRes.status === 'rejected') throw boardRes.reason
      setBoards(unwrapPaged(boardRes.value).items)
      setSettings(settingRes.status === 'fulfilled' ? unwrap(settingRes.value) : null)
      setEligibility(eligibleRes.status === 'fulfilled' ? unwrap(eligibleRes.value) : null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  async function act(key, fn) {
    setBusy(key)
    setActionError(null)
    try {
      await fn()
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'تعذّر تنفيذ هذا الإجراء.')
    } finally {
      setBusy(null)
    }
  }

  if (loading) return <Spinner label="جارٍ تحميل ألواح الجوائز" />
  if (error) return <ErrorState error={error} />

  const excluded = eligibility?.excluded_count ?? 0
  const eligibleCount = eligibility?.eligible_count ?? 0

  return (
    <div className="space-y-6">
      <PageHeader
        title="جوائز Pixel-It"
        subtitle="تكريم مرتب للمشاريع التخرُّجية المكتملة"
        actions={
          <Button
            disabled={busy === 'build'}
            onClick={() => act('build', () => leaderboardApi.build({}))}
          >
            {busy === 'build' ? 'جارٍ الإنشاء…' : 'إنشاء مسودة جديدة'}
          </Button>
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {/* ملخص الأهلية — هذا ما يمنع نشر لوح خاطئ. */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">مشاريع مؤهلة</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">{eligibleCount}</p>
          <p className="mt-1 text-sm text-slate-500">
            درجات مُفرَج عنها و{settings?.min_assessors ?? 2} مُقيِّم على الأقل
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">مُستبعدة</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">{excluded}</p>
          <p className="mt-1 text-sm text-slate-500">
            {settings?.honour_opt_out ? 'يشمل الطلاب الذين انسحبوا' : 'غير مؤهلة للترتيب'}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">لوح مباشر</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">
            {boards.filter((b) => b.is_published).length}
          </p>
          <p className="mt-1 text-sm text-slate-500">ظاهر للجمهور الآن</p>
        </Card>
      </div>

      <Card>
        <CardHeader title="الألواح" subtitle="الألواح المسوَّدة خاصة حتى النشر" />
        {boards.length === 0 ? (
          <EmptyState
            title="لا توجد ألواح بعد"
            message="أنشئ مسودة لأخذ لقطة من الترتيب الحالي، ثم انشرها."
          />
        ) : (
          <DataTable
            columns={[
              { key: 'board', label: 'اللوح' },
              { key: 'session', label: 'الفصل' },
              { key: 'entries', label: 'المشاركات' },
              { key: 'top', label: 'أعلى ن' },
              { key: 'state', label: 'الحالة' },
              { key: 'built', label: 'تاريخ الإنشاء' },
              { key: 'actions', label: '' },
            ]}
            render={(board) => (
              <>
                <Td>
                  <div className="font-medium text-slate-800">{board.title ?? board.name}</div>
                  {board.slug && (
                    <div className="font-mono text-xs text-slate-400">{board.slug}</div>
                  )}
                </Td>
                <Td className="text-sm text-slate-600">{board.academic_session ?? '—'}</Td>
                <Td className="tabular-nums">{board.entries_count ?? board.entry_count ?? 0}</Td>
                <Td className="tabular-nums">{board.top_n ?? '—'}</Td>
                <Td>
                  {board.is_published ? (
                    <>
                      <Badge tone="success">مباشر</Badge>
                      {board.published_at && (
                        <div className="mt-0.5 text-xs text-slate-400">
                          {formatDate(board.published_at)}
                        </div>
                      )}
                    </>
                  ) : (
                    <Badge tone="warning">مسودة</Badge>
                  )}
                </Td>
                <Td className="text-sm text-slate-500">
                  {formatDate(board.built_at ?? board.created_at, { fallback: '—' })}
                </Td>
                <Td>
                  <div className="flex flex-wrap gap-2">
                    <Link to={`/leaderboards/${board.id}`}>
                      <Button size="sm" variant="secondary">فتح</Button>
                    </Link>
                    {!board.is_published ? (
                      <Button
                        size="sm"
                        disabled={busy === `publish-${board.id}`}
                        onClick={() =>
                          act(`publish-${board.id}`, () => leaderboardApi.publish(board.id))
                        }
                      >
                        {busy === `publish-${board.id}` ? 'جارٍ النشر…' : 'نشر'}
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy === `unpublish-${board.id}`}
                        onClick={() =>
                          act(`unpublish-${board.id}`, () => leaderboardApi.unpublish(board.id))
                        }
                      >
                        إزالة من النشر
                      </Button>
                    )}
                  </div>
                </Td>
              </>
            )}
            rows={boards}
          />
        )}
      </Card>

      {settings && (
        <Card>
          <CardHeader
            title="إعدادات الجوائز"
            subtitle="قواعد عامة تُطبَّق عند إنشاء لوح"
          />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Setting label="عدد الفائزين المعروضين" value={settings.top_n ?? 3} />
            <Setting label="الحد الأدنى للمُقيِّمين" value={settings.min_assessors ?? 2} />
            <Setting
              label="يتطلب الاعتماد"
              value={settings.require_approval ? 'نعم' : 'لا'}
            />
            <Setting
              label="خيار الانسحاب"
              value={settings.honour_opt_out ? 'نعم' : 'لا'}
            />
          </div>
          <p className="mt-4 text-sm text-slate-500">
            الإعدادات قادمة من إعدادات الخادم والبيئة. غيّرها عبر بيئة النشر وليس لكل لوح
            على حدة، بحيث يُرتَّب كل الألواح في الفصل تحت نفس القواعد.
          </p>
        </Card>
      )}
    </div>
  )
}

function Setting({ label, value }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-lg font-semibold text-slate-800">{value}</p>
    </div>
  )
}
