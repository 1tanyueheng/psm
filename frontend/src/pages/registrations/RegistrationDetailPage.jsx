import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { registrationApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Field, Input, Textarea, Select, Button,
  Badge, Spinner, ErrorState,
} from '../../components/ui'

const STATUS = {
  pending_supervisor: { label: 'Awaiting supervisor', tone: 'amber' },
  pending_jkpsm:      { label: 'Awaiting JKPSM',      tone: 'sky' },
  approved:           { label: 'Approved',            tone: 'emerald' },
  rejected:           { label: 'Rejected',            tone: 'rose' },
  cancelled:          { label: 'Cancelled',           tone: 'slate' },
}

const FIELD_STUDY = [
  'Kejuruteraan perisian',
  'Keselamatan maklumat',
  'Teknologi web',
  'Pengkomputeran Multimedia',
  'Teknologi maklumat',
]

/**
 * Lampiran A detail — where each role acts on the agreement:
 *   supervisor  → acknowledge Part C (pick the agreed title)
 *   JKPSM/admin → approve (registers the pairing) or reject
 *   student     → once approved, submit Lampiran B (title proposal)
 */
export default function RegistrationDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { hasRole } = useAuth()

  const [agreement, setAgreement] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)

  const [agreedTitle, setAgreedTitle] = useState('')
  const [reason, setReason] = useState('')
  const [proposal, setProposal] = useState({
    project_title: '',
    project_type: 'Pembangunan',
    field_study: FIELD_STUDY[0],
    project_origin: 'Idea saya sendiri',
    req_software: '',
    req_hardware: '',
    req_tech: '',
  })

  async function reload() {
    const res = await registrationApi.agreement(id)
    const data = unwrap(res)
    setAgreement(data)
    setAgreedTitle((prev) => prev || data?.agreed_title || data?.proposed_title_1 || '')
    return data
  }

  useEffect(() => {
    let cancelled = false
    async function load() {
      try {
        const res = await registrationApi.agreement(id)
        if (!cancelled) {
          const data = unwrap(res)
          setAgreement(data)
          setAgreedTitle(data?.agreed_title || data?.proposed_title_1 || '')
        }
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
  }, [id])

  async function run(fn) {
    setBusy(true)
    setActionError(null)
    try {
      await fn()
      await reload()
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Spinner label="Loading agreement" />
  if (error) return <ErrorState error={error} />
  if (!agreement) return null

  const status = agreement.status
  const titles = [
    agreement.proposed_title_1,
    agreement.proposed_title_2,
    agreement.proposed_title_3,
  ].filter(Boolean)

  const canAcknowledge = hasRole('supervisor') && status === 'pending_supervisor'
  const canDecide = hasRole('admin', 'coordinator') && status === 'pending_jkpsm'
  const canPropose = hasRole('student') && status === 'approved'

  const update = (key) => (event) =>
    setProposal((prev) => ({ ...prev, [key]: event.target.value }))

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Lampiran A — Supervisor agreement"
        subtitle={`${agreement.student_profile?.user?.name ?? 'Student'} · ${agreement.session}`}
        back={{ to: '/registrations', label: 'Back to registration' }}
        action={<Badge tone={STATUS[status]?.tone}>{STATUS[status]?.label ?? status}</Badge>}
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      <Card>
        <CardHeader title="Agreement details" />
        <dl className="grid gap-4 sm:grid-cols-2 text-sm">
          <div>
            <dt className="text-slate-400">Student</dt>
            <dd className="text-slate-700">
              {agreement.student_profile?.user?.name ?? '—'}{' '}
              <span className="text-slate-400">({agreement.student_profile?.student_id})</span>
            </dd>
          </div>
          <div>
            <dt className="text-slate-400">Supervisor</dt>
            <dd className="text-slate-700">{agreement.supervisor_profile?.user?.name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-slate-400">PSM part</dt>
            <dd className="text-slate-700">{agreement.psm_part}</dd>
          </div>
          <div>
            <dt className="text-slate-400">Report language</dt>
            <dd className="text-slate-700">{agreement.english_report ? 'English' : 'Bahasa Melayu'}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-slate-400">Proposed titles</dt>
            <dd className="text-slate-700">
              <ol className="list-decimal pl-5">
                {titles.map((t) => (
                  <li key={t}>{t}</li>
                ))}
              </ol>
            </dd>
          </div>
          {agreement.agreed_title && (
            <div className="sm:col-span-2">
              <dt className="text-slate-400">Agreed title</dt>
              <dd className="font-medium text-slate-800">{agreement.agreed_title}</dd>
            </div>
          )}
          {agreement.rejection_reason && (
            <div className="sm:col-span-2">
              <dt className="text-slate-400">Rejection reason</dt>
              <dd className="text-rose-700">{agreement.rejection_reason}</dd>
            </div>
          )}
        </dl>
      </Card>

      {canAcknowledge && (
        <Card>
          <CardHeader
            title="Part C — Acknowledge"
            subtitle="Confirm you agree to supervise this student and pick the agreed title"
          />
          <div className="space-y-5">
            <Field label="Agreed title" htmlFor="agreed_title" required>
              <Select
                id="agreed_title"
                value={agreedTitle}
                onChange={(e) => setAgreedTitle(e.target.value)}
              >
                {titles.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </Select>
            </Field>
            <div className="flex justify-end">
              <Button
                disabled={busy}
                onClick={() => run(() => registrationApi.acknowledge(id, agreedTitle))}
              >
                {busy ? 'Saving…' : 'Acknowledge'}
              </Button>
            </div>
          </div>
        </Card>
      )}

      {canDecide && (
        <Card>
          <CardHeader
            title="Part D — JKPSM decision"
            subtitle="Approving registers the supervisor↔student pairing"
          />
          <div className="space-y-5">
            <div className="flex flex-wrap gap-2">
              <Button disabled={busy} onClick={() => run(() => registrationApi.approve(id))}>
                {busy ? 'Working…' : 'Approve & register pairing'}
              </Button>
            </div>
            <Field label="Rejection reason" htmlFor="reason" hint="Required only when rejecting">
              <Textarea id="reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
            <div className="flex justify-end">
              <Button
                variant="secondary"
                disabled={busy || !reason.trim()}
                onClick={() => run(() => registrationApi.reject(id, reason.trim()))}
              >
                Reject
              </Button>
            </div>
          </div>
        </Card>
      )}

      {canPropose && (
        <Card>
          <CardHeader
            title="Lampiran B — Title proposal"
            subtitle="Submit the project details to create your project record"
          />
          <div className="space-y-5">
            <Field label="Project title" htmlFor="project_title" required>
              <Input
                id="project_title"
                value={proposal.project_title}
                onChange={update('project_title')}
                maxLength={255}
              />
            </Field>

            <div className="grid gap-5 sm:grid-cols-2">
              <Field label="Project type" htmlFor="project_type" required>
                <Select id="project_type" value={proposal.project_type} onChange={update('project_type')}>
                  <option value="Pembangunan">Pembangunan (Development)</option>
                  <option value="Kajian">Kajian (Research)</option>
                </Select>
              </Field>
              <Field label="Field of study" htmlFor="field_study">
                <Select id="field_study" value={proposal.field_study} onChange={update('field_study')}>
                  {FIELD_STUDY.map((f) => (
                    <option key={f} value={f}>
                      {f}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            <Field label="Project origin" htmlFor="project_origin">
              <Select id="project_origin" value={proposal.project_origin} onChange={update('project_origin')}>
                <option value="Idea saya sendiri">Idea saya sendiri (My own idea)</option>
                <option value="Dicadangkan oleh penyelia saya">
                  Dicadangkan oleh penyelia saya (Suggested by supervisor)
                </option>
              </Select>
            </Field>

            <Field label="Software required" htmlFor="req_software">
              <Textarea id="req_software" rows={2} value={proposal.req_software} onChange={update('req_software')} />
            </Field>
            <Field label="Hardware required" htmlFor="req_hardware">
              <Textarea id="req_hardware" rows={2} value={proposal.req_hardware} onChange={update('req_hardware')} />
            </Field>
            <Field label="Technology / technique / method / algorithm" htmlFor="req_tech">
              <Textarea id="req_tech" rows={2} value={proposal.req_tech} onChange={update('req_tech')} />
            </Field>

            <div className="flex justify-end">
              <Button
                disabled={busy || !proposal.project_title.trim()}
                onClick={() =>
                  run(async () => {
                    const res = await registrationApi.submitTitleProposal(id, {
                      project_title: proposal.project_title.trim(),
                      project_type: proposal.project_type,
                      field_study: proposal.field_study,
                      project_origin: proposal.project_origin,
                      req_software: proposal.req_software.trim() || undefined,
                      req_hardware: proposal.req_hardware.trim() || undefined,
                      req_tech: proposal.req_tech.trim() || undefined,
                    })
                    const project = unwrap(res)
                    if (project?.id) navigate(`/projects/${project.id}`)
                  })
                }
              >
                {busy ? 'Submitting…' : 'Submit Lampiran B'}
              </Button>
            </div>
          </div>
        </Card>
      )}
    </div>
  )
}
