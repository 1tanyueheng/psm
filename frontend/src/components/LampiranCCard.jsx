import { useState } from 'react'
import { milestoneApi } from '../api/endpoints'
import { Badge, Button, Card, CardHeader, Field, Input, Textarea } from './ui'
import { formatDateTime } from '../lib/format'
import SignaturePad from './SignaturePad'

/**
 * Lampiran C — the corrections form a conditional approval obliges.
 *
 * Two parties, two states, one card:
 *
 *   open / returned   the **student** fills Bahagian A-C and files it
 *   filed             the **supervisor** reads it and either signs (approve) or
 *                     returns it with a reason (no signature — a refusal is not
 *                     an attestation)
 *   signed            read-only, with the corrected title confirmed
 *
 * The layout follows the published form (UTHM.FSKTM/UA-34/2025):
 *   A  student particulars + title-defence date + the two examiners
 *   B  previous title / new title
 *   C  examiner comment → correction action, per row, then the student's signature
 *   D  the supervisor's acknowledgment and signature
 *
 * The examiner names and the previous title are prefilled from the project, so
 * the student is correcting a record rather than retyping it — and the previous
 * title is what the panel actually conditionally approved, not something the
 * student can quietly redefine.
 */

const EMPTY_ROW = { comment: '', action: '' }

