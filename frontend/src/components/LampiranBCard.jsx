import { useCallback, useEffect, useState } from 'react'
import { lampiranBApi } from '../api/endpoints'
import {
  Card, CardHeader, Badge, Field, Input, Select, Button, Textarea,
} from './ui'
import { formatDateTime } from '../lib/format'

/**
 * Lampiran B — the filings for a project, and the actions on them.
 *
 * Shown on the **proposal** milestone, because that is where the title is
 * decided and therefore where a refused title and the one replacing it belong
 * side by side. A separate comparison screen was offered and rejected: the panel
 * reading a proposal already has this page open, and asking them to open
 * something else to see what changed would mean most of them never would.
 *
 * The card has two faces:
 *
 *   - **the student's** — read why the previous title was refused, revise the
 *     pre-filled form, submit it for signature. The panel's words are pinned to
 *     the top of the form, because a student who cannot see the objection will
 *     submit the same title again.
 *   - **the supervisor's** — read the revised title and sign it, or send it back
 *     with a reason. Signing opens the proposal milestone; nothing else does.
 *
 * History is always shown, newest first, so a third filing still displays what
 * the first one was and why it failed.
 */
export default function LampiranBCard({ projectId, milestone, onDone }) {
  const [forms, setForms] = useState([])
  const [acceptedTitle, setAcceptedTitle] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)

  /** The form being edited by the student. */
  const [draft, setDraft] = useState(null)
  /** The form the supervisor is sending back, if any. */
  const [returning, setReturning] = useState(null)
  const [returnReason, setReturnReason] = useState('')

  const load = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true)
    setError(null)
    try {
      const data = await lampiranBApi.list(projectId)
      setForms(data?.forms ?? [])
      setAcceptedTitle(data?.accepted_title ?? null)
    } catch (err) {
      setError(err)
    } finally {
      if (!loading || !quiet) setLoading(false)
    }
  }, [projectId, loading])

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId])

  function edit(form) {
    setDraft({
      id: form.id,
      title: form.title,
      project_type: form.project_type ?? 'system',
      field_study: form.field_study ?? '',
      req_software: form.requirements?.software ?? '',
      req_hardware: form.requirements?.hardware ?? '',
      req_technology: form.requirements?.technology ?? '',
    })
    setActionError(null)
  }

  async function run(fn) {
    setBusy(true)
    setActionError(null)
    setNotice(null)
    try {
      const res = await fn()
      await load({ quiet: true })
      if (res?.message) setNotice(res.message)
      onDone?.()
      return res
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
      return null
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <Card>
        <CardHeader title="Lampiran B — Title proposals" />
        <p className="text-sm text-slate-500">Loading…</p>
      </Card>
    )
  }

  if (error) {
    return (
      <Card>
        <CardHeader title="Lampiran B — Title proposals" />
        <p className="text-sm text-rose-700">{error?.message ?? 'Could not load the filings.'}</p>
      </Card>
    )
  }

  // Only one filing and it is accepted: this project never had a title refused,
  // so there is nothing to manage here. The record itself is on the milestone.
  if (forms.length <= 1) return null

  const current = forms.find((f) => f.is_current)
  const refused = forms.filter((f) => f.is_rejected)

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title="Lampiran B — Title proposals"
          subtitle="A refused title is revised on a new form, then signed by your supervisor"
        />

        {notice && (
          <p className="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>
        )}
        {actionError && (
          <p className="mb-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{actionError}</p>
        )}

        {/* ---------------- the refusal, in the panel's words ------------- */}
        {refused.length > 0 && (
          <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
            <p className="font-medium">
              {refused.length === 1 ? 'The panel refused a title' : `The panel refused ${refused.length} titles`}
            </p>
            {refused.map((f) => (
              <p key={f.id} className="mt-1 text-xs">
                <span className="font-mono">{f.label}</span> — “{f.title}”
                {f.rejected_reason && (
                  <span className="mt-0.5 block italic">“{f.rejected_reason}”</span>
                )}
              </p>
            ))}
          </div>
        )}

        {/* ---------------- the student's form ---------------------------- */}
        {draft ? (
          <div className="space-y-3 rounded-md border border-brand-200 bg-brand-50/40 p-3">
            <p className="text-sm font-medium text-slate-700">
              Revise the title — this is a new Lampiran B, not an edit of the refused one
            </p>

            <Field
              label="Project title"
              required
              hint="It must differ from the title the panel refused, or they will simply refuse it again."
            >
              <Input
                value={draft.title}
                onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                maxLength={255}
              />
            </Field>

            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Project type" required>
                <Select
                  value={draft.project_type}
                  onChange={(e) => setDraft({ ...draft, project_type: e.target.value })}
                >
                  <option value="system">Pembangunan (Development)</option>
                  <option value="research">Kajian (Research)</option>
                </Select>
              </Field>

              <Field label="Field of study">
                <Input
                  value={draft.field_study}
                  onChange={(e) => setDraft({ ...draft, field_study: e.target.value })}
                  maxLength={255}
                />
              </Field>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Software">
                <Input
                  value={draft.req_software}
                  onChange={(e) => setDraft({ ...draft, req_software: e.target.value })}
                  maxLength={255}
                />
              </Field>
              <Field label="Hardware">
                <Input
                  value={draft.req_hardware}
                  onChange={(e) => setDraft({ ...draft, req_hardware: e.target.value })}
                  maxLength={255}
                />
              </Field>
              <Field label="Technology / method">
                <Input
                  value={draft.req_technology}
                  onChange={(e) => setDraft({ ...draft, req_technology: e.target.value })}
                  maxLength={255}
                />
              </Field>
            </div>

            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setDraft(null)} disabled={busy}>
                Cancel
              </Button>
              <Button
                disabled={busy || draft.title.trim().length < 10}
                onClick={() =>
                  run(async () => {
                    const res = await lampiranBApi.file(draft.id, {
                      title: draft.title.trim(),
                      project_type: draft.project_type,
                      field_study: draft.field_study.trim() || null,
                      req_software: draft.req_software.trim() || null,
                      req_hardware: draft.req_hardware.trim() || null,
                      req_technology: draft.req_technology.trim() || null,
                    })
                    setDraft(null)
                    return res
                  })
                }
              >
                {busy ? 'Submitting…' : 'Submit for signature'}
              </Button>
            </div>
          </div>
        ) : (
          current && (
            <CurrentFiling
              form={current}
              busy={busy}
              onEdit={() => edit(current)}
              onSign={() =>
                run(() => lampiranBApi.sign(current.id, { approve: true }))
              }
              onReturn={() => {
                setReturning(current.id)
                setReturnReason('')
                setActionError(null)
              }}
            />
          )
        )}

        {returning !== null && (
          <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
            <Field label="Why is it being sent back?" required hint="The student cannot act on a refusal with no reason.">
              <Textarea
                rows={2}
                value={returnReason}
                onChange={(e) => setReturnReason(e.target.value)}
                placeholder="e.g. the scope still covers three districts."
              />
            </Field>
            <div className="mt-2 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setReturning(null)} disabled={busy}>
                Cancel
              </Button>
              <Button
                variant="danger"
                disabled={busy || returnReason.trim().length < 10}
                onClick={() =>
                  run(async () => {
                    const res = await lampiranBApi.sign(returning, {
                      approve: false,
                      comment: returnReason.trim(),
                    })
                    setReturning(null)
                    return res
                  })
                }
              >
                {busy ? 'Sending…' : 'Send back'}
              </Button>
            </div>
          </div>
        )}
      </Card>

      {/* ---------------- history --------------------------------------- */}
      <Card>
        <CardHeader
          title="Filing history"
          subtitle="Every Lampiran B for this project, newest first"
        />
        <ul className="divide-y divide-slate-100">
          {forms.map((f) => (
            <li key={f.id} className="flex flex-wrap items-start justify-between gap-2 py-2">
              <div className="min-w-0">
                <p className="text-sm text-slate-800">
                  <span className="font-mono text-xs text-slate-400">{f.label}</span>{' '}
                  “{f.title}”
                </p>
                <p className="text-xs text-slate-500">
                  {f.is_signed
                    ? `Signed ${formatDateTime(f.signed_at)}${f.supervisor?.name ? ` by ${f.supervisor.name}` : ''}`
                    : 'Not yet signed'}
                  {f.signature_is_demo && (
                    <span className="ml-1 font-medium text-amber-700">· demo signature</span>
                  )}
                </p>
              </div>
              <FilingBadge form={f} />
            </li>
          ))}
        </ul>

        {acceptedTitle && (
          <p className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
            The project&apos;s current title — the last one a panel accepted — is
            <span className="font-medium text-slate-700"> “{acceptedTitle}”</span>. A proposed
            title does not replace it until it is accepted.
          </p>
        )}
      </Card>
    </div>
  )
}

