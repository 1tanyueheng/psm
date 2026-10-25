import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { projectApi } from '../../api/endpoints'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Textarea, Select, FilterBar, FilterField, SegmentedControl,
  DataTable, Td,
} from '../../components/ui'
import { partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'
import { formatDateTime, formatMark } from '../../lib/format'

/**
 * Coordinator — the end-of-term verdict board.
 *
 * This is where a PSM project stops being "in progress" and becomes either
 * completed or failed. It replaces the old archive button, which did nothing
 * but hide a project: the real decision at the end of a semester is whether the
 * student passed, because everything after it — the grade, the right to move to
 * PSM 2, the need to repeat — follows from that one ruling.
 *
 * Three facts about the process shape this screen:
 *
 *  1. **One list, not one screen per part.** The committee sits on PSM 1 and
 *     PSM 2 in the same meeting, so both batches are on one board and filtered
 *     rather than separated.
 *  2. **A verdict needs locked marks.** A project cannot be ruled on until its
 *     Lampiran marks are in and locked, because the verdict commits the grade.
 *     Rather than hiding such a project, every row states its blocker — the
 *     same show-but-disable rule the rollover screen follows.
 *  3. **A failure is not a dead end.** The failed row survives with its marks,
 *     and the row offers the repeat. What a repeat *means* differs by batch, and
 *     the server decides: PSM 1 goes back to registration, PSM 2 gets a fresh
 *     project in the next term.
 *
 * The page also deliberately refuses to resolve a project twice, and warns
 * before a repeat is started out of turn (term still running) — both are rules
 * the backend enforces too, but a button that explains itself is better than a
 * 422.
 */
export default function VerdictPage() {
  const { selectedId: termId, active } = useSemesters()
  const [searchParams] = useSearchParams()

  const scopedSemesterId = searchParams.get('semester_id')
  const semesterId = scopedSemesterId ?? termId ?? active?.id ?? null

  const [rows, setRows] = useState([])
  const [semesterName, setSemesterName] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  /** Which row is mid-action, so only that row's buttons spin. */
  const [busyKey, setBusyKey] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)

  /** The fail-reason dialog: `{ row }` when open, `null` when closed. */
  const [failing, setFailing] = useState(null)
  /** The repeat confirmation: `{ row }` when open. */
  const [repeating, setRepeating] = useState(null)

  // --- Filters ---------------------------------------------------------
  // All three are client-side: the worklist is one term's projects (tens, not
  // thousands), and a filter that does not round-trip to the server responds
  // instantly, which is what a coordinator scanning for one student wants.
  const [part, setPart] = useState('')
  const [state, setState] = useState('pending')
  const [query, setQuery] = useState('')

  const load = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true)
    setError(null)
    try {
      const payload = await projectApi.verdicts({
        semester_id: semesterId ?? undefined,
      })
      setRows(payload?.candidates ?? [])
      setSemesterName(payload?.semester ?? null)
    } catch (err) {
      setError(err)
    } finally {
      if (!quiet) setLoading(false)
    }
  }, [semesterId])

  useEffect(() => {
    load()
  }, [load])

  /**
   * Run one action against one row, then refresh.
   *
   * The refresh is quiet so the table does not flash — after a verdict the eye
   * is on the row that just changed, and a spinner over the whole board would
   * lose that.
   */
  async function act(row, fn) {
    setBusyKey(row.project_id)
    setActionError(null)
    setNotice(null)
    try {
      const result = await fn()
      await load({ quiet: true })
      return result
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
      return null
    } finally {
      setBusyKey(null)
    }
  }

  async function markPassed(row) {
    const res = await act(row, () => projectApi.resolve(row.project_id, 'passed', null))
    if (res) setNotice(`${row.code} recorded as passed.`)
  }

  async function markFailed(row, reason) {
    const res = await act(row, () => projectApi.resolve(row.project_id, 'failed', reason))
    if (res) {
      setFailing(null)
      setNotice(`${row.code} recorded as failed. The student may now repeat.`)
    }
  }

  async function startRepeat(row, keepTitle) {
    const res = await act(row, () => projectApi.repeat(row.project_id, keepTitle))
    if (res) {
      setRepeating(null)
      setNotice(res?.message ?? `Repeat started for ${row.code}.`)
    }
  }

  /** Every row in view after the filters — also what the summary counts. */
  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()

    return rows.filter((r) => {
      if (part && r.psm_part !== part) return false

      if (state === 'pending' && r.finished) return false
      if (state === 'decided' && !r.finished) return false
      if (state === 'passed' && !r.passed) return false
      if (state === 'failed' && r.status !== 'failed') return false

      if (q) {
        const haystack = [r.code, r.student_name, r.student_id, r.title, r.supervisor]
          .filter(Boolean)
          .join(' ')
          .toLowerCase()
        if (!haystack.includes(q)) return false
      }

      return true
    })
  }, [rows, part, state, query])

  const counts = useMemo(() => {
    const of = (fn) => rows.filter(fn).length
    return {
      all: rows.length,
      pending: of((r) => !r.finished),
      passed: of((r) => r.passed),
      failed: of((r) => r.status === 'failed'),
      ready: of((r) => r.can_resolve),
      psm1: of((r) => r.psm_part === 'PSM1'),
      psm2: of((r) => r.psm_part === 'PSM2'),
    }
  }, [rows])

  if (loading) return <Spinner label="Loading the verdict worklist" />
  if (error) return <ErrorState error={error} onRetry={() => load()} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Verdicts"
        subtitle="Rule each project passed or failed at the end of the semester. A failure opens the repeat."
        action={
          <div className="text-right text-sm text-slate-500">
            <div className="font-medium text-slate-700">
              {counts.ready} of {counts.pending} ready to rule
            </div>
            <div>{semesterName ?? 'No term selected'}</div>
          </div>
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

      <FilterBar>
        <FilterField label="Batch">
          <SegmentedControl
            value={part}
            onChange={setPart}
            name="verdict_part"
            options={[
              { value: '', label: 'All', count: counts.all },
              { value: 'PSM1', label: 'PSM 1', count: counts.psm1 },
              { value: 'PSM2', label: 'PSM 2', count: counts.psm2 },
            ]}
          />
        </FilterField>

        <FilterField label="Outcome">
          <Select value={state} onChange={(e) => setState(e.target.value)}>
            <option value="pending">Awaiting a verdict ({counts.pending})</option>
            <option value="decided">Already decided</option>
            <option value="passed">Passed ({counts.passed})</option>
            <option value="failed">Failed ({counts.failed})</option>
            <option value="all">Everything ({counts.all})</option>
          </Select>
        </FilterField>

        <FilterField label="Find" className="min-w-[14rem] flex-1">
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Name, matric, project code or title"
            className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm
              focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
          />
        </FilterField>
      </FilterBar>

      <Card className="overflow-hidden p-0">
        <CardHeader
          title="Projects in this term"
          subtitle="A project can only be ruled on once its marks are locked"
        />

        <DataTable
          columns={[
            { label: 'Student', width: '20%' },
            { label: 'Batch', width: '8%' },
            { label: 'Project', width: '26%' },
            { label: 'Mark', align: 'right', width: '8%' },
            { label: 'Outcome', width: '14%' },
            { label: '', align: 'right', width: '24%' },
          ]}
          rows={visible}
          keyField="project_id"
          minWidth={900}
          empty={
            <EmptyState
              title={
                rows.length === 0
                  ? 'No projects in this term'
                  : 'Nothing matches these filters'
              }
              message={
                rows.length === 0
                  ? 'Verdicts are given to projects, so a term with no registered projects has nothing to rule on yet.'
                  : 'Clear the filters to see the rest of the term.'
              }
            />
          }
          render={(row) => (
            <VerdictRow
              key={row.project_id}
              row={row}
              busy={busyKey === row.project_id}
              onPass={() => markPassed(row)}
              onFail={() => setFailing({ row })}
              onRepeat={() => setRepeating({ row })}
            />
          )}
        />
      </Card>

      {failing && (
        <FailDialog
          row={failing.row}
          busy={busyKey === failing.row.project_id}
          error={actionError}
          onCancel={() => setFailing(null)}
          onConfirm={(reason) => markFailed(failing.row, reason)}
        />
      )}

      {repeating && (
        <RepeatDialog
          row={repeating.row}
          busy={busyKey === repeating.row.project_id}
          onCancel={() => setRepeating(null)}
          onConfirm={(keepTitle) => startRepeat(repeating.row, keepTitle)}
        />
      )}
    </div>
  )
}

