import { useEffect, useRef, useState } from 'react'
import { milestoneApi } from '../api/endpoints'
import { Badge, Button, Card, CardHeader, Field, Select, Textarea } from './ui'
import { formatDateTime } from '../lib/format'
import MilestoneReviewDocument from './MilestoneReviewDocument'

/**
 * Lampiran R1 — the supervisor's review of a chapter submission, and the
 * history of every round.
 *
 * The published form (UTHM.FSKTM/UA-31/2025) has two parts that matter here:
 *
 *   Bahagian A  the student's details and which item is being submitted,
 *               filled by the student and **frozen into the review** so the
 *               record says what was reviewed, not what the profile says later
 *   Bahagian B  the supervisor's acknowledgment — the two checkboxes that *are*
 *               the verdict — the 0-5 review scores, comments and signature
 *
 * The two checkboxes are the whole decision:
 *
 *   "menerima item ... untuk semakan"          -> received, the milestone settles
 *   "menyemak item ... untuk penambahbaikan"   -> improvement, the student revises
 *                                                 and the next round starts
 *
 * Ticking the second implies the first, so both ticked reads as improvement.
 * Ticking neither is refused: an R1 that declares nothing decides nothing.
 *
 * The scores are evidential. They are stored and printed, and are deliberately
 * absent from every grade calculation — see `is_evidence_only` on the API.
 */

/** The four review items the sheet prints, in its order. */
const REVIEW_ITEMS = [
  { key: 'report_content', label: 'Kandungan (Content)', section: 'report' },
  { key: 'report_format', label: 'Format Laporan (Writing Format)', section: 'report' },
  { key: 'prototype_analysis', label: 'Analisis & Spesifikasi / Alat, Data, Kajian Kes', section: 'prototype' },
  { key: 'prototype_ui', label: 'Antara Muka Pengguna & Kefungsian / Teknik, Metrik Prestasi', section: 'prototype' },
]

const SCORE_OPTIONS = [
  { value: '', label: '—' },
  { value: '0', label: '0' },
  { value: '1', label: '1' },
  { value: '2', label: '2' },
  { value: '3', label: '3' },
  { value: '4', label: '4' },
  { value: '5', label: '5' },
]

/**
 * Draw-or-upload signature, mirroring the form's "[ Ruang Tandatangan ]" box.
 *
 * Both routes produce a PNG-ish file the server stores privately. Drawing is
 * offered first because the paper form expects a signature, not a photograph of
 * one; uploading is the fallback for a trackpad-free machine.
 */
function SignatureBox({ onChange, disabled }) {
  const canvasRef = useRef(null)
  const drawingRef = useRef(false)
  const [mode, setMode] = useState('draw')
  const [hasInk, setHasInk] = useState(false)

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas || mode !== 'draw') return
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.strokeStyle = '#0f172a'
    ctx.lineWidth = 2.5
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }, [mode])

  function pos(e) {
    const canvas = canvasRef.current
    const rect = canvas.getBoundingClientRect()
    const point = e.touches?.[0] ?? e
    return {
      x: ((point.clientX - rect.left) / rect.width) * canvas.width,
      y: ((point.clientY - rect.top) / rect.height) * canvas.height,
    }
  }

  function start(e) {
    if (disabled) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    drawingRef.current = true
    ctx.beginPath()
    ctx.moveTo(x, y)
  }

  function move(e) {
    if (!drawingRef.current || disabled) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    ctx.lineTo(x, y)
    ctx.stroke()
    if (!hasInk) setHasInk(true)
  }

  function end() {
    if (!drawingRef.current) return
    drawingRef.current = false
    const canvas = canvasRef.current
    // `toBlob` is async but the callback fires promptly; resolving through it
    // keeps the encoded bytes identical to what is on screen.
    canvas.toBlob((blob) => {
      if (!blob) return
      onChange(new File([blob], 'signature.png', { type: 'image/png' }), 'drawn')
    }, 'image/png')
  }

  function clear() {
    const canvas = canvasRef.current
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    setHasInk(false)
    onChange(null, 'drawn')
  }

  return (
    <div>
      <div className="mb-2 flex items-center gap-3 text-xs">
        <label className="flex items-center gap-1.5">
          <input
            type="radio"
            checked={mode === 'draw'}
            onChange={() => { setMode('draw'); onChange(null, 'drawn') }}
            disabled={disabled}
          />
          Tandatangan (draw)
        </label>
        <label className="flex items-center gap-1.5">
          <input
            type="radio"
            checked={mode === 'upload'}
            onChange={() => { setMode('upload'); onChange(null, 'uploaded') }}
            disabled={disabled}
          />
          Upload image
        </label>
      </div>

      {mode === 'draw' ? (
        <div>
          <canvas
            ref={canvasRef}
            width={480}
            height={140}
            onMouseDown={start}
            onMouseMove={move}
            onMouseUp={end}
            onMouseLeave={end}
            onTouchStart={start}
            onTouchMove={move}
            onTouchEnd={end}
            className="w-full cursor-crosshair touch-none rounded-md border border-dashed border-slate-300 bg-white"
          />
          <div className="mt-1 flex items-center justify-between text-xs text-slate-500">
            <span>{hasInk ? 'Signature captured.' : 'Sign in the box above.'}</span>
            <button
              type="button"
              onClick={clear}
              disabled={disabled}
              className="text-brand-700 hover:underline disabled:text-slate-400"
            >
              Clear
            </button>
          </div>
        </div>
      ) : (
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={disabled}
          onChange={(e) => {
            const file = e.target.files?.[0] ?? null
            onChange(file, 'uploaded')
          }}
          className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0
                     file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium
                     hover:file:bg-slate-200"
        />
      )}
    </div>
  )
}