/** The filing currently in play, with whatever actions the reader may take. */
function CurrentFiling({ form, busy, onEdit, onSign, onReturn }) {
  const awaiting = form.status === 'submitted'
  const isDraft = form.status === 'draft'

  return (
    <div className="rounded-md border border-slate-200 p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs text-slate-400">{form.label}</p>
          <p className="text-sm font-medium text-slate-800">“{form.title}”</p>
        </div>
        <FilingBadge form={form} />
      </div>

      <p className="mt-2 text-xs text-slate-500">
        {isDraft &&
          (form.supersedes_id
            ? 'This filing carries the refused title forward as a starting point. It must be changed before it is submitted.'
            : 'This filing is with you. Revise the title, then submit it for the supervisor signature.')}
        {awaiting && 'Submitted. Your supervisor signs it before the proposal opens for the panel.'}
        {form.status === 'endorsed' &&
          'Signed. The proposal for this title is open — upload it there, and the panel will see both this title and the one it replaced.'}
      </p>

      {/*
        A re-filing arrives pre-filled with the refused title, so it looks
        identical to the form that was just refused. Saying so stops a student
        submitting it unchanged and being refused twice for the same reason.
      */}
      {isDraft && form.supersedes_id && (
        <p className="mt-1 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">
          Pre-filled from the refused filing — its title cannot be submitted again.
        </p>
      )}

      <div className="mt-3 flex flex-wrap justify-end gap-2">
        {form.can_file && (
          <Button size="sm" variant="secondary" onClick={onEdit} disabled={busy}>
            {isDraft ? 'Revise the title' : 'Edit'}
          </Button>
        )}
        {form.can_sign && awaiting && (
          <>
            <Button size="sm" variant="secondary" onClick={onReturn} disabled={busy}>
              Send back
            </Button>
            <Button size="sm" onClick={onSign} disabled={busy} loading={busy}>
              Sign
            </Button>
          </>
        )}
        {form.can_sign && isDraft && (
          <p className="text-xs text-slate-400">Awaiting the student's submission.</p>
        )}
      </div>
    </div>
  )
}

function FilingBadge({ form }) {
  const map = {
    draft: { label: 'With the student', tone: 'slate' },
    submitted: { label: 'Awaiting signature', tone: 'amber' },
    endorsed: { label: 'Signed', tone: 'blue' },
    accepted: { label: 'Accepted', tone: 'success' },
    rejected: { label: 'Refused', tone: 'rose' },
  }
  const meta = map[form.status] ?? { label: form.status, tone: 'neutral' }
  return <Badge tone={meta.tone}>{meta.label}</Badge>
}
