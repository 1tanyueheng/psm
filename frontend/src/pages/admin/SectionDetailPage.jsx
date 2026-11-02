import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { sectionApi } from '../../api/endpoints'
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
  Input,
  PageHeader,
  Select,
  Spinner,
  StatCard,
  Textarea,
} from '../../components/ui'

/**
 * One teaching section, and everything that belongs to it.
 *
 * The section list used to carry all of this — the roster count, the teacher
 * picker, a class-list upload and a one-student placement form — which meant a
 * coordinator placing twelve students made twelve trips to the same page and
 * scrolled past three other sections to do it. The list is now a list; this page
 * is the class.
 *
 * ## What lives here, and why it is one page
 *
 * Four decisions about a class are really one decision: **who teaches it**, **who
 * is in it**, **how the roster arrives** and **who might still be added**. They
 * share the same two facts — the section's course and its term — and doing them
 * on one screen means the course is stated once rather than re-derived by four
 * pickers.
 *
 * The course is the load-bearing fact. A term runs two offerings of the same
 * course title, so `S1` alone names nothing: this page always shows which one it
 * is, and the "add a student" picker is drawn from the section's own course for
 * that reason (`show()` computes `candidates` that way).
 *
 * ## Why the roster is a table with per-row actions
 *
 * Removing a student is the operation that goes wrong when it is a side effect:
 * `PUT /students/{id}/section { section_id: null }` is a real, reversible
 * unplacement, not a delete, and it must be one click with no form to fill in —
 * but it must also not be a click that happens by accident, which is why it asks
 * first. The student's marks stay; only their seat changes.
 */

/** The two offerings, mirrored from `Section::courses()`. */
const COURSES = {
  BIC31602: { title: 'Projek Sarjana Muda I', programmes: 'BIP, BIW, BIS, BIM' },
  BIT34002: { title: 'Projek Sarjana Muda I', programmes: 'BIT' },
}

function courseLabel(code) {
  const course = COURSES[code]
  return course ? `${code} — ${course.title}` : code
}

export default function SectionDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { hasRole } = useAuth()
  const { semesters, selectedId } = useSemesters()

  const isAdmin = hasRole('admin')

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      setData(await sectionApi.get(id))
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const section = data?.section
  const students = useMemo(() => data?.students ?? [], [data])
  const candidates = useMemo(() => data?.candidates ?? [], [data])

  const term = semesters?.find((s) => String(s.id) === String(section?.academic_semester_id))

  if (loading) return <Spinner label="Loading the section…" />

  if (error) {
    return (
      <>
        <PageHeader
          title="Section"
          action={
            <Link to="/admin/sections" className="text-sm text-brand-700 hover:underline">
              ← All sections
            </Link>
          }
        />
        <ErrorState error={error} onRetry={load} />
      </>
    )
  }

  if (!section) {
    return (
      <>
        <PageHeader title="Section not found" />
        <Card>
          <EmptyState
            title="That section no longer exists"
            description="It may have been deleted, or the link may be from an earlier term."
          />
        </Card>
      </>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={`${section.course_code ?? 'BIC31602'} · ${section.code} — ${section.name}`}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span>
              {courseLabel(section.course_code)}
              {term ? ` · ${term.name}` : ''}
            </span>
            {section.lecturer ? (
              <Badge tone="success">{section.lecturer.name}</Badge>
            ) : (
              <Badge tone="warning">No lecturer appointed</Badge>
            )}
          </span>
        }
        action={
          <Link to="/admin/sections" className="text-sm text-brand-700 hover:underline">
            ← All sections
          </Link>
        }
      />

      {notice && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </div>
      )}

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Students" value={students.length} hint="Placed in this section" />
        <StatCard
          label="Awaiting a section"
          value={candidates.length}
          hint={`${(data.course?.programmes ?? []).join(', ')} students with none`}
          tone={candidates.length > 0 ? 'warn' : 'default'}
        />
        <StatCard
          label="Marked"
          value={students.filter((s) => s.marked).length}
          hint="From this term's coursework"
        />
        <StatCard
          label="Outstanding"
          value={students.filter((s) => !s.marked).length}
          tone={students.some((s) => !s.marked) ? 'warn' : 'good'}
          hint="Not yet keyed by the lecturer"
        />
      </div>

      <ClassListCard
        section={section}
        termId={section.academic_semester_id ?? selectedId}
        onChanged={async (message) => {
          setNotice(message)
          await load()
        }}
      />

      <AddStudentCard
        section={section}
        candidates={candidates}
        onChanged={async (message) => {
          setNotice(message)
          await load()
        }}
      />

      <RosterCard
        students={students}
        section={section}
        onChanged={async (message) => {
          setNotice(message)
          await load()
        }}
      />

      <TeacherCard
        section={section}
        canEdit={isAdmin}
        onChanged={async (message) => {
          setNotice(message)
          await load()
        }}
      />

      {isAdmin && (
        <DangerCard
          section={section}
          students={students.length}
          onDeleted={() => navigate('/admin/sections')}
          onNotice={setNotice}
        />
      )}
    </div>
  )
}

