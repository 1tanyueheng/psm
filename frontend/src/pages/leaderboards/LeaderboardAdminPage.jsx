import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { leaderboardApi } from '../../api/endpoints'
import { unwrap, unwrapPaged } from '../../api/client'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, DataTable, Td,
} from '../../components/ui'
import { formatDate } from '../../lib/format'

/**
 * Pixel-It award administration (Module 8, staff side).
 *
 * Publishing is deliberately two-phase. `build` computes a board from current
 * marks into a draft snapshot; `publish` flips that snapshot live. The public
 * page therefore always reads a frozen ranking rather than recomputing on each
 * request, which means a board cannot change underneath a visitor mid-ceremony
 * — and a half-graded cohort never produces a half-built public page.
 */
export default function LeaderboardAdminPage() {
  const [boards, setBoards] = useState([])
  const [settings, setSettings] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [boardRes, settingRes, eligibleRes] = await Promise.allSettled([
        leaderboardApi.list({ per_page: 50 }),
        leaderboardApi.settings(),
        leaderboardApi.eligible({ per_page: 1 }),
      ])

      if (boardRes.status === 'rejected') throw boardRes.reason
      setBoards(unwrapPaged(boardRes.value).items)
      setSettings(settingRes.status === 'fulfilled' ? unwrap(settingRes.value) : null)
      setEligibility(eligibleRes.status === 'fulfilled' ? unwrap(eligibleRes.value) : null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  async function act(key, fn) {
    setBusy(key)
    setActionError(null)
    try {
      await fn()
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'That action could not be completed.')
    } finally {
      setBusy(null)
    }
  }

  if (loading) return <Spinner label="Loading award boards" />
  if (error) return <ErrorState error={error} />

  const excluded = eligibility?.excluded_count ?? 0
  const eligibleCount = eligibility?.eligible_count ?? 0

  return (
    <div className="space-y-6">
      <PageHeader
        title="Pixel-It awards"
        subtitle="Ranked recognition for completed PSM projects"
        actions={
          <Button
            disabled={busy === 'build'}
            onClick={() => act('build', () => leaderboardApi.build({}))}
          >
            {busy === 'build' ? 'Building…' : 'Build a new draft'}
          </Button>
        }
      />

      {actionError && <ErrorState error={{ message: actionError }} />}

      {/* Eligibility summary — this is what stops a bad board being published. */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">Eligible projects</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">{eligibleCount}</p>
          <p className="mt-1 text-sm text-slate-500">
            Released grades and at least {settings?.min_assessors ?? 2} assessors
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">Excluded</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">{excluded}</p>
          <p className="mt-1 text-sm text-slate-500">
            {settings?.honour_opt_out ? 'Includes students who opted out' : 'Not eligible for ranking'}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-400">Live board</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-slate-900">
            {boards.filter((b) => b.is_published).length}
          </p>
          <p className="mt-1 text-sm text-slate-500">Currently visible to the public</p>
        </Card>
      </div>

      <Card>
        <CardHeader title="Boards" subtitle="Draft boards are private until published" />
        {boards.length === 0 ? (
          <EmptyState
            title="No boards yet"
            message="Build a draft to snapshot the current rankings, then publish it."
          />
        ) : (
          <DataTable columns={['Board', 'Session', 'Entries', 'Top N', 'State', 'Built', '']}>
            {boards.map((board) => (
              <tr key={board.id} className="hover:bg-slate-50/60">
                <Td>
                  <div className="font-medium text-slate-800">{board.title ?? board.name}</div>
                  {board.slug && (
                    <div className="font-mono text-xs text-slate-400">{board.slug}</div>
                  )}
                </Td>
                <Td className="text-sm text-slate-600">{board.academic_session ?? '—'}</Td>
                <Td className="tabular-nums">{board.entries_count ?? board.entry_count ?? 0}</Td>
                <Td className="tabular-nums">{board.top_n ?? '—'}</Td>
                <Td>
                  {board.is_published ? (
                    <>
                      <Badge tone="success">live</Badge>
                      {board.published_at && (
                        <div className="mt-0.5 text-xs text-slate-400">
                          {formatDate(board.published_at)}
                        </div>
                      )}
                    </>
                  ) : (
                    <Badge tone="warning">draft</Badge>
                  )}
                </Td>
                <Td className="text-sm text-slate-500">
                  {formatDate(board.built_at ?? board.created_at, { fallback: '—' })}
                </Td>
                <Td>
                  <div className="flex flex-wrap gap-2">
                    <Link to={`/leaderboards/${board.id}`}>
                      <Button size="sm" variant="secondary">Open</Button>
                    </Link>
                    {!board.is_published ? (
                      <Button
                        size="sm"
                        disabled={busy === `publish-${board.id}`}
                        onClick={() =>
                          act(`publish-${board.id}`, () => leaderboardApi.publish(board.id))
                        }
                      >
                        {busy === `publish-${board.id}` ? 'Publishing…' : 'Publish'}
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy === `unpublish-${board.id}`}
                        onClick={() =>
                          act(`unpublish-${board.id}`, () => leaderboardApi.unpublish(board.id))
                        }
                      >
                        Take down
                      </Button>
                    )}
                  </div>
                </Td>
              </tr>
            ))}
          </DataTable>
        )}
      </Card>

      {settings && (
        <Card>
          <CardHeader
            title="Award settings"
            subtitle="System-wide rules applied when a board is built"
          />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Setting label="Winners shown" value={settings.top_n ?? 3} />
            <Setting label="Minimum assessors" value={settings.min_assessors ?? 2} />
            <Setting
              label="Require approval"
              value={settings.require_approval ? 'Yes' : 'No'}
            />
            <Setting
              label="Honour opt-out"
              value={settings.honour_opt_out ? 'Yes' : 'No'}
            />
          </div>
          <p className="mt-4 text-sm text-slate-500">
            Settings come from the server&rsquo;s configuration and environment. Change them via
            the deployment environment rather than per-board, so every board in a session
            is ranked under the same rules.
          </p>
        </Card>
      )}
    </div>
  )
}

function Setting({ label, value }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-lg font-semibold text-slate-800">{value}</p>
    </div>
  )
}
