import { useCallback, useEffect, useState } from 'react'
import { milestoneScheduleApi } from '../../api/endpoints'
import { useAuth } from '../../context/AuthContext'
import { useSemesters } from '../../context/SemesterContext'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Input, DataTable, Td, Select,
} from '../../components/ui'
import { PSM_PARTS, partLabel, PSM_PART_BADGE_TONES } from '../../lib/psmPart'
import { formatDate } from '../../lib/format'

/**
 * Coordinator — the faculty's milestone calendar.
 *
 * One set of deadlines per batch per term. Setting a date here moves it for
 * *every* student in the batch, which is the point: the faculty publishes a
 * calendar, and previously each project derived its own dates from its own
 * registration date — so two students who registered a fortnight apart were
 * held to deadlines a fortnight apart, and nothing could state the real date.
 *
 * Dates are edited as a whole calendar and saved together. A partial save would
 * leave the batch half on the old dates and half on the new with no way to tell
 * which, which is worse than refusing the save.
 *
 * Milestones whose work is already in are shown as locked rather than hidden:
 * the coordinator needs to know a change did not reach them, and why.
 */
export default function MilestoneSchedulePage() {
  const { user } = useAuth()
  const { selectedId: termId, active, selected } = useSemesters()

  const [part, setPart] = useState('PSM1')
  const [data, setData] = useState(null)
  const [draft, setDraft] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [actionError, setActionError] = useState(null)

  const canManage = ['coordinator', 'admin'].includes(user?.role)
  const semesterId = termId ?? active?.id ?? null

  const load = useCallback(async () => {
    if (semesterId == null) {
      setLoading(false)
      return
    }

    setLoading(true)
    setError(null)
    try {
      const result = await milestoneScheduleApi.get({
        academic_semester_id: semesterId,
        psm_part: part,
      })
      setData(result)

      // The draft is seeded from the server on every load, so a save or a batch
      // switch cannot leave stale edits behind.
      setDraft(
        Object.fromEntries(
          (result?.milestones ?? []).map((m) => [m.code, m.due_at ?? ''])
        )
      )
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [semesterId, part])

  useEffect(() => {
    load()
  }, [load])

  const milestones = data?.milestones ?? []

  /** Which rows differ from what is stored, so Save can be honest about it. */
  const changed = milestones.filter((m) => (draft[m.code] ?? '') !== (m.due_at ?? ''))

  async function save() {
    setBusy(true)
    setActionError(null)
    setNotice(null)
    try {
      const result = await milestoneScheduleApi.save({
        academic_semester_id: semesterId,
        psm_part: part,
        milestones: milestones.map((m) => ({
          code: m.code,
          due_at: draft[m.code],
          opens_at: m.opens_at ?? null,
        })),
      })
      setNotice(result?.message ?? 'Calendar saved.')
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'The calendar could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  async function reset() {
    setBusy(true)
    setActionError(null)
    setNotice(null)
    try {
      const result = await milestoneScheduleApi.reset({
        academic_semester_id: semesterId,
        psm_part: part,
        // Relative to today, which is what "start the calendar now" means for a
        // term whose dates have not been set. Anchoring on the term's own
        // `starts_at` is offered separately for a term being planned.
        start_at: new Date().toISOString().slice(0, 10),
      })
      setNotice(result?.message ?? 'Calendar re-seeded.')
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'The calendar could not be re-seeded.')
    } finally {
      setBusy(false)
    }
  }

  if (semesterId == null) {
    return (
      <div className="space-y-6">
        <PageHeader title="Milestone deadlines" />
        <Card>
          <EmptyState
            title="No term selected"
            message="Choose a term first — the calendar belongs to a term, not to the faculty as a whole."
          />
        </Card>
      </div>
    )
  }

  if (loading) return <Spinner label="Loading the milestone calendar" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Milestone deadlines"
        subtitle="One calendar per batch. A date set here applies to every student in it."
        action={
          canManage ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={reset} disabled={busy}>
                Re-seed from template
              </Button>
              <Button onClick={save} disabled={busy || changed.length === 0}>
                {busy
                  ? 'Saving…'
                  : changed.length === 0
                    ? 'No changes'
                    : `Save ${changed.length} change${changed.length === 1 ? '' : 's'}`}
              </Button>
            </div>
          ) : undefined
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

      <Card>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Term">
            <Input value={selected?.name ?? '—'} readOnly disabled />
          </Field>
          <Field label="Batch">
            <Select value={part} onChange={(e) => setPart(e.target.value)}>
              {PSM_PARTS.map((p) => (
                <option key={p} value={p}>
                  {partLabel(p)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Students affected">
            <Input value={data?.project_count ?? 0} readOnly disabled />
          </Field>
        </div>

        {data?.term && (
          <p className="mt-3 text-xs text-slate-500">
            {data.term.name} runs {formatDate(data.term.starts_at)} to{' '}
            {formatDate(data.term.ends_at)}. A deadline outside that span is
            usually a typo.
          </p>
        )}
      </Card>

      <Card className="overflow-hidden p-0">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Badge tone={PSM_PART_BADGE_TONES[part] ?? 'neutral'}>{partLabel(part)}</Badge>
              <span className="text-sm font-normal text-slate-500">
                {milestones.length} milestone{milestones.length === 1 ? '' : 's'}
              </span>
            </span>
          }
          subtitle="The deadline every student in this batch is held to"
        />

        {milestones.length === 0 ? (
          <div className="p-4">
            <EmptyState
              title="No milestones in this chain"
              message="No active milestone template covers this batch, so there is no calendar to set."
            />
          </div>
        ) : (
          <DataTable columns={['#', 'Milestone', 'Deadline', 'Reaches', 'Status']}>
            {milestones.map((m) => {
              const value = draft[m.code] ?? ''
              const isChanged = value !== (m.due_at ?? '')

              return (
                <tr key={m.code} className={isChanged ? 'bg-amber-50/50' : 'hover:bg-slate-50/60'}>
                  <Td className="tabular-nums text-slate-400">{m.sequence}</Td>

                  <Td>
                    <div className="text-sm font-medium text-slate-800">{m.title}</div>
                    <div className="font-mono text-xs text-slate-400">{m.code}</div>
                  </Td>

                  <Td>
                    {canManage ? (
                      <Input
                        type="date"
                        value={value}
                        onChange={(e) =>
                          setDraft((d) => ({ ...d, [m.code]: e.target.value }))
                        }
                        className="max-w-[11rem]"
                      />
                    ) : (
                      <span className="text-sm text-slate-700">{formatDate(m.due_at)}</span>
                    )}
                  </Td>

                  <Td>
                    <span className="tabular-nums text-sm text-slate-600">
                      {m.affected} student{m.affected === 1 ? '' : 's'}
                    </span>
                  </Td>

                  <Td>
                    {/*
                      Locked rows are the ones a change will not reach: their
                      work is already submitted, so the date they were held to is
                      part of their record. Saying so is the difference between
                      "the save worked" and "the save worked, except for these".
                    */}
                    {m.locked > 0 ? (
                      <Badge tone="warning">
                        {m.locked} already submitted
                      </Badge>
                    ) : (
                      <Badge tone="success">All open to change</Badge>
                    )}
                  </Td>
                </tr>
              )
            })}
          </DataTable>
        )}
      </Card>

      <Card>
        <p className="text-xs text-slate-500">
          <span className="font-medium text-slate-600">How this works.</span> Saving
          applies the dates to every project in this batch. A milestone whose work
          has already been submitted keeps the date the student was held to —
          moving it afterwards would change whether they were late, so those are
          left alone and can be changed one at a time from the milestone page with
          a recorded reason.
        </p>
      </Card>
    </div>
  )
}
