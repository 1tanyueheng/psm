import { useCallback, useEffect, useMemo, useState } from 'react'
import { lecturerApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  DataTable,
  EmptyState,
  ErrorState,
  Input,
  PageHeader,
  Select,
  Spinner,
  StatCard,
  Td,
} from '../../components/ui'
import { formatDateTime, formatMark, formatPercent } from '../../lib/format'

/**
 * Module 1/4 — the lecturer's coursework screen.
 *
 * This is the lecturer's *only* screen, and that is the design rather than a
 * gap: a lecturer marks four coursework items for one section of a service
 * course that runs alongside PSM 1. They have no project, no milestones and no
 * panel of their own (`Role::worksOnPsm()` is false for them), so the app gives
 * them one page and no navigation to anything else.
 *
 * ## The distinction this screen is built around
 *
 * An unkeyed component is **not** a zero, and the difference is load bearing.
 * The four items are summed into a single coursework component of the PSM 1
 * aggregate, so a row missing its quiz has *no total at all* — while the same
 * row with a 0 in the quiz has a real total that is five marks short, which
 * reads as a student who failed the quiz. A lecturer part-way through marking
 * would therefore fail a whole section by accident if a blank box were stored
 * as 0.
 *
 * So: a blank box stays blank and travels as `null`; the total shows "—" until
 * every item is keyed; and the row says "Incomplete" rather than showing the
 * partial sum. The backend agrees — `LecturerMark::total()` returns null unless
 * `isComplete()` — and this screen never invents a number the server declined
 * to give.
 *
 * ## Why marks save per row
 *
 * A marking sheet is filled in one student at a time, out of a paper mark book,
 * and a lecturer will be interrupted. One Save per row means a mistake or a
 * dropped connection costs one student's entry rather than the whole section's,
 * and the per-row result tells them exactly which entry to retry.
 */

/**
 * The four components, with their maxima.
 *
 * Hardcoded rather than fetched, because they are a faculty decision and not a
 * setting: `config/psm.php` declares the same four with the same maxima and
 * comments that they are fixed. The values are mirrored here so an input can
 * carry `max`, `step` and a client-side bound *before* the first save — the
 * server's validation is still the authority, and its message is what a
 * rejected save displays.
 *
 * If the faculty ever changes the split, this list and `config/psm.php` move
 * together. The roster response also carries a `components` array, which is
 * what the read path uses; this constant exists for the write path and for
 * bounding the inputs.
 */
const COMPONENTS = [
  { key: 'quiz', label: 'Quiz', max: 5 },
  { key: 'assignment_c', label: 'Assignment-C', max: 12 },
  { key: 'assignment_p', label: 'Assignment-P', max: 9 },
  { key: 'assignment_a', label: 'Assignment-A', max: 9 },
]

/** The coursework total. Also a faculty constant — see the note above. */
const MAX_TOTAL = COMPONENTS.reduce((sum, c) => sum + c.max, 0)

/**
 * Turn a student's stored marks into the strings the inputs hold.
 *
 * `null` becomes `''` rather than `'0'`. This is the whole not-entered-versus-
 * zero distinction at the data layer: a blank input is the only representation
 * of "nobody has keyed this", and defaulting it to `'0'` here would key a real
 * zero for every student in the section the first time the page rendered.
 */
function formFromMarks(marks) {
  return Object.fromEntries(
    COMPONENTS.map(({ key }) => [key, marks?.[key] == null ? '' : String(marks[key])]),
  )
}

/**
 * Turn the typed strings back into a payload.
 *
 * An empty string — or a box holding only whitespace — becomes `null`, which is
 * the API's word for "not entered". Everything else goes as a number so the
 * server's `numeric` rule sees a number rather than a string.
 */
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
 * `type="number"` with `max` stops the spinner and marks the field invalid, but
 * it does not stop a typed 40 being submitted — and a native tooltip is a poor
 * way to explain "Quiz is out of 5". Validating here means the common mistake
 * is caught without a round trip, while anything more subtle still reaches the
 * server, whose message is authoritative and is never swallowed.
 *
 * Returns null when the row is fine.
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

