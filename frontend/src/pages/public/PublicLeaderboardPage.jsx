import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { publicApi } from '../../api/endpoints'
import { Card, Badge, EmptyState, Spinner, ErrorState, Button } from '../../components/ui'
import { formatMark, medalFor } from '../../lib/format'

/**
 * Public Pixel-It leaderboard (Module 8).
 *
 * This page is reachable without signing in, which makes it the one place in
 * the system where the privacy boundary really matters. Two rules govern it:
 *
 * 1. It renders only what the server already froze into the published board —
 *    no computation, no live joins, nothing that could leak a draft.
 * 2. Student identity is limited to a name and student ID. Marks, e-mails, and
 *    supervisor identities are only shown if the board was built to include
 *    them, and the API simply omits them otherwise.
 *
 * The layout treats the top three as a podium because that is how the awards
 * are actually presented, with the remaining ranked projects below.
 */
export default function PublicLeaderboardPage() {
  const { slug } = useParams()

  const [board, setBoard] = useState(null)
  const [index, setIndex] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // With a slug, load that board. Without one, ask the API which boards
        // are public and show the most recent — so a bare visit to the URL
        // works even without knowing a slug.
        // publicApi.* already unwraps the envelope — see ProfilePage for the
        // same note. Unwrapping twice yields null and the page renders empty.
        if (slug) {
          const data = await publicApi.leaderboard(slug)
          if (!cancelled) setBoard(data)
        } else {
          const list = (await publicApi.leaderboards()) ?? []
          const published = (Array.isArray(list) ? list : list.items ?? []).filter(
            (b) => b.is_published !== false
          )
          if (published.length === 0) {
            if (!cancelled) setIndex([])
          } else {
            const data = await publicApi.leaderboard(published[0].slug)
            if (!cancelled) {
              setBoard(data)
              setIndex(published)
            }
          }
        }
      } catch (err) {
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [slug])

  if (loading) {
    return (
      <PublicShell>
        <Spinner label="Loading the results" />
      </PublicShell>
    )
  }

  if (error) {
    return (
      <PublicShell>
        <ErrorState error={error} />
        <div className="mt-6 text-center">
          <Link to="/leaderboard">
            <Button variant="secondary">Try the current board</Button>
          </Link>
        </div>
      </PublicShell>
    )
  }

  if (!board || (index && index.length === 0)) {
    return (
      <PublicShell>
        <Card>
          <EmptyState
            title="No results published yet"
            message="The Pixel-It award board for this session has not been published. Please check back later."
          />
        </Card>
        <p className="mt-6 text-center">
          <Link to="/login" className="text-sm font-medium text-brand-700 hover:underline">
            Staff and student sign in
          </Link>
        </p>
      </PublicShell>
    )
  }

  const entries = (board.entries ?? []).slice().sort((a, b) => (a.rank ?? 0) - (b.rank ?? 0))
  const topN = board.top_n ?? 3
  const podium = entries.filter((e) => e.rank <= Math.min(topN, 3))
  const rest = entries.filter((e) => e.rank > Math.min(topN, 3))
  const others = board.other_boards ?? index ?? []

  return (
    <PublicShell>
      <header className="mb-8 text-center">
        <p className="text-sm font-medium uppercase tracking-widest text-brand-700">
          Pixel-It Awards
        </p>
        <h1 className="mt-2 text-3xl font-semibold text-slate-900 sm:text-4xl">
          {board.title ?? 'PSM Project Recognition'}
        </h1>
        <p className="mt-2 text-slate-600">
          {board.academic_session ? `${board.academic_session} · ` : ''}
          {entries.length} project{entries.length === 1 ? '' : 's'} ranked
        </p>
        {board.subtitle && <p className="mt-3 text-sm text-slate-500">{board.subtitle}</p>}
      </header>

      {entries.length === 0 ? (
        <Card>
          <EmptyState
            title="Board is empty"
            message="This board has been published but contains no ranked projects."
          />
        </Card>
      ) : (
        <>
          {/* Podium — the top placements, given real visual weight. */}
          <div className="mb-10 grid gap-4 sm:grid-cols-3">
            {podium.map((entry) => (
              <PodiumCard
                key={entry.id}
                entry={entry}
                showScores={board.show_scores !== false}
                showNames={board.show_student_names !== false}
                showSupervisors={board.show_supervisors !== false}
                featured={entry.rank === 1}
              />
            ))}
          </div>

          {rest.length > 0 && (
            <Card className="overflow-hidden p-0">
              <div className="border-b border-slate-200 px-5 py-3">
                <h2 className="font-semibold text-slate-800">Remaining placements</h2>
              </div>
              <ul className="divide-y divide-slate-100">
                {rest.map((entry) => (
                  <li
                    key={entry.id}
                    className="flex flex-wrap items-center gap-4 px-5 py-4"
                  >
                    <span className="w-8 shrink-0 text-center font-semibold tabular-nums text-slate-500">
                      {entry.rank}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-slate-800">{entry.title}</p>
                      <p className="mt-0.5 text-sm text-slate-500">
                        {board.show_student_names !== false
                          ? studentLine(entry)
                          : entry.category ?? ''}
                      </p>
                    </div>
                    {entry.category && board.show_student_names !== false && (
                      <Badge tone="neutral">{entry.category}</Badge>
                    )}
                    {board.show_scores !== false && entry.final_mark != null && (
                      <span className="shrink-0 font-semibold tabular-nums text-slate-700">
                        {formatMark(entry.final_mark)}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}

      {others.length > 1 && (
        <nav className="mt-8 text-center" aria-label="Other award sessions">
          <p className="mb-2 text-sm text-slate-500">Other sessions</p>
          <div className="flex flex-wrap justify-center gap-2">
            {others.map((other) => (
              <Link key={other.slug} to={`/leaderboard/${other.slug}`}>
                <Button size="sm" variant={other.slug === board.slug ? 'primary' : 'secondary'}>
                  {other.academic_session ?? other.title}
                </Button>
              </Link>
            ))}
          </div>
        </nav>
      )}

      <footer className="mt-12 border-t border-slate-200 pt-6 text-center text-xs text-slate-400">
        <p>
          Marks shown are final moderated results. Student ID numbers are not published.
        </p>
        <p className="mt-2">
          <Link to="/login" className="font-medium text-brand-700 hover:underline">
            Staff and student sign in
          </Link>
        </p>
      </footer>
    </PublicShell>
  )
}

/**
 * A podium card. The winner gets a stronger treatment because the page exists
 * to celebrate one result, not to present a neutral table.
 */
function PodiumCard({ entry, showScores, showNames, showSupervisors, featured }) {
  const medal = medalFor(entry.rank)

  return (
    <article
      className={`relative overflow-hidden rounded-xl border p-5 text-center ${
        featured
          ? 'border-brand-300 bg-gradient-to-b from-brand-50 to-white shadow-sm sm:-mt-3 sm:pb-7'
          : 'border-slate-200 bg-white'
      }`}
    >
      <div
        className={`mx-auto flex h-12 w-12 items-center justify-center rounded-full text-2xl ${
          featured ? 'bg-brand-100' : 'bg-slate-100'
        }`}
        aria-hidden="true"
      >
        {medal ?? entry.rank}
      </div>

      <p className="mt-3 text-xs font-medium uppercase tracking-wide text-slate-400">
        {entry.rank === 1 ? 'Winner' : `Place ${entry.rank}`}
      </p>

      <h2 className="mt-1.5 text-base font-semibold leading-snug text-slate-900">
        {entry.title}
      </h2>

      {showNames && (
        <p className="mt-2 text-sm text-slate-600">{studentLine(entry)}</p>
      )}

      {entry.category && (
        <p className="mt-2">
          <Badge tone="neutral">{entry.category}</Badge>
        </p>
      )}

      {showScores && entry.final_mark != null && (
        <p className="mt-3 text-2xl font-semibold tabular-nums text-slate-900">
          {formatMark(entry.final_mark)}
        </p>
      )}

      {entry.supervisors?.length > 0 && showSupervisors && (
        <p className="mt-3 text-xs text-slate-500">
          Supervised by{' '}
          {entry.supervisors.map((s) => (typeof s === 'string' ? s : s.name)).join(', ')}
        </p>
      )}
    </article>
  )
}

/** Names only — never the ID, which the API does not publish anyway. */
function studentLine(entry) {
  const names = (entry.students ?? [])
    .map((s) => (typeof s === 'string' ? s : s.name))
    .filter(Boolean)
  return names.length > 0 ? names.join(', ') : '—'
}

/**
 * A deliberately plain frame: no app shell, no navigation into authenticated
 * areas. Someone arriving from a shared link should see a self-contained page.
 */
function PublicShell({ children }) {
  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-14">{children}</div>
    </div>
  )
}
