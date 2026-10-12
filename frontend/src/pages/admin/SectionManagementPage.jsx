import { useCallback, useEffect, useMemo, useState } from 'react'
import { sectionApi, userApi } from '../../api/endpoints'
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
} from '../../components/ui'

/**
 * Module 1/4 — section administration.
 *
 * Two jobs on one screen because they are two halves of one decision: who owns
 * a section, and who sits in it. A section is a teaching group for the
 * coursework component of PSM 1 — the faculty runs four, each with one lecturer
 * appointed permanently, and the lecturer then keys that group's marks on their
 * own screen.
 *
 * ## Why this is admin-only
 *
 * The server splits the two writes deliberately, and this screen follows:
 * appointing a section's lecturer is `manage()` — **admin only** — while placing
 * a student into a section is `assignStudents()` — admin *or* coordinator. The
 * asymmetry is real: a section's lecturer is a faculty appointment, whereas
 * moving a student between sections is ordinary academic administration. A
 * lecturer can reach neither; letting them add a student to their own roster
 * would let them mark someone the faculty never placed with them.
 *
 * ## Why the term picker matters here
 *
 * `GET /sections` is term-scoped (`forSemester`), so what this page lists is the
 * sections of the term in view. Changing the term therefore changes the roster
 * counts and can legitimately change the *set* of sections, which is why the
 * picker is part of the header rather than tucked away as a filter.
 */

/**
 * The lecturer picker's source.
 *
 * `userApi.options({ role: 'lecturer' })` — i.e. `GET /api/users/options?role=…`
 * — is the right helper, and it was chosen over `lecturerApi` on purpose.
 * `lecturerApi` is the *lecturer's own* screen: it resolves the section from the
 * session and returns no user list at all, so there is nothing there to feed a
 * "who should own this section?" dropdown.
 *
 * `options()` is the generic picker vocabulary keyed on **user** ids, which is
 * exactly the id space this write needs: `PUT /api/sections/{id}` validates
 * `lecturer_id` with `exists:users,id` and additionally constrains it to
 * `role = 'lecturer'`. Note this is *not* the same id space as
 * `assignmentApi.supervisors()`, which returns `supervisor_profiles` ids — user
 * 3 and supervisor_profiles 3 are different people, so feeding one into the
 * other would appoint the wrong person.
 *
 * The route sits behind `role:admin,coordinator`, so it answers for an admin.
 * A failure is not fatal: the dropdown shows an empty list with a note rather
 * than blocking the page.
 */
function useLecturerOptions() {
  const [lecturers, setLecturers] = useState([])
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false

    userApi
      .options({ role: 'lecturer' })
      .then((list) => {
        if (!cancelled) setLecturers(Array.isArray(list) ? list : [])
      })
      .catch(() => {
        // Surfaced as a note beside the control rather than thrown: the rest of
        // the page — the list, the roster counts — is still useful.
        if (!cancelled) setError(true)
      })

    return () => {
      cancelled = true
    }
  }, [])

  return { lecturers, error }
}

