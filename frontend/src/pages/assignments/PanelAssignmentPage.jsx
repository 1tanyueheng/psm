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

  const visibleRows = useMemo(() => {
    const needle = search.trim().toLowerCase()
    if (!needle) return matching

    return matching.filter((row) =>
      [row.student?.name, row.student?.student_id, row.project?.code]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle)),
    )
  }, [matching, search])

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

            <DataTable
              columns={['Student', 'Cannot examine', 'Seated', 'Eligible', '']}
              rows={visibleRows}
              empty="No students match that search."
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
