import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { userApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input,
} from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { roleLabel, roleTone } from '../../lib/permissions'

/**
 * Module 2 — user administration.
 *
 * The job of this screen is account lifecycle, not profile editing: an admin
 * arrives here to find an account, unblock it, or issue a password reset. The
 * actions are therefore ordered by how often they are needed and how safely
 * they can be undone.
 *
 * Two deliberate omissions:
 *
 *  - There is no delete button. `DELETE /users/{id}` soft-deletes, which in a
 *    system with an audit trail and archived projects is rarely the right
 *    answer. Deactivating is reversible; deleting hides a student's history.
 *    The API supports both, but offering only deactivate here is the safer
 *    default.
 *  - There is no password field. Reset emails are the only supported path —
 *    an admin choosing a user's password breaks the audit story, because the
 *    reset cannot be attributed to the account holder.
 */
export default function UserListPage() {
  const { user: me } = useAuth()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)

  const role = params.get('role') ?? ''
  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next, { replace: true })
  }

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await userApi.list({
        role: role || undefined,
        status: status || undefined,
        q: search || undefined,
        per_page: 100,
      })
      const { items, meta: pageMeta } = unwrapPaged(res)
      setRows(items)
      setMeta(pageMeta)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [role, status, search])

  useEffect(() => {
    load()
  }, [load])

  /**
   * Run one account action, then refresh.
   *
   * Every action here changes server state that other people depend on
   * (a suspended supervisor stops being assignable), so the list is reloaded
   * rather than patched locally — the response is the source of truth.
   */
  async function act(key, fn, successMessage) {
    setBusy(key)
    setActionError(null)
    setNotice(null)
    try {
      await fn()
      setNotice(successMessage)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'That action failed.')
    } finally {
      setBusy(null)
    }
  }

  const counts = useMemo(() => {
    const out = { total: meta?.total ?? rows.length, suspended: 0, pending: 0 }
    for (const row of rows) {
      if (row.is_active === false || row.status === 'suspended') out.suspended += 1
      if (row.status === 'pending' || row.must_change_password) out.pending += 1
    }
    return out
  }, [rows, meta])

  if (loading && rows.length === 0) return <Spinner label="Loading accounts" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="User accounts"
        subtitle={
          meta?.total != null ? `${meta.total} account${meta.total === 1 ? '' : 's'}` : undefined
        }
      />

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && <ErrorState error={{ message: actionError }} />}

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Accounts</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.total}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Suspended</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.suspended}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">Awaiting sign-in</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.pending}
          </p>
        </Card>
      </div>

      <Card>
        <CardHeader title="Filter" />
        <div className="grid gap-3 sm:grid-cols-3">
          <Input
            type="search"
            placeholder="Name, email or staff number"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="Search accounts"
          />
          <Select
            value={role}
            onChange={(e) => setFilter('role', e.target.value)}
            aria-label="Filter by role"
          >
            <option value="">All roles</option>
            <option value="student">Students</option>
            <option value="supervisor">Supervisors</option>
            <option value="coordinator">Coordinators</option>
            <option value="examiner">Examiners</option>
            <option value="admin">Administrators</option>
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Invited, not yet signed in</option>
          </Select>
        </div>
      </Card>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="No accounts match"
            description="Adjust the filters, or check that the accounts have been imported for this session."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={['Name', 'Role', 'Identifier', 'Status', 'Last signed in', 'Actions']}
            >
              {rows.map((row) => {
                const suspended = row.is_active === false || row.status === 'suspended'
                const isSelf = row.id === me?.id

                return (
                  <tr key={row.id} className="hover:bg-slate-50/60">
                    <Td>
                      <div className="flex items-center gap-2">
                        <Avatar name={row.name} size="sm" />
                        <div className="min-w-0">
                          <div className="truncate text-sm font-medium text-slate-800">
                            {row.name}
                            {isSelf && (
                              <span className="ml-1.5 text-xs font-normal text-slate-400">
                                you
                              </span>
                            )}
                          </div>
                          <div className="truncate text-xs text-slate-500">{row.email}</div>
                        </div>
                      </div>
                    </Td>
                    <Td>
                      <Badge tone={roleTone(row.role)}>{roleLabel(row.role)}</Badge>
                    </Td>
                    <Td className="font-mono text-xs text-slate-500">
                      {row.student_id ?? row.staff_no ?? '—'}
                    </Td>
                    <Td>
                      <Badge tone={suspended ? 'danger' : 'success'}>
                        {suspended ? 'suspended' : (row.status ?? 'active')}
                      </Badge>
                      {row.must_change_password && (
                        <div className="mt-0.5 text-xs text-amber-600">
                          must change password
                        </div>
                      )}
                    </Td>
                    <Td className="text-sm text-slate-600">
                      {row.last_login_at ? (
                        <>
                          <div>{formatDate(row.last_login_at)}</div>
                          <div className="text-xs text-slate-400">
                            {formatDateTime(row.last_login_at)}
                          </div>
                        </>
                      ) : (
                        <span className="text-slate-400">never</span>
                      )}
                    </Td>
                    <Td>
                      <div className="flex flex-wrap gap-1.5">
                        {/*
                          Deactivating yourself would lock you out of the very
                          screen you would use to undo it. The API permits it;
                          this UI declines to offer it.
                        */}
                        <Button
                          size="sm"
                          variant="secondary"
                          disabled={isSelf || busy === `toggle-${row.id}`}
                          onClick={() =>
                            act(
                              `toggle-${row.id}`,
                              () =>
                                suspended ? userApi.reactivate(row.id) : userApi.deactivate(row.id),
                              suspended
                                ? `${row.name} can sign in again.`
                                : `${row.name} can no longer sign in.`,
                            )
                          }
                        >
                          {busy === `toggle-${row.id}`
                            ? 'Working…'
                            : suspended
                              ? 'Reactivate'
                              : 'Suspend'}
                        </Button>

                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={busy === `reset-${row.id}`}
                          onClick={() =>
                            act(
                              `reset-${row.id}`,
                              () => userApi.sendPasswordReset(row.id),
                              `Password reset for ${row.name} will receive an email.`,
                            )
                          }
                        >
                          {busy === `reset-${row.id}` ? 'Sending…' : 'Reset password'}
                        </Button>

                        {row.locked_at && (
                          <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy === `unlock-${row.id}`}
                            onClick={() =>
                              act(
                                `unlock-${row.id}`,
                                () => userApi.unlock(row.id),
                                `${row.name} is unlocked.`,
                              )
                            }
                          >
                            Unlock
                          </Button>
                        )}
                      </div>
                    </Td>
                  </tr>
                )
              })}
            </DataTable>
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="What these actions do" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            <span className="font-medium text-slate-800">Suspend</span> blocks sign-in but keeps
            the account, its projects and its audit history intact. It is reversible.
          </li>
          <li>
            <span className="font-medium text-slate-800">Reset password</span> emails a
            single-use link to the account holder. No administrator ever sees or sets a password,
            so the change stays attributable.
          </li>
          <li>
            <span className="font-medium text-slate-800">Unlock</span> clears a lockout from
            repeated failed sign-ins. It appears only for accounts that are actually locked.
          </li>
        </ul>
        <p className="mt-3 text-xs text-slate-500">
          Accounts are never deleted from this screen. A student&rsquo;s record is needed by the
          archive and the audit trail long after they graduate.
        </p>
      </Card>
    </div>
  )
}
