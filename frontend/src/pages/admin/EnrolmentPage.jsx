import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { enrolmentApi } from '../../api/endpoints'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Select, FilterBar, FilterField, SegmentedControl,
  DataTable, Td, StatCard,
} from '../../components/ui'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'

/**
 * Admin / coordinator — the term's enrolment register.
 *
 * This is the screen that makes a student exist in a semester before they have
 * a project. Two jobs, and the tabs keep them apart because they are asked at
 * different times:
 *
 *  - **Cohort** — who is enrolled for this term, and enrolling more. The
 *    start-of-term act. Enrolment numbers here *are* the attempt numbers: the
 *    second time a student appears for PSM 2, they are on attempt 2.
 *  - **Awaiting a term** — repeats the committee has opened that nobody has
 *    placed yet. This list had no equivalent before enrolments existed: a repeat
 *    used to create a project immediately in whatever term the server guessed,
 *    and when it could not guess, the project was written with no term at all.
 *    Now the guess is gone and the choice is on this screen.
 *
 * Enrolling is a batch operation, not a single one, because registering thirty
 * students is the normal act and thirty round trips is not.
 */
export default function EnrolmentPage() {
  const { semesters, selectedId: termId, active } = useSemesters()

  const [tab, setTab] = useState('cohort')
  const [semesterId, setSemesterId] = useState(termId ?? active?.id ?? null)

  const [rows, setRows] = useState([])
  const [counts, setCounts] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [busyId, setBusyId] = useState(null)
  const [notice, setNotice] = useState(null)
  const [actionError, setActionError] = useState(null)

  const [statusFilter, setStatusFilter] = useState('')
  const [partFilter, setPartFilter] = useState('')

  /** The enrol dialog's open state. */
  const [enrolling, setEnrolling] = useState(false)
  /** The placement dialog for an unplaced repeat. */
  const [placing, setPlacing] = useState(null)

  const load = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true)
    setError(null)
    try {
      const payload = await enrolmentApi.list(
        tab === 'unplaced'
          ? { unplaced: true }
          : { semester_id: semesterId ?? undefined }
      )
      setRows(payload?.enrolments ?? [])
      setCounts(payload?.counts ?? {})
      // A term with nothing in it yet should not default to the empty list; the
      // admin opened this screen to fix that.
    } catch (err) {
      setError(err)
    } finally {
      if (!quiet) setLoading(false)
    }
  }, [semesterId, tab])

  useEffect(() => {
    load()
  }, [load])

  async function act(id, fn) {
    setBusyId(id)
    setActionError(null)
    setNotice(null)
    try {
      const res = await fn()
      await load({ quiet: true })
      if (res?.message) setNotice(res.message)
      return res
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
      return null
    } finally {
      setBusyId(null)
    }
  }

  const visible = useMemo(
    () =>
      rows.filter((r) => {
        if (statusFilter && r.status !== statusFilter) return false
        if (partFilter && r.psm_part !== partFilter) return false
        return true
      }),
    [rows, statusFilter, partFilter]
  )

  if (loading) return <Spinner label="Loading the enrolment register" />
  if (error) return <ErrorState error={error} onRetry={() => load()} />

  const unplacedTab = tab === 'unplaced'
  const termName = semesters.find((s) => s.id === Number(semesterId))?.name

  return (
    <div className="space-y-6">
      <PageHeader
        title="Enrolment"
        subtitle="Register students into a PSM part for a semester, and place repeats into the term they belong to."
        action={
          !unplacedTab && (
            <Button onClick={() => setEnrolling(true)}>
              Enrol students
            </Button>
          )
        }
      />

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && (
        <Card className="border-rose-200 bg-rose-50/60">
          <p className="text-sm text-rose-900">{actionError}</p>
        </Card>
      )}

      <SegmentedControl
        value={tab}
        onChange={setTab}
        name="enrolment_tab"
        options={[
          { value: 'cohort', label: 'Cohort' },
          { value: 'unplaced', label: 'Awaiting a term' },
        ]}
      />

      {!unplacedTab && (
        <>
          <FilterBar>
            <FilterField label="Semester">
              <Select
                value={semesterId ?? ''}
                onChange={(e) => setSemesterId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">Select a term…</option>
                {semesters.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                    {s.is_active ? ' (active)' : ''}
                  </option>
                ))}
              </Select>
            </FilterField>

            <FilterField label="Batch">
              <SegmentedControl
                value={partFilter}
                onChange={setPartFilter}
                name="enrolment_part"
                options={[
                  { value: '', label: 'All' },
                  ...PSM_PARTS.map((p) => ({ value: p, label: partLabel(p, { short: true }) })),
                ]}
              />
            </FilterField>

            <FilterField label="Status">
              <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">Any status</option>
                <option value="enrolling">Enrolling</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="withdrawn">Withdrawn</option>
              </Select>
            </FilterField>
          </FilterBar>

          <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
            <StatCard label="Enrolled" value={counts.total ?? 0} hint={termName ?? 'No term'} />
            <StatCard label="Active" value={counts.active ?? 0} />
            <StatCard label="Enrolling" value={counts.enrolling ?? 0} hint="No project yet" />
            <StatCard label="Completed" value={counts.completed ?? 0} />
            <StatCard label="Failed" value={counts.failed ?? 0} hint="May repeat" />
          </div>
        </>
      )}

      <Card className="overflow-hidden p-0">
        <CardHeader
          title={unplacedTab ? 'Repeats awaiting a term' : 'Who is enrolled'}
          subtitle={
            unplacedTab
              ? 'A failed attempt entitles the student to another. Choose the semester it goes into.'
              : 'The attempt number counts how many times the student has taken this part'
          }
        />

        <DataTable
          columns={[
            { label: 'Student', width: '20%' },
            { label: 'Batch', width: '8%' },
            { label: 'Attempt', width: '9%' },
            { label: unplacedTab ? 'Repeats' : 'Term', width: '22%' },
            { label: 'Status', width: '13%' },
            { label: '', align: 'right', width: '18%' },
          ]}
          rows={visible}
          keyField="id"
          minWidth={860}
          empty={
            <EmptyState
              title={
                unplacedTab
                  ? 'No repeats are waiting'
                  : rows.length === 0
                    ? 'Nobody is enrolled in this term'
                    : 'Nothing matches these filters'
              }
              message={
                unplacedTab
                  ? 'When the committee records a failure, the student\'s repeat appears here for you to place into a semester.'
                  : rows.length === 0
                    ? 'Use Enrol students to register a cohort for this term.'
                    : 'Clear the filters to see the rest of the cohort.'
              }
            />
          }
          render={(row) => (
            <tr key={row.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
              <Td>
                <p className="truncate font-medium text-slate-800">{row.student_name ?? '—'}</p>
                <p className="font-mono text-xs text-slate-400">{row.student_id ?? '—'}</p>
              </Td>

              <Td>
                <Badge tone={PSM_PART_BADGE_TONES[row.psm_part] ?? 'neutral'}>
                  {partLabel(row.psm_part, { short: true })}
                </Badge>
              </Td>

              <Td>
                <span className="tabular-nums text-sm text-slate-700">{row.attempt_no}</span>
              </Td>

              <Td>
                {unplacedTab ? (
                  <div className="text-xs text-slate-500">
                    {row.repeats ? (
                      <>
                        <span className="text-slate-700">Attempt {row.repeats.attempt}</span>
                        {row.repeats.semester && <> · {row.repeats.semester}</>}
                      </>
                    ) : (
                      'No prior attempt on record'
                    )}
                  </div>
                ) : (
                  <div className="min-w-0">
                    {row.project_id ? (
                      <Link
                        to={`/projects/${row.project_id}`}
                        className="block truncate text-sm text-slate-800 hover:text-brand-700 hover:underline"
                        title={row.project_title}
                      >
                        {row.project_code}
                      </Link>
                    ) : (
                      <span className="text-xs text-slate-400">No project yet</span>
                    )}
                  </div>
                )}
              </Td>

              <Td>
                <Badge tone={row.status_tone ?? 'neutral'}>{row.status_label ?? row.status}</Badge>
              </Td>

              <Td align="right">
                <div className="flex justify-end gap-2">
                  {unplacedTab && (
                    <Button
                      size="sm"
                      onClick={() => setPlacing(row)}
                      disabled={busyId === row.id}
                    >
                      Place in a term
                    </Button>
                  )}
                  {!unplacedTab && row.is_live && (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={busyId === row.id}
                      onClick={() => act(row.id, () => enrolmentApi.withdraw(row.id, null))}
                    >
                      {busyId === row.id ? 'Withdrawing…' : 'Withdraw'}
                    </Button>
                  )}
                </div>
              </Td>
            </tr>
          )}
        />
      </Card>

      {enrolling && (
        <EnrolDialog
          semesters={semesters}
          defaultSemesterId={semesterId}
          onClose={() => setEnrolling(false)}
          onDone={async (message) => {
            setEnrolling(false)
            setNotice(message)
            await load({ quiet: true })
          }}
        />
      )}

      {placing && (
        <PlaceDialog
          row={placing}
          semesters={semesters}
          onClose={() => setPlacing(null)}
          onConfirm={async (targetId) => {
            const res = await act(placing.id, () =>
              enrolmentApi.place(placing.id, targetId)
            )
            if (res) setPlacing(null)
          }}
          busy={busyId === placing.id}
          error={actionError}
        />
      )}
    </div>
  )
}

