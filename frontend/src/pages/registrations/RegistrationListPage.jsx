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
                {rows.map((row) => (
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
                      <Link
                        to={`/registrations/${row.id}`}
                        className="font-medium text-brand-600 hover:underline"
                      >
                        View
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
