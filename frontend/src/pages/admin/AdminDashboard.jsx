import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportApi, auditApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, EmptyState,
  Spinner, ErrorState, Button,
} from '../../components/ui'
import { formatDateTime, relativeDays } from '../../lib/format'

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

  if (loading) return <Spinner label="Loading system overview" />
  if (error) return <ErrorState error={error} />

  const s = stats ?? {}
  const roleCounts = s.users_by_role ?? {}

  return (
    <div className="space-y-6">
      <PageHeader
        title="System administration"
        subtitle="Accounts, access, and the audit trail"
        actions={
          <div className="flex gap-2">
            <Link to="/audit">
              <Button variant="secondary">Audit log</Button>
            </Link>
            <Link to="/leaderboards">
              <Button>Pixel-It awards</Button>
            </Link>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Active users" value={s.active_users ?? 0} hint="Not soft-deleted" />
        <StatCard
          label="Suspended"
          value={s.suspended_users ?? 0}
          hint="Blocked from signing in"
          tone={(s.suspended_users ?? 0) > 0 ? 'warning' : 'default'}
        />
        <StatCard
          label="Archived projects"
          value={s.archived_projects ?? 0}
          hint="Permanent record"
        />
        <StatCard
          label="Flagged events"
          value={suspicious}
          hint="Marked suspicious"
          tone={suspicious > 0 ? 'danger' : 'success'}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader title="Accounts by role" />
          <ul className="divide-y divide-slate-100">
            {Object.entries(roleCounts).length === 0 ? (
              <li className="py-3 text-sm text-slate-500">No data</li>
            ) : (
              Object.entries(roleCounts).map(([role, count]) => (
                <li key={role} className="flex items-center justify-between py-2.5">
                  <span className="text-sm capitalize text-slate-700">{role.replace(/_/g, ' ')}</span>
                  <span className="font-semibold tabular-nums text-slate-800">{count}</span>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader
            title="Recent privileged activity"
            subtitle="The most recent audited events across the system"
            action={
              <Link to="/audit">
                <Button size="sm" variant="ghost">View all</Button>
              </Link>
            }
          />
          {audit.length === 0 ? (
            <EmptyState title="No activity recorded" message="Audited events will appear here." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {audit.map((entry) => (
                <li key={entry.id} className="flex items-start gap-3 py-3">
                  <SeverityDot severity={entry.severity} />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-slate-800">{entry.description}</p>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                      <span className="font-medium">{entry.actor_name ?? 'system'}</span>
                      {entry.actor_role && <span>· {entry.actor_role}</span>}
                      {entry.category && <Badge tone="neutral">{entry.category}</Badge>}
                      {entry.is_suspicious && <Badge tone="danger">flagged</Badge>}
                    </p>
                  </div>
                  <div className="shrink-0 text-right text-xs text-slate-400">
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
        <CardHeader title="Administrative tools" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <AdminLink
            to="/users"
            title="Manage users"
            description="Create accounts, reset passwords, suspend access"
          />
          <AdminLink
            to="/rubrics"
            title="Rubric templates"
            description="Publish new versions for future cohorts"
          />
          <AdminLink
            to="/archive"
            title="Project archive"
            description="Browse and release historic projects"
          />
          <AdminLink
            to="/audit"
            title="Audit trail"
            description="Immutable record of every change"
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
