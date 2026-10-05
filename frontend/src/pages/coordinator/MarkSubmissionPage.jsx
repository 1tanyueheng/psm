import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { markSubmissionApi, projectApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Badge, Button, EmptyState, Spinner, ErrorState,
  DataTable, Td,
} from '../../components/ui'
import { formatDateTime, formatMark } from '../../lib/format'

/**
 * Coordinator: mark submission page for one student on one project.
 *
 * - Opens the submission (creates or fetches) on mount.
 * - Shows the readiness checklist (supervisor + panel forms).
 * - Lock button enabled only when `ready === true`.
 * - Unlock button with mandatory reason when status = Locked.
 */
export default function MarkSubmissionPage() {
  const { project, student } = useParams()
  const navigate = useNavigate()

  const [submission, setSubmission] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [actionLoading, setActionLoading] = useState(false)
  const [unlockReason, setUnlockReason] = useState('')
  const [showUnlockConfirm, setShowUnlockConfirm] = useState(false)

  const fetchSubmission = async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await markSubmissionApi.show(project, student)
      setSubmission(data)
    } catch (e) {
      setError(e.message || 'Failed to load mark submission')
    } finally {
      setLoading(false)
    }
  }

  const handleOpen = async () => {
    setActionLoading(true)
    try {
      await markSubmissionApi.open(project, student)
      await fetchSubmission()
    } catch (e) {
      setError(e.message || 'Failed to open mark submission')
    } finally {
      setActionLoading(false)
    }
  }

  const handleLock = async () => {
    setActionLoading(true)
    try {
      await markSubmissionApi.lock(project, student)
      await fetchSubmission()
    } catch (e) {
      setError(e.message || 'Failed to lock mark submission')
    } finally {
      setActionLoading(false)
    }
  }

  const handleUnlock = async () => {
    if (!unlockReason.trim()) return
    setActionLoading(true)
    try {
      await markSubmissionApi.unlock(project, student, unlockReason)
      setUnlockReason('')
      setShowUnlockConfirm(false)
      await fetchSubmission()
    } catch (e) {
      setError(e.message || 'Failed to unlock mark submission')
    } finally {
      setActionLoading(false)
    }
  }

  useEffect(() => {
    fetchSubmission()
  }, [project, student])

  if (loading) {
    return (
      <PageHeader title="Mark Submission">
        <Spinner size="lg" />
      </PageHeader>
    )
  }

  if (error && !submission) {
    return (
      <PageHeader title="Mark Submission">
        <ErrorState message={error} onRetry={fetchSubmission} />
      </PageHeader>
    )
  }

  if (!submission) {
    return (
      <PageHeader title="Mark Submission">
        <EmptyState
          title="No mark submission yet"
          description="Click 'Open Mark Submission' to allocate forms and start the marking window."
          action={<Button onClick={handleOpen} loading={actionLoading}>Open Mark Submission</Button>}
        />
      </PageHeader>
    )
  }

  const statusColors = {
    open: 'blue',
    ready: 'green',
    locked: 'purple',
    released: 'slate',
  }

  const statusLabels = {
    open: 'Open',
    ready: 'Ready to Lock',
    locked: 'Locked',
    released: 'Released',
  }

  const currentStatus = submission.status?.toLowerCase()

  return (
    <div className="space-y-6">
      <PageHeader
        title="Mark Submission"
        subtitle={`${submission.project?.code} — ${submission.student?.user?.name} (${submission.psm_part})`}
        actions={
          <div className="flex flex-wrap gap-2">
            {currentStatus === 'open' && (
              <Button
                variant="primary"
                onClick={handleLock}
                loading={actionLoading}
                disabled={!submission.readiness?.ready}
              >
                Lock Submission
              </Button>
            )}
            {currentStatus === 'locked' && (
              <Button variant="outline" onClick={() => setShowUnlockConfirm(true)}>
                Unlock
              </Button>
            )}
            {currentStatus === 'open' && submission.readiness && !submission.readiness.ready && (
              <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">
                Not ready to lock: {submission.readiness.outstanding?.length} form(s) outstanding.
              </div>
            )}
          </div>
        }
      >
        <Badge tone={statusColors[currentStatus] || 'neutral'}>
          {statusLabels[currentStatus] || submission.status}
        </Badge>
      </PageHeader>

<Card>
              <CardHeader>Readiness Checklist</CardHeader>
              {submission.readiness && (
                <div className="space-y-4">
                  <div className="flex flex-wrap gap-4 text-sm text-muted">
                    <div>Expected panel: <strong>{submission.readiness.expected_panel_size}</strong></div>
                    <div>Returned: <strong>{submission.readiness.returned_panel_size}</strong></div>
                    <div>Supervisor: <strong>{submission.readiness.supervisor_submitted ? 'Submitted' : 'Pending'}</strong></div>
                  </div>

                  {submission.readiness.outstanding?.length > 0 && (
                    <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                      <strong>Outstanding:</strong>
                      <ul className="mt-2 mb-0 list-disc list-inside space-y-1">
                        {submission.readiness.outstanding.map((item, i) => (
                          <li key={i}>
                            {item.role === 'supervisor' ? 'Supervisor' : `Examiner (${item.assessor || 'unassigned'})`} — {item.reason}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}

                  <DataTable
                    columns={['Form', 'Assessor', 'Type', 'Status', 'Score', 'Submitted']}
                    rows={submission.readiness.forms ?? []}
                    keyField="evaluation_id"
                    render={(form) => (
                      <tr>
                        <Td>{form.form_code}</Td>
                        <Td>{form.assessor_name}</Td>
                        <Td>
                          <Badge tone={form.assessor_type === 'supervisor' ? 'blue' : 'green'}>
                            {form.assessor_label}
                          </Badge>
                        </Td>
                        <Td>
                          <Badge tone={
                            form.status === 'submitted' || form.status === 'released' ? 'green' :
                            form.status === 'draft' ? 'amber' : 'neutral'
                          }>
                            {form.status}
                          </Badge>
                        </Td>
                        <Td>
                          {/* The raw mark out of the form's own total. The old
                              expression divided a 0-100 percentage by the raw
                              max, so a full-marks form read as "2.4%". */}
                          {form.raw_score !== null && form.max_score !== null
                            ? `${formatMark(form.raw_score)} / ${formatMark(form.max_score)}`
                            : '—'}
                        </Td>
                        <Td>{form.submitted_at ? formatDateTime(form.submitted_at) : '—'}</Td>
                      </tr>
                    )}
                  />
                </div>
              )}
            </Card>

      {submission.forms && submission.forms.length > 0 && (
        <Card>
          <CardHeader>Allocated Forms</CardHeader>
          <DataTable
            columns={['Form Code', 'Assessor', 'Type', 'Status', 'Actions']}
            rows={submission.forms}
            keyField="id"
            render={(form) => (
              <tr>
                <Td>{form.form_code}</Td>
                <Td>{form.assessor?.name}</Td>
                <Td>
                  <Badge tone={form.assessor_type === 'supervisor' ? 'blue' : 'green'}>
                    {form.assessor_type}
                  </Badge>
                </Td>
                <Td>
                  <Badge tone={
                    form.status === 'submitted' || form.status === 'released' ? 'green' :
                    form.status === 'draft' ? 'amber' : 'neutral'
                  }>
                    {form.status}
                  </Badge>
                </Td>
                <Td>
                  <Link to={`/evaluations/${form.id}`}>
                    <Button variant="ghost" size="sm">Open</Button>
                  </Link>
                </Td>
              </tr>
            )}
          />
        </Card>
      )}

      {showUnlockConfirm && (
        <Card variant="bordered" className="border-warning">
          <CardHeader>Unlock Mark Submission</CardHeader>
          <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg mb-4">
            Unlocking will allow changes to submitted forms. This action is audited.
          </div>
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium mb-1">Reason (required)</label>
              <textarea
                className="w-full p-2 border rounded"
                rows={3}
                value={unlockReason}
                onChange={(e) => setUnlockReason(e.target.value)}
                placeholder="Explain why this submission needs to be unlocked..."
                required
              />
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="ghost" onClick={() => setShowUnlockConfirm(false)}>Cancel</Button>
              <Button variant="danger" onClick={handleUnlock} loading={actionLoading}>
                Confirm Unlock
              </Button>
            </div>
          </div>
        </Card>
      )}

      <div>
        <Link to={`/coordinator/projects/${project}`}>
          <Button variant="ghost">← Back to Project</Button>
        </Link>
      </div>
    </div>
  )
}