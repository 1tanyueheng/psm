import { useEffect, useRef, useState } from 'react'
import { milestoneApi } from '../api/endpoints'
import { Badge, Button, Card, CardHeader, Field, Textarea } from './ui'
import { formatDateTime } from '../lib/format'
import MilestoneReviewDocument from './MilestoneReviewDocument'

/**
 * Lampiran R1 — the supervisor's endorsement of a chapter submission.
 *
 * The form is laid out as the published sheet (UTHM.FSKTM/UA-31/2025) is laid
 * out, because it is filled in during a supervision meeting with the printed
 * copy on the table:
 *
 *   Bahagian A  the student's details and the item being reviewed; filled by the
 *               student and **frozen into the review** so the record says what
 *               was reviewed, not what the profile says later
 *   Bahagian B  the supervisor's acknowledgment — the two checkboxes — the 0-5
 *               review scores, comments and signature
 *
 * **Both checkboxes are ticked.** The faculty files R1 once per chapter and
 * endorses it by declaring both halves of the form; the pair together is the
 * approval, not a choice between accept and reject. A single tick is an unsigned
 * form, so the service refuses it and the screen says so in red before anything
 * is submitted.
 *
 * The scores are evidential. They are stored and printed, and are deliberately
 * absent from every grade calculation — see `is_evidence_only` on the API.
 */

/**
 * The four review items the sheet prints, split into the two tables the form
 * prints them in. Items are keyed exactly as `MilestoneReviewService::REVIEW_ITEMS`
 * keys them, so the rows the screen renders are the rows the API stores.
 */
const REPORT_ITEMS = [
  { key: 'report_content', ms: 'Kandungan', en: 'Content' },
  { key: 'report_format', ms: 'Format Laporan', en: 'Writing Format' },
]

const PROTOTYPE_ITEMS = [
  {
    key: 'prototype_analysis',
    ms: 'Analisis & Spesifikasi / Alat, Data, Kajian Kes',
    en: 'Analysis & Specifications / Tool, Data, Case Studies',
  },
  {
    key: 'prototype_ui',
    ms: 'Antara Muka Pengguna & Kefungsian / Teknik, Metrik Prestasi',
    en: 'User Interface & Functionality / Technique, Performance Metric',
  },
]

/** The printed 0-5 reviewing scale: score, Malay label, English label. */
const SCALE = [
  [0, 'Tiada Data', 'No Data'],
  [1, 'Sangat Kurang Memuaskan', 'Very Unsatisfactory'],
  [2, 'Kurang Memuaskan', 'Unsatisfactory'],
  [3, 'Sederhana', 'Moderate'],
  [4, 'Baik', 'Good'],
  [5, 'Sangat Baik', 'Very Good'],
]

/**
 * One printed item-quality table, with the form's own 0-5 radio row per item.
 *
 * The paper form prints six radio buttons per row, so the screen does too. A
 * `Select` would fit in less width but it is a control the form does not have:
 * a supervisor copying from the sheet reads across a row of numbers, not down a
 * dropdown.
 */