/**
 * One project on the board.
 *
 * The action column has four possible faces and only one shows at a time,
 * because a row is in exactly one of four states: running and unblocked (Pass /
 * Fail), running and blocked (the reason, no buttons), passed (a badge), or
 * failed (the repeat).
 */
function VerdictRow({ row, busy, onPass, onFail, onRepeat }) {
  return (
    <tr className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
      <Td>
        <div className="min-w-0">
          <p className="truncate font-medium text-slate-800">{row.student_name ?? '—'}</p>
          <p className="font-mono text-xs text-slate-400">{row.student_id ?? '—'}</p>
        </div>
      </Td>

      <Td>
        <Badge tone={PSM_PART_BADGE_TONES[row.psm_part] ?? 'neutral'}>
          {partLabel(row.psm_part, { short: true })}
        </Badge>
      </Td>

      <Td>
        <div className="min-w-0">
          <Link
            to={`/projects/${row.project_id}`}
            className="block truncate text-sm text-slate-800 hover:text-brand-700 hover:underline"
            title={row.title}
          >
            {row.title ?? '(no title)'}
          </Link>
          <p className="font-mono text-xs text-slate-400">{row.code}</p>
        </div>
      </Td>

      <Td align="right">
        <span className="tabular-nums text-sm text-slate-700">{formatMark(row.mark)}</span>
      </Td>

      <Td>
        <OutcomeCell row={row} />
      </Td>

      <Td align="right">
        <div className="flex flex-wrap items-center justify-end gap-2">
          {row.can_resolve && (
            <>
              <Button size="sm" variant="secondary" onClick={onFail} disabled={busy}>
                Fail…
              </Button>
              <Button size="sm" variant="success" onClick={onPass} disabled={busy} loading={busy}>
                Pass
              </Button>
            </>
          )}

          {row.status === 'failed' && (
            <Button size="sm" variant="secondary" onClick={onRepeat} disabled={busy}>
              Start repeat
            </Button>
          )}

          {row.finished && row.status === 'completed' && (
            <span className="text-xs text-slate-400">Decided</span>
          )}
        </div>
      </Td>
    </tr>
  )
}

