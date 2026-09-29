import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportApi, auditApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, EmptyState,
  Spinner, ErrorState, Button,
} from '../../components/ui'
import { formatDateTime, relativeDays } from '../../lib/format'
import { ROLE_LABELS } from '../../lib/permissions'

/**
 * Admin dashboard — Module 1/2 housekeeping plus Module 7 oversight.
 *
 * Admin is not a teaching role, so the page is deliberately operational:
 * account counts, recent privileged activity, and anything anomalous. The
 * audit feed is the main event here, because "who changed what" is the only
 * question only an admin can answer.
 */
export default function AdminDashboard() {
  const [stats, setStats] = useState(null)
  const [audit, setAudit] = useState([])
  const [suspicious, setSuspicious] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const [statsRes, auditRes, flagRes] = await Promise.all([
          reportApi.systemStats(),
          auditApi.list({ per_page: 12 }),
          auditApi.list({ suspicious: true, per_page: 1 }),
        ])
        if (cancelled) return

        setStats(unwrap(statsRes) ?? {})
        setAudit(unwrapPaged(auditRes).items)
        setSuspicious(unwrapPaged(flagRes).meta?.total ?? 0)
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
  }, [])

  if (loading) return <Spinner label="جارٍ تحميل نظرة النظام" />
  if (error) return <ErrorState error={error} />

  const s = stats ?? {}
  const roleCounts = s.users_by_role ?? {}

  return (
    <div className="space-y-6">
      <PageHeader
        title="إدارة النظام"
        subtitle="الحسابات، الوصول، وسجل التدقيق"
        actions={
          <div className="flex gap-2">
            <Link to="/audit">
              <Button variant="secondary">سجل التدقيق</Button>
            </Link>
            <Link to="/leaderboards">
              <Button>جوائز Pixel-It</Button>
            </Link>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="مستخدمون نشطون" value={s.active_users ?? 0} hint="لم يُحذفوا" />
        <StatCard
          label="موقوفون"
          value={s.suspended_users ?? 0}
          hint="محجوبون عن الدخول"
          tone={(s.suspended_users ?? 0) > 0 ? 'warning' : 'default'}
        />
        <StatCard
          label="مشاريع مؤرشفة"
          value={s.archived_projects ?? 0}
          hint="سجل دائم"
        />
        <StatCard
          label="أحداث معلَّمة"
          value={suspicious}
          hint="معلَّمة كمشبوهة"
          tone={suspicious > 0 ? 'danger' : 'success'}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader title="الحسابات حسب الدور" />
          <ul className="divide-y divide-slate-100">
            {Object.entries(roleCounts).length === 0 ? (
              <li className="py-3 text-sm text-slate-500">لا توجد بيانات</li>
            ) : (
              Object.entries(roleCounts).map(([role, count]) => (
                <li key={role} className="flex items-center justify-between py-2.5">
                  <span className="text-sm text-slate-700">
                    {ROLE_LABELS[role] ?? role.replace(/_/g, ' ')}
                  </span>
                  <span className="font-semibold tabular-nums text-slate-800">{count}</span>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader
            title="النشاط المميز الأخير"
            subtitle="أحدث أحداث التدقيق عبر النظام"
            action={
              <Link to="/audit">
                <Button size="sm" variant="ghost">عرض الكل</Button>
              </Link>
            }
          />
          {audit.length === 0 ? (
            <EmptyState title="لا نشاط مسجّل" message="ستظهر الأحداث المُدققة هنا." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {audit.map((entry) => (
                <li key={entry.id} className="flex items-start gap-3 py-3">
                  <SeverityDot severity={entry.severity} />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-slate-800">{entry.description}</p>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                      <span className="font-medium">{entry.actor_name ?? 'النظام'}</span>
                      {entry.actor_role && <span>· {entry.actor_role}</span>}
                      {entry.category && <Badge tone="neutral">{entry.category}</Badge>}
                      {entry.is_suspicious && <Badge tone="danger">معلَّم</Badge>}
                    </p>
                  </div>
                  <div className="shrink-0 text-end text-xs text-slate-400">
                    <div>{formatDateTime(entry.created_at)}</div>
                    <div>{relativeDays(entry.created_at)}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* Quick links to the tasks only an admin can do. */}
      <Card>
        <CardHeader title="أدوات إدارية" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <AdminLink
            to="/users"
            title="إدارة المستخدمين"
            description="إنشاء الحسابات، إعادة تعيين كلمات المرور، إيقاف الوصول"
          />
          <AdminLink
            to="/rubrics"
            title="قوالب سلم التقييم"
            description="نشر إصدارات جديدة للدفعات القادمة"
          />
          <AdminLink
            to="/archive"
            title="أرشيف المشاريع"
            description="تصفح وإتاحة المشاريع السابقة"
          />
          <AdminLink
            to="/audit"
            title="مسار التدقيق"
            description="سجل ثابت لكل تغيير"
          />
        </div>
      </Card>
    </div>
  )
}

function AdminLink({ to, title, description }) {
  return (
    <Link
      to={to}
      className="rounded-lg border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/40"
    >
      <p className="font-medium text-slate-800">{title}</p>
      <p className="mt-1 text-sm text-slate-500">{description}</p>
    </Link>
  )
}

function SeverityDot({ severity }) {
  const tone = {
    info: 'bg-slate-300',
    warning: 'bg-amber-400',
    critical: 'bg-rose-500',
  }[severity] ?? 'bg-slate-300'

  return <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${tone}`} aria-hidden="true" />
}