function ScoreTable({ title, subtitle, items, scores, onScore, disabled, dimmed }) {
  return (
    <div className={dimmed ? 'opacity-40' : ''}>
      <p className="text-[11px] font-bold uppercase">{title}</p>
      <p className="text-[10px] italic">{subtitle}</p>
      <table className="mt-1 w-full border-collapse text-[11px]">
        <thead>
          <tr>
            <th className="w-9 border border-slate-400 bg-slate-100 p-1 text-left">Bil</th>
            <th className="border border-slate-400 bg-slate-100 p-1 text-left">
              Item Semakan (Review Item)
            </th>
            <th className="w-52 border border-slate-400 bg-slate-100 p-1">
              Skor / Score (0 - 5)
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item, i) => (
            <tr key={item.key}>
              <td className="border border-slate-400 p-1 align-top">{i + 1}.</td>
              <td className="border border-slate-400 p-1 align-top">
                {item.ms}
                <span className="block text-[10px] italic">({item.en})</span>
              </td>
              <td className="border border-slate-400 p-1">
                <div className="flex items-center justify-around">
                  {SCALE.map(([n]) => (
                    <label key={n} className="flex items-center gap-0.5">
                      <input
                        type="radio"
                        name={`r1-${item.key}`}
                        checked={String(scores[item.key] ?? '') === String(n)}
                        onChange={() => onScore(item.key, n)}
                        disabled={disabled}
                        aria-label={`${item.ms} — ${n}`}
                      />
                      {n}
                    </label>
                  ))}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

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
              {[...REPORT_ITEMS, ...PROTOTYPE_ITEMS].map((item) => (
                <tr key={item.key} className="border-b border-slate-100 last:border-0">
                  <td className="py-1 pr-3 text-slate-600">
                    {item.ms}
                    <span className="block text-[11px] italic text-slate-400">({item.en})</span>
                  </td>
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
 * The reviewer's card: file the R1 once, and read the one already filed.
 *
 * `milestone.can_review_r1` comes from the server, so the card cannot offer a
 * review the API would refuse (nothing submitted, the proposal milestone whose
 * verdict belongs to the panel, or a chapter whose R1 is already filed).
 *
 * **Both acknowledgement boxes must be ticked.** The faculty files Lampiran R1
 * once per chapter and endorses it by declaring both halves of the form; a
 * single tick is not an endorsement, so the form refuses to record one and says
 * so in red.
 */
export default function MilestoneReviewCard({ milestone, rounds, onDone, isReviewer = false }) {
  /**
   * Collapsed by default.
   *
   * The form is long — two acknowledgment boxes, four score rows, a comment and
   * a signature pad — and on a milestone page the reader usually wants the
   * submission and the filed form first. Opening it on demand keeps the page
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
  /** Set once the reader has tried to record with an incomplete tick. */
  const [triedIncomplete, setTriedIncomplete] = useState(false)

  /**
   * "Sila tandakan pada ITEM YANG DISEMAK" — the top row of the printed form.
   *
   * Pre-selected from the milestone rather than left blank: a chapter milestone
   * knows its own number, and the system demo is the prototype review. The
   * supervisor is still free to change it, which is what the printed row is for.
   */
  const milestoneChapterNo = /^chapter_(\d)$/.exec(milestone.code ?? '')?.[1] ?? null
  const [chapterNo, setChapterNo] = useState(milestoneChapterNo)
  const [isProceeding, setIsProceeding] = useState(
    Boolean(milestone.declaration?.items?.includes?.('proceeding'))
  )
  const [isPrototype, setIsPrototype] = useState(
    milestone.code === 'demo' || Boolean(milestone.declaration?.items?.includes?.('prototype'))
  )

  /** Which of the two printed tables the ticked items call for. */
  const isReportItem = chapterNo !== null || isProceeding

  const declaration = milestone.declaration
  /**
   * The supervisor's own name, from the declaration block the resource builds
   * from the student's active supervision — the project payload has no
   * `supervisor` relation, so reading it produced a blank line where the official
   * form prints the endorser's name.
   */
  const supervisorName = declaration?.supervisor_name ?? '_______________'

  /** Bahagian A's "Tarikh Penyerahan" — when the student filed, not today. */
  const submittedLabel = milestone.submitted_at
    ? new Date(milestone.submitted_at).toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
      })
    : '—'

  const todayLabel = new Date().toLocaleDateString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
  })

  function setScore(key, value) {
    // Radios cannot be unticked, so a second click on the chosen value clears it
    // — the form's rows may legitimately be left unscored, and a blank is not a
    // zero on this scale (0 is "Tiada Data").
    setScores((prev) => {
      if (String(prev[key] ?? '') === String(value)) {
        const next = { ...prev }
        delete next[key]
        return next
      }
      return { ...prev, [key]: value }
    })
  }

  const canReview = Boolean(milestone.can_review_r1)
  const bothTicked = ackReceived && ackReviewed
  const incomplete = !bothTicked
  const canSubmit = canReview && bothTicked && !busy

  /**
   * The form is the *reviewer's* tool. A student opening the same milestone gets
   * the filed R1 — the verdicts, the scores and the comment — but never the
   * inputs, because recording a review of one's own chapter is not something the
   * API would accept and not something the screen should offer.
   */
  const showForm = canReview && isReviewer

  async function submit() {
    /**
     * The red prompt is the primary guard, but the button is also disabled —
     * this branch exists so a keyboard or programmatic submit still gets the
     * message rather than a silent no-op.
     */
    if (incomplete) {
      setTriedIncomplete(true)
      return
    }

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
      setTriedIncomplete(false)
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
            ? "The supervisor's endorsement of this chapter"
            : rounds.length > 0
              ? 'Lampiran R1 filed'
              : 'The supervisor endorses this chapter on the Lampiran R1 form'
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
          /**
           * The form is laid out as the published sheet lays it out, not as a
           * generic CRUD form: the document reference and the faculty header,
           * the two blue section bars, the scale table printed above the scores,
           * and one row of 0-5 radio buttons per item exactly where the paper
           * form puts them. A supervisor fills this in during a meeting with the
           * student, with the printed R1 beside them, so matching the print is
           * the point — the earlier version was a stack of `Select` dropdowns
           * with the sections renamed, and it did not read as the same document.
           *
           * What the app adds is the part paper cannot: the live red prompt, the
           * signature pad, and the fact that recording it moves the milestone.
           */
          <div className="rounded-md border border-slate-300 bg-white">
            {/* ---------- doc reference + header, as printed ---------- */}
            <div className="border-b border-slate-200 px-4 pt-3">
              <p className="text-right text-[11px] font-bold leading-tight">
                UTHM.FSKTM/UA-31/2025
                <br />
                LAMPIRAN R1
              </p>
              <div className="border-b-2 border-[#003366] pb-2 text-center">
                <p className="text-[13px] font-bold uppercase text-[#003366]">
                  Universiti Tun Hussein Onn Malaysia
                </p>
                <p className="text-[12px] font-bold uppercase text-[#003366]">
                  Fakulti Sains Komputer dan Teknologi Maklumat
                </p>
                <p className="mt-1 text-[12px] font-bold text-[#003366]">Projek Sarjana Muda 1</p>
                <p className="mt-1 text-[11px] font-bold">
                  BORANG PENYERAHAN DAN SEMAKAN BAB DALAM LAPORAN, KERTAS PROSIDING &amp;
                  PROTOTAIP SISTEM/KERANGKA PENYELIDIKAN
                </p>
                <p className="text-[10px] italic">
                  Report Chapter, Proceeding Paper &amp; System Prototype/ Research Framework
                  Submission and Reviewing Form
                </p>
              </div>
            </div>

            <div className="space-y-3 px-4 py-3">
              {/* ---------- Sesi / Semester ---------- */}
              <div className="text-xs">
                <p className="font-bold">Sesi/Semester (Session/Semester):</p>
                <p className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                  {declaration?.session ?? milestone.project?.academic_session ?? '—'}
                </p>
              </div>

              {/* ---------- BAHAGIAN A ---------- */}
              <p className="bg-[#003366] px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                Bahagian A: Maklumat Pelajar / Part A: Student Information
              </p>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                {[
                  ['Nama (Name)', declaration?.student_name],
                  ['No. Matrik (Matric Num)', declaration?.matric],
                  ['Program (Programme)', declaration?.programme],
                  ['Tahun Pengajian (Current Year of Study)', declaration?.year_study],
                ].map(([label, value]) => (
                  <div key={label}>
                    <dt className="font-bold">{label}:</dt>
                    <dd className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                      {value ?? '—'}
                    </dd>
                  </div>
                ))}
                <div className="col-span-2">
                  <dt className="font-bold">Tajuk Projek (Project Title):</dt>
                  <dd className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                    {declaration?.project_title ?? milestone.project?.title ?? '—'}
                  </dd>
                </div>
                <div>
                  <dt className="font-bold">Tarikh Penyerahan (Date):</dt>
                  <dd className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                    {submittedLabel}
                  </dd>
                </div>
              </dl>

              {/* ---------- ITEM YANG DISEMAK ---------- */}
              <div className="border border-slate-300 p-2 text-xs">
                <p className="text-[11px] font-bold">
                  Sila tandakan pada ITEM YANG DISEMAK (Tick on the REVIEWED ITEM):
                </p>
                <div className="mt-1 flex flex-wrap items-center gap-x-5 gap-y-1">
                  <span className="flex items-center gap-1">
                    <strong>Bab (Chapter):</strong>
                    {['1', '2', '3', '4'].map((n) => (
                      <label key={n} className="flex items-center gap-0.5">
                        <input
                          type="radio"
                          name="r1-chapter"
                          checked={chapterNo === n}
                          onChange={() => setChapterNo(n)}
                          disabled={busy}
                        />
                        {n}
                      </label>
                    ))}
                  </span>
                  <label className="flex items-center gap-1">
                    <input
                      type="checkbox"
                      checked={isProceeding}
                      onChange={(e) => setIsProceeding(e.target.checked)}
                      disabled={busy}
                    />
                    <strong>Kertas Prosiding (Proceeding Paper)</strong>
                  </label>
                  <label className="flex items-center gap-1">
                    <input
                      type="checkbox"
                      checked={isPrototype}
                      onChange={(e) => setIsPrototype(e.target.checked)}
                      disabled={busy}
                    />
                    <strong>Prototaip Sistem / Kerangka Penyelidikan</strong>
                  </label>
                </div>
              </div>

              {/* ---------- BAHAGIAN B ---------- */}
              <p className="bg-[#003366] px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                Bahagian B: Akuan Semakan oleh Penyelia / Part B: Supervisor Review Acknowledgment
              </p>

              <div className="text-xs">
                <p className="font-bold">Nama Penyelia (Supervisor Name):</p>
                <p className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                  {supervisorName}
                </p>
              </div>

              <p className="text-[11px] font-bold">
                Mengesahkan / Menolak bahawa saya telah (confirm / decline that I have):
              </p>

              <div className="space-y-1.5 text-xs">
                <label className="flex items-start gap-2">
                  <input
                    type="checkbox"
                    checked={ackReceived}
                    onChange={(e) => setAckReceived(e.target.checked)}
                    disabled={busy}
                    className="mt-0.5"
                    aria-invalid={triedIncomplete && !ackReceived}
                  />
                  <span>
                    menerima item projek pelajar (seperti yang ditandakan di atas) untuk semakan{' '}
                    <em className="text-[10.5px] text-slate-500">
                      (received student&apos;s project item (as indicated above) for review)
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
                    aria-invalid={triedIncomplete && !ackReviewed}
                  />
                  <span>
                    menyemak item projek pelajar (seperti yang ditandakan di atas) untuk
                    penambahbaikan{' '}
                    <em className="text-[10.5px] text-slate-500">
                      (reviewed student&apos;s project item (as indicated above) for improvement)
                    </em>
                  </span>
                </label>
              </div>

              {/*
                Both boxes, or nothing. Lampiran R1 is filed once per chapter and
                the two declarations together are the endorsement, so one tick is
                not a weaker approval — it is an unsigned form. The alert is red
                and always visible while a box is unticked, not only after a
                failed submit, so the supervisor is told before they fill in four
                score rows.
              */}
              {incomplete ? (
                <p
                  role="alert"
                  className="rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700"
                >
                  <strong>Tick both boxes.</strong> Lampiran R1 is submitted once: both
                  declarations together are the endorsement. Ticking only one will not record
                  the review.
                  {triedIncomplete && (
                    <span className="mt-1 block font-normal">
                      Nothing was saved. Tick the box that is still empty and try again.
                    </span>
                  )}
                </p>
              ) : (
                <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700">
                  Both declarations ticked — recording this will endorse the chapter and settle
                  the milestone.
                </p>
              )}

              {/* ---------- Skala Semakan, as printed ---------- */}
              <p className="text-[11px] font-bold">Skala Semakan (Reviewing Scale):</p>
              <table className="w-full border-collapse text-center text-[10px]">
                <tbody>
                  <tr>
                    {SCALE.map(([n]) => (
                      <th key={n} className="border border-slate-400 bg-slate-100 p-1">
                        {n}
                      </th>
                    ))}
                  </tr>
                  <tr>
                    {SCALE.map(([n, ms, en]) => (
                      <td key={n} className="border border-slate-400 p-1 align-top">
                        {ms}
                        <span className="block italic">({en})</span>
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>

              {/* ---------- quality tables, one radio row per item ---------- */}
              <ScoreTable
                title="Kualiti Item - Lengkapkan bahagian ini untuk semakan bab dalam laporan atau prosiding sahaja"
                subtitle="(Item Quality - Complete this section for review on the chapter or proceeding only)"
                items={REPORT_ITEMS}
                scores={scores}
                onScore={setScore}
                disabled={busy}
                dimmed={!isReportItem && isPrototype}
              />

              <ScoreTable
                title="Kualiti Item - Lengkapkan bahagian ini untuk semakan prototaip sistem atau kerangka penyelidikan sahaja"
                subtitle="(Item Quality - Complete this section for review on the system prototype or research framework only)"
                items={PROTOTYPE_ITEMS}
                scores={scores}
                onScore={setScore}
                disabled={busy}
                dimmed={isReportItem && !isPrototype}
              />

              <Field label="Ulasan / Komen (Review / Comment)" htmlFor="r1-comments">
                <Textarea
                  id="r1-comments"
                  rows={3}
                  value={comments}
                  disabled={busy}
                  onChange={(e) => setComments(e.target.value)}
                  placeholder="Optional — a note for the student's record. The endorsement is the two ticks above."
                />
              </Field>

              {/* ---------- signature, as printed ---------- */}
              <div className="grid grid-cols-2 gap-4 border-t border-slate-300 pt-3">
                <div className="text-xs">
                  <p className="font-bold">Tarikh (Date):</p>
                  <p className="mt-0.5 border border-slate-300 bg-slate-50 px-2 py-1">
                    {todayLabel}
                  </p>
                </div>
                <div className="text-xs">
                  <p className="font-bold">
                    Tandatangan Penyelia (Supervisor Signature):
                    <em className="block text-[10px] font-normal text-slate-500">
                      Optional — the acknowledgment above is the record
                    </em>
                  </p>
                  <SignatureBox
                    disabled={busy}
                    onChange={(file, type) => setSignature({ file, type })}
                  />
                </div>
              </div>

              <p className="text-[10.5px] text-slate-500">
                Cop Rasmi Penyelia (Official Stamp): the stamp is applied to the printed copy;
                this record carries the supervisor&apos;s account and signature.
              </p>

              <div className="flex items-center justify-end gap-2">
                <Button onClick={submit} disabled={!canSubmit}>
                  {busy ? 'Recording…' : 'Record R1'}
                </Button>
              </div>
            </div>
          </div>
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
                'Your supervisor files Lampiran R1 for this chapter. Their endorsement, scores and comments will appear here.'
              : milestone.status === 'approved'
                ? 'Lampiran R1 has already been filed — this chapter is endorsed and settled.'
                : milestone.is_proposal
                  ? 'The proposal is decided by the seated panel, not by an R1 review.'
                  : !milestone.uses_r1
                    ? 'Lampiran R1 covers Chapters 1-4 of PSM 1 only.'
                    : (milestone.current_files?.length ?? 0) === 0
                      ? 'The student has not uploaded anything for this milestone yet.'
                      : 'You are not the assigned reviewer for this milestone right now.'}
          </p>
        )}

        {/* The filed form — there is only ever one, but it is a list so a
            milestone that carries an older send-back row still renders. */}
        {rounds.length > 0 && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
              Lampiran R1 filed
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
