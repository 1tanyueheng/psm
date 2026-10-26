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
/**
 * Why a draft title is not yet acceptable, or `null` when it is.
 *
 * Mirrors `App\Rules\IsTitle` so the student is told beside the field rather
 * than after the round trip. Not a security boundary — the server validates
 * independently — so it says the first thing that is wrong, not all of them.
 *
 * Returns `null` for an empty value: "required" is the field's own business and
 * shouting at someone who has not typed yet is noise.
 */
function titleHint(value) {
  const title = String(value ?? '').trim()

  if (title === '') return null
  if (title.length < 10) return 'Too short — write the full title.'

  const words = title.split(/\s+/).filter(Boolean)
  if (words.length < 3) return 'Add more detail — say what the system does and how.'

  if (!/[A-Za-z]{3,}/.test(title)) return 'A title needs words, not just numbers or symbols.'

  return null
}

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
    setErrors(null)
    setFormError(null)

    /**
     * Stop a form that cannot succeed before it is sent.
     *
     * The same three checks the server makes, so an obviously bad title does not
     * cost a round trip and come back as a field error the student has to
     * re-read while the form scrolls back to the top.
     */
    const titleProblems = {}
    ;[1, 2, 3].forEach((n) => {
      const key = `proposed_title_${n}`
      const problem = titleHint(form[key])
      if (problem) titleProblems[key] = [problem]
    })

    if (Object.keys(titleProblems).length > 0) {
      setErrors(titleProblems)
      setFormError('Check the proposed titles below.')
      return
    }

    setSubmitting(true)

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
            {/*
              Fixed to PSM 1, shown rather than chosen.

              PSM 2 is not registered by a student — it is inherited. PSM 1 and
              PSM 2 are one project across two continuous terms on one title, and
              the move into PSM 2 happens through the PSM 2 Rollover screen,
              which carries the title, supervisor and panel forward. Offering
              PSM 2 here invited a second Lampiran A for a part that has no
              registration step.
            */}
            <Field
              label="PSM part"
              hint="Lampiran A registers PSM 1. PSM 2 continues this project and is carried over, not registered again."
            >
              <Input value="PSM 1" readOnly disabled />
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
            {/*
              Each title is checked here as well as on the server.

              The rule mirrors `App\Rules\IsTitle` — at least 10 characters, at
              least three words, and something alphabetic — and its only purpose
              is to say so beside the field while the student is typing. Without
              it a student learns the requirement only after submitting, and the
              cheapest way past a rejected form is to type "1", "2", "3", which
              is how the demo data ended up with a Lampiran A that had no titles.
            */}
            {[1, 2, 3].map((n) => {
              const key = `proposed_title_${n}`
              const value = form[key]
              const hint = titleHint(value)

              return (
                <Field
                  key={key}
                  label={`Title ${n}`}
                  htmlFor={key}
                  required
                  error={errors?.[key]}
                  hint={
                    value && hint
                      ? hint
                      : 'A full title — what the system does and how. At least 10 characters, three words.'
                  }
                >
                  <Input
                    id={key}
                    value={value}
                    onChange={update(key)}
                    required
                    maxLength={255}
                    placeholder="e.g. Automated Attendance Tracking Using Face Recognition"
                  />
                </Field>
              )
            })}

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
