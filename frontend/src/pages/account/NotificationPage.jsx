import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { notificationApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Select,
} from '../../components/ui'
import { formatDateTime, relativeDays } from '../../lib/format'

/**
 * In-app notifications (Module 6).
 *
 * Notifications are grouped by read state rather than by type, because the
 * only decision a reader makes here is "is there something I still need to
 * deal with". Each row links straight to whatever it is about, so the list
 * works as a to-do queue rather than just a log.
 */
export default function NotificationPage() {
  const [items, setItems] = useState([])
  const [meta, setMeta] = useState(null)
  const [summary, setSummary] = useState(null)
  const [filter, setFilter] = useState('unread')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [listRes, summaryRes] = await Promise.allSettled([
        notificationApi.list({
          // 'unread' is a read-state filter; 'all' is no filter at all; any
          // other value is a notification type.
          unread_only: filter === 'unread' ? true : undefined,
          type: filter === 'unread' || filter === 'all' ? undefined : filter,
          per_page: 50,
        }),
        notificationApi.summary(),
      ])

      if (listRes.status === 'rejected') throw listRes.reason
      const { items: rows, meta: pageMeta } = unwrapPaged(listRes.value)
      setItems(rows)
      setMeta(pageMeta)
      setSummary(summaryRes.status === 'fulfilled' ? unwrap(summaryRes.value) : null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [filter])

  useEffect(() => {
    load()
  }, [load])

  async function markRead(item) {
    if (item.read_at) return
    try {
      await notificationApi.markRead(item.id)
      // Optimistic: flip the row locally, then reconcile.
      setItems((prev) =>
        prev.map((n) => (n.id === item.id ? { ...n, read_at: new Date().toISOString() } : n))
      )
      setSummary((prev) =>
        prev && prev.unread_count > 0 ? { ...prev, unread_count: prev.unread_count - 1 } : prev
      )
    } catch {
      await load()
    }
  }

  async function markAllRead() {
    setBusy(true)
    try {
      await notificationApi.markAllRead()
      await load()
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Spinner label="Loading notifications" />
  if (error) return <ErrorState error={error} />

  const unreadCount = summary?.unread_count ?? items.filter((i) => !i.read_at).length

  return (
    <div className="space-y-6">
      <PageHeader
        title="Notifications"
        subtitle={
          unreadCount > 0
            ? `${unreadCount} unread`
            : 'You are up to date'
        }
        actions={
          unreadCount > 0 ? (
            <Button variant="secondary" disabled={busy} onClick={markAllRead}>
              {busy ? 'Marking…' : 'Mark all as read'}
            </Button>
          ) : null
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <Select
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            aria-label="Filter notifications"
          >
            <option value="unread">Unread</option>
            <option value="all">All</option>
            <option value="deadline_reminder">Deadline reminders</option>
            <option value="status_change">Status changes</option>
            <option value="submission_received">Submissions</option>
            <option value="evaluation_assigned">Assessments</option>
            <option value="grade_released">Grades</option>
          </Select>
          {meta && (
            <span className="text-sm text-slate-500">
              {meta.total} notification{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>
      </Card>

      {items.length === 0 ? (
        <Card>
          <EmptyState
            title={filter === 'unread' ? 'Nothing unread' : 'No notifications'}
            message={
              filter === 'unread'
                ? 'You have read everything. Switch to "All" to review previous notifications.'
                : 'Notifications about deadlines, submissions and grades will appear here.'
            }
          />
        </Card>
      ) : (
        <Card className="p-0">
          <ul className="divide-y divide-slate-100">
            {items.map((item) => (
              <NotificationRow key={item.id} item={item} onRead={markRead} />
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function NotificationRow({ item, onRead }) {
  const unread = !item.read_at
  const target = linkFor(item)

  const body = (
    <div className={`flex gap-3 px-4 py-4 transition ${unread ? 'bg-brand-50/40' : ''}`}>
      <span
        className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${
          unread ? 'bg-brand-500' : 'bg-transparent'
        }`}
        aria-hidden="true"
      />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className={`text-sm ${unread ? 'font-medium text-slate-900' : 'text-slate-700'}`}>
            {item.title ?? item.subject ?? typeLabel(item.type)}
          </p>
          <Badge tone={toneFor(item.type)}>{typeLabel(item.type)}</Badge>
        </div>
        {item.body || item.message ? (
          <p className="mt-1 text-sm text-slate-600">{item.body ?? item.message}</p>
        ) : null}
        <p className="mt-1 text-xs text-slate-400">
          {formatDateTime(item.created_at)}
          {' · '}
          {relativeDays(item.created_at)}
        </p>
      </div>
      {unread && (
        <span className="shrink-0 self-center text-xs text-slate-400">new</span>
      )}
    </div>
  )

  if (target) {
    return (
      <li>
        <Link to={target} className="block hover:bg-slate-50" onClick={() => onRead(item)}>
          {body}
        </Link>
      </li>
    )
  }

  // No linked subject — the row itself is the only affordance, so it is
  // exposed as a button and made keyboard-operable.
  return (
    <li>
      <button type="button" className="block w-full text-left" onClick={() => onRead(item)}>
        {body}
      </button>
    </li>
  )
}

/**
 * Where a notification should send you. The API carries the subject, so this
 * maps a type to the route that can display it.
 */
function linkFor(item) {
  const id = item.related_id ?? item.subject_id
  if (!id) return null

  switch (item.type) {
    case 'submission_received':
    case 'deadline_reminder':
    case 'status_change':
      return `/milestones/${id}`
    case 'evaluation_assigned':
      return `/evaluations/${id}`
    case 'grade_released':
      return `/projects/${id}`
    default:
      return null
  }
}

function typeLabel(type) {
  const labels = {
    deadline_reminder: 'deadline',
    status_change: 'status',
    submission_received: 'submission',
    evaluation_assigned: 'assessment',
    grade_released: 'grade',
    system: 'system',
  }
  return labels[type] ?? (type ?? 'notice').replace(/_/g, ' ')
}

function toneFor(type) {
  const tones = {
    deadline_reminder: 'warning',
    status_change: 'info',
    submission_received: 'brand',
    evaluation_assigned: 'brand',
    grade_released: 'success',
    system: 'neutral',
  }
  return tones[type] ?? 'neutral'
}
