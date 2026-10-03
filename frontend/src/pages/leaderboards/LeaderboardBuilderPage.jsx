import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { leaderboardApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, DataTable, Td, Field, Input,
} from '../../components/ui'
import { formatMark, medalFor } from '../../lib/format'

/**
 * Board builder — inspect and adjust a draft before it goes public.
 *
 * The point of a draft is that staff can see exactly who would be shown and
 * who would be left out, with reasons, before committing. So the excluded list
 * is given equal weight to the ranked list: an unexplained omission from an
 * award is the thing that generates complaints.
 */
export default function LeaderboardBuilderPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [board, setBoard] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [tab, setTab] = useState('ranked')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // leaderboardApi.get() already unwraps the envelope.
      const data = await leaderboardApi.get(id)
      setBoard(data)

      // Eligibility is only meaningful for a draft; a published board is frozen.
      // leaderboardApi.eligible() already unwraps, so no .then(unwrap) here.
      if (!data?.is_published) {
        const eligible = await leaderboardApi
          .eligible({ min_assessors: data?.min_assessors })
          .catch(() => null)
        setEligibility(eligible)
      } else {
        setEligibility(null)
      }
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  async function update(patch) {
    setSaving(true)
    setActionError(null)
    try {
      await leaderboardApi.update(id, patch)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'Could not save that change.')
    } finally {
      setSaving(false)
    }
  }

  async function publish() {
    setSaving(true)
    setActionError(null)
    try {
      await leaderboardApi.publish(id)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'Could not publish this board.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner label="Loading board" />
  if (error) return <ErrorState error={error} />
  if (!board) return <ErrorState error={{ message: 'Board not found.' }} />

  const entries = board.entries ?? []
  const ranked = entries.filter((e) => e.rank != null).sort((a, b) => a.rank - b.rank)
  const excluded = eligibility?.excluded ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title={board.title ?? 'Award board'}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            {board.is_published ? (
              <Badge tone="success">live</Badge>
            ) : (
              <Badge tone="warning">draft</Badge>
            )}
            <span className="font-mono text-xs">{board.slug}</span>
            {board.academic_session && <Badge tone="neutral">{board.academic_session}</Badge>}
          </span>
        }
        back={{ to: '/leaderboards', label: 'All boards' }}
        actions={
          <div className="flex gap-2">
            {board.is_published ? (
              <>
                <Button variant="secondary" onClick={() => window.open(`/leaderboard/${board.slug}`, '_blank')}>
                  View public page
                </Button>
                <Button
                  variant="secondary"
                  disabled={saving}
                  onClick={() =>
                    update({ is_published: false }).then(() => navigate('/leaderboards'))
                  }
                >
                  Take down
                </Button>
              </>
            ) : (
              <Button disabled={saving || ranked.length === 0} onClick={publish}>
                {saving ? 'Working…' : 'Publish board'}
              </Button>
            )}
          </div>
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {!board.is_published && (
        <Card>
          <CardHeader title="Display options" subtitle="Applies to this board only" />
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Winners to show" htmlFor="top_n">
              <Input
                id="top_n"
                type="number"
                min={1}
                max={20}
                defaultValue={board.top_n ?? 3}
                onBlur={(e) => {
                  const value = Number(e.target.value)
                  if (value && value !== board.top_n) update({ top_n: value })
                }}
              />
            </Field>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={board.show_scores !== false}
                onChange={(e) => update({ show_scores: e.target.checked })}
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              />
              Show marks publicly
            </label>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={board.show_student_names !== false}
                onChange={(e) => update({ show_student_names: e.target.checked })}
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              />
              Show student names
            </label>
          </div>
          <p className="mt-3 text-sm text-slate-500">
            Hiding names or marks still shows the project title and rank. Student IDs are
            never published.
          </p>
        </Card>
      )}

      <div className="flex gap-1 border-b border-slate-200">
        {[
          { key: 'ranked', label: 'Ranked', count: ranked.length },
          { key: 'excluded', label: 'Excluded', count: excluded.length },
        ].map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition ${
              tab === t.key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
            aria-current={tab === t.key ? 'page' : undefined}
          >
            {t.label}
            <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 text-xs tabular-nums text-slate-600">
              {t.count}
            </span>
          </button>
        ))}
      </div>

      {tab === 'ranked' ? (
        ranked.length === 0 ? (
          <Card>
            <EmptyState
              title="No ranked entries"
              message="This board has no ranking yet. Build a draft from the awards page once marks are released."
            />
          </Card>
        ) : (
          <Card className="overflow-hidden p-0">
            <div className="overflow-x-auto">
              <DataTable
                columns={['Rank', 'Project', 'Students', 'Category', 'Assessors', 'Final mark', 'Board']}
              >
                {ranked.map((entry) => {
                  const medal = medalFor(entry.rank)
                  const inTop = entry.rank <= (board.top_n ?? 3)

                  return (
                    <tr
                      key={entry.id}
                      className={`hover:bg-slate-50/60 ${inTop ? 'bg-brand-50/30' : ''}`}
                    >
                      <Td>
                        <span className="inline-flex items-center gap-1.5">
                          {medal && <span aria-hidden="true">{medal}</span>}
                          <span className="font-semibold tabular-nums text-slate-800">
                            {entry.rank}
                          </span>
                        </span>
                      </Td>
                      <Td>
                        <div className="font-medium text-slate-800">{entry.title}</div>
                        {entry.code && (
                          <div className="font-mono text-xs text-slate-400">{entry.code}</div>
                        )}
                      </Td>
                      <Td className="text-sm text-slate-600">
                        {(entry.students ?? [])
                          .map((s) => (typeof s === 'string' ? s : s.name))
                          .filter(Boolean)
                          .join(', ') || '—'}
                      </Td>
                      <Td className="text-sm capitalize text-slate-600">
                        {entry.category ?? '—'}
                      </Td>
                      <Td className="text-center tabular-nums text-slate-600">
                        {entry.assessor_count ?? '—'}
                      </Td>
                      <Td className="font-semibold tabular-nums">
                        {formatMark(entry.final_mark)}
                      </Td>
                      <Td>
                        {inTop ? (
                          <Badge tone="brand">shown</Badge>
                        ) : (
                          <Badge tone="neutral">hidden</Badge>
                        )}
                      </Td>
                    </tr>
                  )
                })}
              </DataTable>
            </div>
          </Card>
        )
      ) : excluded.length === 0 ? (
        <Card>
          <EmptyState
            title="Nothing excluded"
            message="Every project with a released grade qualifies for this board."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable columns={['Project', 'Student', 'Category', 'Why excluded']}>
              {excluded.map((row) => (
                <tr key={row.id ?? row.project_id} className="hover:bg-slate-50/60">
                  <Td>
                    <div className="font-medium text-slate-800">{row.title}</div>
                    {row.code && (
                      <div className="font-mono text-xs text-slate-400">{row.code}</div>
                    )}
                  </Td>
                  <Td className="text-sm text-slate-600">
                    {row.student?.name ?? row.student_name ?? '—'}
                    {row.student?.student_id && (
                      <span className="ml-1.5 font-mono text-xs text-slate-400">
                        {row.student.student_id}
                      </span>
                    )}
                  </Td>
                  <Td className="text-sm capitalize text-slate-600">{row.category ?? '—'}</Td>
                  <Td>
                    <ul className="space-y-0.5">
                      {(row.reasons ?? []).map((reason) => (
                        <li key={reason} className="text-sm text-amber-700">
                          {reason}
                        </li>
                      ))}
                      {(row.reasons?.length ?? 0) === 0 && (
                        <li className="text-sm text-slate-400">No reason recorded</li>
                      )}
                    </ul>
                  </Td>
                </tr>
              ))}
            </DataTable>
          </div>
        </Card>
      )}
    </div>
  )
}
