import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { milestoneApi } from '../../api/endpoints'
import MilestoneReviewCard from '../../components/MilestoneReviewCard'
import OsheSignCard from '../../components/OsheSignCard'
import LampiranBCard from '../../components/LampiranBCard'
import LampiranCCard from '../../components/LampiranCCard'
import RegistrationDocumentCard from '../../components/RegistrationDocumentCard'
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

  /**
   * The Lampiran R1 rounds filed against this milestone, newest first.
   *
   * Loaded separately from the milestone because a worklist of many milestones
   * must not pay for every one's history — the endpoint is per milestone, and
   * only the detail page needs it. A failure here is swallowed: the review card
   * still works without the history, and an empty list is a truthful rendering
   * of "none recorded" for the common case.
   */
  const [rounds, setRounds] = useState([])

  /**
   * Re-read the milestone.
   *
   * `silent` skips the full-page spinner. Without it, every action — upload,
   * approve, withdraw, add a comment — swapped the entire screen for a
   * "Loading milestone" placeholder and then rebuilt it. On a hosted database
   * each of these round trips costs a few hundred milliseconds, so the page
   * flashed empty and came back: it read as a browser refresh, and it lost the
   * user's place each time.
   *
   * The first load still shows the spinner, because there is genuinely nothing
   * to show yet. A refresh after an action has content on screen already, so
   * the honest thing is to leave it there and swap in the new data when it
   * arrives.
   */
  const load = useCallback(async ({ silent = false } = {}) => {
    if (!silent) setLoading(true)
    setError(null)
    try {
      // milestoneApi.get() already unwraps the envelope.
      setMilestone(await milestoneApi.get(id))
    } catch (err) {
      setError(err)
    } finally {
      if (!silent) setLoading(false)
    }

    try {
      const history = await milestoneApi.reviews(id)
      setRounds(history ?? [])
    } catch {
      setRounds([])
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
      await load({ silent: true })
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
            // Wrapped, not passed directly: React hands a callback its event as
            // the first argument, which would arrive as the `options` object and
            // be destructured for `silent`. The wrapper keeps the call explicit.
            onChanged={() => load({ silent: true })}
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
            /**
             * The OSHE milestone is the one form the supervisor *signs* and
             * returns rather than reviews, so it keeps its own narrow card.
             *
             * Lampiran R1 is deliberately NOT rendered here: the form is a whole
             * page of the official document, and a one-third-width sidebar
             * column is the wrong shape for it. It is rendered full-width below
             * the grid instead — see <MilestoneReviewCard> after this block.
             */
            milestone.code === 'oshe' && isReviewer && (
              <OsheSignCard milestone={milestone} onDone={() => load({ silent: true })} />
            )
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

          {/*
            Moving a deadline is the coordinator's, and it is the administrative
            action most likely to be challenged later — so the reason is
            mandatory and the previous value is shown before it is changed. The
            API has always existed; nothing called it.
          */}
          {milestone.can_change_deadline && (
            <DeadlineCard milestone={milestone} busy={busy} onRun={run} />
          )}

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

      {/* Lampiran A / B - the registration documents, read-only. */}
      {milestone.is_registration_document && (
        <div className="mt-6">
          <RegistrationDocumentCard milestone={milestone} />
        </div>
      )}

      {/*
        Lampiran C — full width, below the grid.

        A two-party digital form rather than a review: the student fills Bahagian
        A-C (including their own signature), the supervisor signs Bahagian D. The
        card shows each party only their own half.
      */}
      {milestone.code === 'lampiran_c' && (
        <div className="mt-6">
          <LampiranCCard
            milestone={milestone}
            isStudentOwner={isStudentOwner}
            isReviewer={isReviewer}
            onDone={() => load({ silent: true })}
          />
        </div>
      )}

      {/*
        Lampiran B — the filings, on the proposal milestone.

        Placed here because this is where the title is decided, so it is where a
        refused title and the one replacing it belong side by side. A separate
        comparison screen was considered and rejected: the panel reading a
        proposal already has this page open, and a screen they have to go and
        find is a screen most of them will not read.

        The card renders nothing when a project has only its original filing —
        a title that was never refused needs no management here.
      */}
      {milestone.code === 'proposal' && (
        <div className="mt-6">
          <LampiranBCard
            projectId={milestone.project?.id ?? milestone.project_id}
            milestone={milestone}
            onDone={() => load({ silent: true })}
          />
        </div>
      )}

      {/*
        Lampiran R1 — full width, below the two-column grid.

        **Only where the form applies.** `uses_r1` is the server's answer to
        "does Lampiran R1 govern this milestone" — Chapters 1-4 of PSM 1 plus the
        demo milestone. It is the *gate*, not a widening condition: an earlier
        version read `isReviewer || ... || uses_r1`, which meant any supervisor
        saw an R1 card on the final report and on Lampiran C, where the form does
        not exist.

        Anyone with rounds to read still gets the card, so a student keeps access
        to history on a milestone the form no longer covers.
      */}
      {!milestone.is_proposal &&
        milestone.uses_r1 &&
        (isReviewer || rounds.length > 0) && (
          <div className="mt-6">
            <MilestoneReviewCard
              milestone={milestone}
              rounds={rounds}
              isReviewer={isReviewer}
              onDone={() => load({ silent: true })}
            />
          </div>
        )}
    </div>
  )
}

/**
 * PDF viewer.
 *
 * A plain `<iframe>` over a blob URL, which the browser's own PDF viewer renders
 * — no pdf.js dependency, and the reader gets the same scroll/zoom/print
 * controls they would get from the file on disk. A signed Lampiran has to be
 * inspectable in place: "view the document that was signed" is the whole point
 * of keeping the uploaded copy.
 */
function FilePreviewModal({ preview, onClose }) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  if (!preview) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label={preview.name}
    >
      <div
        className="flex h-full max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-2.5">
          <p className="truncate text-sm font-medium text-slate-800">{preview.name}</p>
          <div className="flex items-center gap-2">
            <a
              href={preview.url}
              target="_blank"
              rel="noreferrer"
              className="text-xs font-medium text-brand-700 hover:underline"
            >
              Open in new tab
            </a>
            <Button size="sm" variant="ghost" onClick={onClose}>
              Close
            </Button>
          </div>
        </div>
        <iframe src={preview.url} title={preview.name} className="min-h-0 flex-1 border-0" />
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

  /** `{ url, name }` while a PDF is open in the viewer, else null. */
  const [preview, setPreview] = useState(null)

  // Release the blob URL when the viewer closes, or every opened file would
  // stay in memory for the life of the tab.
  useEffect(() => {
    if (!preview?.url) return undefined
    return () => URL.revokeObjectURL(preview.url)
  }, [preview?.url])

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
      if (err?.errors) {
        setErrors(err.errors)
      } else if (err?.isTimeout) {
        /**
         * A timeout is not the same as a failure.
         *
         * The request is abandoned client-side, but the server keeps working —
         * so the submission may well have gone through. Telling the student it
         * failed invites them to submit again and create a duplicate revision,
         * which is exactly what happened before. Re-reading the milestone shows
         * the truth either way.
         */
        setError(
          'This is taking longer than expected, so the upload was stopped here. ' +
            'It may still have completed on the server — the page has been ' +
            'refreshed to show the current state. Please check before submitting again.'
        )
        await onChanged()
      } else {
        setError(err?.message ?? 'Upload failed. Please try again.')
      }
    } finally {
      setUploading(false)
    }
  }

  /**
   * Open a submission in the viewer.
   *
   * Fetched as a blob through the authenticated axios client, then handed to the
   * modal as an object URL. `window.open` would not work: the download route sits
   * behind Sanctum and a plain navigation carries no Authorization header, so it
   * would either 401 or silently save the file instead of displaying it.
   */
  async function view(file) {
    setFileError(null)

    const name = file.original_name ?? file.name ?? ''
    const isPdf = /\.pdf$/i.test(name) || file.mime_type === 'application/pdf'

    if (!isPdf) {
      // Word documents cannot be rendered by the browser. Downloading is the
      // honest action; showing an empty frame would look like a failed upload.
      setFileError(`${name} is not a PDF, so it cannot be shown here — use Download instead.`)
      return
    }

    setBusyFileId(file.id)
    try {
      const url = await milestoneApi.preview(file.id)
      setPreview({ url, name })
    } catch (err) {
      setFileError(err?.message ?? 'That file could not be opened.')
    } finally {
      setBusyFileId(null)
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
                {/*
                  View, not just Download. A supervisor reviewing a chapter wants
                  to read it, not to accumulate files in their Downloads folder —
                  and a Lampiran that has been signed and returned has to be
                  checkable in place. PDFs open in a viewer modal; anything else
                  falls back to download, because the browser cannot render a
                  .docx and pretending otherwise shows a blank frame.
                */}
                <Button
                  size="sm"
                  variant="ghost"
                  disabled={busyFileId === file.id}
                  onClick={() => view(file)}
                >
                  View
                </Button>
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

      {/* Rendered here, beside the View button whose state it uses. It was
          previously rendered by the page, which does not own `preview`. */}
      <FilePreviewModal preview={preview} onClose={() => setPreview(null)} />
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

  // The proposal has two signatures and the supervisor holds the first.
  //
  // "Approve" would be the wrong word here twice over: it opens the next
  // milestone, which would let the student skip the title defence, and it
  // implies settling the milestone when the supervisor is only saying the
  // document is ready to be examined. `endorse` is that narrower act.
  const isProposal = milestone.code === 'proposal'

  async function confirm() {
    if (needsComment && comment.trim().length < 10) {
      setError('Explain what needs changing — at least 10 characters.')
      return
    }
    setError(null)
    await onRun(() =>
      isProposal
        ? milestoneApi.reviewProposal(milestone.id, {
            decision,
            comment: comment.trim() || undefined,
          })
        : milestoneApi.review(milestone.id, {
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
      <CardHeader
        title="Review"
        subtitle={
          isProposal
            ? 'Endorse the proposal for the title defence, or send it back'
            : 'Approve, or send back with feedback'
        }
      />
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
            {isProposal ? 'Endorse' : 'Approve'}
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
          {busy
            ? 'Recording…'
            : decision === 'approved'
              ? isProposal
                ? 'Endorse — send to the panel'
                : 'Approve milestone'
              : 'Send back for revision'}
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
    /*
     * Conditional approval — read-only.
     *
     * The corrections are filed on the dedicated **Lampiran C milestone**, which
     * the panel's verdict inserted into the chain. This card used to carry a
     * duplicate form; two places to file the same document meant two records of
     * the same corrections, and the one on the proposal had no signature.
     *
     * The status stays `conditional_approve` until the supervisor signs the C
     * form, at which point both this milestone and the C milestone settle.
     */
    return (
      <Card>
        <CardHeader
          title="Lampiran C — corrections required"
          subtitle="Filed on the Lampiran C milestone in this project"
        />
        <div className="space-y-3">
          {milestone.requires_lampiran_c && (
            <div className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
              <p className="font-medium">The panel asked for</p>
              <p className="mt-0.5">{milestone.requires_lampiran_c}</p>
            </div>
          )}

          <p className="text-sm text-slate-600">
            Record the corrections on the <strong>Lampiran C</strong> milestone, where you fill in
            the previous and new title, the corrections taken, and your signature. Your supervisor
            signs it there — this proposal then becomes <strong>approved</strong>.
          </p>

          <p className="text-xs text-slate-500">
            Find it in this project&apos;s milestone list, immediately after the proposal.
          </p>
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

/**
 * The coordinator's deadline control.
 *
 * The API (`POST /milestones/{id}/deadline`) has existed since Module 6 but
 * nothing called it, so the only way to move a deadline was by hand in the
 * database. It is deliberately a two-step card rather than a bare button: the
 * current date is stated first, the new date must be in the future, and the
 * reason is mandatory and at least ten characters. That mirrors the validator,
 * so the form cannot stage a request the server will reject.
 *
 * Shown only when `can_change_deadline` is true, which is the policy's own
 * answer rather than a role check repeated here.
 */
function DeadlineCard({ milestone, busy, onRun }) {
  const [open, setOpen] = useState(false)
  const [dueAt, setDueAt] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)

  const current = milestone.effective_due_at ?? milestone.due_at

  /**
   * Today, as `YYYY-MM-DD`, for the input's `min`.
   *
   * Built from the local date parts rather than `toISOString()`, which converts
   * to UTC first — for a user east of Greenwich that reports yesterday's date
   * and would let them pick a day the server then rejects as not in the future.
   */
  const now = new Date()
  const today = [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
  ].join('-')

  async function submit() {
    if (!dueAt) {
      setError('Choose the new deadline date.')
      return
    }

    if (dueAt <= today) {
      setError('The new deadline must be in the future.')
      return
    }

    if (reason.trim().length < 10) {
      setError('Give the reason for the change — at least 10 characters. It is kept in the audit trail.')
      return
    }

    setError(null)
    await onRun(() => milestoneApi.changeDeadline(milestone.id, dueAt, reason.trim()))
    setOpen(false)
    setDueAt('')
    setReason('')
  }

  return (
    <Card>
      <CardHeader
        title="Deadline"
        subtitle="Moving a deadline is recorded against your name"
        action={
          <Button size="sm" variant="secondary" onClick={() => setOpen((v) => !v)} disabled={busy}>
            {open ? 'Cancel' : 'Change deadline'}
          </Button>
        }
      />

      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <Detail label="Currently due" value={formatDate(current)} />
          {milestone.extended_until && (
            <Detail label="Extended to" value={formatDate(milestone.extended_until)} />
          )}
        </div>

        {milestone.deadline_override_reason && (
          <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <span className="font-medium">Last change:</span> {milestone.deadline_override_reason}
          </p>
        )}

        {open && (
          <div className="space-y-4 border-t border-slate-100 pt-4">
            {error && (
              <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
            )}

            <Field
              label="New deadline"
              htmlFor="new-due-at"
              required
              hint="The student is notified, and an extension is applied from this date"
            >
              <Input
                id="new-due-at"
                type="date"
                min={today}
                value={dueAt}
                onChange={(e) => setDueAt(e.target.value)}
              />
            </Field>

            <Field
              label="Reason"
              htmlFor="deadline-reason"
              required
              hint="Required — this is the action most likely to be challenged later"
            >
              <Textarea
                id="deadline-reason"
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Panel review ran late; the faculty approved a two-week extension."
              />
            </Field>

            <div className="flex justify-end">
              <Button onClick={submit} disabled={busy}>
                {busy ? 'Saving…' : 'Move the deadline'}
              </Button>
            </div>
          </div>
        )}
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
