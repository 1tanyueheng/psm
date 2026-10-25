import { useState } from 'react'
import { milestoneApi } from '../api/endpoints'
import { Button, Card, CardHeader, Field, Textarea } from './ui'

/**
 * OSHE — the supervisor's half of a two-party form.
 *
 * Every other milestone is the student's to submit and the supervisor's to
 * review. The OSHE (occupational safety, health and environment) form is
 * different: the student fills it in, and the **supervisor signs it**. The
 * student cannot enter a lab or a site until a signed copy exists, so the
 * milestone is not finished when the student uploads it — it is finished when the
 * signed copy comes back.
 *
 * This card is the return leg. The supervisor downloads the student's copy,
 * signs it on paper (or digitally), and uploads the signed PDF here. The server
 * records it as the next revision of the same milestone and settles it, so the
 * record holds both the filled form and the signed one.
 *
 * Only shown when the student has actually filed something: there is nothing to
 * sign otherwise, and the server refuses it for the same reason.
 */
export default function OsheSignCard({ milestone, onDone }) {
  const [files, setFiles] = useState([])
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const studentFiles = milestone.current_files ?? []
  const hasStudentCopy = studentFiles.length > 0
  const alreadySigned = milestone.status === 'approved'
  const canSign = hasStudentCopy && !alreadySigned && files.length > 0 && !busy

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      await milestoneApi.signOshe(milestone.id, files, comment.trim() || undefined)
      setFiles([])
      setComment('')
      onDone?.()
    } catch (err) {
      setError(err?.message ?? 'The signed form could not be uploaded.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="OSHE Form"
        subtitle="Sign the student's form and return the signed copy"
      />

      <div className="space-y-4">
        {error && (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
        )}

        {alreadySigned && (
          <p className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            The signed OSHE form has been returned — this milestone is complete.
          </p>
        )}

        <div>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
            The student&apos;s form
          </p>
          {hasStudentCopy ? (
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
              {studentFiles.map((file) => (
                <li key={file.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                  <span className="truncate text-slate-700">{file.original_name ?? file.name}</span>
                  <span className="text-xs text-slate-400">revision {file.revision_no}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500">
              Nothing to sign yet — the student has not uploaded the OSHE form.
            </p>
          )}
          {hasStudentCopy && (
            <p className="mt-1 text-xs text-slate-500">
              Download it from the Submission card, sign it, then upload the signed copy below.
            </p>
          )}
        </div>

        {!alreadySigned && (
          <>
            <Field
              label="Signed copy (PDF)"
              hint="The same form, signed. It replaces the student's copy as the current file."
              htmlFor="oshe-signed"
            >
              <input
                id="oshe-signed"
                type="file"
                accept="application/pdf"
                multiple
                disabled={busy || !hasStudentCopy}
                onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0
                           file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium
                           hover:file:bg-slate-200 disabled:opacity-50"
              />
            </Field>

            <Field label="Ulasan (Comment)" hint="Optional" htmlFor="oshe-comment">
              <Textarea
                id="oshe-comment"
                rows={2}
                value={comment}
                disabled={busy}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Anything the student should know about the signed form…"
              />
            </Field>

            <div className="flex justify-end">
              <Button onClick={submit} disabled={!canSign}>
                {busy ? 'Uploading…' : 'Return signed form'}
              </Button>
            </div>
          </>
        )}
      </div>
    </Card>
  )
}
