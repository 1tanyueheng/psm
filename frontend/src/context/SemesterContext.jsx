import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { semesterApi } from '../api/endpoints'

const SemesterContext = createContext(null)

const STORAGE_KEY = 'psm.selectedSemesterId'

/**
 * Which academic term the whole SPA is looking at (Module 3).
 *
 * PSM 1 and PSM 2 run concurrently *within* one term, so the term is the outer
 * scope and the batch is the inner one. Screens read their filters from here
 * rather than each fetching `/semesters/current`, which keeps every list on a
 * page agreeing about which term it is showing.
 *
 * The selection persists in localStorage so a coordinator comparing two terms
 * does not start over on each navigation — but only as a *hint*. If the stored
 * id no longer exists, or the user has no permission to list terms, the
 * context falls back to the server's answer rather than rendering an empty app.
 *
 * Not a security boundary: the API scopes and authorises independently.
 */
export function SemesterProvider({ children }) {
  const [semesters, setSemesters] = useState([])
  const [selectedId, setSelectedId] = useState(() => readStoredId())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // --- Load the terms the caller may see -------------------------------
  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)

      try {
        // Two reads: `/current` is the term this person is actually enrolled
        // in, while the list gives filter dropdowns every term they may switch
        // to. They disagree legitimately — a coordinator is not enrolled in
        // any term but still needs the active one to be the default.
        const [current, all] = await Promise.all([
          semesterApi.current().catch(() => null),
          semesterApi.list(),
        ])

        if (cancelled) return

        const list = Array.isArray(all) ? all : all?.data ?? []
        setSemesters(list)

        // Prefer the caller's own term, then the faculty's active one, then
        // whatever the list says is active. Only then does the stored
        // preference get a look in — it must never outrank a real answer.
        const fallback =
          current?.id ??
          list.find((s) => s.is_active)?.id ??
          list[0]?.id ??
          null

        const stored = readStoredId()
        const exists = stored != null && list.some((s) => String(s.id) === String(stored))

        setSelectedId(exists ? stored : fallback)
      } catch (err) {
        // A coordinator without `viewAny` on semesters, or a student hitting a
        // transient failure, should still get a working app. Record the error
        // but do not block rendering on it.
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [])

  const selectSemester = useCallback((id) => {
    setSelectedId(id == null ? null : Number(id))
    writeStoredId(id)
  }, [])

  /** Re-read the terms — after creating, closing, or toggling a gate. */
  const refresh = useCallback(async () => {
    try {
      const all = await semesterApi.list()
      const list = Array.isArray(all) ? all : all?.data ?? []
      setSemesters(list)
      return list
    } catch {
      return semesters
    }
  }, [semesters])

  const selected = useMemo(
    () => semesters.find((s) => String(s.id) === String(selectedId)) ?? null,
    [semesters, selectedId]
  )

  const value = useMemo(
    () => ({
      semesters,
      selected,
      selectedId: selected?.id ?? null,
      /** The active term on the server, which is not necessarily the selected one. */
      active: semesters.find((s) => s.is_active) ?? null,
      loading,
      error,
      selectSemester,
      refresh,

      /** Options for a `<Select>`; the selected term first when it isn't active. */
      options: buildOptions(semesters, selected),
    }),
    [semesters, selected, loading, error, selectSemester, refresh]
  )

  return <SemesterContext.Provider value={value}>{children}</SemesterContext.Provider>
}

export function useSemesters() {
  const context = useContext(SemesterContext)

  if (context === null) {
    throw new Error('useSemesters must be used inside a <SemesterProvider>')
  }

  return context
}

/**
 * The query params every term-scoped list needs.
 *
 * Returns a stable object for a given term so it is safe to put straight into
 * a `useEffect` dependency array — which is the whole point, since a params
 * object rebuilt on every render would refetch in a loop.
 *
 * `psm_part` is deliberately *not* filled in. The batch filter is per-screen,
 * and leaving it absent is meaningful: the API reads a missing part as *both*
 * batches, which is what an unfiltered "All projects" list should show.
 *
 *   const params = useSemesterQuery({ status })
 *   useEffect(() => { load(params) }, [params])
 */
export function useSemesterQuery(extra) {
  const { selectedId } = useSemesters()
  const extraKey = extra ? JSON.stringify(extra) : '{}'

  return useMemo(
    () => (selectedId == null ? { ...extra } : { semester_id: selectedId, ...extra }),
    // `extraKey` rather than `extra`: callers pass object literals, so the
    // identity changes every render even when the contents do not.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [selectedId, extraKey]
  )
}

/** Active term first, then everything else chronologically. */
function buildOptions(semesters, selected) {
  const ordered = [...semesters].sort((a, b) => {
    if (a.id === selected?.id) return -1
    if (a.is_active) return -1
    if (b.is_active) return 1
    return String(a.starts_at ?? '').localeCompare(String(b.starts_at ?? ''))
  })

  return ordered.map((s) => ({
    value: s.id,
    label: s.name,
    active: Boolean(s.is_active),
  }))
}

function readStoredId() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    const parsed = raw == null ? null : Number(raw)
    return Number.isFinite(parsed) ? parsed : null
  } catch {
    // Private browsing, or storage disabled. The server's answer is fine.
    return null
  }
}

function writeStoredId(id) {
  try {
    if (id == null) window.localStorage.removeItem(STORAGE_KEY)
    else window.localStorage.setItem(STORAGE_KEY, String(id))
  } catch {
    // Preference simply will not persist.
  }
}