export default function SectionManagementPage() {
  const { selectedId, semesters, selected, selectSemester } = useSemesters()
  const { lecturers, error: lecturersError } = useLecturerOptions()

  const [sections, setSections] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [notice, setNotice] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [busy, setBusy] = useState(null)

  /**
   * The per-section edit buffer.
   *
   * Keyed by section id and holding only what has been *typed*, so a section
   * the admin has not touched falls back to the server's value at render. That
   * keeps a reload from stamping over an in-progress edit while still showing
   * the truth for everything else.
   */
  const [edits, setEdits] = useState({})

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const list = await sectionApi.list(
        selectedId == null ? undefined : { semester_id: selectedId },
      )
      setSections(Array.isArray(list) ? list : [])
      setEdits({})
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [selectedId])

  useEffect(() => {
    load()
  }, [load])

  const stats = useMemo(() => {
    const placed = sections.reduce((sum, s) => sum + (s.student_count ?? 0), 0)
    return {
      sections: sections.length,
      placed,
      unappointed: sections.filter((s) => !s.lecturer).length,
    }
  }, [sections])

  const editFor = (section) => ({
    name: edits[section.id]?.name ?? section.name ?? '',
    lecturer_id:
      edits[section.id]?.lecturer_id ?? (section.lecturer?.id != null ? String(section.lecturer.id) : ''),
  })

  const setEdit = (sectionId, key, value) => {
    setEdits((prev) => ({ ...prev, [sectionId]: { ...prev[sectionId], [key]: value } }))
  }

  /**
   * Save a section's name and lecturer.
   *
   * Both fields travel in one call because they are one decision — the admin
   * renames a section and appoints its lecturer in the same sitting. Only the
   * two fields this screen owns are sent: `PUT /sections/{id}` also accepts
   * `academic_semester_id`, and sending it would move the section's term as a
   * side effect of renaming it.
   *
   * The server refuses a lecturer who already owns another section, and its
   * message names that section ("X already owns A1"). That sentence is more
   * useful than anything this screen could compose, so it is shown as given.
   */
  async function saveSection(section) {
    const edit = editFor(section)
    const key = `section-${section.id}`

    setBusy(key)
    setActionError(null)
    setNotice(null)

    try {
      await sectionApi.update(section.id, {
        name: edit.name.trim(),
        // `null` rather than `''`: the column is nullable, and an empty string
        // is not a valid user id. This is how a section is left without a
        // lecturer, which the marks screen reads as "nobody may key yet".
        lecturer_id: edit.lecturer_id === '' ? null : Number(edit.lecturer_id),
      })

      setNotice(`${section.code} updated.`)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'That section could not be saved.')
    } finally {
      setBusy(null)
    }
  }

  if (loading && sections.length === 0) return <Spinner label="Loading sections…" />
  if (error) return <ErrorState error={error} onRetry={load} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Teaching sections"
        subtitle={
          selected?.name
            ? `Coursework groups for ${selected.name}`
            : 'Coursework groups and the lecturers who own them'
        }
        action={
          // Shown only when there is a choice to make; a one-term system does
          // not need a picker that cannot change anything.
          semesters?.length > 1 ? (
            <Select
              value={selectedId ?? ''}
              onChange={(e) => selectSemester(e.target.value || null)}
              aria-label="Term"
              className="w-48"
            >
              {semesters.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          ) : null
        }
      />

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && <ErrorState error={{ message: actionError }} />}

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Sections" value={stats.sections} />
        <StatCard
          label="Students placed"
          value={stats.placed}
          hint="Across every section in this term"
        />
        <StatCard
          label="Without a lecturer"
          value={stats.unappointed}
          tone={stats.unappointed > 0 ? 'warn' : 'default'}
          hint="Nobody can key their marks"
        />
      </div>

      <Card>
        <CardHeader
          title="Sections and their lecturers"
          subtitle="A lecturer owns one section. Appointing or renaming is an administrator's decision."
        />

        {sections.length === 0 ? (
          <EmptyState
            title="No sections in this term"
            description="Sections are created by the faculty when the coursework groups are decided. If this term should have four, they are missing from the server rather than from this page."
          />
        ) : (
          <ul className="space-y-3">
            {sections.map((section) => {
              const edit = editFor(section)
              const dirty =
                edit.name !== (section.name ?? '') ||
                edit.lecturer_id !==
                  (section.lecturer?.id != null ? String(section.lecturer.id) : '')

              return (
                <li
                  key={section.id}
                  className="rounded-xl border border-slate-200 p-4 space-y-4"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs text-slate-500">{section.code}</span>
                        <span className="text-sm font-medium text-slate-800">{section.name}</span>
                        {section.lecturer ? (
                          <Badge tone="success">{section.lecturer.name}</Badge>
                        ) : (
                          /*
                           * The distinction the marks screen depends on: a
                           * section with no lecturer is unkeyable, not merely
                           * unstaffed. The server refuses the mark write rather
                           * than opening it to every lecturer.
                           */
                          <Badge tone="warning">No lecturer appointed</Badge>
                        )}
                      </div>
                      {section.lecturer?.email && (
                        <p className="text-xs text-slate-500 mt-1">{section.lecturer.email}</p>
                      )}
                    </div>

                    <Badge tone="neutral">
                      {section.student_count ?? 0} student
                      {(section.student_count ?? 0) === 1 ? '' : 's'}
                    </Badge>
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Section name" htmlFor={`name-${section.id}`}>
                      <Input
                        id={`name-${section.id}`}
                        value={edit.name}
                        maxLength={128}
                        onChange={(e) => setEdit(section.id, 'name', e.target.value)}
                      />
                    </Field>

                    <Field
                      label="Lecturer"
                      htmlFor={`lecturer-${section.id}`}
                      hint={
                        lecturersError
                          ? 'The lecturer list could not be loaded — reload the page to try again.'
                          : 'The person who keys this section’s coursework marks'
                      }
                    >
                      <Select
                        id={`lecturer-${section.id}`}
                        value={edit.lecturer_id}
                        disabled={lecturersError}
                        onChange={(e) => setEdit(section.id, 'lecturer_id', e.target.value)}
                      >
                        <option value="">Not appointed</option>
                        {lecturers.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </Select>
                    </Field>
                  </div>

                  <div className="flex justify-end">
                    <Button
                      size="sm"
                      variant={dirty ? 'primary' : 'secondary'}
                      loading={busy === `section-${section.id}`}
                      disabled={!dirty || !edit.name.trim()}
                      onClick={() => saveSection(section)}
                    >
                      {busy === `section-${section.id}` ? 'Saving…' : 'Save section'}
                    </Button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>

      <PlaceStudentCard sections={sections} onPlaced={load} />
    </div>
  )
}

/**
 * Place a student into a section.
 *
 * A separate card rather than a column on the table above, because the two
 * operations have different shapes: renaming is per-row, while placing is a
 * one-off lookup — an admin arrives knowing *who* they need to move and has to
 * find them first.
 *
 * The picker is a `<Select>` over `userApi.options({ role: 'student' })` rather
 * than a free-text box. The write needs a `student_profiles` id, and a name
 * typed by hand cannot produce one — `options()` returns `value` as the user id
 * with `meta.student_id` beside it, which is what makes the choice unambiguous
 * when two students share a name.
 *
 * The picker is searchable in the sense that matters here: the student list is
 * filtered client-side as the admin types. The options payload is a few hundred
 * light rows at most, so a round trip per keystroke would be worse than useless.
 */
function PlaceStudentCard({ sections, onPlaced }) {
  const [students, setStudents] = useState([])
  const [listError, setListError] = useState(false)

  const [query, setQuery] = useState('')
  const [studentId, setStudentId] = useState('')
  const [sectionId, setSectionId] = useState('')

  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    userApi
      .options({ role: 'student' })
      .then((list) => {
        if (!cancelled) setStudents(Array.isArray(list) ? list : [])
      })
      .catch(() => {
        if (!cancelled) setListError(true)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const matches = useMemo(() => {
    const needle = query.trim().toLowerCase()

    if (needle === '') return students.slice(0, 50)

    return students
      .filter((option) => {
        const label = String(option.label ?? '').toLowerCase()
        // The matric number is what an admin actually has to hand — a name
        // alone is ambiguous, and `meta.student_id` is the disambiguator.
        const matric = String(option.meta?.student_id ?? '').toLowerCase()
        return label.includes(needle) || matric.includes(needle)
      })
      .slice(0, 50)
  }, [students, query])

  /**
   * Record the placement.
   *
   * The student is identified by their **user** id from the picker, but the
   * route takes a `student_profiles` id. `options()` is keyed on user ids
   * throughout, so the two are the same number here — the API's `User::id` is
   * what `PUT /students/{studentProfileId}/section` binds — but the mismatch is
   * worth stating, because `assignmentApi.supervisors()` returns a genuinely
   * different id space and mixing them is the obvious mistake.
   */
  async function place() {
    setSaving(true)
    setMessage(null)
    setError(null)

    try {
      await sectionApi.assignStudent(
        studentId === '' ? null : Number(studentId),
        sectionId === '' ? null : Number(sectionId),
      )

      const where =
        sectionId === ''
          ? 'removed from their section'
          : `placed in ${sections.find((s) => String(s.id) === sectionId)?.code ?? 'the section'}`

      setMessage(`Student ${where}.`)
      setStudentId('')
      setQuery('')
      await onPlaced()
    } catch (err) {
      setError(err?.message ?? 'That placement could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Place a student in a section"
        subtitle="Coursework marks belong to a section, so a student with no section cannot be marked at all."
      />

      {listError ? (
        <ErrorState message="The student list could not be loaded, so nobody can be placed right now." />
      ) : (
        <div className="space-y-4">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field
              label="Find a student"
              htmlFor="student_search"
              hint="Search by name or matric number"
            >
              <Input
                id="student_search"
                type="search"
                value={query}
                onChange={(e) => {
                  setQuery(e.target.value)
                  // The previous choice may no longer be in the filtered list,
                  // and leaving it selected would place the wrong person.
                  setStudentId('')
                }}
                placeholder="e.g. A123456 or Aisyah"
                maxLength={120}
              />
            </Field>

            <Field label="Student" htmlFor="student_choice">
              <Select
                id="student_choice"
                value={studentId}
                onChange={(e) => setStudentId(e.target.value)}
              >
                <option value="">
                  {matches.length === 0 ? 'No students match' : 'Choose a student…'}
                </option>
                {matches.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                    {option.meta?.student_id ? ` — ${option.meta.student_id}` : ''}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          <div className="grid gap-5 sm:grid-cols-2">
            <Field
              label="Section"
              htmlFor="section_choice"
              hint="Leave as “No section” to remove the student from their current one"
            >
              <Select
                id="section_choice"
                value={sectionId}
                onChange={(e) => setSectionId(e.target.value)}
              >
                <option value="">No section</option>
                {sections.map((section) => (
                  <option key={section.id} value={section.id}>
                    {section.code} — {section.name}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="flex items-end">
              <Button
                loading={saving}
                disabled={studentId === ''}
                onClick={place}
              >
                {saving ? 'Saving…' : 'Save placement'}
              </Button>
            </div>
          </div>

          {message && <p className="text-sm text-emerald-700">{message}</p>}
          {error && <ErrorState error={{ message: error }} />}

          <p className="text-xs text-slate-500">
            A student sits in one section at a time. Moving them re-points the
            coursework marks their lecturer keys — the marks already entered
            against the old section are kept, not deleted.
          </p>
        </div>
      )}
    </Card>
  )
}
