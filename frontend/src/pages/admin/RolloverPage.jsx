import { useCallback, useEffect, useMemo, useState } from 'react'
import { projectApi } from '../../api/endpoints'
import { useSemesters } from '../../context/SemesterContext'
import {
  Badge, Button, Card, CardHeader, EmptyState, ErrorState, PageHeader,
  Select, Spinner, DataTable, Td,
} from '../../components/ui'

/**
 * PSM 1 → PSM 2 rollover (Module 3).
 *
 * PSM 1 and PSM 2 are one project across two continuous terms on one title, so
 * the move is not a re-registration: the title, the supervisor and the examiner
 * panel all carry over, and the PSM 1 project is archived in the same step.
 *
 * The admin ticks who moves. It is a tick-list rather than a single "roll the
 * cohort over" button because a cohort is never uniformly ready — a student who
 * has not finished, or who is repeating PSM 1, must be left behind without
 * holding back everyone else. The screen therefore shows the students who
 * *cannot* move too, each with the reason, so nobody quietly disappears.
 *
 * Two gates stand in front of the whole action and both are stated once at the
 * top rather than repeated on every row:
 *
 *   1. the term must be closed — the rollover runs between terms, and a student
 *      in two live terms at once is not a state the rest of the app handles;
 *   2. every PSM 1 form in the term must be in, because the mark they were
 *      progressed on is what the archived record carries.
 */
