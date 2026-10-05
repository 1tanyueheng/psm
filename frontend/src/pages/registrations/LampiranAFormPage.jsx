import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { registrationApi, projectApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Field, Input, Select, Button,
  ErrorState, Spinner,
} from '../../components/ui'

/**
 * Lampiran A — Borang Persetujuan Penyelia PSM (Supervisor Agreement).
 *
 * The student names the supervisor who has agreed to take them and proposes
 * up to three titles. Submitting sends the form to that supervisor to
 * acknowledge; only after JKPSM approves is the pairing registered.
 */
export default function LampiranAFormPage() {
  const navigate = useNavigate()

  const [supervisors, setSupervisors] = useState([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState(null)
  const [formError, setFormError] = useState(null)

  const [form, setForm] = useState({
    session: '',
    psm_part: 'PSM1',
    supervisor_profile_id: '',
    proposed_title_1: '',
    proposed_title_2: '',
    proposed_title_3: '',
    english_report: false,
  })

  useEffect(() => {
    let cancelled = false

    async function load() {
      try {
        // `/users/options` is admin/coordinator-only, so a student reads the
        // supervisor list from the project registration metadata instead.
        const res = await projectApi.registerMeta()
        const data = unwrap(res) ?? {}
        const list = data.supervisors ?? []
        if (!cancelled) setSupervisors(Array.isArray(list) ? list : [])
      } catch {
        if (!cancelled) setSupervisors([])
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [])

  const update = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors(null)
    setFormError(null)

    try {
      const res = await registrationApi.submitAgreement({
        session: form.session.trim(),
        psm_part: form.psm_part,
        supervisor_profile_id: Number(form.supervisor_profile_id),
        proposed_title_1: form.proposed_title_1.trim(),
        proposed_title_2: form.proposed_title_2.trim(),
        proposed_title_3: form.proposed_title_3.trim(),
        english_report: form.english_report,
      })
      const created = unwrap(res)
      navigate(`/registrations/${created?.id ?? ''}`, { replace: true })
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setFormError(err?.message ?? 'Could not submit Lampiran A.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Spinner label="Preparing Lampiran A" />

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Lampiran A — Supervisor agreement"
        subtitle="Part A & B: your details and proposed titles. Your supervisor signs Part C, then JKPSM approves."
        back={{ to: '/registrations', label: 'Back to registration' }}
      />

      {formError && <ErrorState error={{ message: formError }} />}

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <CardHeader title="Session & part" subtitle="Which semester and PSM part this covers" />
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Session / Semester" htmlFor="session" required error={errors?.session}>
              <Input
                id="session"
                value={form.session}
                onChange={update('session')}
                required
                placeholder="e.g. 2025/2026 Semester I"
              />
            </Field>
            <Field label="PSM part" htmlFor="psm_part" required error={errors?.psm_part}>
              <Select id="psm_part" value={form.psm_part} onChange={update('psm_part')} required>
                <option value="PSM1">PSM1</option>
                <option value="PSM2">PSM2</option>
                <option value="BOTH">BOTH</option>
              </Select>
            </Field>
          </div>
        </Card>

        <Card>
          <CardHeader
            title="Part C — supervisor"
            subtitle="The supervisor who has agreed to supervise you"
          />
          <Field
            label="Supervisor"
            htmlFor="supervisor_profile_id"
            required
            error={errors?.supervisor_profile_id}
          >
            <Select
              id="supervisor_profile_id"
              value={form.supervisor_profile_id}
              onChange={update('supervisor_profile_id')}
              required
            >
              <option value="">Select a supervisor…</option>
              {supervisors.map((s) => (
                <option key={s.value ?? s.id} value={s.value ?? s.id}>
                  {s.label ?? s.name}
                </option>
              ))}
            </Select>
          </Field>
        </Card>

        <Card>
          <CardHeader
            title="Part B — proposed titles"
            subtitle="Three candidate titles are required; your supervisor picks the agreed one"
          />
          <div className="space-y-5">
            <Field label="Title 1" htmlFor="proposed_title_1" required error={errors?.proposed_title_1}>
              <Input
                id="proposed_title_1"
                value={form.proposed_title_1}
                onChange={update('proposed_title_1')}
                required
                maxLength={255}
              />
            </Field>
            <Field label="Title 2" htmlFor="proposed_title_2" required error={errors?.proposed_title_2}>
              <Input
                id="proposed_title_2"
                value={form.proposed_title_2}
                onChange={update('proposed_title_2')}
                required
                maxLength={255}
              />
            </Field>
            <Field label="Title 3" htmlFor="proposed_title_3" required error={errors?.proposed_title_3}>
              <Input
                id="proposed_title_3"
                value={form.proposed_title_3}
                onChange={update('proposed_title_3')}
                required
                maxLength={255}
              />
            </Field>

            <label className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
              <input
                type="checkbox"
                checked={form.english_report}
                onChange={update('english_report')}
                className="mt-0.5"
              />
              <span>
                The project report will be written in English
                <span className="block text-xs text-slate-500">
                  Laporan projek akan ditulis dalam Bahasa Inggeris
                </span>
              </span>
            </label>
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-500">
            Submitting sends this to your supervisor to acknowledge.
          </p>
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/registrations')}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Submitting…' : 'Submit Lampiran A'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  )
}
