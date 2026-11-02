import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { lecturerApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  PageHeader,
  Spinner,
  StatCard,
} from '../../components/ui'
import { formatDateTime, formatPercent } from '../../lib/format'

/**
 * One student's coursework, and nothing else.
 *
 * ## Why this is a page rather than a row
 *
 * It used to be a row in the section's table: four number inputs squeezed into a
 * twelve-column sheet that scrolled sideways, with a Save on the end of it. Three
 * things went wrong with that, and all three are the reason this file exists:
 *
 *  1. **The inputs were small.** A decimal mark in a 80 px box beside six other
 *     columns is a target a lecturer misses, and a mis-keyed mark is discovered
 *     by a student.
 *  2. **A save in a row is hard to watch.** The lecturer's attention is already
 *     on a student; a message that appears in the far column of a wide table is
 *     easier to miss than one under the button they just pressed.
 *  3. **There was nowhere to put the explanation.** Why a criterion is out of 8,
 *     what an empty box means, and what the total will be before it is saved —
 *     none of that fits in a row, and all of it belongs beside the entry.
 *
 * The list keeps the part a list is good at: who still needs marking, and in
 * what order to work. This page is the working.
 *
 * ## The rule it must not break
 *
 * **An empty box is not a zero.** A criterion that has not been marked is sent
 * as `null` and stored as `null`; the student's coursework then has *no total*
 * rather than a total that is short by that criterion. `clientError()` and the
 * live total below both honour it, and the server's message is the authority on
 * anything this page gets wrong.
 */

/** The three criteria, mirrored from `config/psm.php`. */
const COMPONENTS = [
  { key: 'affective', label: 'Assignment-Affective', max: 8 },
  { key: 'cognitive', label: 'Assignment-Cognitive', max: 15 },
  { key: 'psychomotor', label: 'Assignment-Psychomotor', max: 7 },
]

const MAX_TOTAL = COMPONENTS.reduce((sum, c) => sum + c.max, 0)

/** Stored marks → the strings the inputs hold. Null becomes '' , never '0'. */
function formFromMarks(marks) {
  return Object.fromEntries(
    COMPONENTS.map(({ key }) => [key, marks?.[key] == null ? '' : String(marks[key])]),
  )
}

/** Typed strings → a payload. Blank becomes `null`, the API's "not entered". */
function payloadFromForm(form) {
  return Object.fromEntries(
    COMPONENTS.map(({ key }) => {
      const raw = String(form[key] ?? '').trim()
      return [key, raw === '' ? null : Number(raw)]
    }),
  )
}

/**
 * Catch what the browser's `max` attribute does not.
 *
 * A native tooltip is a poor way to explain "Cognitive is out of 15", and it is
 * not shown until a submit is attempted. Validating here means the common
 * mistake is caught without a round trip, while anything subtler still reaches
 * the server, whose message is authoritative and is never swallowed.
 */
function clientError(form) {
  for (const { key, label, max } of COMPONENTS) {
    const raw = String(form[key] ?? '').trim()

    if (raw === '') continue

    const value = Number(raw)

    if (!Number.isFinite(value)) {
      return `${label} must be a number, or left blank if it has not been marked.`
    }

    if (value < 0 || value > max) {
      return `${label} must be between 0 and ${max}.`
    }
  }

  return null
}