/**
 * Enrol a batch of students.
 *
 * The candidate list comes from the server with a `blocker` per student, so the
 * dialog can grey out the ones who cannot be enrolled and say why — a student
 * who simply is not in the picker is unpickable *and* undebuggable.
 */
function EnrolDialog({ semesters, defaultSemesterId, onClose, onDone }) {
  const [semesterId, setSemesterId] = useState(defaultSemesterId ?? '')
  const [part, setPart] = useState('PSM1')
  const [candidates, setCandidates] = useState([])
  const [selected, setSelected] = useState(() => new Set())
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')

  useEffect(() => {
    if (semesterId === '' || !semesterId) {
      setCandidates([])
      return undefined
    }

    let cancelled = false
    setLoading(true)
    setError(null)

    enrolmentApi
      .eligible(semesterId, part)
      .then((data) => {
        if (cancelled) return
        setCandidates(data?.students ?? [])
        setSelected(new Set())
      })
      .catch((err) => {
        if (!cancelled) setError(err?.message ?? 'Could not load the candidate list.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [semesterId, part])

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return candidates
    return candidates.filter((c) =>
      [c.name, c.student_id, c.batch, c.program_code]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q)
    )
  }, [candidates, search])

  const eligibleShown = shown.filter((c) => c.eligible)

  function toggle(id) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function submit() {
    if (selected.size === 0) return
    setBusy(true)
    setError(null)
    try {
      const res = await enrolmentApi.store({
        semester_id: Number(semesterId),
        psm_part: part,
        student_profile_ids: [...selected],
      })
      const skipped = res?.skipped ?? []
      await onDone(
        skipped.length === 0
          ? res?.message
          : `${res?.message} ${skipped.length} skipped: ${skipped[0]?.reason ?? ''}`
      )
    } catch (err) {
      setError(err?.message ?? 'The enrolment could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Overlay title="Enrol students" onClose={onClose}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Semester" required>
          <Select value={semesterId} onChange={(e) => setSemesterId(e.target.value)}>
            <option value="">Select a term…</option>
            {semesters.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
                {s.is_active ? ' (active)' : ''}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="PSM part" required>
          <Select value={part} onChange={(e) => setPart(e.target.value)}>
            {PSM_PARTS.map((p) => (
              <option key={p} value={p}>
                {partLabel(p)}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      {error && (
        <p className="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
      )}

      {semesterId !== '' && (
        <>
          <div className="mt-3 flex items-center gap-2">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Filter by name or matric"
              className="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm
                focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
            <Button
              size="sm"
              variant="secondary"
              onClick={() =>
                setSelected(new Set(eligibleShown.map((c) => c.student_profile_id)))
              }
              disabled={eligibleShown.length === 0}
            >
              Select all eligible
            </Button>
          </div>

          <div className="mt-2 max-h-72 overflow-y-auto rounded-md border border-slate-100">
            {loading ? (
              <p className="px-3 py-4 text-sm text-slate-500">Loading students…</p>
            ) : shown.length === 0 ? (
              <p className="px-3 py-4 text-sm text-slate-500">No students match that filter.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {shown.map((c) => (
                  <li key={c.student_profile_id}>
                    <label
                      className={`flex items-center gap-3 px-3 py-2 ${
                        c.eligible ? 'cursor-pointer hover:bg-slate-50' : 'opacity-60'
                      }`}
                    >
                      <input
                        type="checkbox"
                        className="shrink-0"
                        checked={selected.has(c.student_profile_id)}
                        onChange={() => toggle(c.student_profile_id)}
                        disabled={!c.eligible}
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm text-slate-800">{c.name}</span>
                        <span className="block font-mono text-xs text-slate-400">
                          {c.student_id} · {c.batch}
                          {c.attempts > 0 && ` · ${c.attempts} prior attempt(s)`}
                        </span>
                      </span>
                      {!c.eligible && (
                        <span className="max-w-[45%] text-right text-xs text-slate-400">
                          {c.blocker}
                        </span>
                      )}
                    </label>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}

      <div className="mt-4 flex items-center justify-between gap-2">
        <span className="text-xs text-slate-500">
          {selected.size} selected
        </span>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={busy || selected.size === 0} loading={busy}>
            Enrol {selected.size > 0 ? selected.size : ''} student
            {selected.size === 1 ? '' : 's'}
          </Button>
        </div>
      </div>
    </Overlay>
  )
}

/**
 * Place an unplaced repeat into a term.
 *
 * Only later terms are offered. The server refuses anything else, but offering
 * an impossible choice and then explaining the refusal is worse than not
 * offering it.
 */
function PlaceDialog({ row, semesters, onClose, onConfirm, busy, error }) {
  /**
   * The term being repeated is the semester of the *previous* attempt, which the
   * API gives us by name. Find it, then offer only terms that come after it —
   * a repeat goes into a later term, and the server refuses anything else.
   */
  const sourceIndex = semesters.findIndex((s) => s.name === row.repeats?.semester)
  const laterTerms = sourceIndex >= 0 ? semesters.slice(sourceIndex + 1) : semesters

  const [target, setTarget] = useState(laterTerms[0]?.id ?? '')

  return (
    <Overlay title="Place the repeat" onClose={onClose}>
      <p className="mb-3 text-sm text-slate-600">
        {row.student_name} is on attempt {row.attempt_no} of{' '}
        {partLabel(row.psm_part)}. Choose the semester this attempt belongs to.
      </p>

      {row.repeats?.semester && (
        <p className="mb-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Repeats attempt {row.repeats.attempt} from {row.repeats.semester}. A repeat goes
          into a later term.
        </p>
      )}

      <Field label="Semester" required>
        <Select value={target} onChange={(e) => setTarget(e.target.value)}>
          {laterTerms.length === 0 && <option value="">No later term exists</option>}
          {laterTerms.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
              {s.is_active ? ' (active)' : ''}
            </option>
          ))}
        </Select>
      </Field>

      {error && (
        <p className="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
      )}

      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose} disabled={busy}>
          Cancel
        </Button>
        <Button
          onClick={() => onConfirm(Number(target))}
          disabled={busy || !target}
          loading={busy}
        >
          Place in {semesters.find((s) => s.id === Number(target))?.name ?? 'term'}
        </Button>
      </div>
    </Overlay>
  )
}

/** A fixed overlay; `ui.jsx` has no dialog primitive and this page is its user. */
function Overlay({ title, children, onClose }) {
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
        <h2 className="mb-3 text-base font-semibold text-slate-900">{title}</h2>
        {children}
      </div>
    </div>
  )
}