export default function RolloverPage() {
  const { semesters, selectedId, selectSemester } = useSemesters()

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [ticked, setTicked] = useState(() => new Set())
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)
  const [actionError, setActionError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await projectApi.rolloverCandidates(selectedId ?? undefined)
      setData(res ?? null)
      // The tick-list is rebuilt on every load, so a stale id cannot be
      // submitted against a term the coordinator has since switched away from.
      setTicked(new Set())
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [selectedId])

  useEffect(() => {
    load()
  }, [load])

  const candidates = data?.candidates ?? []

  // A student already in PSM 2 cannot move again; the server refuses it, so
  // offering the checkbox would only produce a predictable error.
  const selectable = useMemo(
    () => candidates.filter((c) => !c.already_psm2),
    [candidates]
  )

  const allTicked = selectable.length > 0 && ticked.size === selectable.length

  function toggle(projectId) {
    setTicked((prev) => {
      const next = new Set(prev)
      if (next.has(projectId)) next.delete(projectId)
      else next.add(projectId)
      return next
    })
  }

  function toggleAll() {
    setTicked(allTicked ? new Set() : new Set(selectable.map((c) => c.project_id)))
  }

  async function submit() {
    setBusy(true)
    setResult(null)
    setActionError(null)
    try {
      const res = await projectApi.rollover([...ticked])
      setResult(res)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'The rollover could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  if (loading && !data) return <Spinner label="Loading the rollover" />
  if (error && !data) return <ErrorState error={error} />

  const ready = Boolean(data?.term_ready)

  return (
    <div className="space-y-6">
      <PageHeader
        title="PSM 1 → PSM 2 rollover"
        subtitle="Move students into PSM 2 on the title they already registered. Their supervisor and panel carry over."
        action={
          semesters.length > 0 ? (
            <Select
              value={selectedId ?? ''}
              onChange={(e) => selectSemester(e.target.value || null)}
              aria-label="Semester"
            >
              {semesters.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          ) : undefined
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {/* --- The two gates, stated once -------------------------------- */}
      {!ready && data?.term_reason && (
        <Card className="border-amber-200 bg-amber-50/60">
          <p className="text-sm text-amber-900">{data.term_reason}</p>
          {data.marks && (
            <p className="mt-1 text-xs text-amber-800">
              {data.marks.complete} of {data.marks.total} mark submissions complete
              {data.marks.outstanding > 0 && ` — ${data.marks.outstanding} still waiting on a form`}.
            </p>
          )}
          <p className="mt-2 text-xs text-amber-800">
            Nothing can be rolled over until both are satisfied. The same condition
            gates closing the semester, so completing the marking unblocks both.
          </p>
        </Card>
      )}

      {ready && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">
            {data.semester} is closed and its marking is complete. Ticked students can
            move to PSM 2.
          </p>
        </Card>
      )}

      {/* --- Outcome of a run ------------------------------------------ */}
      {result && (
        <Card>
          <CardHeader
            title="Last rollover"
            subtitle={
              result.moved === 0 && result.blocked === 0
                ? 'Nothing was selected.'
                : `${result.moved ?? 0} moved, ${result.blocked ?? 0} could not be.`
            }
          />
          {(result.progressed ?? []).length > 0 && (
            <ul className="mb-4 space-y-1">
              {result.progressed.map((row) => (
                <li key={row.project_id} className="text-sm text-emerald-800">
                  <span className="font-mono text-xs">{row.student}</span>{' '}
                  moved — {row.code} → {row.psm2_code}, title kept: “{row.title}”
                </li>
              ))}
            </ul>
          )}
          {(result.failed ?? []).length > 0 && (
            <>
              <p className="mb-1 text-sm font-medium text-rose-800">Not moved</p>
              <ul className="space-y-1">
                {result.failed.map((row) => (
                  <li key={row.project_id} className="text-sm text-rose-800">
                    <span className="font-mono text-xs">{row.code}</span>{' '}
                    {row.student ? `(${row.student})` : ''} — {row.reason}
                  </li>
                ))}
              </ul>
            </>
          )}
        </Card>
      )}

      {/* --- The tick-list --------------------------------------------- */}
      <Card>
        <CardHeader
          title="Students in this term"
          subtitle={`${candidates.length} live PSM 1 project(s). Tick the ones to move.`}
          action={
            selectable.length > 0 && ready ? (
              <div className="flex items-center gap-2">
                <Button size="sm" variant="secondary" onClick={toggleAll} disabled={busy}>
                  {allTicked ? 'Clear all' : `Tick all ${selectable.length}`}
                </Button>
                <Button size="sm" onClick={submit} disabled={busy || ticked.size === 0}>
                  {busy
                    ? 'Progressing…'
                    : ticked.size === 0
                      ? 'Move to PSM 2'
                      : `Move ${ticked.size} to PSM 2`}
                </Button>
              </div>
            ) : undefined
          }
        />

        {candidates.length === 0 ? (
          <EmptyState
            title="No PSM 1 projects in this term"
            message="Either the term holds no PSM 1 cohort, or every student has already been rolled over."
          />
        ) : (
          <DataTable
            columns={['', 'Student', 'Title that carries over', 'Supervisor', 'State']}
          >
            {candidates.map((row) => {
              // Blocked when the term is not ready, or the student already has
              // a live PSM 2 project.
              const blocked = !ready || row.already_psm2

              return (
                <tr
                  key={row.project_id}
                  className={blocked ? 'opacity-60' : 'hover:bg-slate-50/60'}
                >
                  <Td>
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300"
                      checked={ticked.has(row.project_id)}
                      disabled={blocked || busy}
                      onChange={() => toggle(row.project_id)}
                      aria-label={`Move ${row.student_id} to PSM 2`}
                    />
                  </Td>
                  <Td>
                    <div className="text-sm font-medium text-slate-800">
                      {row.student_name ?? '—'}
                    </div>
                    <div className="font-mono text-xs text-slate-400">
                      {row.student_id} · {row.code}
                    </div>
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {row.title}
                    <div className="text-xs text-slate-400">
                      {row.program} · batch {row.batch}
                    </div>
                  </Td>
                  <Td className="text-sm text-slate-600">{row.supervisor ?? '—'}</Td>
                  <Td>
                    {row.already_psm2 ? (
                      <>
                        <Badge tone="success">Already in PSM 2</Badge>
                        <div className="mt-0.5 font-mono text-xs text-slate-400">
                          {row.psm2_code}
                        </div>
                      </>
                    ) : ready ? (
                      <Badge tone="brand">Ready to move</Badge>
                    ) : (
                      <Badge tone="warning">Waiting on the term</Badge>
                    )}
                  </Td>
                </tr>
              )
            })}
          </DataTable>
        )}
      </Card>

      <Card>
        <CardHeader title="What moving a student does" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            <span className="font-medium text-slate-800">The title is kept.</span> PSM 2
            continues the project the student was examined on — there is no second
            Lampiran A and no re-allocation.
          </li>
          <li>
            <span className="font-medium text-slate-800">Supervisor and panel carry over</span>{' '}
            on the same appointment, widened to cover both parts.
          </li>
          <li>
            <span className="font-medium text-slate-800">A PSM 2 project is created</span>{' '}
            in the following term with the PSM 2 milestone chain, and the student&rsquo;s
            enrolment moves with it.
          </li>
          <li>
            <span className="font-medium text-slate-800">The PSM 1 project is archived</span>{' '}
            with the mark it was awarded, so the pair stays traceable in both
            directions.
          </li>
          <li className="text-slate-500">
            Leaving a student unticked is a real answer: it is how someone repeating
            PSM 1, or not yet finished, stays behind without holding up the rest.
          </li>
        </ul>
        <p className="mt-3 text-xs text-slate-500">
          PSM 2 runs in the term after PSM 1 — the following recorded semester, not the
          one you are viewing.
        </p>
      </Card>
    </div>
  )
}
