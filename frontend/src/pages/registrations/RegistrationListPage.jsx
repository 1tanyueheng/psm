import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { registrationApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, PageHeader, Button, Badge, EmptyState, Spinner, ErrorState,
} from '../../components/ui'

/**
 * Registration list — Lampiran A agreements visible to the signed-in user.
 *
 * Students see their own; supervisors see the ones naming them; coordinators
 * and admins see everything. The server scopes the query, so this page only
 * decides what to render.
 */
const STATUS = {
  pending_supervisor: { label: 'Awaiting supervisor', tone: 'amber' },
  approved:           { label: 'Acknowledged',        tone: 'emerald' },
  rejected:           { label: 'Rejected',            tone: 'rose' },
  cancelled:          { label: 'Cancelled',           tone: 'slate' },
}

export default function RegistrationListPage() {
  const navigate = useNavigate()
  const { role } = useAuth()

  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      try {
        const res = await registrationApi.agreements()
        if (!cancelled) setRows(unwrap(res) ?? [])
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

  if (loading) return <Spinner label="Loading registrations" />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Registration"
        subtitle="Lampiran A — supervisor agreement, then Lampiran B — title proposal"
        action={
          role === 'student' ? (
            <Button onClick={() => navigate('/registrations/new')}>New Lampiran A</Button>
          ) : null
        }
      />

      {error && <ErrorState error={error} />}

      {rows.length === 0 ? (
        <EmptyState
          title="No registration forms yet"
          description={
            role === 'student'
              ? 'Start by submitting Lampiran A to name your supervisor.'
              : 'There is nothing to review at the moment.'
          }
          action={
            role === 'student' ? (
              <Button onClick={() => navigate('/registrations/new')}>Submit Lampiran A</Button>
            ) : null
          }
        />
      ) : (
        <Card padded={false}>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-3">Student</th>
                  <th className="px-4 py-3">Supervisor</th>
                  <th className="px-4 py-3">Session</th>
                  <th className="px-4 py-3">PSM</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  /**
                   * Whether this row is the signed-in supervisor's to act on.
                   *
                   * Both halves matter: the role says they *may* acknowledge, the
                   * status says this one still needs it. A supervisor who is also
                   * looking at agreements they already signed sees "View".
                   */
                  const awaitingMe = role === 'supervisor' && row.status === 'pending_supervisor'

                  return (
                    <tr key={row.id} className="border-t border-slate-100">
                    <td className="px-4 py-3">
                      <span className="font-medium text-slate-700">
                        {row.student?.name ?? '—'}
                      </span>
                      <span className="block text-xs text-slate-400">
                        {row.student?.student_id}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {row.supervisor?.name ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{row.session}</td>
                    <td className="px-4 py-3 text-slate-600">{row.psm_part}</td>
                    <td className="px-4 py-3">
                      <Badge tone={STATUS[row.status]?.tone}>
                        {STATUS[row.status]?.label ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {/*
                        The action is named, not just "View".

                        A supervisor's whole job on this screen is to acknowledge
                        Part C, and the row used to offer only "View" — so the
                        acknowledgement lived one undiscovered click away, behind
                        a word that says nothing about acting. The agreement is
                        not "sent for approval" in the student's mind and
                        "awaiting you" in the supervisor's unless the screen
                        says so.
                      */}
                      {awaitingMe ? (
                        <Link
                          to={`/registrations/${row.id}`}
                          className="rounded-md bg-brand-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-brand-700"
                        >
                          Acknowledge
                        </Link>
                      ) : (
                        <div className="flex items-center justify-end gap-3">
                          {/*
                            Withdraw, offered from the list as well as the detail
                            page.

                            A student who filed against the wrong supervisor
                            should be able to fix it from the screen they landed
                            on, without opening the form to find out whether the
                            option exists. Shown only when the server says it is
                            available — `can_withdraw` already combines "is it
                            yours" with "has a project been registered from it",
                            so this cannot offer an action that will be refused.

                            Deleting is not offered and never will be: the record
                            of what was submitted is the faculty's, not the
                            student's to erase.
                          */}
                          {row.can_withdraw && (
                            <Link
                              to={`/registrations/${row.id}`}
                              className="text-xs font-medium text-rose-600 hover:underline"
                            >
                              Withdraw
                            </Link>
                          )}
                          <Link
                            to={`/registrations/${row.id}`}
                            className="font-medium text-brand-600 hover:underline"
                          >
                            View
                          </Link>
                        </div>
                      )}
                    </td>
                  </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
