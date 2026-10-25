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
 * The screen has three jobs, in the order a term is actually opened:
 * **provision** the term's sections (add one, or copy last term's), **appoint**
 * each section's lecturer, and **place** the students. A section is a teaching
 * group for the coursework component of PSM 1 — the faculty runs four — and the
 * person appointed then keys that group's marks on their own screen.
 *
 * ## Sections belong to a term, and the term is the first decision
 *
 * Sections are **not** inherited from term to term: the next term starts with an
 * empty list, and its `S1` is a new row that happens to have the same name as
 * last term's. That is why the picker is part of the header rather than tucked
 * away as a filter, and why this page has a *copy* button at all — without it,
 * opening a term would mean retyping the same four codes. Because the code is
 * unique *within a term* rather than globally, copying is what makes the code
 * repeatable at all; before that restriction was relaxed the second term's `S1`
 * could not be created.
 *
 * ## Why appointing and placing are different permissions
 *
 * The server splits the two writes deliberately, and this screen follows:
 * appointing a section's lecturer is `manage()` — **admin only** — while placing
 * a student into a section is `assignStudents()` — admin *or* coordinator. The
 * asymmetry is real: a section's lecturer is a faculty appointment, whereas
 * moving a student between sections is ordinary academic administration. A
 * lecturer can reach neither; letting them add a student to their own roster
 * would let them mark someone the faculty never placed with them.
 */
export default function SectionManagementPage() {
  const { selectedId, semesters, selected, selectSemester } = useSemesters()
  const { lecturers, error: lecturersError } = useLecturerOptions(selectedId)

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
   * The server refuses a teacher who already holds another section **of this
   * term**, and its message names that section. That sentence is more useful
   * than anything this screen could compose, so it is shown as given. Teaching
   * in another term is not a refusal: the appointment is per term.
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

      <ProvisionCard
        termId={selectedId}
        termName={selected?.name}
        empty={sections.length === 0}
        lecturers={lecturers}
        lecturersError={lecturersError}
        onChanged={load}
        onNotice={(message) => {
          setNotice(message)
          setActionError(null)
        }}
      />

      <Card>
        <CardHeader
          title="Sections and their lecturers"
          subtitle="A teacher holds one section per term. Appointing or renaming is an administrator's decision."
        />

        {sections.length === 0 ? (
          <EmptyState
            title="No sections in this term"
            description="This term has no coursework groups yet. Start from last term's with “Copy last term’s sections”, or add one below — sections are created per term rather than inherited."
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
                      label="Teacher"
                      htmlFor={`lecturer-${section.id}`}
                      hint={
                        lecturersError
                          ? 'The staff list could not be loaded — reload the page to try again.'
                          : 'Lecturers and supervisors: the person who keys this section’s marks'
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
                          <option
                            key={option.value}
                            value={option.value}
                            // A person teaching elsewhere in this term is shown
                            // as taken rather than hidden: the admin can see who
                            // is free and which class the rest are holding.
                            disabled={
                              option.meta?.section != null &&
                              option.meta.section.id !== section.id
                            }
                          >
                            {option.label}
                            {option.meta?.role_label ? ` (${option.meta.role_label})` : ''}
                            {option.meta?.section && option.meta.section.id !== section.id
                              ? ` — teaches ${option.meta.section.code}`
                              : ''}
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

      <BulkPlaceCard termId={selectedId} termName={selected?.name} onPlaced={load} />

      <PlaceStudentCard sections={sections} onPlaced={load} />
    </div>
  )
}

/**
 * The lecturer picker's source.
 *
 * `GET /api/sections/lecturers` — keyed on **user** ids, which is exactly the id
 * space this write needs: `POST`/`PUT /api/sections` validates `lecturer_id`
 * with `exists:users,id` and constrains it to the appointable roles. Note this
 * is *not* the same id space as `assignmentApi.supervisors()`, which returns
 * `supervisor_profiles` ids — user 3 and supervisor_profiles 3 are different
 * people, so feeding one into the other would appoint the wrong person.
 *
 * It replaced `userApi.options({ role: 'lecturer' })`, which was right about the
 * id space and wrong about the people: the staff who teach a section are usually
 * **supervisors**, so a lecturers-only list left the ordinary appointment
 * unmakable. The endpoint also answers per term, so a candidate's row says which
 * section they already teach *in this term* — the picker can then mark them
 * taken instead of offering somebody the one-section-per-term rule refuses a
 * moment later, and the same person reads as free in a term where they teach
 * nothing.
 *
 * A failure is not fatal: the dropdown shows an empty list with a note rather
 * than blocking the page.
 */
function useLecturerOptions(semesterId) {
  const [lecturers, setLecturers] = useState([])
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false

    // Refetched on every term change rather than once: who is free is a fact
    // about the term, so a stale list would mark the wrong people as taken.
    setError(false)

    sectionApi
      .lecturerOptions(semesterId == null ? undefined : { semester_id: semesterId })
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
  }, [semesterId])

  return { lecturers, error }
}

