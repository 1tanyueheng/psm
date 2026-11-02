import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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
 * The three criteria, with their maxima — the course's own assessment domains.
 *
 * They replaced Quiz 5 / Assignment-C 12 / Assignment-P 9 / Assignment-A 9 on
 * 2026-11-02 at the faculty's instruction. The old names were a guess at what
 * "C", "P" and "A" stood for and the guess was wrong: there was never a quiz,
 * and `Assignment-C` was the *criterion* (cognitive), not a cognitive
 * assignment. The course's mark sheet calls them
 * `LECT ASSIGNMENT_AFFECTIVE / COGNITIVE / PSYCHOMOTOR`.
 *
 * Hardcoded rather than fetched, because they are a faculty decision and not a
 * setting: `config/psm.php` declares the same three with the same maxima. The
 * values are mirrored here so an input can carry `max`, `step` and a
 * client-side bound *before* the first save — the server's validation is still
 * the authority, and its message is what a rejected save displays.
 *
 * If the faculty ever changes the split, this list and `config/psm.php` move
 * together. The roster response also carries a `components` array, which is
 * what the read path uses; this constant exists for the write path and for
 * bounding the inputs.
 */
const COMPONENTS = [
  { key: 'affective', label: 'Assignment-Affective', max: 8 },
  { key: 'cognitive', label: 'Assignment-Cognitive', max: 15 },
  { key: 'psychomotor', label: 'Assignment-Psychomotor', max: 7 },
]

/** The coursework total. Also a faculty constant — see the note above. */
const MAX_TOTAL = COMPONENTS.reduce((sum, c) => sum + c.max, 0)

/**
 * The lecturer's section roster — a list, not a marking sheet.
 *
 * This list does not mark anybody. A row opens the student's own marking page
 * (`/dashboard/lecturer/students/:id`), which is where the three criteria are
 * keyed — see that file for why one student per page beat four inputs in a row.
 */
export default function LecturerDashboard() {
  const navigate = useNavigate()
  const { hasRole } = useAuth()
  const { selectedId, semesters, selected, selectSemester } = useSemesters()

  const [section, setSection] = useState(null)
  const [students, setStudents] = useState([])

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

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
   * No section in the selected term: a normal state, not an error.
   *
   * The admin appoints a section's lecturer **per term**, so a lecturer who has
   * just been created — or one whose class was last term's — has none here.
   * Showing an empty roster table would read as "your section has no students",
   * which is a different and much more alarming fact than "you have not been
   * appointed yet".
   *
   * The term picker stays on screen in this branch, and that is the point: the
   * appointment is per term, so "no section" is only ever true of *one* term.
   * Without the picker here, a teacher whose class ended last term could not
   * reach the marks they are still entitled to read — the screen would hide the
   * only control that leads back to them.
   */
  if (!section) {
    return (
      <>
        <PageHeader
          title="Coursework marks"
          subtitle={
            selected?.name
              ? `No section of yours in ${selected.name}`
              : 'Your coursework sections'
          }
          action={
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
            ) : null
          }
        />
        <Card>
          <EmptyState
            title={
              selected?.name
                ? `No section in ${selected.name}`
                : 'No section assigned yet'
            }
            description="An administrator has not appointed you to a section in this term. A section is a class for one term, so this page will list your students as soon as the appointment is made — and if you taught in an earlier term, choose that term above to read its marks."
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
            {/*
              * The course comes before the code: a term runs BIC31602 and
              * BIT34002 as separate cohorts, each with its own S1, so "S1" on
              * its own does not tell a teacher which class they are marking.
              */}
            <span className="font-mono text-xs font-medium text-slate-700">
              {section.course_code ?? 'BIC31602'}
            </span>
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
          hint={COMPONENTS.map((c) => `${c.label.replace('Assignment-', '')} ${c.max}`).join(', ')}
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
          title="Your students"
          subtitle="Open a student to key or review their three coursework criteria. The status here says whether anything is still missing."
          action={
            hasRole('lecturer') ? (
              <Badge tone="brand">Keyed by you</Badge>
            ) : (
              /*
               * An admin or coordinator reaches this screen to correct a mark.
               * Saying so matters: `entered_by` records who typed it, and a
               * correction attributed to the section's lecturer when staff made
               * it would be a false audit trail.
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
             * A list, not a marking sheet.
             *
             * Marking moved to `/dashboard/lecturer/students/:id` on 2026-11-02.
             * One student per page is the difference between a screen a lecturer
             * reads and a screen they work in: four inputs in a row of a
             * twelve-column table are small targets that scroll sideways, and a
             * save that fails halfway down a section is hard to find again.
             *
             * The list keeps what a list is good at — who is marked, who is not,
             * and in what order to work.
             */
            minWidth={760}
            columns={[
              'Matric no.',
              'Student',
              'Programme',
              { key: 'total', label: `Total / ${MAX_TOTAL}`, align: 'right' },
              { key: 'percent', label: 'Percent', align: 'right' },
              'Status',
              { key: 'open', label: '', align: 'right' },
            ]}
          >
            {students.map((student) => (
              <tr
                key={student.id}
                className="hover:bg-slate-50/60 cursor-pointer"
                /*
                 * The whole row opens the marking page, not only the link.
                 * A row a lecturer has to aim at is a row they mis-click; the
                 * link inside stays for the keyboard and for a right-click
                 * "open in new tab".
                 */
                onClick={() => navigate(`/dashboard/lecturer/students/${student.id}`)}
              >
                <Td className="font-mono text-xs text-slate-500 whitespace-nowrap">
                  {student.student_id ?? '—'}
                </Td>

                <Td>
                  <Link
                    to={`/dashboard/lecturer/students/${student.id}`}
                    className="text-sm font-medium text-brand-700 hover:underline"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {student.name}
                  </Link>
                  {student.email && (
                    <div className="text-xs text-slate-500 truncate max-w-[220px]">
                      {student.email}
                    </div>
                  )}
                </Td>

                <Td className="text-xs text-slate-600 whitespace-nowrap">
                  {student.program_code ?? '—'}
                </Td>

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
                     * "Incomplete", not "0 / 30" or "0%" — the row has no
                     * total until every criterion is keyed, and showing a
                     * partial sum would look like a failing mark.
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

                <Td align="right">
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={(e) => {
                      e.stopPropagation()
                      navigate(`/dashboard/lecturer/students/${student.id}`)
                    }}
                  >
                    {student.complete ? 'Review' : 'Mark'} →
                  </Button>
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="How these marks are counted" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            The three criteria sum to <span className="font-medium text-slate-800">{MAX_TOTAL}</span>,
            which enters the PSM 1 result as the coursework component.
          </li>
          <li>
            <span className="font-medium text-slate-800">An empty box is not a zero.</span> It means
            the criterion has not been marked yet, so the student&rsquo;s coursework has no total and
            nothing is published for it. A real zero is a mark you type.
          </li>
          <li>
            Marks save one student at a time, on their own page. If the server refuses a value it
            will say which criterion and why, and what you typed stays in the box so you can
            correct it.
          </li>
        </ul>
      </Card>
    </div>
  )
}
