import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { milestoneApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Textarea, FieldErrors, Avatar,
} from '../../components/ui'
import { can } from '../../lib/permissions'
import {
  formatDate, formatDateTime, formatBytes, relativeDays, isOverdue,
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
  const late = milestone.status !== 'approved' && isOverdue(due)
  const student = milestone.project?.students?.[0]

  // The status machine is the authority; these are just its UI consequences.
  const isStudentOwner =
    role === 'student' && milestone.project?.students?.some((s) => s.user_id === user?.id)
  const isReviewer = can(role, 'assess') && !isStudentOwner

  return (
    <div className="space-y-6">
      <PageHeader
        title={milestone.name}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? milestone.status}</Badge>
            {late && <Badge tone="danger">Overdue</Badge>}
            {milestone.revision_count > 0 && <Badge tone="warning">Revision {milestone.revision_count}</Badge>}
            {milestone.milestone_code && (
              <span className="font-mono text-xs">{milestone.milestone_code}</span>
            )}
          </span>
        }
        back={
          milestone.project
            ? { to: `/projects/${milestone.project.id}`, label: milestone.project.title }
            : { to: '/milestones', label: 'All milestones' }
        }
      />

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
                <Detail label="Due" value={formatDate(due, { fallback: 'No date set' })} />
                <Detail label="Weight" value={milestone.weight ? `${milestone.weight}%` : '—'} />
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
            onSubmitted={() => run(() => Promise.resolve())}
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
          {/* Reviewer actions. Only rendered when this user may actually act. */}
          {isReviewer && <ReviewCard milestone={milestone} busy={busy} onRun={run} />}

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
 */
function SubmissionCard({ milestone, canSubmit, onSubmitted }) {
  const inputRef = useRef(null)
  const [files, setFiles] = useState([])
  const [note, setNote] = useState('')
  const [uploading, setUploading] = useState(false)
  const [errors, setErrors] = useState(null)
  const [error, setError] = useState(null)

  const existing = milestone.files ?? []
  const accepts = milestone.accepts_submission

  const acceptAttr = useMemo(
    () =>
      milestone.allowed_file_types?.length
        ? milestone.allowed_file_types.map((t) => (t.startsWith('.') ? t : `.${t}`)).join(',')
        : undefined,
    [milestone.allowed_file_types]
  )

  function pick(event) {
    const picked = Array.from(event.target.files ?? [])
    const max = milestone.max_files || Infinity

    if (picked.length + files.length > max) {
      setError(`You can attach at most ${max} file${max === 1 ? '' : 's'}.`)
      return
    }
    // Guard the size here too, so the user is told before the request fails.
    const tooBig = picked.find((f) => f.size > 32 * 1024 * 1024)
    if (tooBig) {
      setError(`"${tooBig.name}" is larger than the 32 MB limit.`)
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
      onSubmitted()
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setError(err?.message ?? 'Upload failed. Please try again.')
    } finally {
      setUploading(false)
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
                </p>
              </div>
              <Button size="sm" variant="ghost" onClick={() => window.open(milestoneApi.downloadUrl(file.id), '_blank')}>
                Download
              </Button>
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
              {milestone.max_files ? ` · max ${milestone.max_files} files` : ''}
              {' · 32 MB per file'}
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

  const canReview = ['submitted', 'under_review'].includes(milestone.status)

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

function EventTimeline({ events }) {
  if (events.length === 0) {
    return <EmptyState title="No events yet" message="Submission and review activity will appear here." />
  }

  const sorted = [...events].sort(
    (a, b) => new Date(b.created_at ?? 0) - new Date(a.created_at ?? 0)
  )

  return (
    <ul className="space-y-4">
      {sorted.map((event) => (
        <li key={event.id} className="flex gap-3">
          <Avatar name={event.actor?.name ?? event.actor_name ?? 'System'} size="sm" />
          <div className="min-w-0 flex-1">
            <p className="text-sm text-slate-800">
              <span className="font-medium">{event.actor?.name ?? event.actor_name ?? 'System'}</span>
              {' '}
              <span className="text-slate-600">{describeEvent(event)}</span>
            </p>
            {event.payload?.comment && (
              <p className="mt-1 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {event.payload.comment}
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

function describeEvent(event) {
  const labels = {
    created: 'created this milestone',
    submitted: 'submitted files for review',
    commented: 'left a comment',
    approved: 'approved the submission',
    revision_requested: 'requested a revision',
    resubmitted: 'resubmitted after revision',
    extended: 'extended the deadline',
    reopened: 'reopened the milestone',
  }
  return labels[event.event_type ?? event.action] ?? (event.event_type ?? event.action ?? 'updated this milestone')
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