/**
 * Provision the term: add a section by hand, or copy last term's.
 *
 * Both live in one card because they answer one question ("which sections does
 * this term have?") and are used together — copying to get the four the faculty
 * always runs, then adding the fifth that this cohort needed.
 *
 * The copy button carries two flags rather than one because the codes and the
 * appointments are decided at different moments: the sections when the term is
 * opened, the teachers once the faculty has met. Copying the teachers drops
 * anyone who would end up in two sections of this term — the rule the term is
 * meant to start out respecting — so the response reports how many kept none,
 * and that count is shown rather than swallowed.
 */
function ProvisionCard({ termId, termName, empty, lecturers, lecturersError, onChanged, onNotice }) {
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [lecturerId, setLecturerId] = useState('')
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)

  const where = termName ? ` in ${termName}` : ''

  async function addSection(event) {
    event.preventDefault()

    const trimmedCode = code.trim()

    if (trimmedCode === '') return

    setBusy('add')
    setError(null)

    try {
      const payload = {
        code: trimmedCode,
        // Left blank, the server derives "Section 1" from `S1`. Sending `null`
        // rather than `''` is what makes the derivation happen.
        name: name.trim() === '' ? null : name.trim(),
        lecturer_id: lecturerId === '' ? null : Number(lecturerId),
      }

      /*
       * The term is only sent when one is actually selected. A null would mean
       * "the active term" on the server, and an admin looking at 2026/2027 who
       * adds a section must not have it land in the running term instead.
       */
      if (termId != null) payload.academic_semester_id = termId

      const section = await sectionApi.create(payload)

      setCode('')
      setName('')
      setLecturerId('')
      onNotice(`${section?.code ?? trimmedCode} added${where}.`)
      await onChanged()
    } catch (err) {
      setError(err?.message ?? 'That section could not be added.')
    } finally {
      setBusy(null)
    }
  }

  async function copy(includeLecturers) {
    setBusy(includeLecturers ? 'copy-with-teachers' : 'copy')
    setError(null)

    try {
      const payload = { include_lecturers: includeLecturers }

      if (termId != null) payload.semester_id = termId

      const result = await sectionApi.copy(payload)

      // The server's own sentence is not available here (the API layer unwraps
      // to the payload), so it is rebuilt from the counts — which are also what
      // makes the outcome checkable: four sections copied is the useful fact,
      // "success" is not.
      const count = result?.created?.length ?? 0
      const source = result?.source?.name ?? 'last term'
      const into = result?.term?.name ?? termName ?? 'this term'

      let message =
        count === 0
          ? `${into} already has every section from ${source}.`
          : `Copied ${count} section${count === 1 ? '' : 's'} into ${into} from ${source}.`

      if (includeLecturers && count > 0) {
        const adrift = result?.without_lecturer ?? 0
        message +=
          adrift > 0
            ? ` ${adrift} kept no teacher, because that person already teaches another section of this term.`
            : ' Their teachers were copied too.'
      }

      onNotice(message)
      await onChanged()
    } catch (err) {
      setError(err?.message ?? 'That copy could not be made.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Provision this term"
        subtitle={
          empty
            ? 'This term has no sections yet. Copy last term’s, or add one by hand.'
            : 'Add a section this term needed, or bring over any from last term that are missing.'
        }
      />

      {error && (
        <div className="mb-4">
          <ErrorState error={{ message: error }} />
        </div>
      )}

      <form onSubmit={addSection} className="space-y-4">
        <div className="grid gap-5 sm:grid-cols-3">
          <Field
            label="Section code"
            htmlFor="new_section_code"
            hint="Short and unique within the term, e.g. S5"
          >
            <Input
              id="new_section_code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="S5"
              maxLength={16}
            />
          </Field>

          <Field
            label="Name"
            htmlFor="new_section_name"
            hint="Optional — left blank, it is derived from the code"
          >
            <Input
              id="new_section_name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Section 5"
              maxLength={128}
            />
          </Field>

          <Field
            label="Teacher"
            htmlFor="new_section_lecturer"
            hint={lecturersError ? 'The staff list could not be loaded.' : 'Optional'}
          >
            <Select
              id="new_section_lecturer"
              value={lecturerId}
              disabled={lecturersError}
              onChange={(e) => setLecturerId(e.target.value)}
            >
              <option value="">Not appointed</option>
              {lecturers.map((option) => (
                <option
                  key={option.value}
                  value={option.value}
                  // Appointing somebody who already holds a section of this term
                  // is the one combination the server refuses, so it is not
                  // offered here either.
                  disabled={option.meta?.section != null}
                >
                  {option.label}
                  {option.meta?.role_label ? ` (${option.meta.role_label})` : ''}
                  {option.meta?.section ? ` — teaches ${option.meta.section.code}` : ''}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" loading={busy === 'add'} disabled={code.trim() === ''}>
            {busy === 'add' ? 'Adding…' : 'Add section'}
          </Button>

          {/*
           * Both copy actions are always offered, not just on an empty term:
           * the copy only ever adds, so pressing it again is how an admin fills
           * in the one section added late, and it reports the skip rather than
           * failing.
           */}
          <Button
            type="button"
            variant={empty ? 'primary' : 'secondary'}
            loading={busy === 'copy'}
            disabled={busy !== null}
            onClick={() => copy(false)}
          >
            {busy === 'copy' ? 'Copying…' : 'Copy last term’s sections'}
          </Button>

          <Button
            type="button"
            variant="secondary"
            loading={busy === 'copy-with-teachers'}
            disabled={busy !== null}
            onClick={() => copy(true)}
          >
            {busy === 'copy-with-teachers' ? 'Copying…' : 'Copy last term’s sections and teachers'}
          </Button>
        </div>

        <p className="text-xs text-slate-500">
          The next term always starts empty — a section belongs to one term, so
          this term’s <span className="font-mono">S1</span> is a new group even
          though it shares last term’s name. Students are never copied: a class
          list is uploaded fresh below.
        </p>
      </form>
    </Card>
  )
}

/**
 * Place a whole class list in one request.
 *
 * A card of its own, beside the single-student picker, because the two are
 * genuinely different jobs: the picker exists for "this student changed group",
 * and this exists for "here is the register". An admin with sixty students in a
 * spreadsheet should not be opening sixty dropdowns.
 *
 * A file **or** pasted text — a class list often arrives in an email body, and
 * the paste path is also what makes the endpoint usable without a multipart
 * upload at all. The response is a per-row report, so a list that half works
 * says which rows did not: the rows that resolved are real placements either
 * way, which is why nothing is rolled back.
 *
 * The term is sent along with the list. Section codes repeat every term, so
 * "S1" only means anything together with a term, and an upload sent without one
 * would be resolved against the active term behind the admin's back.
 */
function BulkPlaceCard({ termId, termName, onPlaced }) {
  const [text, setText] = useState('')
  const [file, setFile] = useState(null)
  const [busy, setBusy] = useState(false)
  const [report, setReport] = useState(null)
  const [error, setError] = useState(null)

  const unplaced = report?.rows?.filter((row) => row.status !== 'placed') ?? []

  async function upload(event) {
    event.preventDefault()

    setBusy(true)
    setError(null)
    setReport(null)

    try {
      const result = file
        ? await sectionApi.importPlacements(file, termId)
        : await sectionApi.importPlacements(text, termId)

      setReport(result)
      setText('')
      setFile(null)
      await onPlaced()
    } catch (err) {
      setError(err?.message ?? 'That class list could not be processed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Upload a class list"
        subtitle="A matric number and a section code per line, with the student's name either beside them or left out. Columns are read by what is in them, and a header row is welcome but not required."
      />

      {error && (
        <div className="mb-4">
          <ErrorState error={{ message: error }} />
        </div>
      )}

      <form onSubmit={upload} className="space-y-4">
        <div className="grid gap-5 sm:grid-cols-2">
          <Field
            label="Class list file"
            htmlFor="class_list_file"
            hint="CSV or text, up to 2 MB"
          >
            <Input
              id="class_list_file"
              type="file"
              accept=".csv,.txt,text/csv,text/plain"
              className="file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                // Choosing a file clears the paste: sending both would leave
                // the endpoint to pick one, and `file` wins silently.
                if (e.target.files?.[0]) setText('')
              }}
            />
          </Field>

          <Field
            label="…or paste it"
            htmlFor="class_list_text"
            hint="One student per line, e.g. A123456, S1 — a name column in between is fine"
          >
            <textarea
              id="class_list_text"
              value={text}
              rows={4}
              maxLength={20000}
              onChange={(e) => {
                setText(e.target.value)
                if (e.target.value !== '') setFile(null)
              }}
              placeholder={'Matric No,Name,Section\nA123456,Aisyah binti Rahman,S1\nA123457,Nurul Izzah,S2'}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs text-slate-800 shadow-sm focus:border-slate-400 focus:outline-none"
            />
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" loading={busy} disabled={!file && text.trim() === ''}>
            {busy ? 'Uploading…' : 'Place students'}
          </Button>
          <span className="text-xs text-slate-500">
            Placing into the sections of {termName ?? 'the active term'}.
          </span>
        </div>
      </form>

      {report && (
        <div className="mt-5 space-y-3">
          <div className="flex flex-wrap gap-2">
            <Badge tone="success">{report.placed ?? 0} placed</Badge>
            <Badge tone="neutral">{report.unchanged ?? 0} already in place</Badge>
            <Badge tone={(report.failed ?? 0) > 0 ? 'warning' : 'neutral'}>
              {report.failed ?? 0} not placed
            </Badge>
          </div>

          {unplaced.length === 0 ? (
            <p className="text-sm text-emerald-700">
              Every row was placed. The rosters above are up to date.
            </p>
          ) : (
            /*
             * The failures first, because they are the only rows the admin has
             * to act on. A placed row is confirmation; an unplaced row is work.
             */
            <ul className="space-y-1 text-sm">
              {unplaced.slice(0, 100).map((row) => (
                <li key={`${row.line}-${row.student_id}`} className="text-amber-900">
                  <span className="font-mono text-xs text-slate-500">line {row.line}</span>{' '}
                  {row.student_id || '(no matric)'}
                  {row.section_code ? ` → ${row.section_code}` : ''}: {row.message}
                </li>
              ))}
              {unplaced.length > 100 && (
                <li className="text-xs text-slate-500">…and {unplaced.length - 100} more</li>
              )}
            </ul>
          )}
        </div>
      )}
    </Card>
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