export default function LecturerDashboard() {
  const { user, hasRole } = useAuth()
  const { selectedId, semesters, selected, selectSemester } = useSemesters()

  const [section, setSection] = useState(null)
  const [students, setStudents] = useState([])

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  /**
   * The typed values, keyed by student profile id.
   *
   * Held separately from `students` so a save in flight cannot be clobbered by
   * a reload: the roster response is the server's truth, while this is what the
   * lecturer is currently typing. They are merged at render.
   */
  const [drafts, setDrafts] = useState({})

  /**
   * Per-row save state, keyed by student id.
   *
   * Each entry is `{ saving, message, tone }`. Per row rather than one page-wide
   * flag because saves are independent — one failing must not make the row
   * beside it look busy or broken.
   */
  const [rows, setRows] = useState({})

  // --- Load the section and its roster ---------------------------------
  const load = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const roster = await lecturerApi.roster(
        selectedId == null ? undefined : { semester_id: selectedId },
      )

      setSection(roster?.section ?? null)
      setStudents(Array.isArray(roster?.students) ? roster.students : [])
      setDrafts({})
      setRows({})
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [selectedId])

  useEffect(() => {
    load()
  }, [load])

  // --- Derived totals ---------------------------------------------------
  const stats = useMemo(() => {
    const complete = students.filter((s) => s.complete).length
    return { total: students.length, complete, outstanding: students.length - complete }
  }, [students])

  const updateDraft = (studentId, key, value) => {
    setDrafts((prev) => ({ ...prev, [studentId]: { ...prev[studentId], [key]: value } }))
  }

  /** One row's state, with the not-yet-touched defaults filled in. */
  const rowState = (studentId) => rows[studentId] ?? { saving: false, message: null, tone: null }

  /**
   * Save one student's marks.
   *
   * Three things happen in a specific order, and each is deliberate:
   *
   *  1. A client-side bound is checked first, so an obviously wrong value never
   *     makes the round trip. The typed value is *kept* either way.
   *  2. A 422 from the server is displayed verbatim. The server knows which
   *     component is out of range and says so by name, and replacing that with
   *     a generic "could not save" would throw away the only useful sentence.
   *  3. On failure the draft is left exactly as typed — the lecturer keeps
   *     their number, fixes it, and presses Save again. Clearing the box back to
   *     the stored value would destroy the work and hide the mistake.
   */
  async function save(student) {
    const form = drafts[student.id] ?? formFromMarks(student.marks)
    const invalid = clientError(form)

    if (invalid) {
      setRows((prev) => ({
        ...prev,
        [student.id]: { saving: false, message: invalid, tone: 'error' },
      }))
      return
    }

    setRows((prev) => ({ ...prev, [student.id]: { saving: true, message: null, tone: null } }))

    try {
      const saved = await lecturerApi.saveMarks(student.id, {
        ...payloadFromForm(form),
        // The term the marks belong to. A marks row is per student *per term*,
        // so leaving this off would write to the null-term row instead of the
        // one being displayed.
        semester_id: selectedId ?? undefined,
      })

      /**
       * Trust the server's arithmetic, not a local sum.
       *
       * The response carries the recomputed total, percent and completeness.
       * Recomputing them here would be a second implementation of the same rule
       * and the two would eventually disagree — which is exactly the sort of
       * bug that shows a student one mark and their supervisor another.
       */
      setStudents((prev) =>
        prev.map((s) =>
          s.id === student.id
            ? {
                ...s,
                marks: saved?.marks ?? s.marks,
                total: saved?.total ?? null,
                percent: saved?.percent ?? null,
                complete: Boolean(saved?.complete),
              }
            : s,
        ),
      )

      setRows((prev) => ({
        ...prev,
        [student.id]: { saving: false, message: 'Marks saved.', tone: 'success' },
      }))
    } catch (err) {
      // `err.message` is the server's own sentence — see the note above on why
      // it is shown rather than replaced.
      setRows((prev) => ({
        ...prev,
        [student.id]: {
          saving: false,
          message: err?.message ?? 'The marks could not be saved.',
          tone: 'error',
        },
      }))
    }
  }

  if (loading) return <Spinner label="Loading your section…" />

  if (error) {
    return (
      <>
        <PageHeader title="Coursework marks" />
        <ErrorState error={error} onRetry={load} />
      </>
    )
  }

  /**
   * No section: a normal state, not an error.
   *
   * The admin appoints a section's lecturer, so a lecturer who has just been
   * created has none. Showing an empty roster table here would read as "your
   * section has no students", which is a different and much more alarming fact
   * than "you have not been appointed yet".
   */
  if (!section) {
    return (
      <>
        <PageHeader
          title="Coursework marks"
          subtitle={`Signed in as ${user?.name ?? 'a lecturer'}`}
        />
        <Card>
          <EmptyState
            title="No section assigned yet"
            description="An administrator has not appointed you to a section. Once they do, this page will list your students and let you key their coursework marks."
          />
        </Card>
      </>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={section.name}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-xs text-slate-500">{section.code}</span>
            <span>
              {stats.total} student{stats.total === 1 ? '' : 's'} in your section
            </span>
          </span>
        }
        action={
          /*
           * The term picker is the term the marks are saved against, not merely
           * a filter — see the note on `save`. It is shown only when there is
           * more than one term to choose between, so the common case is not a
           * control that does nothing.
           */
          semesters?.length > 1 ? (
            <Select
              value={selectedId ?? ''}
              onChange={(e) => selectSemester(e.target.value || null)}
              aria-label="Term the marks belong to"
              className="w-48"
            >
              {semesters.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          ) : (
            selected?.name && <Badge tone="neutral">{selected.name}</Badge>
          )
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard
          label="Students"
          value={stats.total}
          hint="In your section"
        />
        <StatCard
          label="Fully marked"
          value={stats.complete}
          tone="good"
          hint={`${stats.outstanding} still to key`}
        />
        <StatCard
          label="Coursework out of"
          value={MAX_TOTAL}
          hint="Quiz 5, assignments 12, 9, 9"
        />
        <StatCard
          label="Not yet fully marked"
          value={stats.outstanding}
          tone={stats.outstanding > 0 ? 'warn' : 'default'}
          hint="A partial set has no total"
        />
      </div>

      <Card>
        <CardHeader
          title="Coursework marks"
          subtitle="Each item is out of its own maximum. Leave a box empty until it has been marked — an empty box is not a zero."
          action={
            hasRole('lecturer') ? (
              <Badge tone="brand">Keyed by you</Badge>
            ) : (
              /*
               * An admin or coordinator sees the same page to correct a mark.
               * Saying so matters: `entered_by` records who typed it, and a
               * correction attributed to the section's lecturer when staff
               * made it would be a false audit trail.
               */
              <Badge tone="warning">Staff correction</Badge>
            )
          }
        />

        {students.length === 0 ? (
          <EmptyState
            title="No students in this section"
            description="A student appears here once an administrator places them in your section. If you expected to see someone, ask them to check the placement."
          />
        ) : (
          <DataTable
            /*
             * Wide by nature: matric, name, programme, four inputs, a total, a
             * percent, a status and a button. `fixed` is off because the widths
             * are left to the content, and the min width is raised so the inputs
             * keep a usable size instead of shattering on a narrow screen.
             */
            minWidth={1100}
            columns={[
              'Matric no.',
              'Student',
              'Programme',
              ...COMPONENTS.map(({ label, max }) => ({
                key: label,
                label: `${label} / ${max}`,
                align: 'right',
              })),
              { key: 'total', label: `Total / ${MAX_TOTAL}`, align: 'right' },
              { key: 'percent', label: 'Percent', align: 'right' },
              'Status',
              'Save',
            ]}
          >
            {students.map((student) => {
              const state = rowState(student.id)
              const form = drafts[student.id] ?? formFromMarks(student.marks)
              const dirty = drafts[student.id] != null

              return (
                <tr key={student.id} className="hover:bg-slate-50/60 align-top">
                  <Td className="font-mono text-xs text-slate-500 whitespace-nowrap">
                    {student.student_id ?? '—'}
                  </Td>

                  <Td>
                    <div className="text-sm font-medium text-slate-800">{student.name}</div>
                    {student.email && (
                      <div className="text-xs text-slate-500 truncate max-w-[180px]">
                        {student.email}
                      </div>
                    )}
                  </Td>

                  <Td className="text-xs text-slate-600 whitespace-nowrap">
                    {student.program_code ?? '—'}
                  </Td>

                  {COMPONENTS.map(({ key, label, max }) => (
                    <Td key={key} align="right">
                      <Input
                        /* The label is carried by the column header, so the
                           input itself needs an accessible name rather than a
                           visible one — a label per box would repeat the
                           header four times down the row. */
                        aria-label={`${label} for ${student.name ?? 'this student'}, out of ${max}`}
                        type="number"
                        inputMode="decimal"
                        min={0}
                        max={max}
                        step="0.01"
                        /*
                         * Bounds the typed characters as well as the value.
                         * "12" is the longest legitimate entry (Assignment-C at
                         * full marks is "12", and a decimal never needs more
                         * than five: "12.00"). The attribute alone would not
                         * stop "99999"; it is a fast first line, with the
                         * client-side bound and the server behind it.
                         */
                        maxLength={5}
                        value={form[key]}
                        onChange={(e) => updateDraft(student.id, key, e.target.value)}
                        className="w-20 text-right tabular-nums"
                        placeholder="—"
                      />
                    </Td>
                  ))}

                  <Td align="right" className="tabular-nums text-sm font-medium text-slate-900">
                    {formatMark(student.total)} / {MAX_TOTAL}
                  </Td>

                  <Td align="right" className="tabular-nums text-sm text-slate-600">
                    {formatPercent(student.percent)}
                  </Td>

                  <Td>
                    {student.complete ? (
                      <Badge tone="success">Complete</Badge>
                    ) : (
                      /*
                       * "Incomplete", not "0 / 35" or "0%" — the row has no
                       * total until every item is keyed, and showing a partial
                       * sum would look like a failing mark.
                       */
                      <Badge tone="warning">Incomplete</Badge>
                    )}
                    {student.marks?.entered_at && (
                      <div className="mt-1 text-[11px] text-slate-400 whitespace-nowrap">
                        {formatDateTime(student.marks.entered_at)}
                        {student.marks.entered_by_name && (
                          <> · {student.marks.entered_by_name}</>
                        )}
                      </div>
                    )}
                  </Td>

                  <Td>
                    <Button
                      size="sm"
                      variant={dirty ? 'primary' : 'secondary'}
                      loading={state.saving}
                      onClick={() => save(student)}
                    >
                      {state.saving ? 'Saving…' : 'Save'}
                    </Button>

                    {state.message && (
                      <p
                        className={`mt-1 text-xs ${
                          state.tone === 'error' ? 'text-rose-600' : 'text-emerald-600'
                        }`}
                        role={state.tone === 'error' ? 'alert' : 'status'}
                      >
                        {state.message}
                      </p>
                    )}
                  </Td>
                </tr>
              )
            })}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="How these marks are counted" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            The four items sum to <span className="font-medium text-slate-800">{MAX_TOTAL}</span>,
            which enters the PSM 1 result as the coursework component.
          </li>
          <li>
            <span className="font-medium text-slate-800">An empty box is not a zero.</span> It means
            the item has not been marked yet, so the student&rsquo;s coursework has no total and
            nothing is published for it. A real zero is a mark you type.
          </li>
          <li>
            Marks save one student at a time. If the server refuses a value it will say which item
            and why, and what you typed stays in the box so you can correct it.
          </li>
        </ul>
      </Card>
    </div>
  )
}
