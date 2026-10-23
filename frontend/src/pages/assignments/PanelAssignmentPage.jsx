import { useCallback, useEffect, useMemo, useState } from 'react'
import { assignmentApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, Spinner, ErrorState, Button, Select, Field,
  Input, DataTable, Td,
} from '../../components/ui'

/**
 * Module 2 — matching students to examiners.
 *
 * Two halves of one question:
 *
 *  1. **The matching table** — every student beside every examiner who could
 *     examine them. This is the coordinator's overview: who is excluded and why,
 *     who is already seated, and what is left to choose from.
 *  2. **The seating panel** — pick a pair for the selected student.
 *
 * A panel is **two people** who both decide the proposal and give the final
 * mark, so it is seated against a *student* rather than one examiner at a time
 * against a project. The panel has to exist before the project does — it rules
 * on the title at the proposal milestone — so the student is the anchor.
 *
 * **A student's own supervisor is never eligible.** That is the point of the
 * screen, not a nicety, so the exclusion is shown explicitly: a coordinator who
 * cannot find a name needs to see why it is missing rather than guess. The server
 * refuses them again on submit, so a stale page cannot seat one either.
 */
export default function PanelAssignmentPage() {
  const [matching, setMatching] = useState([])
  const [studentId, setStudentId] = useState('')
  const [panel, setPanel] = useState(null)

  const [loading, setLoading] = useState(true)
  const [panelLoading, setPanelLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [search, setSearch] = useState('')

  /**
   * Filters, added because the matching table is one long list and the
   * coordinator's job on this screen is a *search* task, not a browse.
   *
   * Three axes, chosen from what actually varies between students rather than
   * from what was easy to compute: their programme (the rule a panel must
   * respect anyway), their batch, and whether they still need a panel. The
   * last is the one that saves the most time — a coordinator working through
   * the queue only ever wants the students who are still unseated, and
   * scrolling past finished ones is the whole cost of this screen.
   */
  const [filterProgramme, setFilterProgramme] = useState('')
  const [filterBatch, setFilterBatch] = useState('')
  const [filterPanel, setFilterPanel] = useState('')
  const [filterPart, setFilterPart] = useState('')

  const [chair, setChair] = useState('')
  const [member, setMember] = useState('')

  const loadMatching = useCallback(async () => {
    try {
      const rows = await assignmentApi.panelMatching()
      setMatching(Array.isArray(rows) ? rows : [])
      return rows ?? []
    } catch (err) {
      setError(err)
      return []
    } finally {
      setLoading(false)
    }
  }, [])

  // Seed the picker from the first row on the initial load only.
  useEffect(() => {
    let cancelled = false

    loadMatching().then((rows) => {
      if (!cancelled && rows.length > 0) {
        setStudentId((current) => current || String(rows[0].student.profile_id))
      }
    })

    return () => {
      cancelled = true
    }
  }, [loadMatching])

  const loadPanel = useCallback(async (id) => {
    if (!id) {
      setPanel(null)
      return
    }

    setPanelLoading(true)
    setActionError(null)

    try {
      const data = await assignmentApi.panel(id)
      setPanel(data)

      // Seed the pickers from what is already seated, so saving without
      // touching them is a no-op rather than a surprise re-pairing.
      const seated = data?.seated ?? []
      const seatedChair = seated.find((s) => s.panel_role === 'chair') ?? seated[0]
      const seatedMember = seated.find((s) => s.panel_role === 'member') ?? seated[1]

      setChair(seatedChair ? String(seatedChair.examiner_id) : '')
      setMember(seatedMember ? String(seatedMember.examiner_id) : '')
    } catch (err) {
      setActionError(err?.message ?? 'That panel could not be loaded.')
    } finally {
      setPanelLoading(false)
    }
  }, [])

  useEffect(() => {
    loadPanel(studentId)
  }, [studentId, loadPanel])

  /**
   * Everyone selectable: the eligible candidates, plus whoever is already
   * seated. Without the second half, editing an existing panel would drop the
   * current examiners out of the list the moment they became ineligible.
   */
  const options = useMemo(() => {
    const merged = new Map()

    for (const c of panel?.candidates ?? []) {
      merged.set(String(c.examiner_id), { id: String(c.examiner_id), name: c.name, seated: false })
    }
    for (const s of panel?.seated ?? []) {
      merged.set(String(s.examiner_id), { id: String(s.examiner_id), name: s.name, seated: true })
    }

    return [...merged.values()].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''))
  }, [panel])

  /**
   * The distinct values worth offering, derived from the rows themselves.
   *
   * Built from the data rather than from a hardcoded list so a filter can never
   * offer a choice that matches nothing, and so a new batch or programme
   * appears without a code change. Sorted for a stable order between renders.
   */
  const filterOptions = useMemo(() => {
    const collect = (pick) => [...new Set(matching.map(pick).filter(Boolean))].sort()

    return {
      programmes: collect((r) => r.student?.program_code),
      batches: collect((r) => r.student?.batch),
      parts: collect((r) => r.psm_part),
    }
  }, [matching])

  const visibleRows = useMemo(() => {
    const needle = search.trim().toLowerCase()

    return matching.filter((row) => {
      if (filterProgramme && row.student?.program_code !== filterProgramme) return false
      if (filterBatch && row.student?.batch !== filterBatch) return false
      if (filterPart && row.psm_part !== filterPart) return false

      // "Needs a panel" is the seat count against the configured panel size.
      // The size travels on the row rather than being assumed to be 2, so this
      // keeps working if the faculty ever changes it.
      if (filterPanel === 'unseated' && (row.seated ?? []).length > 0) return false
      if (filterPanel === 'seated' && (row.seated ?? []).length === 0) return false
      if (filterPanel === 'incomplete') {
        const size = row.panel_size ?? 2
        const count = (row.seated ?? []).length
        if (count === 0 || count >= size) return false
      }

      if (!needle) return true

      return [row.student?.name, row.student?.student_id, row.project?.code]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle))
    })
  }, [matching, search, filterProgramme, filterBatch, filterPanel, filterPart])

  const filtersActive =
    filterProgramme || filterBatch || filterPanel || filterPart || search.trim()

  const canSave = chair !== '' && member !== '' && chair !== member

  async function save() {
    setSaving(true)
    setActionError(null)
    setNotice(null)

    try {
      // Order matters: the first id is the chair.
      await assignmentApi.assignPanel(studentId, [Number(chair), Number(member)], panel?.psm_part)
      setNotice("Panel seated. The two examiners now decide this student's proposal and final mark.")
      await Promise.all([loadPanel(studentId), loadMatching()])
    } catch (err) {
      setActionError(err?.message ?? 'That panel could not be seated.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="Matching students to examiners" />
  if (error) return <ErrorState error={error} />

  const selected = matching.find((row) => String(row.student.profile_id) === studentId)
  const panelSize = panel?.panel_size ?? 2
  const seatedCount = (panel?.seated ?? []).length

  return (
    <div className="space-y-6">
      <PageHeader
        title="Examiner panels"
        subtitle="Every student beside the examiners who may examine them — their own supervisor never may"
      />

      {actionError && <ErrorState error={{ message: actionError }} />}
      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}

      {matching.length === 0 ? (
        <Card>
          <p className="text-sm text-slate-500">
            No students have a supervision pair yet, so there is nobody to match. Register a
            student first.
          </p>
        </Card>
      ) : (
        <>
          <Card>
            <CardHeader
              title="Matching"
              subtitle="Eligible examiners exclude the student's own supervisor and anyone already seated"
              action={
                <div className="w-64">
                  <Input
                    type="search"
                    placeholder="Name, matric or project"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="Filter students"
                  />
                </div>
              }
            />

            {/*
              The filter bar.

              Sits between the header and the table rather than inside it, so a
              coordinator can narrow the list without losing sight of what is
              filtered — and so the active state is obvious. The reset only
              appears when something is actually applied; a permanently visible
              "Clear" invites people to press it when nothing is filtered.
            */}
            <div className="flex flex-wrap items-end gap-3 border-t border-slate-100 px-5 py-3">
              <div className="w-40">
                <Select
                  value={filterProgramme}
                  onChange={(e) => setFilterProgramme(e.target.value)}
                  aria-label="Filter by programme"
                >
                  <option value="">All programmes</option>
                  {filterOptions.programmes.map((p) => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </Select>
              </div>

              <div className="w-32">
                <Select
                  value={filterBatch}
                  onChange={(e) => setFilterBatch(e.target.value)}
                  aria-label="Filter by batch"
                >
                  <option value="">All batches</option>
                  {filterOptions.batches.map((b) => (
                    <option key={b} value={b}>{b}</option>
                  ))}
                </Select>
              </div>

              <div className="w-36">
                <Select
                  value={filterPart}
                  onChange={(e) => setFilterPart(e.target.value)}
                  aria-label="Filter by PSM part"
                >
                  <option value="">PSM 1 and 2</option>
                  {filterOptions.parts.map((p) => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </Select>
              </div>

              <div className="w-44">
                <Select
                  value={filterPanel}
                  onChange={(e) => setFilterPanel(e.target.value)}
                  aria-label="Filter by panel status"
                >
                  <option value="">Any panel status</option>
                  <option value="unseated">Needs a panel</option>
                  <option value="incomplete">Panel incomplete</option>
                  <option value="seated">Panel seated</option>
                </Select>
              </div>

              <div className="ml-auto flex items-center gap-3">
                <span className="text-xs tabular-nums text-slate-500">
                  {visibleRows.length} of {matching.length}
                </span>
                {filtersActive && (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      setSearch('')
                      setFilterProgramme('')
                      setFilterBatch('')
                      setFilterPanel('')
                      setFilterPart('')
                    }}
                  >
                    Clear
                  </Button>
                )}
              </div>
            </div>

            <DataTable
              columns={['Student', 'Cannot examine', 'Seated', 'Eligible', '']}
              rows={visibleRows}
              empty={
                matching.length === 0
                  ? 'No students with a supervisor yet.'
                  : 'No students match these filters.'
              }
              render={(row) => (
                <tr key={row.student.profile_id} className="border-t border-slate-100">
                  <Td>
                    <div className="font-medium text-slate-800">{row.student.name}</div>
                    <div className="text-xs text-slate-400">
                      {row.student.student_id}
                      {row.project?.code ? ` · ${row.project.code}` : ''}
                    </div>
                  </Td>
                  <Td>
                    <div className="flex flex-wrap gap-1">
                      {(row.supervisors ?? []).map((s) => (
                        <Badge key={s.id} tone="danger">{s.name}</Badge>
                      ))}
                      {(row.supervisors ?? []).length === 0 && (
                        <span className="text-xs text-slate-400">No supervisor on record</span>
                      )}
                    </div>
                  </Td>
                  <Td>
                    <div className="flex flex-wrap gap-1">
                      {(row.seated ?? []).map((s) => (
                        <Badge key={s.id} tone="neutral">
                          {s.name}
                          {s.panel_role ? ` · ${s.panel_role}` : ''}
                        </Badge>
                      ))}
                      {(row.seated ?? []).length === 0 && <Badge tone="warning">No panel yet</Badge>}
                    </div>
                  </Td>
                  <Td>
                    <span className="tabular-nums text-slate-700">{row.eligible.length}</span>
                  </Td>
                  <Td align="right">
                    <Button
                      size="sm"
                      variant={String(row.student.profile_id) === studentId ? 'primary' : 'secondary'}
                      onClick={() => setStudentId(String(row.student.profile_id))}
                    >
                      Seat
                    </Button>
                  </Td>
                </tr>
              )}
            />
          </Card>

          {panelLoading && <Spinner label="Loading the panel" />}

          {panel && !panelLoading && (
            <Card>
              <CardHeader
                title={`Seat a panel — ${selected?.student.name ?? 'student'}`}
                subtitle="The first choice is the chair. Saving replaces the current panel."
                action={
                  <Badge tone={seatedCount >= panelSize ? 'success' : 'warning'}>
                    {seatedCount} of {panelSize} seats
                  </Badge>
                }
              />

              <div className="space-y-5">
                <dl className="grid gap-4 text-sm sm:grid-cols-3">
                  <div>
                    <dt className="text-slate-400">Batch</dt>
                    <dd className="text-slate-700">{panel.psm_part}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400">Project</dt>
                    <dd className="text-slate-700">{panel.project?.code ?? 'Not registered yet'}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400">Excluded</dt>
                    <dd className="text-slate-700">
                      {(panel.supervisors ?? []).map((s) => s.name).join(', ') || '—'}
                    </dd>
                  </div>
                </dl>

                <div className="grid gap-5 sm:grid-cols-2">
                  <Field label="Chair" htmlFor="panel_chair" required>
                    <Select id="panel_chair" value={chair} onChange={(e) => setChair(e.target.value)}>
                      <option value="">Choose the chair…</option>
                      {options
                        .filter((o) => o.id !== member)
                        .map((o) => (
                          <option key={o.id} value={o.id}>
                            {o.name}
                            {o.seated ? ' (currently seated)' : ''}
                          </option>
                        ))}
                    </Select>
                  </Field>

                  <Field label="Member" htmlFor="panel_member" required>
                    <Select id="panel_member" value={member} onChange={(e) => setMember(e.target.value)}>
                      <option value="">Choose the member…</option>
                      {options
                        .filter((o) => o.id !== chair)
                        .map((o) => (
                          <option key={o.id} value={o.id}>
                            {o.name}
                            {o.seated ? ' (currently seated)' : ''}
                          </option>
                        ))}
                    </Select>
                  </Field>
                </div>

                <p className="text-xs text-slate-400">
                  {options.length} eligible examiner{options.length === 1 ? '' : 's'} for{' '}
                  {selected?.student.name ?? 'this student'}. Capacity does not limit examining, so
                  a supervisor who is already full can still take a panel.
                </p>

                {/*
                  An empty list is a dead end for the coordinator unless it says
                  why. It happens for a real reason: a programme can have more
                  students than staff, and the two staff it has are either the
                  student's own supervisor or already seated. Nothing on this
                  screen can fix that, so the message names the actual remedy —
                  recording a programme on another staff member, or an admin
                  stepping in — instead of leaving a blank dropdown.
                */}
                {(selected?.excluded_by_programme?.length ?? 0) > 0 && (
                  <div className="rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                    <p className="text-sm text-amber-900">
                      {selected.excluded_by_programme.length} examiner
                      {selected.excluded_by_programme.length === 1 ? '' : 's'} excluded — they
                      belong to another programme, and a panel is drawn only from the student&apos;s
                      own programme.
                    </p>
                    {options.length === 0 && (
                      <p className="mt-1 text-sm text-amber-800">
                        Nobody is left for {selected.student?.program_code}. Record a programme on
                        another staff member, or ask an administrator to seat this panel.
                      </p>
                    )}
                  </div>
                )}

                <div className="flex justify-end">
                  <Button disabled={saving || !canSave} onClick={save}>
                    {saving ? 'Seating…' : 'Seat the panel'}
                  </Button>
                </div>
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