/**
 * The class list upload, scoped to this section.
 *
 * The server's importer takes `matric, section-code` rows and resolves the code
 * within the **student's own course**, so two things change for a page that knows
 * which section it is:
 *
 *  - the rows need no section column at all — one matric per line is enough,
 *    because the class is already decided by where this page was opened; and
 *  - a row that names a *different* section is reported rather than honoured,
 *    which is the honest behaviour: the file and the page disagree, and the
 *    page is the thing the admin was looking at.
 *
 * Both are achieved by rewriting each parsed row to `matric, <this code>` before
 * sending, and reporting any row that named another section as a warning in the
 * preview. Nothing is invented on the way through.
 */
function ClassListCard({ section, termId, onChanged }) {
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [report, setReport] = useState(null)
  const [error, setError] = useState(null)
  const [dragging, setDragging] = useState(false)
  const inputRef = useRef(null)

  /** Turn whatever arrived into `matric, <this section>` lines. */
  const rows = useMemo(() => {
    const out = []
    const warnings = []

    for (const raw of text.split(/\r?\n/)) {
      const line = raw.trim()

      if (line === '') continue

      // A header row is welcome but not required, so recognise one and skip it
      // rather than reporting it as an unrecognised matric.
      if (/matric|student|section/i.test(line)) continue

      const cells = line.split(/[,;\t]+/).map((c) => c.trim()).filter(Boolean)

      if (cells.length === 0) continue

      const matric = cells[0]
      const named = cells.slice(1).find((c) => /^s\d+$/i.test(c))

      if (named && named.toUpperCase() !== section.code.toUpperCase()) {
        warnings.push(`${matric} names ${named}, but this page is ${section.code}.`)
      }

      out.push(matric)
    }

    return { matrics: out, warnings }
  }, [text, section.code])

  async function upload() {    if (rows.matrics.length === 0) return

    setBusy(true)
    setError(null)
    setReport(null)

    try {
      // `matric, <this code>` — the section is decided by the page.
      //
      // The text goes as a plain string, not a Blob: the endpoint validates the
      // two shapes separately (`csv` is `string`, `file` is `mimes:csv,txt`), and
      // a Blob arrives as a multipart file part, which fails the `string` rule.
      // The distinction is the endpoint's own note on why it accepts a pasted
      // list at all — it arrives in an email body more often than as an upload.
      const payload = rows.matrics.map((m) => `${m},${section.code}`).join('\n')
      const result = await sectionApi.importPlacements(payload, termId)

      setReport(result)
      setText('')
      await onChanged(
        `Class list processed: ${result?.placed ?? 0} placed, ${result?.failed ?? 0} refused.`,
      )
    } catch (err) {
      setError(err?.message ?? 'That class list could not be imported.')
    } finally {
      setBusy(false)
    }
  }

  /**
   * A dropped or chosen file is read as text and appended to the box.
   *
   * It is not uploaded as a file, and that is deliberate: the list is parsed
   * here first so the admin can *see* what will be sent — a class list with a
   * stray header, a semicolon separator, or a second column naming another
   * section is worth catching on screen rather than in a server report.
   */
  function readFile(file) {
    if (!file) return
    const reader = new FileReader()
    reader.onload = () =>
      setText((prev) => {
        const added = String(reader.result ?? '').trim()
        return prev.trim() === '' ? added : `${prev.trimEnd()}\n${added}`
      })
    reader.readAsText(file)
  }

  return (
    <Card>
      <CardHeader
        title="Upload a class list"
        subtitle={`One matric number per line. Every row is placed in ${section.course_code} ${section.code}, because the section is decided by this page.`}
      />

      <div className="space-y-4">
        <div
          onDragOver={(e) => {
            e.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(e) => {
            e.preventDefault()
            setDragging(false)
            readFile(e.dataTransfer.files?.[0])
          }}
          className={
            'rounded-xl border-2 border-dashed p-6 text-center text-sm transition ' +
            (dragging
              ? 'border-brand-400 bg-brand-50 text-brand-800'
              : 'border-slate-300 text-slate-500')
          }
        >
          <p>Drop a .csv or .txt here, or</p>
          <Button
            variant="secondary"
            size="sm"
            className="mt-2"
            onClick={() => inputRef.current?.click()}
          >
            Choose a file
          </Button>
          <input
            ref={inputRef}
            type="file"
            accept=".csv,.txt,text/plain,text/csv"
            className="sr-only"
            onChange={(e) => readFile(e.target.files?.[0])}
          />
        </div>

        <Field
          label="Or paste the list"
          htmlFor="class_list"
          hint="A matric number per line. A second column naming a section is read and checked, but this page's section wins."
        >
          <Textarea
            id="class_list"
            rows={6}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={'AI240001\nAI240002\nAI240003'}
            className="font-mono text-xs"
          />
        </Field>

        {rows.warnings.length > 0 && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p className="font-medium">These rows name a different section:</p>
            <ul className="mt-1 list-disc pl-5">
              {rows.warnings.slice(0, 8).map((w) => (
                <li key={w}>{w}</li>
              ))}
            </ul>
            <p className="mt-1">
              They will be placed here anyway — this page is {section.code}. Check that
              the file is the one you meant.
            </p>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-3">
          <Button
            loading={busy}
            disabled={rows.matrics.length === 0}
            onClick={upload}
          >
            {busy
              ? 'Importing…'
              : `Import ${rows.matrics.length || ''} student${rows.matrics.length === 1 ? '' : 's'}`}
          </Button>
          {rows.matrics.length > 0 && (
            <span className="text-xs text-slate-500">
              {rows.matrics.length} matric number{rows.matrics.length === 1 ? '' : 's'} read
            </span>
          )}
        </div>

        {error && <ErrorState error={{ message: error }} />}

        {report && <ImportReport report={report} />}
      </div>
    </Card>
  )
}

/** The server's per-row report, rendered as the table it actually is. */
function ImportReport({ report }) {
  const rows = report?.rows ?? report?.report ?? []

  if (rows.length === 0) {
    return (
      <p className="text-sm text-slate-600">
        {report?.message ?? 'The import finished.'}
      </p>
    )
  }

  const counts = rows.reduce(
    (acc, r) => {
      acc[r.status] = (acc[r.status] ?? 0) + 1
      return acc
    },
    {},
  )

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {Object.entries(counts).map(([status, n]) => (
          <Badge
            key={status}
            tone={
              status === 'placed'
                ? 'success'
                : status === 'unchanged'
                  ? 'neutral'
                  : 'danger'
            }
          >
            {status}: {n}
          </Badge>
        ))}
      </div>

      <DataTableish rows={rows} />
    </div>
  )
}

function DataTableish({ rows }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200">
      <table className="min-w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-3 py-2">Line</th>
            <th className="px-3 py-2">Matric</th>
            <th className="px-3 py-2">Result</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((r, i) => (
            <tr key={`${r.line ?? i}`}>
              <td className="px-3 py-2 text-slate-500">{r.line ?? '—'}</td>
              <td className="px-3 py-2 font-mono text-xs">{r.student_id ?? r.matric ?? '—'}</td>
              <td className="px-3 py-2">
                <span
                  className={
                    r.status === 'placed'
                      ? 'text-emerald-700'
                      : r.status === 'unchanged'
                        ? 'text-slate-600'
                        : 'text-rose-700'
                  }
                >
                  {r.status}
                </span>
                {r.message ? ` — ${r.message}` : ''}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Add one student from the waiting list.
 *
 * The picker is drawn from `candidates` — students of this course with no
 * section — so it cannot offer a student the save would refuse, and the search
 * matches a matric number as readily as a name.
 */
function AddStudentCard({ section, candidates, onChanged }) {
  const [query, setQuery] = useState('')
  const [chosen, setChosen] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase()

    return candidates
      .filter((s) => {
        if (needle === '') return true
        return (
          String(s.name ?? '').toLowerCase().includes(needle) ||
          String(s.student_id ?? '').toLowerCase().includes(needle)
        )
      })
      .slice(0, 100)
  }, [candidates, query])

  async function add() {
    setBusy(true)
    setError(null)

    try {
      await sectionApi.assignStudent(Number(chosen), section.id)
      setChosen('')
      setQuery('')
      await onChanged('Student added to this section.')
    } catch (err) {
      setError(err?.message ?? 'That student could not be added.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Add a student"
        subtitle={`Only students taking ${section.course_code} who are not in any section yet can be added here.`}
      />

      {candidates.length === 0 ? (
        <EmptyState
          title="Nobody is waiting for a section"
          description={`Every student taking ${section.course_code} has a section already. A student who needs one is placed from the term's enrolment screen.`}
        />
      ) : (
        <div className="space-y-4">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Find" htmlFor="add_search" hint="Name or matric number">
              <Input
                id="add_search"
                type="search"
                value={query}
                onChange={(e) => {
                  setQuery(e.target.value)
                  setChosen('')
                }}
                placeholder="e.g. AI240012 or Aisyah"
              />
            </Field>

            <Field label="Student" htmlFor="add_choice">
              <Select
                id="add_choice"
                value={chosen}
                onChange={(e) => setChosen(e.target.value)}
              >
                <option value="">
                  {matches.length === 0 ? 'No students match' : 'Choose a student…'}
                </option>
                {matches.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.student_id} — {s.name}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          {error && <ErrorState error={{ message: error }} />}

          <Button loading={busy} disabled={chosen === ''} onClick={add}>
            Add to {section.code}
          </Button>
        </div>
      )}
    </Card>
  )
}

/** The roster, with unplace as the only action — and it asks first. */
function RosterCard({ students, section, onChanged }) {
  const [pending, setPending] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function remove(student) {
    setBusy(true)
    setError(null)

    try {
      // `null` means "no section" — an unplacement, not a delete. Their marks
      // stay; only the seat changes.
      await sectionApi.assignStudent(student.id, null)
      setPending(null)
      await onChanged(`${student.student_id} removed from ${section.code}.`)
    } catch (err) {
      setError(err?.message ?? 'That student could not be removed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Roster"
        subtitle="Removing a student frees their seat. The marks their lecturer already keyed are kept."
      />

      {error && (
        <div className="mb-3">
          <ErrorState error={{ message: error }} />
        </div>
      )}

      {students.length === 0 ? (
        <EmptyState
          title="No students yet"
          description="Upload a class list above, or add students one at a time."
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200">
          <table className="min-w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-2">Matric</th>
                <th className="px-3 py-2">Name</th>
                <th className="px-3 py-2">Programme</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {students.map((s) => (
                <tr key={s.id} className="hover:bg-slate-50">
                  <td className="px-3 py-2 font-mono text-xs">{s.student_id}</td>
                  <td className="px-3 py-2">
                    <span className="font-medium text-slate-800">{s.name}</span>
                    {s.email && <div className="text-xs text-slate-500">{s.email}</div>}
                  </td>
                  <td className="px-3 py-2">{s.program_code ?? '—'}</td>
                  <td className="px-3 py-2 text-right">
                    {pending === s.id ? (
                      <span className="inline-flex items-center gap-2">
                        <span className="text-xs text-slate-600">Remove from {section.code}?</span>
                        <Button size="sm" variant="danger" loading={busy} onClick={() => remove(s)}>
                          Remove
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setPending(null)}>
                          Cancel
                        </Button>
                      </span>
                    ) : (
                      <Button size="sm" variant="ghost" onClick={() => setPending(s.id)}>
                        Remove
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

/** The teacher, moved here from the list. Admin only, as the API is. */
function TeacherCard({ section, canEdit, onChanged }) {
  const [options, setOptions] = useState([])
  const [lecturerId, setLecturerId] = useState(
    section.lecturer?.id != null ? String(section.lecturer.id) : '',
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    sectionApi
      .lecturerOptions({ semester_id: section.academic_semester_id })
      .then((list) => {
        if (!cancelled) setOptions(Array.isArray(list) ? list : [])
      })
      .catch(() => {
        if (!cancelled) setOptions([])
      })

    return () => {
      cancelled = true
    }
  }, [section.academic_semester_id])

  useEffect(() => {
    setLecturerId(section.lecturer?.id != null ? String(section.lecturer.id) : '')
  }, [section.lecturer?.id])

  async function save() {
    setBusy(true)
    setError(null)

    try {
      await sectionApi.update(section.id, {
        lecturer_id: lecturerId === '' ? null : Number(lecturerId),
      })
      await onChanged('Teacher updated.')
    } catch (err) {
      setError(err?.message ?? 'That appointment could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Teacher"
        subtitle={
          canEdit
            ? 'The person who keys this section’s coursework marks. A teacher may hold one section of each course.'
            : 'Only an administrator may appoint a section’s teacher.'
        }
      />

      <div className="space-y-4">
        <Field label="Appointed lecturer" htmlFor="teacher_choice">
          <Select
            id="teacher_choice"
            value={lecturerId}
            disabled={!canEdit}
            onChange={(e) => setLecturerId(e.target.value)}
          >
            <option value="">Not appointed</option>
            {options.map((option) => (
              <option
                key={option.value}
                value={option.value}
                /*
                 * Somebody teaching another section **of this course** in this
                 * term is the one combination the server refuses, so it is shown
                 * as taken rather than hidden — the admin can see who is free and
                 * which class the rest are holding. A section of the *other*
                 * course does not count.
                 */
                disabled={
                  option.meta?.section != null &&
                  option.meta.section.id !== section.id &&
                  (option.meta.section.course_code ?? 'BIC31602') ===
                    (section.course_code ?? 'BIC31602')
                }
              >
                {option.label}
                {option.meta?.section && option.meta.section.id !== section.id
                  ? ` — teaches ${option.meta.section.course_code ?? ''} ${option.meta.section.code}`
                  : ''}
              </option>
            ))}
          </Select>
        </Field>

        {error && <ErrorState error={{ message: error }} />}

        {canEdit && (
          <Button loading={busy} onClick={save}>
            Save teacher
          </Button>
        )}
      </div>
    </Card>
  )
}

/** Delete, and it says what will refuse it before it is pressed. */
function DangerCard({ section, students, onDeleted }) {
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function remove() {
    setBusy(true)
    setError(null)

    try {
      await sectionApi.remove(section.id)
      onDeleted()
    } catch (err) {
      setError(err?.message ?? 'That section could not be deleted.')
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Delete this section"
        subtitle="Only an empty section can be deleted. A class with students or marked coursework is a record, not a typo."
      />

      <div className="space-y-3">
        {error && <ErrorState error={{ message: error }} />}

        {students > 0 && (
          <p className="text-sm text-amber-800">
            This section holds {students} student{students === 1 ? '' : 's'} and cannot be
            deleted until they are moved out.
          </p>
        )}

        {confirming ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-slate-700">
              Delete {section.course_code} {section.code} permanently?
            </span>
            <Button variant="danger" loading={busy} onClick={remove}>
              Delete
            </Button>
            <Button variant="ghost" onClick={() => setConfirming(false)}>
              Cancel
            </Button>
          </div>
        ) : (
          <Button variant="secondary" disabled={students > 0} onClick={() => setConfirming(true)}>
            Delete section
          </Button>
        )}
      </div>
    </Card>
  )
}
