import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { milestoneApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Input, Textarea, FieldErrors, Avatar, ProgressBar,
} from '../../components/ui'
import { can } from '../../lib/permissions'
import {
  formatDate, formatDateTime, formatBytes, relativeDays, isOverdue, formatPercent,
  MILESTONE_STATUS, statusMeta,
} from '../../lib/format'

/**
 * Milestone detail — the submission and review surface for Module 3.
 *
 * Two audiences share one page. A student sees the requirement, uploads files
 * and submits. A supervisor sees the same requirement plus whatever was
 * submitted, then approves or requests a revision. The status machine decides
 * which controls are live, so the UI never offers an illegal transition.
 */
export default function MilestoneDetailPage() {
  const { id } = useParams()
  const { user, role } = useAuth()

  const [milestone, setMilestone] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // milestoneApi.get() already unwraps the envelope.
      setMilestone(await milestoneApi.get(id))
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  async function run(fn) {
    setBusy(true)
    setActionError(null)
    try {
      await fn()
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Spinner label="Loading milestone" />
  if (error) return <ErrorState error={error} />
  if (!milestone) return <ErrorState error={{ message: 'Milestone not found.' }} />

  const meta = statusMeta(MILESTONE_STATUS, milestone.status)
  const due = milestone.effective_due_at ?? milestone.due_at
  const late = milestone.status !== 'approved' && isOverdue(due, milestone.status)
  const student = milestone.project?.students?.[0]

  // The status machine is the authority; these are just its UI consequences.
  const isStudentOwner =
    role === 'student' && milestone.project?.students?.some((s) => s.user_id === user?.id)
  const isReviewer = can(role, 'assess') && !isStudentOwner

  return (
    <div className="space-y-6">
      <PageHeader
        title={milestone.title}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={milestone.status_tone ?? 'neutral'}>
              {milestone.status_label ?? meta?.label ?? milestone.status}
            </Badge>
            {late && <Badge tone="danger">Overdue</Badge>}
            {milestone.revision_count > 0 && <Badge tone="warning">Revision {milestone.revision_count}</Badge>}
            {milestone.code && (
              <span className="font-mono text-xs">{milestone.code}</span>
            )}
          </span>
        }
        back={
          milestone.project
            ? { to: `/projects/${milestone.project.id}`, label: milestone.project.title }
            : { to: '/milestones', label: 'All milestones' }
        }
      />

      {/* How far this chapter has got, and what it is worth toward the
          project total. */}
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm font-medium text-slate-800">
            {milestone.title} progress
          </p>
          <div className="flex items-center gap-4 text-sm text-slate-600">
            <span className="tabular-nums">
              {formatPercent(milestone.completion_percent ?? 0)} complete
            </span>
            <span className="tabular-nums text-slate-400">
              worth {formatPercent(milestone.weight_percent ?? 0, 0)}
            </span>
          </div>
        </div>
        <div className="mt-3">
          <ProgressBar
            value={milestone.completion_percent ?? 0}
            tone={milestone.status_tone ?? 'brand'}
          />
        </div>
      </Card>

      {actionError && <ErrorState error={{ message: actionError }} />}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          {/* The requirement — what is actually being asked for. */}
          <Card>
            <CardHeader title="What is required" />
            <div className="space-y-4">
              {milestone.deliverable_expectation ? (
                <p className="whitespace-pre-line text-sm leading-relaxed text-slate-700">
                  {milestone.deliverable_expectation}
                </p>
              ) : (
                <p className="text-sm text-slate-500">
                  No detailed brief was recorded for this milestone.
                </p>
              )}

              <dl className="grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-4">
                <Detail label="Due" value={formatDate(due)} />
                <Detail label="Weight" value={formatPercent(milestone.weight_percent ?? 0, 0)} />
                <Detail
                  label="Files"
                  value={
                    milestone.max_files
                      ? `up to ${milestone.max_files}`
                      : 'unlimited'
                  }
                />
                <Detail
                  label="Accepted"
                  value={
                    milestone.allowed_file_types?.length
                      ? milestone.allowed_file_types.join(', ')
                      : 'any'
                  }
                />
              </dl>
            </div>
          </Card>

          {/* Submission — visible to everyone, editable only by the owner. */}
          <SubmissionCard
            milestone={milestone}
            canSubmit={isStudentOwner && milestone.accepts_submission}
            // Decided by the API, not re-derived here: withdrawal stays legal
            // for a short window after submitting, which `accepts_submission`
            // alone does not describe.
            canWithdraw={milestone.can_withdraw}
            onChanged={load}
          />

          {/* Decision history — the audit trail of this specific milestone. */}
          <Card>
            <CardHeader
              title="History"
              subtitle={`${milestone.events?.length ?? 0} recorded event${milestone.events?.length === 1 ? '' : 's'}`}
            />
            <EventTimeline events={milestone.events ?? []} />
          </Card>
        </div>

        <div className="space-y-6">
          {/* Reviewer actions. The **proposal milestone** is different: its
              verdict is the panel's and it settles the project title, so it gets
              its own card and the generic approve/revision controls are not
              offered — the server refuses them for this milestone anyway. */}
          {milestone.is_proposal ? (
            <>
              {milestone.can_decide_title && (
                <ProposalDecisionCard milestone={milestone} busy={busy} onRun={run} />
              )}
              {isStudentOwner && (
                <ProposalResponseCard milestone={milestone} busy={busy} onRun={run} />
              )}
            </>
          ) : (
            isReviewer && <ReviewCard milestone={milestone} busy={busy} onRun={run} />
          )}

          {milestone.is_proposal && (
            <Card>
              <CardHeader
                title="Panel"
                subtitle="The examiners seated on this student"
                action={
                  (milestone.panel?.length ?? 0) === 0 ? (
                    <Badge tone="warning">None seated</Badge>
                  ) : null
                }
              />

              {(milestone.panel?.length ?? 0) > 0 ? (
                <ul className="space-y-2 text-sm">
                  {milestone.panel.map((member) => (
                    <li key={member.user_id} className="flex items-center justify-between gap-3">
                      <span className="text-slate-700">{member.name}</span>
                      {member.role_label && <Badge tone="neutral">{member.role_label}</Badge>}
                    </li>
                  ))}
                </ul>
              ) : (
                /**
                 * Say so, rather than rendering nothing.
                 *
                 * An empty panel is not a cosmetic gap: this milestone is decided
                 * by the panel, so with nobody seated there is no one appointed to
                 * rule on the title and the rest of the chain cannot open. A blank
                 * card let that read as "nothing to show" instead of "this student
                 * has no panel".
                 */
                <p className="text-sm text-slate-500">
                  No panel is seated on this student yet. The proposal is decided by the panel, so
                  nobody is currently appointed to rule on this title.
                  {(role === 'coordinator' || role === 'admin') && (
                    <>
                      {' '}
                      <Link to="/assignments/panels" className="text-brand-700 underline">
                        Seat a panel
                      </Link>
                      .
                    </>
                  )}
                </p>
              )}
            </Card>
          )}

          <Card>
            <CardHeader title="Ownership" />
            <div className="space-y-4">
              <PersonRow label="Student" person={student} showId />
              {milestone.project?.supervisors?.map((s) => (
                <PersonRow key={s.id ?? s.name} label="Supervisor" person={s} />
              ))}
            </div>
          </Card>

          {milestone.status === 'approved' && (
            <Card className="border-emerald-200 bg-emerald-50/50">
              <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-xs text-white">
                  ✓
                </span>
                <div>
                  <p className="font-medium text-emerald-900">Approved</p>
                  <p className="text-sm text-emerald-800">
                    {formatDateTime(milestone.approved_at)}
                    {milestone.approved_by?.name && ` by ${milestone.approved_by.name}`}
                  </p>
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * Upload + submit. Files are staged locally and only sent when the student
 * confirms, because a half-uploaded submission is worse than none — the
 * student's work is only "submitted" once every file arrived.
 *
 * Every limit shown here comes from the server (`max_files`, `max_file_mb`,
 * `allowed_extensions`) instead of being hardcoded. A client guard that is
 * more permissive than the validator is worse than no guard: it lets the
 * student spend the upload, then rejects them at the end.
 */
function SubmissionCard({ milestone, canSubmit, canWithdraw, onChanged }) {
  const inputRef = useRef(null)
  const [files, setFiles] = useState([])
  const [note, setNote] = useState('')
  const [uploading, setUploading] = useState(false)
  const [busyFileId, setBusyFileId] = useState(null)
  const [errors, setErrors] = useState(null)
  const [error, setError] = useState(null)
  const [fileError, setFileError] = useState(null)

  const existing = milestone.files ?? []
  const accepts = milestone.accepts_submission

  // Server-provided constraints; the literals are only a fallback for a stale
  // bundle talking to an older API.
  const maxMb = milestone.max_file_mb ?? 25
  const maxFiles = milestone.max_files || Infinity
  const allowed = milestone.allowed_extensions ?? milestone.allowed_file_types ?? []

  const acceptAttr = useMemo(
    () =>
      allowed.length
        ? allowed.map((t) => (t.startsWith('.') ? t : `.${t}`)).join(',')
        : undefined,
    [allowed]
  )

  function pick(event) {
    const picked = Array.from(event.target.files ?? [])

    if (picked.length + files.length > maxFiles) {
      setError(`You can attach at most ${maxFiles} file${maxFiles === 1 ? '' : 's'}.`)
      return
    }
    // Guard the size here too, so the user is told before the upload is spent
    // rather than after the server rejects it.
    const tooBig = picked.find((f) => f.size > maxMb * 1024 * 1024)
    if (tooBig) {
      setError(`"${tooBig.name}" is larger than the ${maxMb} MB limit.`)
      return
    }

    setError(null)
    setFiles((prev) => [...prev, ...picked])
    if (inputRef.current) inputRef.current.value = ''
  }

  function remove(index) {
    setFiles((prev) => prev.filter((_, i) => i !== index))
  }

  async function submit() {
    if (files.length === 0) {
      setError('Attach at least one file before submitting.')
      return
    }

    setUploading(true)
    setErrors(null)
    setError(null)

    const body = new FormData()
    files.forEach((file) => body.append('files[]', file))
    if (note.trim()) body.append('note', note.trim())

    try {
      await milestoneApi.submit(milestone.id, body)
      setFiles([])
      setNote('')
      await onChanged()
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setError(err?.message ?? 'Upload failed. Please try again.')
    } finally {
      setUploading(false)
    }
  }

  async function download(file) {
    setFileError(null)
    setBusyFileId(file.id)
    try {
      // The original name is passed as the fallback: if Content-Disposition is
      // ever unavailable, the saved file still arrives with its real extension
      // instead of being called `submission-42` with none.
      await milestoneApi.download(file.id, file.original_name ?? file.name)
    } catch (err) {
      setFileError(err?.message ?? 'That file could not be downloaded.')
    } finally {
      setBusyFileId(null)
    }
  }

  async function withdraw(file) {
    const name = file.original_name ?? file.name
    // Withdrawing from a submitted milestone invalidates the submission, so
    // say so before the student is surprised by the status change.
    const wasSubmitted = milestone.status === 'submitted'

    const confirmed = window.confirm(
      `Withdraw "${name}"?\n\n` +
        (wasSubmitted
          ? 'This submission has not been reviewed yet, so withdrawing a file ' +
            'returns the milestone to "Revision required" and you will need to ' +
            'submit again.\n\n'
          : '') +
        'The file stays in the audit trail but is no longer available for ' +
        'review. You can upload a replacement.'
    )
    if (!confirmed) return

    setFileError(null)
    setBusyFileId(file.id)
    try {
      await milestoneApi.deleteFile(file.id)
      // Reload rather than using the withdrawal response: that payload carries
      // the file list but not the project/student/supervisor relations this
      // page also renders, so reusing it would blank those sections.
      await onChanged()
    } catch (err) {
      setFileError(err?.message ?? 'That file could not be withdrawn.')
    } finally {
      setBusyFileId(null)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Submission"
        subtitle={
          accepts
            ? 'Attach your deliverable, then submit for review'
            : `This milestone is ${milestone.status.replace(/_/g, ' ')} — submissions are closed`
        }
      />

      {fileError && <ErrorState error={{ message: fileError }} />}

      {/* Already-submitted files. Always visible, whatever the status. */}
      {existing.length > 0 ? (
        <ul className="mb-5 divide-y divide-slate-100 border-b border-slate-100 pb-4">
          {existing.map((file) => (
            <li key={file.id} className="flex items-center gap-3 py-2.5">
              <FileIcon name={file.original_name ?? file.name} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm text-slate-700">
                  {file.original_name ?? file.name}
                </p>
                <p className="text-xs text-slate-400">
                  {formatBytes(file.size)}
                  {file.uploaded_at && ` · uploaded ${relativeDays(file.uploaded_at)}`}
                  {file.revision_no > 1 && ` · revision ${file.revision_no}`}
                </p>
              </div>
              <div className="flex items-center gap-1">
                <Button
                  size="sm"
                  variant="ghost"
                  disabled={busyFileId === file.id}
                  onClick={() => download(file)}
                >
                  {busyFileId === file.id ? 'Working…' : 'Download'}
                </Button>
                {canWithdraw && (
                  <Button
                    size="sm"
                    variant="ghost"
                    disabled={busyFileId === file.id}
                    className="text-rose-600 hover:bg-rose-50 hover:text-rose-700"
                    onClick={() => withdraw(file)}
                  >
                    Withdraw
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mb-4 text-sm text-slate-500">Nothing has been submitted yet.</p>
      )}

      {canSubmit && (
        <div className="space-y-4">
          {error && (
            <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
          )}
          <FieldErrors errors={errors} />

          <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
              e.preventDefault()
              pick({ target: { files: e.dataTransfer.files } })
            }}
            className="rounded-lg border-2 border-dashed border-slate-300 px-4 py-6 text-center"
          >
            <p className="text-sm text-slate-600">
              Drag files here, or
              {' '}
              <button
                type="button"
                className="font-medium text-brand-700 hover:underline"
                onClick={() => inputRef.current?.click()}
              >
                browse
              </button>
            </p>
            <p className="mt-1 text-xs text-slate-400">
              {acceptAttr ? `Accepted: ${acceptAttr}` : 'Any file type'}
              {milestone.max_files ? ` · max ${maxFiles} files` : ''}
              {` · ${maxMb} MB per file`}
            </p>
            <input
              ref={inputRef}
              type="file"
              multiple
              accept={acceptAttr}
              onChange={pick}
              className="sr-only"
            />
          </div>

          {/* Submitting supersedes the previous attempt rather than adding to
              it, so say so before the student is surprised by it. */}
          {existing.length > 0 && files.length > 0 && (
            <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Submitting will supersede the {existing.length} file
              {existing.length === 1 ? '' : 's'} currently on record. They stay in
              the history and remain downloadable.
            </p>
          )}

          {files.length > 0 && (
            <ul className="space-y-2">
              {files.map((file, index) => (
                <li
                  key={`${file.name}-${file.size}-${index}`}
                  className="flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2"
                >
                  <FileIcon name={file.name} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm text-slate-700">{file.name}</p>
                    <p className="text-xs text-slate-400">{formatBytes(file.size)}</p>
                  </div>
                  <button
                    type="button"
                    onClick={() => remove(index)}
                    className="text-sm text-slate-500 hover:text-rose-600"
                    aria-label={`Remove ${file.name}`}
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}

          <Field label="Note for your supervisor" htmlFor="note" hint="Optional">
            <Textarea
              id="note"
              rows={3}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Anything your reviewer should know about this submission…"
            />
          </Field>

          <div className="flex justify-end">
            <Button onClick={submit} disabled={uploading || files.length === 0}>
              {uploading ? 'Uploading…' : 'Submit for review'}
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}

function ReviewCard({ milestone, busy, onRun }) {
  const [decision, setDecision] = useState(null)
  const [comment, setComment] = useState('')
  const [error, setError] = useState(null)

  // Feedback is mandatory for a revision request — otherwise the student is
  // told "no" with no way to find out why.
  const needsComment = decision === 'revision'

  async function confirm() {
    if (needsComment && comment.trim().length < 10) {
      setError('Explain what needs changing — at least 10 characters.')
      return
    }
    setError(null)
    await onRun(() =>
      milestoneApi.review(milestone.id, {
        decision,
        comment: comment.trim() || undefined,
      })
    )
  }

  // `reviewed` is the real backend status; there is no `under_review`. A
  // milestone already in `reviewed` can still be approved or sent back.
  const canReview = ['submitted', 'reviewed'].includes(milestone.status)

  if (!canReview) {
    return (
      <Card>
        <CardHeader title="Review" />
        <p className="text-sm text-slate-500">
          {milestone.status === 'approved'
            ? 'This milestone has been approved.'
            : 'There is nothing awaiting your review right now.'}
        </p>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader title="Review" subtitle="Approve, or send back with feedback" />
      <div className="space-y-4">
        {error && (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
        )}

        <div className="grid grid-cols-2 gap-2">
          <button
            type="button"
            onClick={() => setDecision('approved')}
            className={`rounded-lg border px-3 py-2.5 text-sm font-medium transition ${
              decision === 'approved'
                ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                : 'border-slate-200 text-slate-600 hover:border-slate-300'
            }`}
          >
            Approve
          </button>
          <button
            type="button"
            onClick={() => setDecision('revision')}
            className={`rounded-lg border px-3 py-2.5 text-sm font-medium transition ${
              decision === 'revision'
                ? 'border-amber-500 bg-amber-50 text-amber-800'
                : 'border-slate-200 text-slate-600 hover:border-slate-300'
            }`}
          >
            Request revision
          </button>
        </div>

        <Field
          label={needsComment ? 'What needs changing?' : 'Comment'}
          htmlFor="comment"
          required={needsComment}
          hint={needsComment ? 'Required' : 'Optional, visible to the student'}
        >
          <Textarea
            id="comment"
            rows={5}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder={
              needsComment
                ? 'Be specific — the student needs to know exactly what to fix.'
                : 'Feedback, or a note for the record…'
            }
          />
        </Field>

        <Button className="w-full" disabled={busy || !decision} onClick={confirm}>
          {busy ? 'Recording…' : decision === 'approved' ? 'Approve milestone' : 'Send back for revision'}
        </Button>
      </div>
    </Card>
  )
}

/**
 * The panel's verdict on the proposal — the title decision.
 *
 * Three outcomes, and they are genuinely different: an approval opens the rest
 * of the chain, a conditional approval leaves the student owing Lampiran C, and
 * a rejection means the title itself has to change. That is why this is not the
 * generic approve/request-revision card.
 */
function ProposalDecisionCard({ milestone, busy, onRun }) {
  const [decision, setDecision] = useState('approved')
  const [reason, setReason] = useState('')

  const needsReason = decision !== 'approved'
  const ready = !needsReason || reason.trim().length > 0

  const OPTIONS = [
    {
      value: 'approved',
      label: 'Approve',
      hint: 'The title stands, and the remaining milestones open',
      active: 'border-emerald-500 bg-emerald-50 text-emerald-800',
      idle: 'border-slate-200 text-slate-600 hover:border-slate-300',
    },
    {
      value: 'conditional_approve',
      label: 'Conditional approval',
      hint: 'The title stands subject to corrections — the student files Lampiran C',
      active: 'border-amber-500 bg-amber-50 text-amber-800',
      idle: 'border-slate-200 text-slate-600 hover:border-slate-300',
    },
    {
      value: 'rejected',
      label: 'Reject the title',
      hint: 'The title is refused and the student must change it',
      active: 'border-rose-500 bg-rose-50 text-rose-800',
      idle: 'border-slate-200 text-slate-600 hover:border-slate-300',
    },
  ]

  // Nothing to decide until the student has filed the proposal.
  if (!['submitted', 'reviewed'].includes(milestone.status)) {
    return (
      <Card>
        <CardHeader title="Title decision" subtitle="The panel's verdict on the proposed title" />
        <p className="text-sm text-slate-500">
          {milestone.status === 'approved'
            ? 'This title has been approved, and the remaining milestones are open.'
            : milestone.status === 'conditional_approve'
              ? 'Approved conditionally — the student still owes the Lampiran C form.'
              : milestone.status === 'rejected'
                ? 'This title was rejected. The student must change it before a fresh decision.'
                : 'There is nothing to decide yet — the student has not submitted the proposal.'}
        </p>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader
        title="Title decision"
        subtitle="Settles the project title and gates the remaining milestones"
      />
      <div className="space-y-4">
        <div className="space-y-2">
          {OPTIONS.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setDecision(option.value)}
              className={`w-full rounded-lg border px-3 py-2.5 text-left transition ${
                decision === option.value ? option.active : option.idle
              }`}
            >
              <span className="block text-sm font-medium">{option.label}</span>
              <span className="block text-xs text-slate-500">{option.hint}</span>
            </button>
          ))}
        </div>

        <Field
          label={needsReason ? 'Reason for the decision' : 'Comment'}
          htmlFor="panel_reason"
          required={needsReason}
          hint={needsReason ? 'Required — the student needs to know what to change' : 'Optional'}
        >
          <Textarea
            id="panel_reason"
            rows={4}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={
              needsReason
                ? 'Be specific — the student has to act on this.'
                : 'Feedback, or a note for the record…'
            }
          />
        </Field>

        <Button
          className="w-full"
          disabled={busy || !ready}
          onClick={() =>
            onRun(() =>
              milestoneApi.titleDecision(milestone.id, {
                decision,
                panel_reason: reason.trim() || undefined,
              }),
            )
          }
        >
          {busy ? 'Recording…' : 'Record the decision'}
        </Button>
      </div>
    </Card>
  )
}

/**
 * The student's side of the proposal decision.
 *
 * A conditional approval owes the Lampiran C form; a rejection owes a new title.
 * Either way the rest of the chain stays shut until it is done.
 */
function ProposalResponseCard({ milestone, busy, onRun }) {
  const [title, setTitle] = useState('')
  const [rows, setRows] = useState([{ comment: '', action: '' }])

  const conditional = milestone.status === 'conditional_approve'
  const rejected = milestone.status === 'rejected'

  if (!conditional && !rejected) return null

  if (conditional) {
    return (
      <Card>
        <CardHeader
          title="Lampiran C — Corrections"
          subtitle="Record the corrected title and what was changed, then the chain opens"
        />
        <div className="space-y-5">
          {milestone.review_comment && (
            <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
              {milestone.review_comment}
            </p>
          )}

          <Field
            label="Corrected title (Tajuk Baharu)"
            htmlFor="corrections_title"
            required
            hint="This becomes the project title"
          >
            <Input
              id="corrections_title"
              value={title || milestone.project?.title || ''}
              onChange={(e) => setTitle(e.target.value)}
              maxLength={255}
            />
          </Field>

          <div className="space-y-3">
            <p className="text-sm font-medium text-slate-700">Panel comment → action taken</p>
            {rows.map((row, index) => (
              <div key={index} className="grid gap-3 sm:grid-cols-2">
                <Input
                  placeholder="Panel comment"
                  value={row.comment}
                  onChange={(e) =>
                    setRows((prev) => prev.map((r, i) => (i === index ? { ...r, comment: e.target.value } : r)))
                  }
                />
                <Input
                  placeholder="Action taken"
                  value={row.action}
                  onChange={(e) =>
                    setRows((prev) => prev.map((r, i) => (i === index ? { ...r, action: e.target.value } : r)))
                  }
                />
              </div>
            ))}
            <Button variant="secondary" onClick={() => setRows((prev) => [...prev, { comment: '', action: '' }])}>
              Add row
            </Button>
          </div>

          <div className="flex justify-end">
            <Button
              disabled={busy || !(title || milestone.project?.title || '').trim()}
              onClick={() =>
                onRun(() =>
                  milestoneApi.fileLampiranC(milestone.id, {
                    corrections_title: (title || milestone.project?.title || '').trim(),
                    corrections_actions: rows.filter((r) => r.comment.trim() || r.action.trim()),
                  }),
                )
              }
            >
              {busy ? 'Filing…' : 'File Lampiran C'}
            </Button>
          </div>
        </div>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader
        title="Change the title"
        subtitle="The panel refused the title — propose a new one and the proposal reopens"
      />
      <div className="space-y-5">
        {milestone.review_comment && (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">
            {milestone.review_comment}
          </p>
        )}

        <Field
          label="New title"
          htmlFor="new_title"
          required
          hint="Written to the project; the milestone reopens so you can resubmit"
        >
          <Input
            id="new_title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={255}
            placeholder={milestone.project?.title ?? ''}
          />
        </Field>

        <div className="flex justify-end">
          <Button
            disabled={busy || !title.trim() || title.trim() === milestone.project?.title}
            onClick={() => onRun(() => milestoneApi.changeTitle(milestone.id, title.trim()))}
          >
            {busy ? 'Saving…' : 'Change the title'}
          </Button>
        </div>
      </div>
    </Card>
  )
}

function EventTimeline({ events }) {
  if (events.length === 0) {
    return <EmptyState title="No events yet" message="Submission and review activity will appear here." />
  }

  // Newest first. Ties are broken on id so the order is stable: a withdrawal
  // and the status change it triggers land in the same second, and without a
  // tiebreak their relative order would depend on sort implementation details.
  const sorted = [...events].sort((a, b) => {
    const byTime = new Date(b.created_at ?? 0) - new Date(a.created_at ?? 0)
    return byTime !== 0 ? byTime : (b.id ?? 0) - (a.id ?? 0)
  })

  return (
    <ul className="space-y-4">
      {sorted.map((event) => (
        <li key={event.id} className="flex gap-3">
          <Avatar name={event.actor?.name ?? 'System'} size="sm" />
          <div className="min-w-0 flex-1">
            {/* The API sends a pre-rendered sentence that already names the
                actor, so it is rendered as-is. `describeEvent` is only the
                fallback for an event type the server does not label. */}
            <p className="text-sm text-slate-800">
              {event.description ?? describeEvent(event)}
            </p>
            {event.comment && (
              <p className="mt-1 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {event.comment}
              </p>
            )}
            <p className="mt-1 text-xs text-slate-400">
              {formatDateTime(event.created_at)}
              {event.created_at && ` · ${relativeDays(event.created_at)}`}
            </p>
          </div>
        </li>
      ))}
    </ul>
  )
}

/**
 * Fallback wording, keyed on the raw `event` value the API stores.
 *
 * The earlier version read `event_type` / `event.action`, neither of which the
 * resource sends, so every row fell through to "updated this milestone" and a
 * supervisor could not tell an upload from an approval.
 */
function describeEvent(event) {
  const labels = {
    created: 'created this milestone',
    opened: 'Milestone opened for submission',
    submitted: 'uploaded a submission',
    uploaded: 'uploaded a submission',
    commented: 'added a comment',
    approved: 'approved this milestone',
    revision_requested: 'requested a revision',
    rejected: 'requested a revision',
    replaced: 'replaced the submission file',
    withdrawn: 'withdrew a file',
    extended: 'extended the deadline',
    deadline_changed: 'changed the deadline',
    reopened: 'reopened the milestone',
  }
  const key = event.event ?? event.event_type ?? event.action
  return labels[key] ?? (key ?? 'updated this milestone')
}

function Detail({ label, value }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-sm font-medium text-slate-700">{value}</dd>
    </div>
  )
}

function PersonRow({ label, person, showId = false }) {
  if (!person) return null
  return (
    <div className="flex items-center gap-3">
      <Avatar name={person.name ?? '?'} size="sm" />
      <div className="min-w-0">
        <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
        <p className="truncate text-sm text-slate-700">{person.name}</p>
        {showId && person.student_id && (
          <p className="font-mono text-xs text-slate-400">{person.student_id}</p>
        )}
      </div>
    </div>
  )
}

function FileIcon({ name = '' }) {
  const ext = name.split('.').pop()?.toLowerCase() ?? ''
  const tone =
    ['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)
      ? 'bg-amber-100 text-amber-700'
      : ['pdf'].includes(ext)
        ? 'bg-rose-100 text-rose-700'
        : ['doc', 'docx'].includes(ext)
          ? 'bg-brand-100 text-brand-700'
          : ['xls', 'xlsx', 'csv'].includes(ext)
            ? 'bg-emerald-100 text-emerald-700'
            : 'bg-slate-100 text-slate-600'

  return (
    <span
      className={`flex h-8 w-8 shrink-0 items-center justify-center rounded text-xs font-semibold uppercase ${tone}`}
      aria-hidden="true"
    >
      {ext.slice(0, 4) || '?'}
    </span>
  )
}