/**
 * Bahagian A, read-only: what the student submitted for this round.
 *
 * Rendered from `declaration` when the server has one, and from the project and
 * milestone otherwise — a chapter with no declaration still shows the student
 * and title, because an empty header would make the sheet useless as evidence.
 */
function DeclarationBlock({ declaration, project, milestone }) {
  const rows = [
    ['Sesi/Semester', declaration?.session ?? project?.session ?? '—'],
    ['Nama (Name)', declaration?.student_name ?? project?.students?.[0]?.name ?? '—'],
    ['No. Matrik', declaration?.matric ?? project?.students?.[0]?.student_id ?? '—'],
    ['Program', declaration?.programme ?? project?.programme ?? '—'],
    ['Tahun Pengajian', declaration?.year_study ?? '—'],
    ['Tajuk Projek', declaration?.project_title ?? project?.title ?? milestone?.title ?? '—'],
  ]

  return (
    <div className="rounded-md border border-slate-200">
      <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
        Bahagian A — Maklumat Pelajar
      </div>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-1 px-3 py-2 text-sm sm:grid-cols-2">
        {rows.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-3 border-b border-slate-100 py-1 last:border-0">
            <dt className="text-slate-500">{label}</dt>
            <dd className="text-right font-medium text-slate-800">{value}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

/** One past round, collapsed behind a summary line. */
function RoundRow({ round, milestone, defaultOpen = false }) {
  const [open, setOpen] = useState(defaultOpen)
  const [signatureUrl, setSignatureUrl] = useState(null)
  const [showDocument, setShowDocument] = useState(false)
  const milestoneId = milestone.id

  useEffect(() => {
    if (!open || !round.signature?.url || signatureUrl) return
    let revoke = null
    milestoneApi
      .reviewSignature(milestoneId, round.id)
      .then((url) => { revoke = url; setSignatureUrl(url) })
      .catch(() => {})
    return () => { if (revoke) URL.revokeObjectURL(revoke) }
  }, [open, round.signature, round.id, milestoneId, signatureUrl])

  const improvement = round.verdict === 'improvement'

  return (
    <li className="rounded-md border border-slate-200">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50"
      >
        <span className="flex items-center gap-2">
          <span className="font-medium text-slate-800">R1 #{round.round_no}</span>
          <Badge tone={improvement ? 'warning' : 'success'}>{round.verdict_label}</Badge>
        </span>
        <span className="text-xs text-slate-500">
          {round.reviewer?.name} · {formatDateTime(round.reviewed_at)}
        </span>
      </button>

      {open && (
        <div className="space-y-3 border-t border-slate-200 px-3 py-3">
          {/*
            The summary below is for scanning a list of rounds. "View document"
            opens the same round laid out as the published Lampiran R1, which is
            what a student is shown and what gets printed or saved as a PDF.
          */}
          <div className="flex justify-end">
            <Button size="sm" variant="ghost" onClick={() => setShowDocument(true)}>
              View document
            </Button>
          </div>

          <table className="w-full text-sm">
            <tbody>
              {REVIEW_ITEMS.map((item) => (
                <tr key={item.key} className="border-b border-slate-100 last:border-0">
                  <td className="py-1 pr-3 text-slate-600">{item.label}</td>
                  <td className="w-16 py-1 text-right font-medium tabular-nums">
                    {round.scores?.[item.key] ?? '—'}
                    <span className="text-slate-400"> / 5</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {round.total_score != null && (
            <p className="text-xs text-slate-500">
              Total {round.total_score} / {round.max_score}
              {round.is_evidence_only && ' — evidential, not part of the grade'}
            </p>
          )}

          {round.comments && (
            <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{round.comments}</p>
          )}

          {signatureUrl && (
            <div>
              <p className="mb-1 text-xs text-slate-500">
                Tandatangan ({round.signature?.type ?? 'uploaded'})
              </p>
              <img src={signatureUrl} alt="Supervisor signature" className="max-h-20 rounded border border-slate-200 bg-white" />
            </div>
          )}

          {showDocument && (
            <MilestoneReviewDocument
              milestone={milestone}
              round={round}
              onClose={() => setShowDocument(false)}
            />
          )}
        </div>
      )}
    </li>
  )
}

/**
 * The reviewer's card: fill in a new R1, and read the rounds already filed.
 *
 * `milestone.can_review_r1` comes from the server, so the card cannot offer a
 * review the API would refuse (nothing submitted, or the proposal milestone,
 * whose verdict belongs to the panel).
 */
export default function MilestoneReviewCard({ milestone, rounds, onDone, isReviewer = false }) {
  /**
   * Collapsed by default.
   *
   * The form is long — two acknowledgment boxes, four score rows, a comment and
   * a signature pad — and on a milestone page the reader usually wants the
   * submission and the history first. Opening it on demand keeps the page
   * scannable and gives the form the whole width when it is opened.
   */
  const [expanded, setExpanded] = useState(false)
  const [ackReceived, setAckReceived] = useState(false)
  const [ackReviewed, setAckReviewed] = useState(false)
  const [scores, setScores] = useState({})
  const [comments, setComments] = useState('')
  const [signature, setSignature] = useState({ file: null, type: 'drawn' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const canReview = Boolean(milestone.can_review_r1)
  const needsReason = ackReviewed && comments.trim().length === 0
  const canSubmit = canReview && (ackReceived || ackReviewed) && !needsReason && !busy

  /**
   * The form is the *reviewer's* tool. A student opening the same milestone gets
   * the history of their reviews — the verdicts, the scores and the reasons they
   * were sent back — but never the inputs, because recording a review of one's
   * own chapter is not something the API would accept and not something the
   * screen should offer.
   */
  const showForm = canReview && isReviewer

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      const payload = {
        ack_received: ackReceived,
        ack_reviewed: ackReviewed,
        comments: comments.trim() || undefined,
        scores: Object.fromEntries(
          Object.entries(scores).filter(([, v]) => v !== '' && v != null)
        ),
        declaration: milestone.declaration ?? undefined,
      }

      const review = await milestoneApi.reviewR1(milestone.id, payload)

      // The signature is attached afterwards: it is optional, and a failure to
      // upload it must not undo a verdict that has already been recorded.
      if (signature.file && review?.id) {
        try {
          await milestoneApi.attachReviewSignature(milestone.id, review.id, signature.file, signature.type)
        } catch {
          setError('The review was saved, but the signature could not be uploaded.')
        }
      }

      setAckReceived(false)
      setAckReviewed(false)
      setScores({})
      setComments('')
      setSignature({ file: null, type: 'drawn' })
      onDone?.()
    } catch (err) {
      setError(err?.message ?? 'The review could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Lampiran R1 — Semakan Penyelia"
        subtitle={
          showForm
            ? "The supervisor's review of this submission"
            : rounds.length > 0
              ? `${rounds.length} review round${rounds.length === 1 ? '' : 's'} recorded`
              : 'The supervisor reviews this chapter on the Lampiran R1 form'
        }
        action={
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            className="flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5
                       text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50
                       focus:outline-none focus:ring-2 focus:ring-brand-500/30"
            aria-expanded={expanded}
          >
            {expanded ? 'Collapse' : showForm ? 'Fill in R1' : 'View R1'}
            <span aria-hidden="true" className={`transition-transform ${expanded ? 'rotate-180' : ''}`}>
              ▾
            </span>
          </button>
        }
      />

      {expanded && (
      <div className="space-y-4">
        {error && (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
        )}

        <DeclarationBlock
          declaration={milestone.declaration}
          project={milestone.project}
          milestone={milestone}
        />

        {/* What the student actually attached. Shown above the form so the
            supervisor can see there is a document to review — the earlier card
            said "nothing to review" while a submitted file was right there. */}
        {(milestone.current_files?.length ?? 0) > 0 && (
          <div className="rounded-md border border-slate-200">
            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
              Submitted for review
            </div>
            <ul className="divide-y divide-slate-100">
              {milestone.current_files.map((file) => (
                <li key={file.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                  <span className="truncate text-slate-700">
                    {file.original_name ?? file.name}
                  </span>
                  <span className="shrink-0 text-xs text-slate-400">
                    revision {file.revision_no}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {showForm ? (
          <>
            {/* Bahagian B — the acknowledgment that decides the milestone. */}
            <div className="rounded-md border border-slate-200">
              <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                Bahagian B — Akuan Semakan oleh Penyelia
              </div>
              <div className="space-y-2 px-3 py-3 text-sm">
                <p className="text-xs font-medium text-slate-600">
                  Mengesahkan bahawa saya telah (confirm that I have):
                </p>

                <label className="flex items-start gap-2">
                  <input
                    type="checkbox"
                    checked={ackReceived}
                    onChange={(e) => setAckReceived(e.target.checked)}
                    disabled={busy}
                    className="mt-0.5"
                  />
                  <span>
                    menerima item projek pelajar untuk semakan
                    <em className="block text-xs text-slate-500">
                      received the student's project item for review — settles this milestone
                    </em>
                  </span>
                </label>

                <label className="flex items-start gap-2">
                  <input
                    type="checkbox"
                    checked={ackReviewed}
                    onChange={(e) => setAckReviewed(e.target.checked)}
                    disabled={busy}
                    className="mt-0.5"
                  />
                  <span>
                    menyemak item projek pelajar untuk penambahbaikan
                    <em className="block text-xs text-slate-500">
                      reviewed the student's project item for improvement — sends it back to revise
                    </em>
                  </span>
                </label>

                {ackReviewed && ackReceived && (
                  <p className="text-xs text-amber-700">
                    Both ticked reads as improvement: asking for improvement implies receipt.
                  </p>
                )}
              </div>
            </div>

            {/* The review scores, 0-5 as the sheet prints them. */}
            <div>
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                Skala Semakan (0 — Tiada Data … 5 — Sangat Baik)
              </p>
              <table className="w-full text-sm">
                <tbody>
                  {REVIEW_ITEMS.map((item) => (
                    <tr key={item.key} className="border-b border-slate-100 last:border-0">
                      <td className="py-1.5 pr-3 text-slate-600">{item.label}</td>
                      <td className="w-20 py-1.5 text-right">
                        <Select
                          value={scores[item.key] ?? ''}
                          disabled={busy}
                          onChange={(e) => setScores((prev) => ({ ...prev, [item.key]: e.target.value }))}
                          className="py-1 text-sm"
                          aria-label={`Score for ${item.label}`}
                        >
                          {SCORE_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                          ))}
                        </Select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <Field label="Ulasan / Komen (Review / Comment)" htmlFor="r1-comments">
              <Textarea
                id="r1-comments"
                rows={3}
                value={comments}
                disabled={busy}
                onChange={(e) => setComments(e.target.value)}
                placeholder={
                  ackReviewed
                    ? 'Required when sending back for improvement — the student needs to know what to change.'
                    : 'Optional when accepting the work.'
                }
              />
            </Field>

            <Field label="Tandatangan (Signature)" hint="Optional — the acknowledgment above is the record">
              <SignatureBox
                disabled={busy}
                onChange={(file, type) => setSignature({ file, type })}
              />
            </Field>

            <div className="flex items-center justify-end gap-2">
              <Button onClick={submit} disabled={!canSubmit}>
                {busy ? 'Recording…' : 'Record R1'}
              </Button>
            </div>

            {!ackReceived && !ackReviewed && (
              <p className="text-right text-xs text-slate-500">
                Tick at least one acknowledgment to record this review.
              </p>
            )}
          </>
        ) : (
          /**
           * Say *why* the form is absent rather than a blanket "nothing to
           * review". Several different causes produced that one sentence before,
           * and a supervisor looking at a submitted chapter could not tell which
           * applied.
           *
           * Order matters: the most specific explanation first. Note the file
           * count is read as an *expression* — an earlier version of this line
           * had it inside quotes, so the branch was a truthy string literal and
           * always fired, printing "not uploaded anything" directly above the
           * list of uploaded files.
           */
          <p className="text-sm text-slate-500">
            {!isReviewer
              ? // The student's view. Saying "you are not the reviewer" to the
                // person who wrote the chapter is technically true and useless;
                // what they need to know is that this is where feedback arrives.
                'Your supervisor reviews this chapter on the Lampiran R1 form. Their verdict, scores and comments will appear here.'
              : milestone.status === 'approved'
                ? 'This milestone has been approved — there is nothing left to review.'
                : milestone.is_proposal
                  ? 'The proposal is decided by the seated panel, not by an R1 review.'
                  : !milestone.uses_r1
                    ? 'Lampiran R1 covers Chapters 1-4 of PSM 1 only.'
                    : (milestone.current_files?.length ?? 0) === 0
                      ? 'The student has not uploaded anything for this milestone yet.'
                      : 'You are not the assigned reviewer for this milestone right now.'}
          </p>
        )}

        {/* Previous rounds, newest first, visible to both parties. */}
        {rounds.length > 0 && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
              R1 rounds ({rounds.length})
            </p>
            <ul className="space-y-2">
              {rounds.map((round, i) => (
                <RoundRow
                  key={round.id}
                  round={round}
                  milestone={milestone}
                  defaultOpen={i === 0}
                />
              ))}
            </ul>
          </div>
        )}
      </div>
      )}
    </Card>
  )
}
