import { useEffect, useState } from 'react'
import { Button, Card, CardHeader } from './ui'
import { lampiranBApi } from '../api/endpoints'

/**
 * The PSM 2 boundary question.
 *
 * A student who passed PSM 1 may take a different title for PSM 2, and the
 * faculty's rule is that the decision is made once, at the start of the chain,
 * before Chapter 5 opens. This is where that decision is put to them: the first
 * time they open their PSM 2 project, they are asked to keep the inherited title
 * or change it.
 *
 * Three states, and the difference between them matters:
 *
 *   - **asked** — `decided` is false and `can_change` is true. The prompt shows.
 *   - **answered** — `keep` was chosen, or a change is already in flight. The
 *     component renders a one-line confirmation or nothing at all, because a
 *     question that reappears after it has been answered is worse than no
 *     question.
 *   - **closed** — `can_change` is false, with the reason shown. Chapter 5 has
 *     opened, or the title has already changed once. The student is told *why*
 *     rather than left wondering where the option went.
 *
 * Keeping the title is recorded rather than assumed. Without that answer there
 * is no way to tell "not asked yet" from "asked and declined", so the prompt
 * would either nag on every visit or never appear at all.
 */
export default function Psm2BoundaryCard({ project, onChanged }) {
  const [status, setStatus] = useState(null)
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [form, setForm] = useState(null)

  useEffect(() => {
    let cancelled = false

    lampiranBApi
      .titleStatus(project.id)
      .then((data) => {
        if (!cancelled) setStatus(data)
      })
      .catch(() => {
        // A student who may not see this project simply sees no card.
        if (!cancelled) setStatus(null)
      })

    return () => {
      cancelled = true
    }
  }, [project.id])

  if (!status?.eligible) return null

  async function decide(decision) {
    setBusy(decision)
    setError(null)
    try {
      const res = await lampiranBApi.decideTitle(project.id, decision)
      if (decision === 'keep') {
        setStatus((s) => ({ ...s, decided: true, decision: 'keep' }))
      } else {
        // The Lampiran B draft is open; the rest happens on the milestone page
        // once the supervisor signs it.
        setForm(res?.data ?? res)
        setStatus((s) => ({ ...s, decided: true, decision: 'change', can_change: false }))
      }
      onChanged?.()
    } catch (err) {
      setError(err?.message ?? 'That could not be saved.')
    } finally {
      setBusy(null)
    }
  }

  // Already answered — say so once, quietly, and stop asking.
  if (status.decided && status.decision === 'keep') {
    return (
      <Card>
        <div className="flex items-start gap-3 px-1 py-1">
          <span aria-hidden className="mt-0.5 text-lg leading-none text-emerald-600">✓</span>
          <div>
            <p className="text-sm font-medium text-slate-800">
              You are continuing with your inherited title
            </p>
            <p className="mt-1 text-sm text-slate-500">
              “{status.current_title}”. You chose to keep it, so nothing is refiled and
              Chapter 5 begins where it stands.
            </p>
          </div>
        </div>
      </Card>
    )
  }

  // Already chose to change — the form exists, so point at it rather than
  // offering the choice a second time.
  if (status.decided && status.decision === 'change') {
    return (
      <Card>
        <CardHeader
          title="Title change in progress"
          subtitle="Your supervisor signs the new title before the proposal opens"
        />
        <p className="text-sm text-slate-600">
          You chose to change the title at the start of PSM 2. The new Lampiran B is
          open{form?.revision_no ? ` as revision ${form.revision_no}` : ''}; your
          supervisor must sign it, and only then does the new proposal appear in your
          milestone chain.
        </p>
      </Card>
    )
  }

  // Eligible but the four guards refuse a change. Say why, so the missing option
  // is explained rather than simply absent.
  if (!status.can_change) {
    return (
      <Card>
        <CardHeader title="Title for PSM 2" />
        <p className="text-sm text-slate-600">
          You are continuing with <strong>“{status.current_title}”</strong>.
        </p>
        {status.blocked_reason && (
          <p className="mt-2 text-sm text-slate-500">{status.blocked_reason}</p>
        )}
      </Card>
    )
  }

  // The question itself.
  return (
    <Card>
      <CardHeader
        title="Decide once: keep your PSM 1 title, or change it?"
        subtitle="This is the only point where the title can change. It cannot be undone."
      />

      <p className="text-sm text-slate-600">
        You finished PSM 1 on{' '}
        <strong>“{status.current_title}”</strong>. You may continue with it, or take a
        different title for PSM 2 — but a different title is a different project: you
        would file a new Lampiran B, defend the new proposal to a panel, and write
        Chapters 1–4 again before Chapters 5–7. Those refiled chapters carry no marks,
        because your PSM 1 mark is already awarded.
      </p>

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
          {error}
        </p>
      )}

      <div className="mt-5 flex flex-wrap gap-3">
        <Button onClick={() => decide('keep')} disabled={busy !== null}>
          {busy === 'keep' ? 'Saving…' : 'Keep this title and continue'}
        </Button>
        <Button variant="secondary" onClick={() => decide('change')} disabled={busy !== null}>
          {busy === 'change' ? 'Opening the form…' : 'Change the title…'}
        </Button>
      </div>

      <p className="mt-4 text-xs text-slate-400">
        Choosing to change opens a new Lampiran B. If you close it without filing,
        tell your coordinator — the choice is recorded as soon as you press the button.
      </p>
    </Card>
  )
}
