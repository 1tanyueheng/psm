import { useEffect, useState } from 'react'
import { milestoneApi } from '../api/endpoints'
import { formatDateTime } from '../lib/format'
import uthmLogo from '../assets/uthm-logo.png'

/**
 * Lampiran R1 as the published document — every field the official form carries.
 *
 * Mirrors `form required/supervision chapter 1-4 (R1)/lampiranR1.html` clause for
 * clause (JKPSM/112025, UTHM.FSKTM/UA-31/2025):
 *
 *   doc-ref     UTHM.FSKTM/UA-31/2025 · LAMPIRAN R1
 *   header      crest, FAKULTI SAINS KOMPUTER DAN TEKNOLOGI MAKLUMAT,
 *               PROJEK SARJANA MUDA 1, the long bilingual title
 *   session     Sesi/Semester
 *   BAHAGIAN A  Nama · No. Matrik · Program · Tahun Pengajian · Tajuk Projek ·
 *               Tarikh Penyerahan
 *   tick row    Bab (Chapter) 1 2 3 4 — Kertas Prosiding — Prototaip Sistem
 *   BAHAGIAN B  Nama Penyelia · the two acknowledgments (mengesahkan / menolak)
 *   scale       0-5 reviewing scale
 *   quality 1   KUALITI ITEM ... BAB ATAU PROSIDING: Kandungan, Format Laporan
 *   quality 2   KUALITI ITEM ... PROTOTAIP ATAU KERANGKA: Analisis, Antara Muka
 *   footer      Tarikh · Tandatangan & Cop Rasmi Penyelia
 *
 * Rendered as a centred page (not a sidebar) because that is what the artefact
 * is: a printed form. `window.print()` produces it alone — the app's print
 * stylesheet hides navigation, buttons and the overlay backdrop.
 */

/** The printed 0-5 reviewing scale. */
const SCALE = [
  ['0', 'Tiada Data', 'No Data'],
  ['1', 'Sangat Kurang Memuaskan', 'Very Unsatisfactory'],
  ['2', 'Kurang Memuaskan', 'Unsatisfactory'],
  ['3', 'Sederhana', 'Moderate'],
  ['4', 'Baik', 'Good'],
  ['5', 'Sangat Baik', 'Very Good'],
]

/** Quality table 1 — a chapter in the report, or a proceeding paper. */
const REPORT_ITEMS = [
  { key: 'report_content', ms: 'Kandungan', en: 'Content' },
  { key: 'report_format', ms: 'Format Laporan', en: 'Writing Format' },
]

/** Quality table 2 — a system prototype, or a research framework. */
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

/** A box that shows a tick when the form was marked, and an empty box otherwise. */
function Check({ on }) {
  return (
    <span className="mr-1 inline-block h-3.5 w-3.5 border border-slate-700 text-center align-middle text-[10px] font-bold leading-[13px]">
      {on ? '✓' : ''}
    </span>
  )
}

/** A dotted rule with a value written on it, as the paper form prints it. */
function Field({ label, sub, value }) {
  return (
    <div className="flex items-baseline gap-1 py-0.5">
      <span className="shrink-0">
        {label}
        {sub && <span className="block text-[10px] leading-tight text-slate-600">{sub}</span>}
      </span>
      <span className="min-w-0 flex-1 border-b border-dotted border-slate-500 px-1 text-center font-semibold">
        {value || '\u00a0'}
      </span>
    </div>
  )
}

