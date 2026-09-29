import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { projectApi } from '../../api/endpoints'
import { unwrap } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Field, Input, Textarea, Select, Button,
  ErrorState, Spinner,
} from '../../components/ui'
import { CATEGORY_LABELS } from '../../lib/format'

/**
 * Project registration.
 *
 * Module 3 requires the student to declare a title, abstract and category at
 * registration, because the category decides which milestone template is
 * instantiated later. That makes the category field a real decision, so the
 * form explains the consequence rather than presenting four bare options.
 */
export default function ProjectRegisterPage() {
  const navigate = useNavigate()

  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState(null)
  const [formError, setFormError] = useState(null)

  const [form, setForm] = useState({
    title: '',
    abstract: '',
    category: '',
    psm_part: 'PSM1',
    academic_session: '',
    keywords: '',
    preferred_supervisor_id: '',
  })

  useEffect(() => {
    let cancelled = false

    async function load() {
      try {
        // Registering needs to know which session is current and which
        // supervisors are taking students — both come from the same call.
        const res = await projectApi.registerMeta()
        if (!cancelled) {
          const data = unwrap(res) ?? {}
          setMeta(data)
          setForm((prev) => ({
            ...prev,
            academic_session: prev.academic_session || data.academic_session || '',
          }))
        }
      } catch {
        // Non-fatal: the coordinator sets the session server-side anyway.
        if (!cancelled) setMeta({})
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
    const value = event.target.value
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors(null)
    setFormError(null)

    const payload = {
      title: form.title.trim(),
      abstract: form.abstract.trim(),
      category: form.category,
      psm_part: form.psm_part,
      academic_session: form.academic_session.trim() || undefined,
      keywords: form.keywords
        .split(',')
        .map((k) => k.trim())
        .filter(Boolean),
      preferred_supervisor_id: form.preferred_supervisor_id || undefined,
    }

    try {
      const res = await projectApi.create(payload)
      const created = unwrap(res)
      navigate(`/projects/${created?.id ?? ''}`, { replace: true })
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setFormError(err?.message ?? 'Could not register the project.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Spinner label="Preparing the registration form" />

  const categories = meta?.categories?.length
    ? meta.categories.map((c) => (typeof c === 'string' ? { value: c, label: CATEGORY_LABELS[c] ?? c } : c))
    : Object.entries(CATEGORY_LABELS).map(([value, label]) => ({ value, label }))

  const supervisors = meta?.supervisors ?? []

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Register your PSM project"
        subtitle="Your coordinator reviews this before milestones are generated"
        back={{ to: '/projects', label: 'Back to projects' }}
      />

      {formError && <ErrorState error={{ message: formError }} />}

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <CardHeader title="Project details" subtitle="What you intend to build or investigate" />

          <div className="space-y-5">
            <Field
              label="Project title"
              htmlFor="title"
              required
              hint="Aim for a specific, descriptive title — it appears in the archive and on the Pixel-It board."
              error={errors?.title}
            >
              <Input
                id="title"
                value={form.title}
                onChange={update('title')}
                required
                minLength={10}
                maxLength={255}
                placeholder="e.g. A Web-Based Clinical Appointment Scheduler for Rural Clinics"
              />
            </Field>

            <Field
              label="Abstract"
              htmlFor="abstract"
              required
              hint="150–300 words. Describe the problem, your intended approach, and the expected outcome."
              error={errors?.abstract}
            >
              <Textarea
                id="abstract"
                rows={8}
                value={form.abstract}
                onChange={update('abstract')}
                required
                minLength={80}
                maxLength={4000}
                placeholder="State the problem you are addressing, why it matters, and how you plan to solve it…"
              />
              <p className="mt-1 text-right text-xs text-slate-400 tabular-nums">
                {form.abstract.length} characters
              </p>
            </Field>

            <Field
              label="Keywords"
              htmlFor="keywords"
              hint="Comma separated, up to 6. Used for archive search."
              error={errors?.keywords}
            >
              <Input
                id="keywords"
                value={form.keywords}
                onChange={update('keywords')}
                placeholder="web application, scheduling, healthcare"
              />
            </Field>
          </div>
        </Card>

        <Card>
          <CardHeader title="Classification" subtitle="Determines which milestone template you follow" />

          <div className="space-y-5">
            <Field
              label="Category"
              htmlFor="category"
              required
              hint={
                form.category
                  ? CATEGORY_HINTS[form.category]
                  : 'Choose carefully — this fixes the milestone set for the whole project.'
              }
              error={errors?.category}
            >
              <Select id="category" value={form.category} onChange={update('category')} required>
                <option value="">Select a category…</option>
                {categories.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label ?? c.value}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="grid gap-5 sm:grid-cols-2">
              <Field
                label="PSM part"
                htmlFor="psm_part"
                required
                hint="PSM1 runs in one semester; PSM2 continues it."
                error={errors?.psm_part}
              >
                <Select id="psm_part" value={form.psm_part} onChange={update('psm_part')} required>
                  <option value="PSM1">PSM1</option>
                  <option value="PSM2">PSM2</option>
                </Select>
              </Field>

              <Field
                label="Academic session"
                htmlFor="academic_session"
                error={errors?.academic_session}
              >
                <Input
                  id="academic_session"
                  value={form.academic_session}
                  onChange={update('academic_session')}
                  placeholder="2026/2027"
                />
              </Field>
            </div>

            {supervisors.length > 0 && (
              <Field
                label="Preferred supervisor"
                htmlFor="preferred_supervisor_id"
                hint="Optional. Your coordinator makes the final assignment."
                error={errors?.preferred_supervisor_id}
              >
                <Select
                  id="preferred_supervisor_id"
                  value={form.preferred_supervisor_id}
                  onChange={update('preferred_supervisor_id')}
                >
                  <option value="">No preference</option>
                  {supervisors.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                      {s.expertise?.length ? ` — ${s.expertise.slice(0, 2).join(', ')}` : ''}
                    </option>
                  ))}
                </Select>
              </Field>
            )}
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-500">
            You can edit these details while the project is still a draft.
          </p>
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/projects')}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Registering…' : 'Register project'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  )
}

/**
 * The category is not a label — it selects a whole milestone set, so the form
 * tells the student what they are signing up for.
 */
const CATEGORY_HINTS = {
  system:
    'A software artefact: requirements, design, implementation, testing, then a report. You will submit code archives at the implementation and testing milestones.',
  research:
    'An empirical study: literature review, methodology, data collection and analysis, then a report. Submissions are documents rather than code.',
}