export default function LampiranCCard({ milestone, isStudentOwner, isReviewer, onDone }) {
  const c = milestone.lampiran_c ?? {}
  const filed = Boolean(c.is_filed)
  const signed = Boolean(c.is_signed)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  // --- the student's form ---
  const [previousTitle, setPreviousTitle] = useState(
    c.previous_title ?? milestone.project?.title ?? ''
  )
  const [newTitle, setNewTitle] = useState(c.new_title ?? '')
  const [icNumber, setIcNumber] = useState(c.ic_number ?? '')
  const [passportNumber, setPassportNumber] = useState(c.passport_number ?? '')
  const [mobileNumber, setMobileNumber] = useState(c.mobile_number ?? '')
  const [defenseDate, setDefenseDate] = useState(c.defense_date ?? '')
  const [rows, setRows] = useState(
    c.actions?.length ? c.actions : [EMPTY_ROW, EMPTY_ROW, EMPTY_ROW, EMPTY_ROW]
  )
  const [studentSignature, setStudentSignature] = useState(null)

  // --- the supervisor's verdict ---
  const [approve, setApprove] = useState(true)
  const [supervisorComment, setSupervisorComment] = useState('')
  const [supervisorSignature, setSupervisorSignature] = useState(null)

  const examiners = milestone.panel ?? []
  const student = milestone.project?.students?.[0]
  const session = milestone.declaration?.session

  const filledRows = rows.filter((r) => r.comment?.trim() || r.action?.trim())

  const canFile =
    isStudentOwner &&
    !filed &&
    !signed &&
    previousTitle.trim() &&
    newTitle.trim() &&
    filledRows.length > 0 &&
    !busy

  const canSign =
    isReviewer &&
    filed &&
    !signed &&
    (approve || supervisorComment.trim().length > 0) &&
    (approve ? Boolean(supervisorSignature) : true) &&
    !busy

  function updateRow(index, key, value) {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, [key]: value } : row)))
  }

  async function fileForm() {
    setBusy(true)
    setError(null)
    try {
      await milestoneApi.fileLampiranCForm(milestone.id, {
        previous_title: previousTitle.trim(),
        new_title: newTitle.trim(),
        ic_number: icNumber.trim() || undefined,
        passport_number: passportNumber.trim() || undefined,
        mobile_number: mobileNumber.trim() || undefined,
        defense_date: defenseDate || undefined,
        actions: filledRows,
        signature: studentSignature,
        signature_type: 'drawn',
      })
      onDone?.()
    } catch (err) {
      setError(err?.message ?? 'The form could not be filed.')
    } finally {
      setBusy(false)
    }
  }

  async function submitVerdict() {
    setBusy(true)
    setError(null)
    try {
      await milestoneApi.signLampiranC(milestone.id, {
        approve,
        comment: supervisorComment.trim() || undefined,
        signature: approve ? supervisorSignature : null,
        signature_type: 'drawn',
      })
      onDone?.()
    } catch (err) {
      setError(err?.message ?? 'The form could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Lampiran C — Pembetulan Cadangan Projek"
        subtitle="Corrections required by the panel's conditional approval"
        action={
          signed ? (
            <Badge tone="success">Signed</Badge>
          ) : filed ? (
            <Badge tone="warning">Awaiting signature</Badge>
          ) : (
            <Badge tone="neutral">Not filed</Badge>
          )
        }
      />

      <div className="space-y-5">
        {error && (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
        )}

        {/* The panel's reason, from the conditional approval. */}
        {milestone.requires_lampiran_c && (
          <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">
              The panel asked for
            </p>
            <p className="mt-1 text-sm text-amber-900">{milestone.requires_lampiran_c}</p>
          </div>
        )}

        {/* ---------------- Bahagian A ---------------- */}
        <section>
          <SectionHeading ms="BAHAGIAN A: MAKLUMAT PELAJAR" en="PART A: STUDENT INFORMATION" />

          <div className="grid gap-3 sm:grid-cols-2">
            <ReadOnly label="Nama (Name)" value={student?.name} />
            <ReadOnly label="No. Matrik (Matric Num)" value={student?.student_id} />
            <ReadOnly label="Program (Programme)" value={milestone.declaration?.programme} />
            <ReadOnly label="Sesi/Semester" value={session} />

            <Field label="No. KP (IC Num)" htmlFor="c-ic" hint="Leave blank if not applicable">
              <Input
                id="c-ic"
                value={icNumber}
                disabled={filed || signed || busy}
                onChange={(e) => setIcNumber(e.target.value)}
              />
            </Field>
            <Field label="No. Passport (Passport Num)" htmlFor="c-passport">
              <Input
                id="c-passport"
                value={passportNumber}
                disabled={filed || signed || busy}
                onChange={(e) => setPassportNumber(e.target.value)}
              />
            </Field>
            <Field label="No. Tel (Mobile Num)" htmlFor="c-mobile">
              <Input
                id="c-mobile"
                value={mobileNumber}
                disabled={filed || signed || busy}
                onChange={(e) => setMobileNumber(e.target.value)}
              />
            </Field>
            <Field label="Tarikh Pembentangan Tajuk (Date of Title Defense)" htmlFor="c-defence">
              <Input
                id="c-defence"
                type="date"
                value={defenseDate}
                disabled={filed || signed || busy}
                onChange={(e) => setDefenseDate(e.target.value)}
              />
            </Field>

            {examiners.map((e, i) => (
              <ReadOnly
                key={e.user_id ?? i}
                label={`Nama Penilai ${i + 1} (Examiner ${i + 1})`}
                value={e.name}
              />
            ))}
          </div>
        </section>

        {/* ---------------- Bahagian B ---------------- */}
        <section>
          <SectionHeading ms="BAHAGIAN B: TAJUK PROJEK" en="PART B: PROJECT TITLE" />

          <div className="space-y-3">
            <Field
              label="Tajuk Lama (Previous Title)"
              htmlFor="c-prev"
              hint="The title conditionally approved at the title defence"
            >
              <Textarea
                id="c-prev"
                rows={2}
                value={previousTitle}
                disabled={filed || signed || busy}
                onChange={(e) => setPreviousTitle(e.target.value)}
              />
            </Field>

            <Field
              label="Tajuk Baharu (New Title)"
              htmlFor="c-new"
              hint="After discussion with your supervisor — or write 'Tiada Perubahan'"
            >
              <Textarea
                id="c-new"
                rows={2}
                value={newTitle}
                disabled={filed || signed || busy}
                onChange={(e) => setNewTitle(e.target.value)}
              />
            </Field>
          </div>
        </section>

        {/* ---------------- Bahagian C ---------------- */}
        <section>
          <SectionHeading
            ms="BAHAGIAN C: ULASAN PENILAI / PEMBETULAN DIPERLUKAN"
            en="PART C: EXAMINER'S COMMENTS / REQUIRED CORRECTIONS"
          />

          <p className="mb-2 text-xs text-slate-500">
            List the comments received from the examiner and what you changed against each.
          </p>

          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr>
                  <th className="w-10 border border-slate-300 bg-slate-50 p-1.5 text-center">No.</th>
                  <th className="border border-slate-300 bg-slate-50 p-1.5 text-left">
                    Komen Penilai (Examiner&apos;s Comment)
                  </th>
                  <th className="border border-slate-300 bg-slate-50 p-1.5 text-left">
                    Tindakan Pembetulan (Correction Action)
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row, i) => (
                  <tr key={i}>
                    <td className="border border-slate-300 p-1.5 text-center align-top">{i + 1}</td>
                    <td className="border border-slate-300 p-1.5 align-top">
                      <Textarea
                        rows={2}
                        value={row.comment ?? ''}
                        disabled={filed || signed || busy}
                        onChange={(e) => updateRow(i, 'comment', e.target.value)}
                      />
                    </td>
                    <td className="border border-slate-300 p-1.5 align-top">
                      <Textarea
                        rows={2}
                        value={row.action ?? ''}
                        disabled={filed || signed || busy}
                        onChange={(e) => updateRow(i, 'action', e.target.value)}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <button
            type="button"
            disabled={filed || signed || busy}
            onClick={() => setRows((prev) => [...prev, { ...EMPTY_ROW }])}
            className="mt-2 text-xs font-medium text-brand-700 hover:underline disabled:text-slate-400"
          >
            + Add another row
          </button>

          <div className="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
            Saya dengan ini mengakui bahawa cadangan projek di atas telah dipinda/ditambahbaik
            mengikut cadangan penilai seperti yang dinyatakan di atas.
            <em className="mt-0.5 block">
              I hereby confirm that the above project proposal has been revised/improved
              according to the suggestions of the examiner as stated above.
            </em>
          </div>

          {!signed && (
            <div className="mt-3">
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                Tandatangan Pelajar (Student&apos;s Signature)
              </p>
              {filed && c.has_student_signature ? (
                <p className="text-sm text-slate-600">Signed and filed.</p>
              ) : (
                <SignaturePad disabled={filed || busy} onChange={setStudentSignature} />
              )}
            </div>
          )}
        </section>

        {/* ---------------- Bahagian D ---------------- */}
        <section>
          <SectionHeading
            ms="BAHAGIAN D: PENERIMAAN PENYELIA"
            en="PART D: SUPERVISOR ACKNOWLEDGMENT"
          />

          <div className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
            Saya mengesahkan bahawa pelajar telah membuat pembetulan yang diperlukan dan cadangan
            projek/tajuk yang dipinda adalah sesuai untuk diteruskan bagi projek PSM.
            <em className="mt-0.5 block">
              I confirm that the student has made the necessary corrections and that the revised
              title is appropriate for the continuation of the PSM project.
            </em>
          </div>

          {signed ? (
            <div className="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
              <p className="font-medium">Signed by the supervisor</p>
              <p className="text-xs">
                {formatDateTime(c.signed_at)} — the corrected title is confirmed and the project now
                carries it.
              </p>
            </div>
          ) : isReviewer && filed ? (
            <div className="mt-3 space-y-3">
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setApprove(true)}
                  className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition ${
                    approve
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                      : 'border-slate-200 text-slate-600 hover:border-slate-300'
                  }`}
                >
                  Accept and sign
                </button>
                <button
                  type="button"
                  onClick={() => setApprove(false)}
                  className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition ${
                    !approve
                      ? 'border-rose-500 bg-rose-50 text-rose-800'
                      : 'border-slate-200 text-slate-600 hover:border-slate-300'
                  }`}
                >
                  Return for correction
                </button>
              </div>

              {approve ? (
                <div>
                  <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                    Tandatangan Penyelia (Supervisor&apos;s Signature)
                  </p>
                  <SignaturePad disabled={busy} onChange={setSupervisorSignature} />
                  <p className="mt-1 text-xs text-slate-500">
                    Signing approves the corrections and confirms the new title.
                  </p>
                </div>
              ) : (
                <p className="text-xs text-slate-500">
                  Returning the form does not sign it — say what still needs correcting.
                </p>
              )}

              <Field
                label={approve ? 'Ulasan (Comment)' : 'Reason for returning'}
                htmlFor="c-sup-comment"
                hint={approve ? 'Optional' : 'Required'}
              >
                <Textarea
                  id="c-sup-comment"
                  rows={2}
                  value={supervisorComment}
                  disabled={busy}
                  onChange={(e) => setSupervisorComment(e.target.value)}
                  placeholder={
                    approve
                      ? 'Anything to note about the corrections…'
                      : 'What still needs correcting?'
                  }
                />
              </Field>

              <div className="flex justify-end">
                <Button onClick={submitVerdict} disabled={!canSign}>
                  {busy ? 'Submitting…' : approve ? 'Sign and approve' : 'Return to student'}
                </Button>
              </div>
            </div>
          ) : (
            <p className="mt-3 text-sm text-slate-500">
              {filed
                ? 'Awaiting the supervisor\'s signature.'
                : 'This section is completed by your supervisor after you file the form.'}
            </p>
          )}
        </section>

        {filed && (
          <p className="border-t border-slate-200 pt-2 text-xs text-slate-500">
            Filed {formatDateTime(c.filed_at)}
            {c.has_student_signature && ' · student signed'}
            {signed && ` · supervisor signed ${formatDateTime(c.signed_at)}`}
          </p>
        )}

        {canFile && (
          <div className="flex justify-end">
            <Button onClick={fileForm} disabled={!canFile}>
              {busy ? 'Filing…' : 'File Lampiran C'}
            </Button>
          </div>
        )}

        {isStudentOwner && !filed && !signed && !canFile && (
          <p className="text-right text-xs text-slate-500">
            Fill in both titles, at least one correction row, and your signature to file.
          </p>
        )}
      </div>
    </Card>
  )
}

function SectionHeading({ ms, en }) {
  return (
    <div className="mb-2 bg-[#003366] px-3 py-1.5 text-sm font-bold text-white">
      {ms}
      <span className="block text-xs font-normal opacity-90">{en}</span>
    </div>
  )
}

function ReadOnly({ label, value }) {
  return (
    <div>
      <p className="mb-1 text-sm font-bold text-slate-700">{label}</p>
      <p className="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-2 text-sm text-slate-800">
        {value || '—'}
      </p>
    </div>
  )
}