export default function MilestoneReviewDocument({ milestone, round, onClose }) {
  const [signatureUrl, setSignatureUrl] = useState(null)

  useEffect(() => {
    if (!round?.signature?.url) return undefined
    let revoke = null
    milestoneApi
      .reviewSignature(milestone.id, round.id)
      .then((url) => {
        revoke = url
        setSignatureUrl(url)
      })
      .catch(() => {})
    return () => {
      if (revoke) URL.revokeObjectURL(revoke)
    }
  }, [milestone.id, round?.id, round?.signature])

  useEffect(() => {
    function onKey(e) {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  if (!round) return null

  const d = round.declaration ?? {}
  const scores = round.scores ?? {}
  const received = round.ack_received
  const reviewed = round.ack_reviewed

  // Which item was reviewed. A chapter milestone knows its own number; a
  // proceeding or prototype is inferred from the scores the supervisor entered.
  const chapterNo = /^chapter_(\d)$/.exec(milestone.code ?? '')?.[1] ?? null
  const isProceeding = Boolean(round.declaration?.items?.includes?.('proceeding'))
  const isPrototype = Boolean(
    scores.prototype_analysis != null || scores.prototype_ui != null
  )

  const reviewedAt = round.signature?.signed_at ?? round.reviewed_at

  return (
    <div
      className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 p-4 print:static print:bg-white print:p-0"
      role="dialog"
      aria-modal="true"
      aria-label={`Lampiran R1 — review ${round.round_no}`}
      onClick={onClose}
    >
      <div className="mx-auto max-w-[820px]" onClick={(e) => e.stopPropagation()}>
        <div className="mb-2 flex justify-end gap-2 print:hidden">
          <button
            type="button"
            onClick={() => window.print()}
            className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow hover:bg-slate-50"
          >
            Print / Save as PDF
          </button>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md bg-slate-700 px-3 py-1.5 text-sm font-medium text-white shadow hover:bg-slate-600"
          >
            Close
          </button>
        </div>

        <article className="bg-white px-10 py-8 font-serif text-[12.5px] leading-snug text-black shadow-xl print:shadow-none">
          {/* ---------- doc reference ---------- */}
          <div className="mb-2 text-right text-[11px] font-bold leading-tight">
            UTHM.FSKTM/UA-31/2025
            <br />
            LAMPIRAN R1
          </div>

          {/* ---------- header ---------- */}
          <header className="mb-4 border-b-2 border-[#003366] pb-3 text-center">
            <img src={uthmLogo} alt="Universiti Tun Hussein Onn Malaysia" className="mx-auto h-14" />
            <p className="mt-1 text-[13px] font-bold uppercase text-[#003366]">
              Fakulti Sains Komputer dan Teknologi Maklumat
            </p>
            <p className="text-[13px] font-bold text-[#003366]">PROJEK SARJANA MUDA 1</p>
            <p className="mt-1 text-[12px] font-bold">
              BORANG PENYERAHAN DAN SEMAKAN BAB DALAM LAPORAN, KERTAS PROSIDING &amp;
              PROTOTAIP SISTEM/KERANGKA PENYELIDIKAN
            </p>
            <p className="text-[11px] italic">
              Report Chapter, Proceeding Paper &amp; System Prototype/ Research Framework
              Submission and Reviewing Form
            </p>
          </header>

          {/* ---------- session ---------- */}
          <div className="mb-3">
            <Field label="Sesi/Semester (Session/Semester) :" value={d.session} />
          </div>

          {/* ---------- BAHAGIAN A ---------- */}
          <p className="bg-[#003366] px-2 py-1 text-[12px] font-bold text-white">
            BAHAGIAN A: MAKLUMAT PELAJAR / PART A: STUDENT INFORMATION
          </p>

          <div className="mt-2 grid grid-cols-2 gap-x-6">
            <Field label="Nama (Name) :" value={d.student_name} />
            <Field label="No. Matrik (Matric Num) :" value={d.matric} />
            <Field label="Program (Programme) :" value={d.programme} />
            <Field label="Tahun Pengajian (Current Year of Study) :" value={d.year_study} />
            <div className="col-span-2">
              <Field label="Tajuk Projek (Project Title) :" value={d.project_title ?? milestone.project?.title} />
            </div>
            <Field
              label="Tarikh Penyerahan (Date) :"
              value={reviewedAt ? formatDateTime(reviewedAt).split(',')[0] : null}
            />
          </div>

          {/* ---------- tick row ---------- */}
          <div className="mt-3 border border-slate-500 p-2">
            <p className="mb-1 text-[11.5px] font-bold">
              Sila tandakan pada ITEM YANG DISEMAK (Tick on the REVIEWED ITEM):
            </p>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-[11.5px]">
              <span className="flex items-center gap-1">
                <strong>Bab (Chapter):</strong>
                {['1', '2', '3', '4'].map((n) => (
                  <span key={n} className="flex items-center gap-0.5">
                    <Check on={chapterNo === n} />
                    {n}
                  </span>
                ))}
              </span>
              <span className="flex items-center">
                <Check on={isProceeding} />
                <strong>Kertas Prosiding (Proceeding Paper)</strong>
              </span>
              <span className="flex items-center">
                <Check on={isPrototype} />
                <strong>Prototaip Sistem / Kerangka Penyelidikan</strong>
              </span>
            </div>
          </div>

          {/* ---------- BAHAGIAN B ---------- */}
          <p className="mt-4 bg-[#003366] px-2 py-1 text-[12px] font-bold text-white">
            BAHAGIAN B: AKUAN SEMAKAN OLEH PENYELIA / PART B: SUPERVISOR REVIEW ACKNOWLEDGMENT
          </p>

          <div className="mt-2">
            <Field label="Nama Penyelia (Supervisor Name) :" value={round.reviewer?.name} />
          </div>

          <p className="mt-2 text-[11.5px] font-bold">
            Mengesahkan / Menolak bahawa saya telah (confirm / decline that I have):
          </p>

          <div className="mt-1 space-y-0.5 text-[11.5px]">
            <p>
              <Check on={received} /> menerima item projek pelajar (seperti yang ditandakan di
              atas) untuk semakan{' '}
              <em className="text-[10.5px]">
                (received student&apos;s project item (as indicated above) for review)
              </em>
            </p>
            <p>
              <Check on={reviewed} /> menyemak item projek pelajar (seperti yang ditandakan di
              atas) untuk penambahbaikan{' '}
              <em className="text-[10.5px]">
                (reviewed student&apos;s project item (as indicated above) for improvement)
              </em>
            </p>
          </div>

          {/* ---------- reviewing scale ---------- */}
          <p className="mt-3 text-[11.5px] font-bold">Skala Semakan (Reviewing Scale):</p>
          <table className="mt-1 w-full border-collapse text-center text-[10px]">
            <tbody>
              <tr>
                {SCALE.map(([n]) => (
                  <td key={n} className="border border-slate-500 p-1 font-bold">
                    {n}
                  </td>
                ))}
              </tr>
              <tr>
                {SCALE.map(([n, ms, en]) => (
                  <td key={n} className="border border-slate-500 p-1 align-top">
                    {ms}
                    <span className="block italic">({en})</span>
                  </td>
                ))}
              </tr>
            </tbody>
          </table>

          {/* ---------- quality table 1 ---------- */}
          <p className="mt-3 text-[11px] font-bold uppercase">
            Kualiti Item - Lengkapkan bahagian ini untuk semakan bab dalam laporan atau
            prosiding sahaja
          </p>
          <p className="text-[10px] italic">
            (Item Quality - Complete this section for review on the chapter or proceeding only)
          </p>
          <table className="mt-1 w-full border-collapse text-[11px]">
            <thead>
              <tr>
                <th className="w-10 border border-slate-500 bg-slate-100 p-1 text-left">Bil</th>
                <th className="border border-slate-500 bg-slate-100 p-1 text-left">
                  Item Semakan (Review Item)
                </th>
                <th className="w-24 border border-slate-500 bg-slate-100 p-1">
                  Skor / Score (0 - 5)
                </th>
              </tr>
            </thead>
            <tbody>
              {REPORT_ITEMS.map((item, i) => (
                <tr key={item.key}>
                  <td className="border border-slate-500 p-1">{i + 1}.</td>
                  <td className="border border-slate-500 p-1">
                    {item.ms}
                    <span className="block text-[10px] italic">({item.en})</span>
                  </td>
                  <td className="border border-slate-500 p-1 text-center font-bold">
                    {scores[item.key] ?? ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* ---------- quality table 2 ---------- */}
          <p className="mt-3 text-[11px] font-bold uppercase">
            Kualiti Item - Lengkapkan bahagian ini untuk semakan prototaip sistem atau kerangka
            penyelidikan sahaja
          </p>
          <p className="text-[10px] italic">
            (Item Quality - Complete this section for review on the system prototype or research
            framework only)
          </p>
          <table className="mt-1 w-full border-collapse text-[11px]">
            <thead>
              <tr>
                <th className="w-10 border border-slate-500 bg-slate-100 p-1 text-left">Bil</th>
                <th className="border border-slate-500 bg-slate-100 p-1 text-left">
                  Item Semakan (Review Item)
                </th>
                <th className="w-24 border border-slate-500 bg-slate-100 p-1">
                  Skor / Score (0 - 5)
                </th>
              </tr>
            </thead>
            <tbody>
              {PROTOTYPE_ITEMS.map((item, i) => (
                <tr key={item.key}>
                  <td className="border border-slate-500 p-1">{i + 1}.</td>
                  <td className="border border-slate-500 p-1">
                    {item.ms}
                    <span className="block text-[10px] italic">({item.en})</span>
                  </td>
                  <td className="border border-slate-500 p-1 text-center font-bold">
                    {scores[item.key] ?? ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {round.comments && (
            <div className="mt-3">
              <p className="text-[11px] font-bold">
                Ulasan / Komen / Cadangan (Review / Comment / Suggestion)
              </p>
              <p className="min-h-16 whitespace-pre-line border border-slate-500 p-2 text-[11.5px]">
                {round.comments}
              </p>
            </div>
          )}

          {/* ---------- footer ---------- */}
          <div className="mt-4 flex items-end justify-between gap-6 border-t border-slate-300 pt-2">
            <p className="text-[11.5px]">
              Tarikh (Date) :{' '}
              <span className="inline-block w-32 border-b border-dotted border-slate-500 text-center">
                {reviewedAt ? formatDateTime(reviewedAt).split(',')[0] : ''}
              </span>
            </p>
            <div className="text-center">
              <p className="text-[11px]">
                Tandatangan &amp; Cop Rasmi Penyelia
                <span className="block text-[10px] italic">
                  Supervisor&apos;s Signature &amp; Official Stamp
                </span>
              </p>
              {signatureUrl ? (
                <img
                  src={signatureUrl}
                  alt="Supervisor signature"
                  className="mx-auto h-16 object-contain"
                />
              ) : (
                <div className="h-16 w-56" />
              )}
              <p className="w-56 border-t border-slate-500 pt-0.5 text-[10.5px]">
                {round.reviewer?.name}
              </p>
            </div>
          </div>

          <p className="mt-3 border-t border-slate-200 pt-1 text-[10px] text-slate-600">
            Keputusan (Verdict): <strong>{round.verdict_label}</strong> · R1 #{round.round_no}
            {round.total_score != null && ` · Jumlah ${round.total_score} / ${round.max_score}`}
            {round.is_evidence_only &&
              ' — skor ini bukti semakan, bukan sebahagian daripada gred (evidential only, not part of the grade).'}
          </p>
        </article>
      </div>
    </div>
  )
}