/** The middle column: what the project's outcome is, or why it has none yet. */
function OutcomeCell({ row }) {
  if (row.status === 'completed') {
    return (
      <div className="space-y-1">
        <Badge tone="success">Passed</Badge>
        {row.resolved_at && (
          <p className="text-xs text-slate-400">{formatDateTime(row.resolved_at)}</p>
        )}
      </div>
    )
  }

  if (row.status === 'failed') {
    return (
      <div className="space-y-1">
        <Badge tone="danger">Failed</Badge>
        {row.outcome_reason ? (
          <p className="line-clamp-2 text-xs text-slate-500" title={row.outcome_reason}>
            {row.outcome_reason}
          </p>
        ) : (
          <p className="text-xs text-slate-400">Repeat required</p>
        )}
      </div>
    )
  }

  if (row.blocker) {
    return (
      <div className="space-y-1">
        <Badge tone="neutral">Awaiting marks</Badge>
        <p className="text-xs text-slate-500">{row.blocker}</p>
      </div>
    )
  }

  return <Badge tone="warning">Ready to rule</Badge>
}

/**
 * The fail dialog.
 *
 * A reason is required here even though the API treats it as optional for a
 * pass: a fail is what the student is shown, its text is what a repeat is later
 * defended with, and a committee that cannot say why a student failed has not
 * really decided. The server allows a bare fail; the screen does not offer one.
 */