export default function LecturerMarkStudentPage() {
  const { studentId } = useParams()
  const { hasRole } = useAuth()
  const { selectedId } = useSemesters()

  const [section, setSection] = useState(null)
  const [student, setStudent] = useState(null)

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [form, setForm] = useState({})
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState(null)
  const [failure, setFailure] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const roster = await lecturerApi.roster(
        selectedId == null ? undefined : { semester_id: selectedId },
      )

      const list = Array.isArray(roster?.students) ? roster.students : []
      const found = list.find((s) => String(s.id) === String(studentId)) ?? null

      setSection(roster?.section ?? null)
      setStudent(found)
      setForm(formFromMarks(found?.marks))
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [selectedId, studentId])

  useEffect(() => {
    load()
  }, [load])

  /**
   * The live total, computed from what is on screen.
   *
   * The *saved* total always comes from the server — two implementations of the
   * same arithmetic eventually disagree, and a lecturer seeing a different
   * number from the student is the bug that matters. This one is a preview and
   * says so in the label; it is `null` (no total at all) the moment any
   * criterion is blank, which is the same rule `LecturerMark::total()` applies.
   */
  const preview = useMemo(() => {
    const values = COMPONENTS.map(({ key }) => {
      const raw = String(form[key] ?? '').trim()
      return raw === '' ? null : Number(raw)
    })

    if (values.some((v) => v === null || !Number.isFinite(v))) {
      return { total: null, percent: null, complete: false }
    }

    const total = values.reduce((a, b) => a + b, 0)
    return {
      total: Math.round(total * 100) / 100,
      percent: Math.round((total / MAX_TOTAL) * 10000) / 100,
      complete: true,
    }
  }, [form])

  async function save() {
    const invalid = clientError(form)

    if (invalid) {
      setFailure(invalid)
      setMessage(null)
      return
    }

    setSaving(true)
    setMessage(null)
    setFailure(null)

    try {
      const saved = await lecturerApi.saveMarks(Number(studentId), {
        ...payloadFromForm(form),
        // A marks row is per student *per term*; leaving this off would write to
        // the null-term row instead of the one being displayed.
        semester_id: selectedId ?? undefined,
      })

      /*
       * Trust the server's arithmetic, not the preview. The response carries the
       * recomputed total, percent and completeness, and re-deriving them here
       * would be a second implementation of the same rule.
       */
      setStudent((prev) =>
        prev
          ? {
              ...prev,
              marks: saved?.marks ?? prev.marks,
              total: saved?.total ?? null,
              percent: saved?.percent ?? null,
              complete: Boolean(saved?.complete),
            }
          : prev,
      )

      setMessage(
        saved?.complete
          ? 'Marks saved. This student is now fully marked.'
          : 'Marks saved. The coursework has no total until every criterion is keyed.',
      )
    } catch (err) {
      // `err.message` is the server's own sentence, which names the criterion.
      setFailure(err?.message ?? 'The marks could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="Loading the student…" />

  if (error) {
    return (
      <>
        <PageHeader
          title="Coursework marks"
          action={<BackLink />}
        />
        <ErrorState error={error} onRetry={load} />
      </>
    )
  }

  if (!student) {
    return (
      <>
        <PageHeader title="Student not found" action={<BackLink />} />
        <Card>
          <EmptyState
            title="That student is not in your section"
            description="They may have been moved to another section, or the link may be from an earlier term. Choose the term above and pick them from the list."
          />
        </Card>
      </>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={student.name ?? 'Student'}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-xs text-slate-500">
              {student.student_id ?? '—'}
            </span>
            {student.program_code && <Badge tone="neutral">{student.program_code}</Badge>}
            {section && (
              <span className="text-xs text-slate-500">
                {section.course_code} · {section.code} — {section.name}
              </span>
            )}
            {student.complete ? (
              <Badge tone="success">Complete</Badge>
            ) : (
              <Badge tone="warning">Incomplete</Badge>
            )}
          </span>
        }
        action={<BackLink />}
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard
          label="Saved total"
          value={student.total == null ? '—' : `${student.total} / ${MAX_TOTAL}`}
          hint={student.total == null ? 'No total until every criterion is keyed' : 'From the server'}
        />
        <StatCard
          label="Saved percent"
          value={student.percent == null ? '—' : formatPercent(student.percent)}
          hint="Coursework component"
        />
        <StatCard
          label="On screen"
          value={preview.total == null ? '—' : `${preview.total} / ${MAX_TOTAL}`}
          hint="Updates as you type — not saved yet"
          tone={preview.total == null ? 'default' : 'good'}
        />
        <StatCard
          label="Last keyed"
          value={
            student.marks?.entered_at ? formatDateTime(student.marks.entered_at) : 'Never'
          }
          hint={student.marks?.entered_by_name ?? 'Nobody has keyed this form'}
        />
      </div>

      <Card>
        <CardHeader
          title="Coursework criteria"
          subtitle="Each criterion is out of its own maximum. Leave a box empty until it has been marked — an empty box is not a zero."
          action={
            hasRole('lecturer') ? (
              <Badge tone="brand">Keyed by you</Badge>
            ) : (
              <Badge tone="warning">Staff correction</Badge>
            )
          }
        />

        <div className="space-y-4">
          <div className="grid gap-5 sm:grid-cols-3">
            {COMPONENTS.map(({ key, label, max }) => (
              <Field
                key={key}
                label={label}
                htmlFor={`mark-${key}`}
                hint={`Out of ${max}. Leave empty if not marked.`}
              >
                <div className="flex items-center gap-2">
                  <input
                    id={`mark-${key}`}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    max={max}
                    step="0.01"
                    maxLength={5}
                    value={form[key] ?? ''}
                    onChange={(e) =>
                      setForm((prev) => ({ ...prev, [key]: e.target.value }))
                    }
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-right text-lg tabular-nums
                      focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-100"
                    placeholder="—"
                  />
                  <span className="text-sm text-slate-500 tabular-nums">/ {max}</span>
                </div>
              </Field>
            ))}
          </div>

          <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            {preview.total == null ? (
              <>
                <span className="font-medium">No total yet.</span> Every criterion needs a
                mark before the coursework counts — a blank is not a zero.
              </>
            ) : (
              <>
                <span className="font-medium">
                  On screen: {preview.total} / {MAX_TOTAL}
                </span>{' '}
                ({formatPercent(preview.percent)}). Press Save to record it.
              </>
            )}
          </div>

          {failure && <ErrorState error={{ message: failure }} />}

          {message && (
            <p className="text-sm text-emerald-700" role="status">
              {message}
            </p>
          )}

          <div className="flex flex-wrap items-center gap-3">
            <Button loading={saving} onClick={save}>
              {saving ? 'Saving…' : 'Save marks'}
            </Button>
            <Button
              variant="ghost"
              onClick={() => {
                setForm(formFromMarks(student.marks))
                setFailure(null)
                setMessage(null)
              }}
            >
              Reset to saved
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <CardHeader title="How this is counted" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            The three criteria sum to{' '}
            <span className="font-medium text-slate-800">{MAX_TOTAL}</span>, which enters
            this student&rsquo;s PSM 1 result as the coursework component.
          </li>
          <li>
            <span className="font-medium text-slate-800">A real zero is a mark you type.</span>{' '}
            A student who did not attend is assessed as zero; a student nobody has
            marked has no total at all. The two are different facts and only the
            first is a fail.
          </li>
          <li>
            What you key is recorded against your name and time, so a correction
            by a coordinator later does not read as yours.
          </li>
        </ul>
      </Card>
    </div>
  )
}

function BackLink() {
  return (
    <Link to="/dashboard/lecturer" className="text-sm text-brand-700 hover:underline">
      ← All students
    </Link>
  )
}
