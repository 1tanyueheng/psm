import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { auditApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import { can } from '../../lib/permissions'
import {
  Card, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Input, Select, Avatar, DataTable, Td,
} from '../../components/ui'
import { formatDateTime, relativeDays } from '../../lib/format'

/**
 * Audit log (Module 7).
 *
 * The audit trail is append-only and answers "who changed what, when, and from
 * what value to what value". Two details matter for it to be usable:
 *
 * 1. Diffs are shown inline, because "edited marks" is useless without the
 *    before/after values.
 * 2. Suspicious events are surfaced with a filter rather than buried, since
 *    that is the one thing an admin actually needs to catch.
 */
export default function AuditLogPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [actionOptions, setActionOptions] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [expanded, setExpanded] = useState(null)

  const search = params.get('q') ?? ''
  const action = params.get('action') ?? ''
  const severity = params.get('severity') ?? ''
  const suspicious = params.get('suspicious') === '1'
  const page = Number(params.get('page') ?? 1)

  const canView = can(user?.role, 'viewAuditLog')

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

  // The action vocabulary comes from the enum, not from the rows on screen:
  // deriving it from the current page leaves the dropdown empty as soon as the
  // list is filtered down, and only ever offers the actions on this page.
  useEffect(() => {
    let cancelled = false

    auditApi
      .filters()
      .then((res) => {
        if (!cancelled) setActionOptions(unwrap(res)?.actions ?? {})
      })
      .catch(() => {
        // The table still renders; only the filter list stays short.
      })

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        const res = await auditApi.list({
          // `search` and `suspicious_only` are the names the endpoint reads.
          // `with_changes` asks for the field-level diff the detail panel
          // renders; without it the panel would open empty.
          search: search || undefined,
          action: action || undefined,
          severity: severity || undefined,
          suspicious_only: suspicious ? true : undefined,
          with_changes: 1,
          page,
          per_page: 25,
        })
        if (cancelled) return
        const { items, meta: pageMeta } = unwrapPaged(res)
        setRows(items)
        setMeta(pageMeta)
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
  }, [search, action, severity, suspicious, page])

  const actions = useMemo(
    () => Object.entries(actionOptions).sort(([a], [b]) => a.localeCompare(b)),
    [actionOptions]
  )

  if (!canView) {
    return (
      <div className="mx-auto max-w-2xl">
        <Card>
          <EmptyState
            title="Restricted"
            message="The audit log is only visible to coordinators and administrators."
          />
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Audit log"
        subtitle={
          meta
            ? `${meta.total} recorded event${meta.total === 1 ? '' : 's'} — append-only, never edited`
            : undefined
        }
      />

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Input
            type="search"
            placeholder="Search description or actor"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="Search audit log"
          />
          <Select
            value={action}
            onChange={(e) => setFilter('action', e.target.value)}
            aria-label="Filter by action"
          >
            <option value="">All actions</option>
            {actions.map(([value, label]) => (
              <option key={value} value={value}>
                {label ?? value.replace(/_/g, ' ')}
              </option>
            ))}
          </Select>
          <Select
            value={severity}
            onChange={(e) => setFilter('severity', e.target.value)}
            aria-label="Filter by severity"
          >
            <option value="">All severities</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="critical">Critical</option>
          </Select>
          <label className="flex items-center gap-2 self-center text-sm text-slate-700">
            <input
              type="checkbox"
              checked={suspicious}
              onChange={(e) => setFilter('suspicious', e.target.checked ? '1' : '')}
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            Only flagged events
          </label>
        </div>
      </Card>

      {loading ? (
        <Spinner label="Loading the audit trail" />
      ) : error ? (
        <ErrorState error={error} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No events"
            message="No audit entries match the current filters."
          />
        </Card>
      ) : (
        <>
          <Card className="overflow-hidden p-0">
            <div className="overflow-x-auto">
              <DataTable
                columns={['When', 'Actor', 'Action', 'Description', 'Severity', '']}
              >
                {rows.map((entry) => {
                  const isOpen = expanded === entry.id
                  // The API nests the actor, and only sends field diffs when
                  // `with_changes` was requested.
                  const changes = entry.changes ?? []
                  const hasDetail =
                    changes.length > 0 ||
                    entry.context?.ip_address != null ||
                    entry.subject != null

                  // A keyed Fragment is required here: the row and its detail
                  // panel are siblings in the same <tbody>.
                  return (
                    <Fragment key={entry.id}>
                      <tr
                        className={`hover:bg-slate-50/60 ${entry.is_suspicious ? 'bg-rose-50/40' : ''}`}
                      >
                        <Td>
                          <div className="text-sm text-slate-700">
                            {formatDateTime(entry.created_at)}
                          </div>
                          <div className="text-xs text-slate-400">
                            {relativeDays(entry.created_at)}
                          </div>
                        </Td>
                        <Td>
                          <div className="flex items-center gap-2">
                            <Avatar name={entry.actor?.name ?? 'System'} size="sm" />
                            <div className="min-w-0">
                              <div className="truncate text-sm text-slate-700">
                                {entry.actor?.name ?? 'System'}
                              </div>
                              {entry.actor?.role && (
                                <div className="text-xs text-slate-400">{entry.actor.role}</div>
                              )}
                            </div>
                          </div>
                        </Td>
                        <Td>
                          <span className="font-mono text-xs text-slate-600">
                            {entry.action?.replace(/_/g, ' ')}
                          </span>
                          {entry.category && (
                            <div className="mt-0.5">
                              <Badge tone="neutral">{entry.category}</Badge>
                            </div>
                          )}
                        </Td>
                        <Td className="max-w-md text-sm text-slate-700">{entry.description}</Td>
                        <Td>
                          <SeverityBadge severity={entry.severity} />
                          {entry.is_suspicious && (
                            <div className="mt-1">
                              <Badge tone="danger">flagged</Badge>
                            </div>
                          )}
                        </Td>
                        <Td>
                          {hasDetail && (
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => setExpanded(isOpen ? null : entry.id)}
                              aria-expanded={isOpen}
                            >
                              {isOpen ? 'Hide' : 'Details'}
                            </Button>
                          )}
                        </Td>
                      </tr>

                      {isOpen && (
                        <tr className="bg-slate-50/70">
                          <td colSpan={6} className="px-4 py-4">
                            <AuditDetail entry={entry} />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </DataTable>
            </div>
          </Card>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-slate-500">
                Showing {meta.from}–{meta.to} of {meta.total}
              </p>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setFilter('page', String(meta.current_page - 1))}
                >
                  Previous
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setFilter('page', String(meta.current_page + 1))}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

/**
 * The forensic detail. Showing before/after side by side is what turns an
 * audit row into evidence.
 *
 * The API nests the request metadata under `context` and the affected record
 * under `subject`, and returns diffs as a flat [{ field, from, to }] list
 * rather than two objects to compare.
 */
function AuditDetail({ entry }) {
  const changes = entry.changes ?? []
  const context = entry.context ?? {}

  return (
    <div className="space-y-4 text-sm">
      <div className="grid gap-4 sm:grid-cols-3">
        {context.ip_address && (
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">IP address</p>
            <p className="font-mono text-xs text-slate-700">{context.ip_address}</p>
          </div>
        )}
        {context.request_method && (
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Request</p>
            <p className="font-mono text-xs text-slate-700">
              {context.request_method} {context.request_url}
            </p>
          </div>
        )}
        {entry.subject && (
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Subject</p>
            <p className="font-mono text-xs text-slate-700">
              {entry.subject.type}#{entry.subject.id}
            </p>
          </div>
        )}
      </div>

      {changes.length > 0 && (
        <div>
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
            Changes
          </p>
          <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Field</th>
                  <th className="px-3 py-2 font-medium">Before</th>
                  <th className="px-3 py-2 font-medium">After</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {changes.map((change) => (
                  <tr key={change.field}>
                    <td className="px-3 py-2 font-mono text-xs text-slate-600">{change.field}</td>
                    <td className="px-3 py-2 text-rose-700">
                      <Value value={change.from} />
                    </td>
                    <td className="px-3 py-2 text-emerald-700">
                      <Value value={change.to} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {context.user_agent && (
        <p className="truncate text-xs text-slate-400">User agent: {context.user_agent}</p>
      )}
    </div>
  )
}

function Value({ value }) {
  if (value === undefined) return <span className="text-slate-300">—</span>
  if (value === null) return <span className="italic text-slate-400">null</span>
  if (typeof value === 'object') {
    return <span className="font-mono text-xs">{JSON.stringify(value)}</span>
  }
  return <span>{String(value)}</span>
}

function SeverityBadge({ severity }) {
  const tone = { info: 'neutral', warning: 'warning', critical: 'danger' }[severity] ?? 'neutral'
  return <Badge tone={tone}>{severity ?? 'info'}</Badge>
}