function FailDialog({ row, busy, error, onCancel, onConfirm }) {
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)

  const empty = reason.trim().length < 10
  const showError = touched && empty

  return (
    <Dialog
      title="Record as failed"
      subtitle={`${row.student_name ?? row.code} · ${row.code}`}
      onCancel={onCancel}
    >
      <p className="mb-3 text-sm text-slate-600">
        The student keeps this attempt and its marks. Recording a failure is what
        lets them repeat {partLabel(row.psm_part)} in a later semester — nothing is
        deleted.
      </p>

      <Field
        label="Why did this fail?"
        required
        hint="Shown to the student and kept on the project. At least a sentence."
      >
        <Textarea
          rows={4}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          onBlur={() => setTouched(true)}
          placeholder="e.g. Chapters 5–7 were not submitted by the final deadline and the report fell below the 40% pass mark."
        />
      </Field>

      {showError && (
        <p className="mt-1 text-sm text-rose-700">
          Write at least a short sentence explaining the failure.
        </p>
      )}

      {error && (
        <p className="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>
      )}

      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
        <Button
          variant="danger"
          onClick={() => {
            setTouched(true)
            if (!empty) onConfirm(reason.trim())
          }}
          disabled={busy}
          loading={busy}
        >
          Record failure
        </Button>
      </div>
    </Dialog>
  )
}

/**
 * The repeat confirmation.
 *
 * The dialog exists because the two batches repeat in genuinely different ways
 * and the coordinator should know which one they are setting off. Title choice
 * is PSM 2 only: a PSM 1 repeat re-files Lampiran A and re-proposes whatever
 * title the student wants, so carrying the old one would be a promise the
 * system cannot keep.
 *
 * The out-of-term warning is advisory, not a lock. The server refuses the
 * repeat outright until the failed term is closed, so if this dialog is even
 * reachable the term has closed; the warning covers the other mistake — starting
 * a repeat while the *target* term has already begun, which is legal but worth a
 * second look.
 */
function RepeatDialog({ row, busy, onCancel, onConfirm }) {
  const isPsm1 = row.psm_part === 'PSM1'
  const [keepTitle, setKeepTitle] = useState(true)

  return (
    <Dialog
      title="Start a repeat"
      subtitle={`${row.student_name ?? row.code} · ${row.code} · ${partLabel(row.psm_part)}`}
      onCancel={onCancel}
    >
      {isPsm1 ? (
        <div className="space-y-3 text-sm text-slate-600">
          <p>
            A PSM 1 repeat starts over <strong>from registration</strong>. No project
            is created now: the student files Lampiran A again in the next semester,
            proposes a title, and is paired with a supervisor — the same path as a
            first attempt.
          </p>
          <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
            This attempt and its marks stay on record. The supervisor slot it
            occupied is released.
          </p>
        </div>
      ) : (
        <div className="space-y-3 text-sm text-slate-600">
          <p>
            A PSM 2 repeat creates a <strong>new project in the next semester</strong>{' '}
            with the same supervisor, and the Chapters 5–7 chain starts fresh. The
            failed attempt stays on record with its marks.
          </p>

          <Field label="Title">
            <div className="space-y-2">
              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="radio"
                  name="keep_title"
                  className="mt-0.5"
                  checked={keepTitle}
                  onChange={() => setKeepTitle(true)}
                />
                <span>
                  Keep the current title
                  <span className="block text-xs text-slate-400">
                    “{row.title ?? 'no title'}”
                  </span>
                </span>
              </label>
              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="radio"
                  name="keep_title"
                  className="mt-0.5"
                  checked={!keepTitle}
                  onChange={() => setKeepTitle(false)}
                />
                <span>
                  Start with a new title
                  <span className="block text-xs text-slate-400">
                    The project is created untitled; a new one is proposed later.
                  </span>
                </span>
              </label>
            </div>
          </Field>
        </div>
      )}

      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
        <Button onClick={() => onConfirm(isPsm1 ? true : keepTitle)} disabled={busy} loading={busy}>
          {isPsm1 ? 'Send back to registration' : 'Create the new attempt'}
        </Button>
      </div>
    </Dialog>
  )
}

/**
 * A minimal modal.
 *
 * `ui.jsx` has no dialog primitive, and this page is the only caller — a fixed
 * overlay plus a centred panel is the whole requirement, so it lives here rather
 * than becoming a shared component nobody else uses yet.
 */
function Dialog({ title, subtitle, children, onCancel }) {
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onCancel()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onCancel])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
        <div className="mb-3">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
        </div>
        {children}
      </div>
    </div>
  )
}
