import { Badge, Card, CardHeader } from './ui'
import { formatDateTime } from '../lib/format'
import uthmLogo from '../assets/uthm-logo.png'

/**
 * Lampiran A and B as documents â€” the registration paperwork, read-only.
 *
 * Both were filed *before* this project existed: Lampiran A is the supervisor
 * agreement, Lampiran B the title proposal that created the project and its
 * chain. Their milestones are therefore records, not tasks: the student has
 * nothing left to do here, and the card's job is to show what was filed.
 *
 * The two documents share a header and differ in their parts, exactly as the
 * published forms do:
 *
 *   A  BORANG PERSETUJUAN PENYELIA PSM   (UTHM.FSKTM/UA-11/2017 Pin. 2)
 *      A student particulars Â· B three proposed titles + English-report tick
 *      C the supervisor's acceptance, with the agreed title Â· D for JKPSM
 *
 *   B  BORANG CADANGAN TAJUK PSM          (UTHM.FSKTM/UA-12/2023 Pin. 1)
 *      A student particulars Â· B project details: supervisor, title, project
 *      type, field-of-study declaration, requirements
 *
 * Rendered as a centred page with the crest, so the record looks like the
 * document it stands for rather than a form dump. Print hides the app chrome.
 */
export default function RegistrationDocumentCard({ milestone }) {
  const doc = milestone.registration_document
  const agreement = doc?.agreement
  const project = agreement?.project

  if (!doc || !agreement) return null

  const isA = doc.kind === 'lampiran_a'
  const title = isA ? 'BORANG PERSETUJUAN PENYELIA PSM' : 'BORANG CADANGAN TAJUK PSM'
  const subtitle = isA
    ? 'PSM Supervisor Agreement Form'
    : 'PSM Title Proposal Form'
  const docRef = isA
    ? 'UTHM.FSKTM/UA-11/2017 Pin. 2'
    : 'UTHM.FSKTM/UA-12/2023 Pin. 1'
  const formCode = isA ? 'LAMPIRAN A' : 'LAMPIRAN B'

  return (
    <Card>
      <CardHeader
        title={isA ? 'Lampiran A â€” Supervisor Agreement' : 'Lampiran B â€” Title Proposal'}
        subtitle="Filed at registration Â· recorded as complete"
        action={<Badge tone="success">Filed</Badge>}
      />

      {/*
        A seeded project has no agreement behind it, so the form's agreement
        fields are genuinely absent. Saying so is better than rendering an empty
        document that looks like a data loss â€” the student and title are still
        shown, because the project does hold those.
      */}
      {!doc.has_agreement && (
        <div className="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          <p className="font-medium">No registration agreement on file</p>
          <p className="mt-0.5 text-xs">
            This project was created directly rather than through the Lampiran A/B
            registration flow, so the signed agreement is not in the system. The project
            details below are what it holds.
          </p>
        </div>
      )}

      <div className="mb-3 flex justify-end print:hidden">
        <button
          type="button"
          onClick={() => window.print()}
          className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
        >
          Print / Save as PDF
        </button>
      </div>

      <article className="mx-auto max-w-[760px] bg-white px-8 py-7 font-serif text-[12.5px] leading-snug text-black shadow-sm print:shadow-none">
        <div className="mb-2 text-right text-[11px] font-bold leading-tight">
          JKPSM/112025
          <br />
          {docRef}
          <br />
          {formCode}
        </div>

        <header className="mb-4 border-b-2 border-[#003366] pb-3 text-center">
          <img src={uthmLogo} alt="Universiti Tun Hussein Onn Malaysia" className="mx-auto h-14" />
          <p className="mt-1 text-[13px] font-bold uppercase text-[#003366]">
            Fakulti Sains Komputer dan Teknologi Maklumat
          </p>
          <p className="text-[13px] font-bold text-[#003366]">PROJEK SARJANA MUDA</p>
          <p className="mt-1 text-[12px] font-bold">{title}</p>
          <p className="text-[11px] italic">{subtitle}</p>
        </header>

        <p className="mb-3">
          Sesi/Semester (Session/Semester):{' '}
          <Dotted>{agreement.session}</Dotted>
        </p>

        {/* ---------------- Bahagian A: student particulars ---------------- */}
        <SectionHeading ms="BAHAGIAN A: MAKLUMAT PELAJAR" en="PART A: STUDENT INFORMATION" />

        <div className="grid grid-cols-2 gap-x-6">
          <Row label="Nama (Name)" value={agreement.student_name} wide />
          <Row label="No. Matrik (Matric Num)" value={agreement.student_matric} />
          <Row label="Program (Programme)" value={agreement.programme} />
        </div>

        {/* ---------------- Bahagian B: differs by document ---------------- */}
        {isA ? (
          <>
            <SectionHeading ms="BAHAGIAN B: TAJUK CADANGAN" en="PART B: PROPOSED TITLE" />

            {agreement.proposed_titles.map((t, i) => (
              <div key={i} className="mb-1">
                <Row label={`Tajuk ${i + 1} (Title ${i + 1})`} value={t} wide />
              </div>
            ))}

            <p className="mt-2">
              <Tick on={agreement.english_report} /> Laporan projek akan ditulis dalam Bahasa
              Inggeris <em className="text-[11px]">(The project report will be written in English)</em>
            </p>

            <p className="mt-3 text-[11.5px]">
              Saya dengan ini mengemukakan dan bersetuju dengan tajuk-tajuk yang dicadangkan di
              atas.
              <em className="block text-[10.5px]">
                (I hereby submit and agree to the proposed title(s) above.)
              </em>
            </p>

            <div className="mt-2 grid grid-cols-2 gap-4">
              <Row
                label="Tarikh (Date)"
                value={agreement.student_signed_at ? formatDateTime(agreement.student_signed_at).split(',')[0] : null}
              />
              <div>
                <p className="text-slate-600">Tandatangan Pelajar (Student&apos;s Signature)</p>
                <p className="mt-1 border-b border-dotted border-slate-500 pb-3 text-[10.5px] text-slate-500">
                  signed electronically at registration
                </p>
              </div>
            </div>

            <SectionHeading ms="BAHAGIAN C: PENERIMAAN PENYELIA" en="PART C: SUPERVISOR'S ACKNOWLEDGMENT" />

            <p className="text-[11.5px]">
              Saya (<em>I</em>) <Dotted>{agreement.supervisor_name}</Dotted> bersetuju untuk
              menyelia pelajar ini bagi projek bertajuk:
              <em className="block text-[10.5px]">
                (agreed to supervise this student for the project entitled)
              </em>
            </p>
            <p className="mt-1 border-b border-dotted border-slate-500 pb-2 font-semibold">
              {agreement.agreed_title}
            </p>

            <div className="mt-2 grid grid-cols-2 gap-4">
              <Row
                label="Tarikh (Date)"
                value={agreement.acknowledged_at ? formatDateTime(agreement.acknowledged_at).split(',')[0] : null}
              />
              <div>
                <p className="text-slate-600">Tandatangan &amp; Cop Rasmi Penyelia</p>
                <p className="mt-1 border-b border-dotted border-slate-500 pb-3 text-[10.5px] text-slate-500">
                  acknowledged electronically
                </p>
              </div>
            </div>

            <SectionHeading ms="BAHAGIAN D: UNTUK KEGUNAAN JKPSM" en="PART D: FOR JKPSM USE ONLY" />
            <p className="text-[11px] text-slate-600">
              Received by the PSM Committee at registration. No action required.
            </p>
          </>
        ) : (
          <>
            <SectionHeading ms="BAHAGIAN B: KETERANGAN PROJEK" en="PART B: PROJECT DETAILS" />

            <Row label="Nama Penyelia (Supervisor Name)" value={agreement.supervisor_name} wide />
            <Row label="Tajuk Projek (Project Title)" value={project?.title ?? agreement.agreed_title} wide />

            <div className="mt-1 flex items-baseline gap-2">
              <span className="w-56 shrink-0 text-slate-600">Jenis Projek (Project Type)</span>
              <span className="flex-1 border-b border-dotted border-slate-500 px-1">
                {project?.category === 'system'
                  ? 'Pembangunan (Development)'
                  : project?.category
                    ? 'Kajian (Research)'
                    : 'â€”'}
              </span>
            </div>

            <Row label="Abstrak (Abstract)" value={project?.abstract} wide />
            <Row label="Objektif (Objectives)" value={project?.objectives} wide />
            <Row label="Skop Kajian (Scope)" value={project?.scope} wide />
            <Row label="Batch" value={project?.batch} />
            <Row label="Program" value={project?.program} />

            <div className="mt-3 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-[11px] text-slate-700">
              Registered {agreement.acknowledged_at ? formatDateTime(agreement.acknowledged_at) : 'â€”'} Â·
              this document opened the project and its milestone chain.
            </div>
          </>
        )}

        <p className="mt-4 border-t border-slate-300 pt-1 text-[10px] text-slate-500">
          Recorded from registration agreement #{agreement.id} Â· status {agreement.status}
        </p>
      </article>
    </Card>
  )
}

function SectionHeading({ ms, en }) {
  return (
    <div className="mb-2 mt-4 bg-[#003366] px-2 py-1 text-[12px] font-bold text-white">
      {ms}
      <span className="block text-[10.5px] font-normal opacity-90">{en}</span>
    </div>
  )
}

function Row({ label, value, wide = false }) {
  return (
    <div className={`flex items-baseline gap-2 py-0.5 ${wide ? 'col-span-2' : ''}`}>
      <span className="w-56 shrink-0 text-slate-600">{label}</span>
      <span className="min-w-0 flex-1 border-b border-dotted border-slate-500 px-1">
        {value || '\u00a0'}
      </span>
    </div>
  )
}

function Dotted({ children }) {
  return (
    <span className="inline-block min-w-40 border-b border-dotted border-slate-500 px-1 text-center font-semibold">
      {children || '\u00a0'}
    </span>
  )
}

function Tick({ on }) {
  return (
    <span className="mr-1 inline-block h-3.5 w-3.5 border border-slate-700 text-center align-middle text-[10px] font-bold leading-[13px]">
      {on ? 'âœ“' : ''}
    </span>
  )
}
