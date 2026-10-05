import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { registrationApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Field, Input, Textarea, Select, Button,
  Badge, Spinner, ErrorState,
} from '../../components/ui'

const STATUS = {
  pending_supervisor: { label: 'Awaiting supervisor', tone: 'amber' },
  approved:           { label: 'Acknowledged',        tone: 'emerald' },
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
 *   supervisor → acknowledge Part C (pick the agreed title)
 *   student    → file Lampiran B, which creates the project
 *
 * **The title is not decided here.** The panel rules on it at the project's
 * proposal milestone, which is what gates the rest of the milestone chain. This
 * page used to carry the panel's review, then a coordinator approval before
 * that; both are gone, so all this screen does is the paperwork.
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
  const [proposal, setProposal] = useState({
    project_title: '',
    project_type: 'Pembangunan',
    field_study: FIELD_STUDY[0],
    project_origin: 'Idea saya sendiri',
    req_software: '',
    req_hardware: '',
    req_tech: '',
  })

  function seed(data) {
    setAgreement(data)
    setAgreedTitle((prev) => prev || data?.agreed_title || data?.proposed_title_1 || '')
    // Lampiran B registers the title the supervisor agreed, so the field is
    // seeded with it rather than left for the student to retype — a mismatch is
    // refused.
    setProposal((prev) => ({
      ...prev,
      project_title: prev.project_title || data?.agreed_title || data?.proposed_title_1 || '',
    }))
  }

  async function reload() {
    const res = await registrationApi.agreement(id)
    const data = unwrap(res)
    seed(data)
    return data
  }

  useEffect(() => {
    let cancelled = false
    async function load() {
      try {
        const res = await registrationApi.agreement(id)
        if (!cancelled) seed(unwrap(res))
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
  const canSubmitB = hasRole('student') && (agreement.can_submit_b ?? false)

  const update = (key) => (event) =>
    setProposal((prev) => ({ ...prev, [key]: event.target.value }))

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Lampiran A — Supervisor agreement"
        subtitle={`${agreement.student?.name ?? 'Student'} · ${agreement.session}`}
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
              {agreement.student?.name ?? '—'}{' '}
              <span className="text-slate-400">({agreement.student?.student_id})</span>
            </dd>
          </div>
          <div>
            <dt className="text-slate-400">Supervisor</dt>
            <dd className="text-slate-700">{agreement.supervisor?.name ?? '—'}</dd>
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
        </dl>
      </Card>

      {agreement.project && (
        <Card>
          <CardHeader
            title="Project registered"
            subtitle="The title is decided by the panel at the proposal milestone"
          />
          <p className="text-sm text-slate-700">
            {agreement.project.code} — {agreement.project.title}
          </p>
          <p className="mt-3 text-sm text-slate-500">
            File the proposal milestone and the panel will rule on the title there. Until it is
            approved, the rest of the milestones stay closed.
          </p>
          <div className="mt-4">
            <Link
              to={`/projects/${agreement.project.id}`}
              className="font-medium text-brand-600 hover:underline"
            >
              Open the project
            </Link>
          </div>
        </Card>
      )}

      {hasRole('student') && !canSubmitB && agreement.blocked_reason && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {agreement.blocked_reason}
        </p>
      )}

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

      {canSubmitB && (
        <Card>
          <CardHeader
            title="Lampiran B — Title proposal"
            subtitle="Register the agreed title and create your project"
          />
          <div className="space-y-5">
            <Field
              label="Project title"
              htmlFor="project_title"
              required
              hint="Agreed with your supervisor — must match it exactly"
            >
              <Input
                id="project_title"
                value={proposal.project_title}
                onChange={update('project_title')}
                maxLength={255}
                readOnly={Boolean(agreement.agreed_title)}
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
